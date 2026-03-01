# Architectural Shift: PHP to Python AI Engine

This guide outlines the architectural migration of Large Language Model (LLM) logic from the PHP monolithic backend to a specialized Python FastAPI engine with **Partitioned RAG support**.

The PHP backend serves as the **System of Record**, while the Python server acts as the **System of Intelligence**.

---

## 1. Core Architecture

The platform operates on a "Proxy-Brain" model with multi-tenant RAG:

1.  **Request Entry**: PHP receives the request, validates JWT, and confirms session ownership.
2.  **Intelligence Bridge**: `FastAPIProvider.php` forwards the prompt and any file attachments to Python.
3.  **Partitioned RAG Processing**:
    - **Global Knowledge**: Python scans `ai_engine/books/` (recursive subfolders) for shared research data.
    - **Private Knowledge**: Python stores user files in `ai_engine/user_uploads/{session_id}/` for session-specific memory.
4.  **Hot-Reloading**: Python monitors the `books/` folder for changes using `watchdog` and re-indexes files automatically.
5.  **Hybrid Response**: Python first searches the Vector DB (RAG), then uses the LLM to synthesize an answer.

---

## 2. Updated Project Structure

### Python AI Engine (`ai_engine/`)

```text
ai_engine/
├── main.py              # Startup, initial indexing, and watcher initialization
├── books/               # GLOBAL RAG: Shared PDFs/TXTs (supports subfolders)
├── user_uploads/        # PRIVATE RAG: Session-specific file storage
├── app/
│   ├── core/
│   │   ├── config.py    # Path and Model configurations
│   │   └── monitor.py   # Real-time folder watcher (watchdog)
│   ├── api/
│   │   └── chat.py      # Handles JSON and Multipart (file) chat requests
│   ├── schemas/
│   │   └── chat.py      # Pydantic models for request/response
│   └── services/
│       ├── llm_service.py # Prompt augmentation and provider routing
│       └── rag_service.py # Vector math, persistence, and partitioned search
```

---

## 3. Implementation Details

### Phase 1: Partitioned RAG Service

- **Vector Persistence**: The DB is stored in `vector_store.pkl`.
- **Security**: Search results are filtered so that `session_A` cannot see files uploaded in `session_B`, but both can see files in `books/`.
- **Differential Indexing**: Files are hashed; Python only re-chunks if the file content actually changes.

### Phase 2: Real-time Monitoring

- **Watcher**: Implemented `app/core/monitor.py` using the `watchdog` library. It monitors the `books/` directory recursively.

---

## 4. Execution Summary

### Step 1: Install Python Dependencies

```powershell
cd ai_engine
pip install fastapi uvicorn python-dotenv httpx pydantic pymupdf python-docx numpy watchdog
```

### Step 2: Setup Ollama Models

The system requires both a Chat model and an Embedding model.

```powershell
# The "Brain"
ollama pull llama3.1:8b
ollama pull deepseek-r1:7b

# The "Librarian" (Used to turn text into searchable vectors)
ollama pull nomic-embed-text
```

### Step 3: Configure Environment

Add the following to `api/.env`:

```ini
PYTHON_FASTAPI_URL=http://localhost:8001
```

### Step 4: Run the Servers

1.  **Python Engine**: `uvicorn main:app --port 8001 --reload`
2.  **PHP Backend**: `php -S localhost:8000 index.php`

---

## 5. Roadmap Integration

### Advanced RAG

The `rag_service.py` is ready for advanced techniques like hybrid search (semantic + keyword) or moving from a `.pkl` file to a production Vector DB like ChromaDB.

### Multimodal

New routes in `app/api/generate.py` will be added to handle Image and Video generation tasks, keeping them separate from the text chat logic.
