import fitz  # PyMuPDF
from docx import Document
import io


class FileExtractor:
    @staticmethod
    def extract_text(file_bytes: bytes, filename: str) -> str:
        ext = filename.split(".")[-1].lower()
        if ext == "pdf":
            return FileExtractor._pdf(file_bytes)
        elif ext == "docx":
            return FileExtractor._docx(file_bytes)
        elif ext == "txt":
            return file_bytes.decode("utf-8")
        return ""

    @staticmethod
    def _pdf(file_bytes):
        text = ""
        with fitz.open(stream=file_bytes, filetype="pdf") as doc:
            for page in doc:
                text += page.get_text()
        return text

    @staticmethod
    def _docx(file_bytes):
        doc = Document(io.BytesIO(file_bytes))
        return "\n".join([para.text for para in doc.paragraphs])
