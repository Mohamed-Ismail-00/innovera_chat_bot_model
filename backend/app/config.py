"""
Innovera AI Chatbot — Application Configuration
Centralized settings using pydantic-settings for type safety and validation.
"""

from pydantic_settings import BaseSettings
from pathlib import Path


class Settings(BaseSettings):
    """Application settings loaded from environment variables and .env file."""

    # --- LLM Provider Selection ---
    # Options: "groq" (cloud, recommended) or "ollama" (local)
    llm_provider: str = "groq"

    # --- Groq Cloud Configuration ---
    groq_api_key: str = ""
    # Primary model: llama-3.1-8b-instant is ultra fast (1200+ tok/s) and has 30,000 TPM / 14,400 RPD limit
    groq_model: str = "llama-3.1-8b-instant"
    # Active Fallback models chain if primary model hits rate limit or error
    groq_fallback_models: list[str] = [
        "llama-3.1-8b-instant",
        "llama3-8b-8192",
        "llama-3.3-70b-versatile",
    ]

    # --- Ollama Local Configuration (fallback) ---
    ollama_base_url: str = "http://localhost:11434"
    ollama_model: str = "llama3.2"

    # --- ChromaDB Configuration ---
    chroma_persist_dir: str = str(Path(__file__).parent.parent / "chroma_db")

    # --- Embedding Model ---
    embedding_model: str = "all-MiniLM-L6-v2"

    # --- CORS ---
    cors_origins: list[str] = ["*"]

    # --- Paths ---
    data_dir: Path = Path(__file__).parent / "data"

    # --- Session & Rate Limiting ---
    max_history_messages: int = 6  # Kept concise for speed & low token usage
    rate_limit_per_minute: int = 30  # Max requests per session per minute
    max_message_length: int = 500  # Max characters per message

    model_config = {
        "env_file": Path(__file__).parent.parent / ".env",
        "env_file_encoding": "utf-8",
        "extra": "ignore",
    }


# Singleton instance — import this everywhere
settings = Settings()
