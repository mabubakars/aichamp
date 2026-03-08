from pydantic import BaseModel, Field
from typing import List, Optional, Dict, Any

class ChatMessage(BaseModel):
    """Represents a single exchange in the conversation history."""
    role: str # e.g., "user", "assistant", "system"
    content: str

class ChatRequest(BaseModel):
    """
    Data received from the PHP Intermediary.
    Designed for extensibility (RAG & Multimodal).
    """
    session_id: str
    user_id: str
    messages: List[ChatMessage]
    model: str
    provider: str
    
    # context_data allows PHP to send extra info (like user's research field) 
    # to help Python's Advanced RAG logic retrieve better documents.
    context_data: Optional[Dict[str, Any]] = Field(default_factory=dict)
    
    # options holds LLM parameters like temperature, top_p, or max_tokens.
    options: Optional[Dict[str, Any]] = Field(default_factory=dict)

class ChatResponse(BaseModel):
    """
    Standardized response returned to PHP.
    Maps easily back to the OpenAI format.
    """
    content: str
    model: str
    usage: Dict[str, int] = Field(
        default_factory=lambda: {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0}
    )
    
    # metadata can store 'thinking traces', source citations (for RAG), 
    # or generation time.
    metadata: Dict[str, Any] = Field(default_factory=dict)