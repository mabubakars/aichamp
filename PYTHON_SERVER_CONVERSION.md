# Architectural Shift: PHP to Python AI Engine

This guide outlines the architectural migration of Large Language Model (LLM) logic from the PHP monolithic backend to a specialized Python FastAPI engine. 

The PHP backend serves as the **System of Record** and **Authorized Intermediary**, while the Python server acts as the **System of Intelligence**.

---

## 1. Core Architecture

The platform operates on a "Proxy-Brain" model:

1.  **Request Entry**: PHP receives the request, validates the JWT, and confirms session ownership.
2.  **Pre-Persistence**: PHP saves the `UserPrompt` to MySQL.
3.  **Intelligence Bridge**: The `FastAPIProvider.php` normalizes the conversation history and forwards it to the Python AI Engine.
4.  **Intelligence Processing**: Python handles RAG retrieval, multi-model calls, and complex reasoning (e.g., DeepSeek R1).
5.  **Post-Persistence**: PHP receives Python's response, extracts metadata/thinking traces, and saves the `AIResponse` to MySQL.
6.  **Response Delivery**: PHP returns the final standardized JSON to the Frontend.

---

## 2. Updated Project Structure

### Python AI Engine (`ai_engine/`)
Designed for modular expansion into RAG and Multimodal (Image/Video) workflows.

```text
ai_engine/
├── main.py              # FastAPI startup and route registration
├── requirements.txt     # Python dependencies
├── .env                 # AI API Keys
└── app/
    ├── api/             # API Route Handlers (Separated by domain)
    │   └── chat.py      # LLM Chat routes
    ├── schemas/         # Pydantic Models (Strict JSON validation)
    │   └── chat.py      # Request/Response definitions
    └── services/        # Business Logic Core
        └── llm_service.py # LLM routing and processing logic
```

### PHP Backend Bridge (`api/`)
Minimally invasive changes to route existing service logic to Python.

```text
api/
└── services/
    ├── FastAPIProvider.php  # THE BRIDGE: Handles HTTP communication with Python
    └── AIProviderFactory.php # THE ROUTER: Directs all requests to the Bridge
```

---

## 3. Implementation Details

### Phase 1: The PHP Bridge (`FastAPIProvider.php`)
The bridge was engineered to solve JSON compatibility issues between PHP and Python:
*   **Message Normalization**: Converts various PHP message formats into a strict array of objects for Python's Pydantic validation.
*   **Empty Object Handling**: Uses `stdClass` to ensure empty dictionaries (like `context_data`) are sent as `{}` instead of `[]`.
*   **Constructor Injection**: Receives the `AIModel` object to store `model_name` and `provider` context for subsequent API calls.

### Phase 2: Python Modular Core
*   **Main Entry**: `main.py` uses modular routing via `app.include_router`, allowing for the addition of `/v1/rag` or `/v1/generate` without modifying the core startup logic.
*   **Data Integrity**: `app/schemas/chat.py` enforces the data contract. Python will reject any request from PHP that doesn't include `session_id` or `user_id`.
*   **LLM Orchestration**: `app/services/llm_service.py` is the point of entry for all "thinking" tasks, including future RAG context injection.

---

## 4. Modified Files & Logic

### `api/services/AIProviderFactory.php`
The factory logic was simplified to force all requests through the bridge:
```php
public static function create($model) {
    return new FastAPIProvider($model);
}
```

### `api/services/FastAPIProvider.php`
Key logic added for communication:
*   `normalizeMessages($messages)`: Ensures historical context is a clean indexed array.
*   `curl_exec`: Configured with a 300s timeout to support long-reasoning models.

### `ai_engine/app/api/chat.py`
Standardized endpoint:
*   `POST /v1/chat/completions`: Receives `ChatRequest`, calls `LLMService`, returns `ChatResponse`.

---

## 5. Roadmap Integration

### Advanced RAG (Retrieval-Augmented Generation)
Python's `LLMService` is equipped with a placeholder for `rag_service`. Future implementation will:
1.  Intercept the user prompt in Python.
2.  Retrieve documents from a Vector DB.
3.  Inject context directly into the `ChatRequest` messages before calling the LLM.

### Multimodal Support
Image and Video generation will follow the same pattern:
1.  PHP sends a "Task" to `app/api/generate.py`.
2.  Python handles long-running GPU processing.
3.  Python returns a status or file URL for PHP to store in the database.

---

## 6. Execution Summary
1.  **Python**: `uvicorn main:app --port 8001 --reload`
2.  **PHP**: `php -S localhost:8000 index.php`
3.  **ENV UPDATE**: add `PYTHON_FASTAPI_URL=http://localhost:8001` to `api/.env` file.
4.  **Database**: Ensure all tables use `utf8mb4_unicode_ci` to avoid foreign key mismatches.