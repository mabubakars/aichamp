from fastapi import APIRouter, HTTPException, UploadFile, File, Form
from app.schemas.chat import ChatRequest, ChatResponse
from app.services.llm_service import LLMService
from app.utils.extractors import FileExtractor
import json

router = APIRouter()

@router.post("/completions-with-file", response_model=ChatResponse)
async def chat_with_file(
    file: UploadFile = File(...),
    payload: str = Form(...)
):
    try:
        # 1. Parse the request data
        data = json.loads(payload)
        request = ChatRequest(**data)
        
        # 2. Extract text from file
        file_bytes = await file.read()
        extracted_text = FileExtractor.extract_text(file_bytes, file.filename)
        
        # 3. Attach context to the last message
        if extracted_text:
            context_msg = f"\n\n--- ATTACHED DOCUMENT ({file.filename}) ---\n{extracted_text}\n--- END OF DOCUMENT ---"
            request.messages[-1].content += context_msg
            
        return await LLMService.process_chat(request)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))