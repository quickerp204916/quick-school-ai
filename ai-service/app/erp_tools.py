"""Calls back to the ERP's tool API. The ERP checks the user's permissions on every call; this service never sees
another tenant's data because the session token is bound to one institution and one user."""
from __future__ import annotations

import json
import re
from urllib.parse import urlparse

import httpx

from .security import sign

NAME_RE = re.compile(r"[^a-zA-Z0-9_-]")


def safe_name(name: str) -> str:
    """ERP tool names use dots (accounting.trial_balance); LLM function names may not."""
    return NAME_RE.sub("_", name)[:64]


def to_function_specs(tools: list[dict]) -> tuple[list[dict], dict[str, str]]:
    """ERP tool specs → OpenAI-style function specs (also accepted by Gemini through LangChain), plus a name map back."""
    specs, names = [], {}
    for t in tools:
        n = safe_name(t["name"])
        names[n] = t["name"]
        specs.append({"type": "function", "function": {"name": n, "description": t.get("description", "")[:1024],
                                                       "parameters": t.get("parameters") or {"type": "object", "properties": {}}}})
    return specs, names


def host_allowed(url: str, allowed: list[str]) -> bool:
    host = (urlparse(url).hostname or "").lower()
    return any(host == a.lstrip(".") or (a.startswith(".") and host.endswith(a)) for a in allowed)


class ErpToolClient:
    def __init__(self, base_url: str, session_token: str, key: str, allowed_hosts: list[str], timeout: float = 20.0, client: httpx.AsyncClient | None = None):
        if not host_allowed(base_url, allowed_hosts):
            raise ValueError("The ERP tool URL is not on the allowed host list.")
        self.base = base_url.rstrip("/")
        self.token = session_token
        self.key = key
        self.client = client or httpx.AsyncClient(timeout=timeout)

    async def call(self, name: str, args: dict) -> dict:
        body = json.dumps({"arguments": args}, separators=(",", ":")).encode()
        headers = {"Content-Type": "application/json", "Accept": "application/json", "X-AI-Session": self.token, **sign(body, self.key)}
        try:
            r = await self.client.post(f"{self.base}/{name}", content=body, headers=headers)
        except httpx.HTTPError as e:
            return {"ok": False, "error": f"The ERP could not be reached: {type(e).__name__}."}
        try:
            data = r.json()
        except ValueError:
            data = {"error": r.text[:300]}
        if r.status_code >= 400:
            return {"ok": False, "status": r.status_code, "error": data.get("message") or data.get("error") or "The ERP refused this tool call."}
        return {"ok": True, **data} if isinstance(data, dict) else {"ok": True, "data": data}
