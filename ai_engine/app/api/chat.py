from fastapi import APIRouter, HTTPException
from app.schemas.chat import ChatRequest, ChatResponse
from app.services.llm_service import LLMService

router = APIRouter()

@router.post("/completions", response_model=ChatResponse)
async def chat_completions(request: ChatRequest):
    try:
        return await LLMService.process_chat(request)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))