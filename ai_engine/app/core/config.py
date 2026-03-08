import os
from pathlib import Path

# This should point to the root of ai_engine
BASE_DIR = Path(__file__).resolve().parent.parent.parent

VECTOR_DB_PATH = BASE_DIR / "vector_store.pkl"
BOOKS_DIR = BASE_DIR / "books"
UPLOADS_DIR = BASE_DIR / "user_uploads"

# Ensure directories exist immediately
BOOKS_DIR.mkdir(parents=True, exist_ok=True)
UPLOADS_DIR.mkdir(parents=True, exist_ok=True)

OLLAMA_URL = os.getenv("OLLAMA_URL", "http://localhost:11434")
EMBED_MODEL = "nomic-embed-text"