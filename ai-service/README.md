# Quick School AI intelligence service

FastAPI + LangGraph service that runs the seven AI agents for the Laravel ERP. The ERP owns users, permissions, prompts,
conversations, approvals and credits; this service owns the model calls and the vector store.

```
ERP (Laravel)  ── signed POST /v1/chat ──▶  AI service ──▶ OpenAI or Gemini
     ▲                                          │
     └── signed POST /api/v1/ai/tools/{tool} ◀──┘   (as the signed-in user, inside their institution)
```

- **Agents:** school, accounting, payroll, admission, academic, document and voice. Their default prompts and the fixed guardrails are in `app/agents.py`. The ERP sends each agent's active prompt version.
- **Orchestration:** a LangGraph state graph (`app/graph.py`) alternates between the model and the tools, up to `QSA_AI_MAX_STEPS` steps.
- **Tools:** ERP tools are called back over HTTP with a short-lived session token bound to one institution, one user and the tools that user may use. The ERP checks permissions again on every call. The service never sees data the user couldn't see in the app.
- **Sensitive actions:** posting vouchers, refunds, waivers and payroll approval exist only as `propose_*` tools. They create a request that a different, authorised person approves in the ERP.
- **RAG:** `search_knowledge` searches the institution's documents in PostgreSQL + pgvector (`app/rag.py`). Results are filtered by tenant and by the roles allowed to see each document.
- **Document extraction:** `/v1/extract` reads marksheets, certificates and vendor bills with a vision model into a fixed schema. The ERP shows the result to a person to review.
- **Voice:** `app/voice.py` does speech-to-text and text-to-speech for the voice assistant. A telephony provider (for example Exotel or Twilio) streams the call audio.
- **Security:** every request in both directions carries `X-QSA-Timestamp` and `X-QSA-Signature` = HMAC-SHA256(key, "timestamp.body"), and requests older than 5 minutes are refused. Tool calls may only go to hosts in `QSA_ERP_ALLOWED_HOSTS`.

## Run

```bash
cp .env.example .env            # set the shared key, model keys and the database URL
psql -c 'CREATE EXTENSION IF NOT EXISTS vector;' qsa_ai   # once, as a superuser
pip install -r requirements.txt
uvicorn app.main:app --port 8090
# or: docker build -t qsa-ai . && docker run --env-file .env -p 8090:8090 qsa-ai
```

## Tests

```bash
pip install -r requirements-dev.txt
QSA_AI_TEST_DATABASE_URL=postgresql://qsa_ai:qsa_ai@127.0.0.1:5432/qsa_ai_test pytest
```

The tests use a scripted model in place of OpenAI or Gemini, and an ERP stand-in for tool calls. The RAG tests need PostgreSQL with pgvector and are skipped without it. Production code paths never use canned responses.
