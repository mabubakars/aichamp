from pathlib import Path
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler
import asyncio
from app.core.config import BOOKS_DIR
from app.services.rag_service import RAGService

class BookHandler(FileSystemEventHandler):
    def on_modified(self, event):
        if not event.is_directory:
            asyncio.run_coroutine_threadsafe(RAGService.index_file(Path(event.src_path)), asyncio.get_event_loop())

    def on_created(self, event):
        if not event.is_directory:
            asyncio.run_coroutine_threadsafe(RAGService.index_file(Path(event.src_path)), asyncio.get_event_loop())

def start_monitoring():
    observer = Observer()
    observer.schedule(BookHandler(), str(BOOKS_DIR), recursive=True)
    observer.start()
    print(f"Monitoring {BOOKS_DIR} for changes...")