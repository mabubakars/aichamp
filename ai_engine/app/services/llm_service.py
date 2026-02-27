import httpx
from app.schemas.chat import ChatRequest, ChatResponse
from app.services.rag_service import RAGService


class LLMService:
    @staticmethod
    async def process_chat(request: ChatRequest) -> ChatResponse:
        user_query = request.messages[-1].content
        context_str = ""
        sources = []

        # 1. RAG Lookup
        try:
            query_emb = await RAGService.get_embedding(user_query)
            search_results = RAGService.search(query_emb)

            context_parts = []
            for chunk, meta, score in search_results:
                if score > 0.4:  # Similarity threshold
                    context_parts.append(f"[File: {meta['source']}]\n{chunk}")
                    sources.append(meta["source"])

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
                "to answer fully, supplement the answer with your general knowledge, "
                "but clearly distinguish between what's in the text and what is general knowledge.\n\n"
                f"DOCUMENT CONTEXT:\n{context_str}"
            )
        else:
            system_prompt = "You are ScholarAI, an intelligent research assistant. Answer the user's questions clearly and accurately."

        # 3. Call Provider
        messages = [{"role": "system", "content": system_prompt}] + [
            m.dict() for m in request.messages
        ]

        return await LLMService._call_ollama(request, messages, sources)

    @staticmethod
    async def _call_ollama(request: ChatRequest, messages: list, sources: list):
        async with httpx.AsyncClient(timeout=300.0) as client:
            resp = await client.post(
                "http://localhost:11434/api/chat",
                json={"model": request.model, "messages": messages, "stream": False},
            )
            data = resp.json()
            return ChatResponse(
                content=data["message"]["content"],
                model=request.model,
                usage={"total_tokens": data.get("eval_count", 0)},
                metadata={
                    "sources": list(set(sources)),
                    "rag_applied": len(sources) > 0,
                },
            )
