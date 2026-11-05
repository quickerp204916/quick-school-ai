"""OpenAI and Gemini chat and embedding models through LangChain. The model name comes from the ERP's settings per agent."""
from __future__ import annotations

from .config import Settings

PROVIDERS = {"openai", "gemini"}


def chat_model(provider: str, model: str, s: Settings, temperature: float = 0.2):
    if provider == "openai":
        if not s.openai_api_key:
            raise RuntimeError("OPENAI_API_KEY is not set on the AI service.")
        from langchain_openai import ChatOpenAI
        return ChatOpenAI(model=model, api_key=s.openai_api_key, temperature=temperature, timeout=60, max_retries=2)
    if provider == "gemini":
        if not s.gemini_api_key:
            raise RuntimeError("GEMINI_API_KEY is not set on the AI service.")
        from langchain_google_genai import ChatGoogleGenerativeAI
        return ChatGoogleGenerativeAI(model=model, google_api_key=s.gemini_api_key, temperature=temperature, timeout=60, max_retries=2)
    raise ValueError(f"Unknown provider {provider!r}.")


def embedder(s: Settings):
    if s.embedding_provider == "openai":
        from langchain_openai import OpenAIEmbeddings
        return OpenAIEmbeddings(model=s.embedding_model, api_key=s.openai_api_key, dimensions=s.embedding_dim)
    if s.embedding_provider == "gemini":
        from langchain_google_genai import GoogleGenerativeAIEmbeddings
        return GoogleGenerativeAIEmbeddings(model=s.embedding_model, google_api_key=s.gemini_api_key)
    raise ValueError(f"Unknown embedding provider {s.embedding_provider!r}.")


def usage_of(message) -> dict[str, int]:
    """Token counts from a LangChain AIMessage (both providers fill usage_metadata)."""
    u = getattr(message, "usage_metadata", None) or {}
    return {"input_tokens": int(u.get("input_tokens", 0) or 0), "output_tokens": int(u.get("output_tokens", 0) or 0)}
