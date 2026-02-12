from fastapi import FastAPI
from app.api import chat
from dotenv import load_dotenv

load_dotenv()

app = FastAPI(title="ScholarCompass AI Engine")

# Modular route registration
app.include_router(chat.router, prefix="/v1/chat", tags=["Chat"])

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8001, reload=True)