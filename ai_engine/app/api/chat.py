from fastapi import APIRouter, HTTPException, UploadFile, File, Form
from app.schemas.chat import ChatRequest, ChatResponse
from app.services.llm_service import LLMService
from app.utils.extractors import FileExtractor
from app.services.rag_service import RAGService
from app.core.config import UPLOADS_DIR
import json

router = APIRouter()


@router.post("/completions-with-file", response_model=ChatResponse)
async def chat_with_file(
    file: UploadFile = File(...),
    payload: str = Form(...)
):
    try:
        data = json.loads(payload)
        request = ChatRequest(**data)
        
        # 1. Create session-specific folder
        session_path = UPLOADS_DIR / request.session_id
        session_path.mkdir(exist_ok=True)
        
        # 2. Save file to disk
        file_path = session_path / file.filename
        with open(file_path, "wb") as f:
            f.write(await file.read())
            
        # 3. Index for this session specifically
        await RAGService.index_file(file_path, session_id=request.session_id)
            
        return await LLMService.process_chat(request)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))