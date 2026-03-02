from app.schemas.chat import ChatRequest, ChatResponse
from app.services.rag_service import RAGService
import httpx

class LLMService:
    @staticmethod
    async def process_chat(request: ChatRequest) -> ChatResponse:
        user_query = request.messages[-1].content
        context_str = ""
        sources = []

        # 1. RAG Lookup
        try:
            query_emb = await RAGService.get_embedding(user_query)
            # Pass the session_id to ensure we look in the right folders
            search_results = RAGService.search(query_emb, request.session_id)
            
            context_parts = []
            for chunk, meta, score in search_results:
                # Lowered threshold to 0.3 to be more inclusive of relevant text
                if score > 0.3: 
                    context_parts.append(f"[File: {meta['source']}]\n{chunk}")
                    sources.append(meta['source'])
            
            if context_parts:
                context_str = "\n\n---\n\n".join(context_parts)
        except Exception as e:
            print(f"RAG Error: {e}")

        # 2. Build Hybrid System Prompt
        if context_str:
            system_prompt = (
                "You are ScholarAI, an intelligent research assistant. "
                "Below are relevant excerpts from the user's uploaded documents. "
                "Use this context to provide a precise answer. If the context is not sufficient "
                "to answer fully, use your general knowledge to help, but mention that "
                "the specific details were not found in the provided text.\n\n"
                f"DOCUMENT CONTEXT:\n{context_str}"
            )
        else:
            system_prompt = (
                "You are ScholarAI, an intelligent research assistant. "
                "Answer the user's questions clearly. (No document context was found for this specific query)."
            )

        messages = [{"role": "system", "content": system_prompt}] + [m.dict() for m in request.messages]
        
        return await LLMService._call_ollama(request, messages, sources)

    @staticmethod
    async def _call_ollama(request: ChatRequest, messages: list, sources: list):
        async with httpx.AsyncClient(timeout=300.0) as client:
            resp = await client.post(
                "http://localhost:11434/api/chat",
                json={
                    "model": request.model,
                    "messages": messages,
                    "stream": False
                }
            )
            
            data = resp.json()

            if "message" not in data:
                error_detail = data.get("error", "Unknown Ollama Error")
                raise Exception(f"Ollama API Error: {error_detail}")

            return ChatResponse(
                content=data['message']['content'],
                model=request.model,
                usage={"total_tokens": data.get("eval_count", 0)},
                metadata={
                    "sources": list(set(sources)),
                    "rag_applied": len(sources) > 0
                }
            )