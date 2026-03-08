from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent.parent
VECTOR_DB_PATH = BASE_DIR / "vector_store.pkl"
BOOKS_DIR = BASE_DIR / "books"
UPLOADS_DIR = BASE_DIR / "user_uploads"

# Ensure directories exist
BOOKS_DIR.mkdir(exist_ok=True)
UPLOADS_DIR.mkdir(exist_ok=True)

OLLAMA_URL = "http://localhost:11434"
EMBED_MODEL = "nomic-embed-text"