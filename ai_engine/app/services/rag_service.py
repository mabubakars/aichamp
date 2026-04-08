import pickle, hashlib, numpy as np, httpx, asyncio
from pathlib import Path
from app.core.config import VECTOR_DB_PATH, OLLAMA_URL, EMBED_MODEL, BASE_DIR
from app.utils.extractors import FileExtractor

class RAGService:
    # Structure: {"hashes": {path: hash}, "embeddings": [], "chunks": [], "metadata": []}
    _db = {"hashes": {}, "embeddings": [], "chunks": [], "metadata": []}

    @classmethod
    def load_db(cls):
        if Path(VECTOR_DB_PATH).exists():
            with open(VECTOR_DB_PATH, "rb") as f:
                cls._db = pickle.load(f)

    @classmethod
    def save_db(cls):
        with open(VECTOR_DB_PATH, "wb") as f:
            pickle.dump(cls._db, f)

    @staticmethod
    async def get_embedding(text: str):
        async with httpx.AsyncClient(timeout=60) as client:
            r = await client.post(f"{OLLAMA_URL}/api/embed", json={"model": EMBED_MODEL, "input": text})
            r.raise_for_status()
            return np.array(r.json()["embeddings"][0], dtype=np.float32)

    @classmethod
    async def index_file(cls, file_path: Path, session_id: str = None):
        """Indexes a file if it's new or changed. session_id=None means it's a 'book'."""
        content_bytes = file_path.read_bytes()
        file_hash = hashlib.md5(content_bytes).hexdigest()
        
        # Check if already indexed and unchanged
        path_key = str(file_path.relative_to(BASE_DIR))
        if cls._db["hashes"].get(path_key) == file_hash:
            return False

        print(f"Indexing: {path_key}")
        text = FileExtractor.extract_text(content_bytes, file_path.name)
        if not text.strip(): return False

        # Remove old entries for this file if re-indexing
        cls.remove_file_from_db(path_key)

        words = text.split()
        chunks = [" ".join(words[i:i + 500]) for i in range(0, len(words), 450)]
        
        for i, chunk in enumerate(chunks):
            emb = await cls.get_embedding(chunk)
            cls._db["embeddings"].append(emb)
            cls._db["chunks"].append(chunk)
            cls._db["metadata"].append({
                "path": path_key,
                "source": file_path.name,
                "session_id": session_id, # Key for partitioning
                "is_library": session_id is None
            })

        cls._db["hashes"][path_key] = file_hash
        cls.save_db()
        return True

    @classmethod
    def remove_file_from_db(cls, path_key):
        indices_to_remove = [i for i, m in enumerate(cls._db["metadata"]) if m["path"] == path_key]
        for i in sorted(indices_to_remove, reverse=True):
            cls._db["embeddings"].pop(i)
            cls._db["chunks"].pop(i)
            cls._db["metadata"].pop(i)

    @classmethod
    def search(cls, query_embedding: np.ndarray, session_id: str):
        if not cls._db["embeddings"]: return []
        
        matrix = np.array(cls._db["embeddings"])
        q = query_embedding / (np.linalg.norm(query_embedding) + 1e-10)
        norms = np.linalg.norm(matrix, axis=1, keepdims=True) + 1e-10
        scores = (matrix / norms) @ q
        
        results = []
        for i in np.argsort(scores)[::-1]:
            meta = cls._db["metadata"][i]
            # SECURITY: Only return if it's a global book OR belongs to this session
            if meta["is_library"] or meta["session_id"] == session_id:
                results.append((cls._db["chunks"][i], meta, float(scores[i])))
            if len(results) >= 5: break
        return results
    
    @classmethod
    def clear_session_vectors(cls, session_id: str):
        """Removes all chunks and embeddings associated with a deleted session."""
        if not cls._db["metadata"]:
            return 0

        # Find indices where session_id matches
        indices_to_remove = [
            i for i, m in enumerate(cls._db["metadata"]) 
            if m.get("session_id") == session_id
        ]

        # Remove from back to front to avoid index shifting
        for i in sorted(indices_to_remove, reverse=True):
            cls._db["embeddings"].pop(i)
            cls._db["chunks"].pop(i)
            cls._db["metadata"].pop(i)

        # Also remove from hashes to allow re-uploading in the future
        keys_to_forget = [k for k, v in cls._db["hashes"].items() if f"user_uploads/{session_id}" in k]
        for k in keys_to_forget:
            cls._db.get("hashes", {}).pop(k, None)

        cls.save_db()
        print(f"Cleaned up {len(indices_to_remove)} vectors for session {session_id}")
        return len(indices_to_remove)