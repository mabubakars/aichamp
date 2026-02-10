import httpx
from app.schemas.chat import ChatRequest, ChatResponse

class LLMService:
    @staticmethod
    async def process_chat(request: ChatRequest) -> ChatResponse:
        # 1. Logic for Advanced RAG will be injected here in the future
        # context = await RAGService.get_context(request.messages[-1].content)
        
        # 2. Route to the correct provider
        if request.provider == "ollama":
            return await LLMService._call_ollama(request)
        elif request.provider == "openai":
            return await LLMService._call_openai(request)
        
        raise ValueError(f"Unsupported provider: {request.provider}")

    @staticmethod
    async def _call_ollama(request: ChatRequest):
        async with httpx.AsyncClient(timeout=300.0) as client:
            resp = await client.post(
                "http://localhost:11434/api/chat",
                json={
                    "model": request.model,
                    "messages": [m.dict() for m in request.messages],
                    "stream": False
                }
            )
            data = resp.json()
            return ChatResponse(
                content=data['message']['content'],
                model=request.model,
                usage={"total_tokens": data.get("eval_count", 0)},
                metadata={"reasoning_detected": "<think>" in data['message']['content']}
            )
    
    # Add _call_openai, _call_anthropic, etc.