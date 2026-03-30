import httpx
import os
import json
import traceback
from app.schemas.chat import ChatRequest, ChatResponse
from app.services.rag_service import RAGService

class LLMService:
    @staticmethod
    def _log_debug(title, data):
        print(f"\n{'='*20} DEBUG: {title} {'='*20}")
        if isinstance(data, (dict, list)):
            print(json.dumps(data, indent=2))
        else:
            print(data)
        print(f"{'='*50}\n")

    @staticmethod
    async def process_chat(request: ChatRequest) -> ChatResponse:
        user_query = request.messages[-1].content
        context_str = ""
        sources = []

        print(f"--- Processing Chat for Provider: {request.provider} | Model: {request.model} ---")

        # 1. RAG Lookup
        try:
            query_emb = await RAGService.get_embedding(user_query)
            search_results = RAGService.search(query_emb, request.session_id)
            
            context_parts = []
            for chunk, meta, score in search_results:
                if score > 0.3: 
                    context_parts.append(f"[File: {meta['source']}]\n{chunk}")
                    sources.append(meta['source'])
            
            if context_parts:
                context_str = "\n\n---\n\n".join(context_parts)
        except Exception as e:
            print(f"RAG Error (Non-Fatal): {e}")

        # 2. Build System Prompt
        system_prompt = (
            f"You are ScholarAI. DOCUMENT CONTEXT:\n{context_str}" if context_str 
            else "You are ScholarAI, an intelligent research assistant."
        )

        messages = [{"role": "system", "content": system_prompt}] + [m.dict() for m in request.messages]
        
        # 3. ROUTING
        try:
            if request.provider == "openrouter":
                return await LLMService._call_openrouter(request, messages, sources)
            else:
                return await LLMService._call_ollama(request, messages, sources)
        except Exception as e:
            print(f"FATAL ERROR in {request.provider}: {str(e)}")
            traceback.print_exc() # This will print the full line-by-line error in your terminal
            raise e

    @staticmethod
    async def _call_openrouter(request: ChatRequest, messages: list, sources: list):
        api_key = os.getenv("OPENROUTER_API_KEY")
        api_url = os.getenv("OPENROUTER_API_URL")
        
        if not api_key or not api_url:
            raise ValueError("OPENROUTER_API_KEY or OPENROUTER_API_URL not set")

        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "http://localhost:8000",
            "X-Title": "ScholarCompass"
        }

        clean_messages = []
        for m in messages:
            if not clean_messages or clean_messages[-1]['role'] != m['role']:
                clean_messages.append(m)
            else:
                clean_messages[-1]['content'] += f"\n{m['content']}"

        async with httpx.AsyncClient(timeout=300.0) as client:
            resp = await client.post(
                api_url, 
                headers=headers, 
                json={"model": request.model, "messages": clean_messages}
            )
            
            # Log full response for debugging
            print(f"OpenRouter Status: {resp.status_code}")
            data = resp.json()
            # print(f"OpenRouter Response: {json.dumps(data, indent=2)}")
            
            # Check for API-level errors
            if resp.status_code != 200:
                raise Exception(f"OpenRouter API error {resp.status_code}: {data.get('error', data)}")
            
            if 'choices' not in data or not data['choices']:
                raise Exception(f"No choices in OpenRouter response: {data}")
            
            msg_obj = data['choices'][0]['message']
            content = msg_obj.get('content') or ''
            reasoning = msg_obj.get('reasoning') or ''

            full_content = f"<think>\n{reasoning}\n</think>\n{content}" if reasoning else content

            return ChatResponse(
                content=full_content,
                model=request.model,
                usage=data.get("usage", {}),
                metadata={
                    "sources": list(set(sources)),
                    "rag_applied": len(sources) > 0,
                    "raw_reasoning": reasoning
                }
            )

    @staticmethod
    async def _call_ollama(request: ChatRequest, messages: list, sources: list):
        async with httpx.AsyncClient(timeout=300.0) as client:
            resp = await client.post(
                "http://127.0.0.1:11434/api/chat",
                json={"model": request.model, "messages": messages, "stream": False}
            )
            data = resp.json()
            return ChatResponse(
                content=data['message']['content'],
                model=request.model,
                usage={"total_tokens": data.get("eval_count", 0)},
                metadata={"sources": list(set(sources)), "rag_applied": len(sources) > 0}
            )