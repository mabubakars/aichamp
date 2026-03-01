feat: implement partitioned RAG and real-time document monitoring
February 27, 2026

*   **Integrated Local Embeddings**: Added support for `nomic-embed-text` via Ollama to generate high-quality vector representations of document chunks.
*   **Persistent Vector Storage**: Developed a custom Vector DB using NumPy and Pickle (`vector_store.pkl`). This ensures similarity searches are processed with native performance and data remains persistent between server restarts.
*   **Partitioned Knowledge Architecture**: Implemented a two-tier RAG storage system to manage data privacy and accessibility:
    *   **Global Library**: A `books/` directory that supports recursive sub-folder scanning (e.g., `books/physics`, `books/chemistry`). Files in this hierarchy are indexed and searchable by all users globally.
    *   **Private Memory**: A `user_uploads/` directory that dynamically creates sub-folders based on `session_id`. Files uploaded here are strictly isolated to the specific chat session where they were provided.
*   **Multi-Format Extraction**: Built specialized text extraction utilities to support scholarly and technical documents in `.pdf` (via PyMuPDF), `.docx` (via python-docx), and `.txt` formats.
*   **Real-time Hot-Reloading**: Integrated the `watchdog` library to monitor the `books/` directory. The system automatically triggers re-indexing for new or modified files in real-time, allowing the knowledge base to stay updated without manual intervention or server downtime.
*   **Hybrid Knowledge Prompting**: Updated the LLM reasoning logic to follow a prioritized context approach. The model is instructed to first seek answers within the provided RAG context; however, if the specific information is missing from the documents, it utilizes its internal general knowledge (Llama 3.1 or DeepSeek R1) to provide a comprehensive response rather than an "information not found" error.

By partitioning the vector search, the engine can handle a large-scale library of shared research while simultaneously allowing users to maintain distinct, private "memories" for individual chat sessions, ensuring that context from one research project does not leak into another.

----------------
----------------