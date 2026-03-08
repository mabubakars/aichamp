from fastapi import APIRouter, HTTPException, UploadFile, File, Form
from app.schemas.chat import ChatRequest, ChatResponse
from app.services.llm_service import LLMService
from app.services.rag_service import RAGService
import json
import os

router = APIRouter()

# 1. Route for simple text messages (JSON)
@router.post("/completions", response_model=ChatResponse)
async def chat_completions(request: ChatRequest):
    try:
        return await LLMService.process_chat(request)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

# 2. Route for messages with attachments (Multipart)
@router.post("/completions-with-file", response_model=ChatResponse)
async def chat_with_file(
    file: UploadFile = File(...),
    payload: str = Form(...)
):
    try:
        # Parse the JSON payload string from PHP
        data = json.loads(payload)
        request = ChatRequest(**data)
        
        # Save and Index the file
        from app.core.config import UPLOADS_DIR
        session_path = UPLOADS_DIR / request.session_id
        session_path.mkdir(parents=True, exist_ok=True)
        
        file_path = session_path / file.filename
        content = await file.read()
        with open(file_path, "wb") as f:
            f.write(content)
            
        # Index for RAG
        await RAGService.index_file(file_path, session_id=request.session_id)
            
        return await LLMService.process_chat(request)
    except Exception as e:
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=str(e))
    
@router.delete("/sessions/{session_id}")
async def delete_session_memory(session_id: str):
    try:
        count = RAGService.clear_session_vectors(session_id)
        
        # Also physically delete the folder
        from app.core.config import UPLOADS_DIR
        import shutil
        session_folder = UPLOADS_DIR / session_id
        if session_folder.exists():
            shutil.rmtree(session_folder)
            
        return {"success": True, "deleted_chunks": count}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))