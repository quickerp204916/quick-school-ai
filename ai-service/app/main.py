"""HTTP API of the intelligence service. Only the ERP calls it: every request must carry a valid signature."""
from __future__ import annotations

import base64
from typing import Any

from fastapi import Depends, FastAPI, HTTPException, Request, Response
from pydantic import BaseModel, Field

from . import agents, graph as g, llm
from .config import Settings, settings
from .erp_tools import ErpToolClient, to_function_specs
from .rag import KnowledgeStore
from .security import verify

app = FastAPI(title="Quick School AI intelligence service", version="1.0.0")


def get_settings() -> Settings:
    return settings()


async def signed(request: Request, s: Settings = Depends(get_settings)) -> bytes:
    body = await request.body()
    if not verify(body, dict(request.headers), s.service_key, s.signature_tolerance):
        raise HTTPException(401, "Invalid or missing request signature.")
    return body


class Claims(BaseModel):
    tenant_id: str
    user_id: str
    role: str


class Turn(BaseModel):
    role: str = Field(pattern="^(user|assistant)$")
    content: str


class ChatIn(BaseModel):
    agent: str
    provider: str
    model: str
    prompt: str | None = None
    language: str | None = None
    messages: list[Turn]
    tools: list[dict[str, Any]] = []
    tool_base_url: str
    session_token: str
    claims: Claims
    rag: bool = True
    max_steps: int | None = None


class IngestIn(BaseModel):
    claims: Claims
    source_type: str
    source_id: str
    title: str
    text: str
    roles: list[str] = []


class ExtractIn(BaseModel):
    claims: Claims
    provider: str
    model: str
    doc_type: str
    mime: str
    data_base64: str


class SpeakIn(BaseModel):
    text: str
    voice: str = "alloy"


def store(s: Settings) -> KnowledgeStore:
    return KnowledgeStore(s.database_url, s.embedding_dim)


# Overridable in tests.
def model_factory():
    return llm.chat_model


def embed_factory():
    return llm.embedder


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/v1/chat")
async def chat(data: ChatIn, _: bytes = Depends(signed), s: Settings = Depends(get_settings), make_model=Depends(model_factory), make_emb=Depends(embed_factory)):
    if data.agent not in agents.AGENTS:
        raise HTTPException(422, "Unknown agent.")
    if data.provider not in llm.PROVIDERS:
        raise HTTPException(422, "Unknown provider.")
    try:
        model = make_model(data.provider, data.model, s)
        erp = ErpToolClient(data.tool_base_url, data.session_token, s.service_key, s.allowed_erp_hosts, s.tool_timeout)
    except (RuntimeError, ValueError) as e:
        raise HTTPException(422, str(e))
    specs, names = to_function_specs(data.tools)
    knowledge = None
    if data.rag and agents.AGENTS[data.agent].get("rag"):
        emb, ks = make_emb(s), store(s)
        knowledge = lambda q: ks.search(data.claims.tenant_id, data.claims.role, q, emb)   # noqa: E731
        specs.append(g.KNOWLEDGE_TOOL)
    app_graph = g.build_graph(model, specs, erp, names, knowledge, min(data.max_steps or s.max_steps, s.max_steps))
    state = await app_graph.ainvoke({"messages": g.history(agents.system_prompt(data.agent, data.prompt, data.language), [t.model_dump() for t in data.messages]),
                                     "steps": 0, "usage": [], "trace": [], "approvals": []})
    return {"reply": g.final_text(state), "tool_calls": state["trace"], "approvals": state["approvals"], "usage": g.totals(state),
            "provider": data.provider, "model": data.model, "steps": state["steps"]}


@app.post("/v1/ingest")
def ingest(data: IngestIn, _: bytes = Depends(signed), s: Settings = Depends(get_settings), make_emb=Depends(embed_factory)):
    n = store(s).ingest(data.claims.tenant_id, data.source_type, data.source_id, data.title, data.text, data.roles, make_emb(s))
    return {"chunks": n}


@app.post("/v1/forget")
def forget(data: IngestIn, _: bytes = Depends(signed), s: Settings = Depends(get_settings)):
    return {"deleted": store(s).forget(data.claims.tenant_id, data.source_type, data.source_id)}


@app.post("/v1/extract")
async def extract(data: ExtractIn, _: bytes = Depends(signed), s: Settings = Depends(get_settings), make_model=Depends(model_factory)):
    from .extraction import SCHEMAS, extract as run
    if data.doc_type not in SCHEMAS:
        raise HTTPException(422, "Unknown document type.")
    if len(data.data_base64) > 14_000_000:
        raise HTTPException(413, "The document is too large.")
    try:
        base64.b64decode(data.data_base64, validate=True)
        model = make_model(data.provider, data.model, s, 0)
    except (ValueError, RuntimeError) as e:
        raise HTTPException(422, str(e))
    return await run(model, data.doc_type, data.mime, data.data_base64)


@app.post("/v1/voice/speak")
def speak(data: SpeakIn, _: bytes = Depends(signed), s: Settings = Depends(get_settings)):
    from .voice import speak as tts
    return Response(tts(s, data.text, data.voice), media_type="audio/mpeg")
