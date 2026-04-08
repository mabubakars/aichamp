from fastapi import FastAPI
from app.api import chat
from dotenv import load_dotenv
from app.services.rag_service import RAGService
from app.core.config import BOOKS_DIR
from app.core.monitor import start_monitoring

load_dotenv()

app = FastAPI(title="ScholarCompass AI Engine")

async def initial_index():
    # Recursively find all files in books folder
    for path in BOOKS_DIR.rglob("*"):
        if path.suffix.lower() in [".pdf", ".txt", ".docx"]:
            await RAGService.index_file(path)
    print("Initial library indexing complete.")

@app.on_event("startup")
async def startup():
    RAGService.load_db()
    await initial_index()
    start_monitoring()

app.include_router(chat.router, prefix="/v1/chat", tags=["Chat"])

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8001, reload=True)