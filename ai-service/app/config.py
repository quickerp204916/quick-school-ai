"""Settings from the environment. Nothing secret is ever logged."""
from __future__ import annotations

import os
from dataclasses import dataclass, field


def _list(name: str, default: str = "") -> list[str]:
    return [x.strip() for x in os.environ.get(name, default).split(",") if x.strip()]


@dataclass(frozen=True)
class Settings:
    # Shared secret with the Laravel app: signs every request in both directions.
    service_key: str = field(default_factory=lambda: os.environ.get("QSA_AI_SERVICE_KEY", ""))
    # ERP hosts the service may call back for tools (stops a forged request pointing the service at other hosts).
    allowed_erp_hosts: list[str] = field(default_factory=lambda: _list("QSA_ERP_ALLOWED_HOSTS", ".quickschool.app,localhost,127.0.0.1"))
    openai_api_key: str = field(default_factory=lambda: os.environ.get("OPENAI_API_KEY", ""))
    gemini_api_key: str = field(default_factory=lambda: os.environ.get("GEMINI_API_KEY", os.environ.get("GOOGLE_API_KEY", "")))
    database_url: str = field(default_factory=lambda: os.environ.get("QSA_AI_DATABASE_URL", "postgresql://qsa_ai:qsa_ai@127.0.0.1:5432/qsa_ai"))
    embedding_provider: str = field(default_factory=lambda: os.environ.get("QSA_EMBEDDING_PROVIDER", "openai"))
    embedding_model: str = field(default_factory=lambda: os.environ.get("QSA_EMBEDDING_MODEL", "text-embedding-3-small"))
    embedding_dim: int = field(default_factory=lambda: int(os.environ.get("QSA_EMBEDDING_DIM", "1536")))
    signature_tolerance: int = 300
    max_steps: int = field(default_factory=lambda: int(os.environ.get("QSA_AI_MAX_STEPS", "8")))
    tool_timeout: float = 20.0


def settings() -> Settings:
    return Settings()
