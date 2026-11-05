"""Retrieval over the institution's own documents with PostgreSQL + pgvector.

Every row carries tenant_id and the roles allowed to see it. Each query runs in a transaction that sets
app.tenant_id, and the table has row-level security on that setting, as well as an explicit tenant filter,
so one institution can never retrieve another's text even if a query were written wrongly.
"""
from __future__ import annotations

import re
from typing import Protocol

import psycopg
from pgvector.psycopg import register_vector


class Embedder(Protocol):
    def embed_documents(self, texts: list[str]) -> list[list[float]]: ...
    def embed_query(self, text: str) -> list[float]: ...


def chunk(text: str, size: int = 900, overlap: int = 150) -> list[str]:
    """Splits on paragraphs, then sentences, into chunks of about `size` characters with some overlap."""
    text = re.sub(r"[ \t]+", " ", text).strip()
    paras = [p.strip() for p in re.split(r"\n\s*\n", text) if p.strip()]
    out, cur = [], ""
    for p in paras:
        pieces = [p] if len(p) <= size else re.split(r"(?<=[.!?।])\s+", p)
        for piece in pieces:
            if len(cur) + len(piece) + 1 > size and cur:
                out.append(cur)
                cur = cur[-overlap:] + " " + piece if overlap else piece
            else:
                cur = f"{cur}\n{piece}" if cur else piece
    if cur:
        out.append(cur)
    return [c[: size * 2] for c in out]


class KnowledgeStore:
    def __init__(self, dsn: str, dim: int):
        self.dsn, self.dim = dsn, dim

    def _conn(self, tenant_id: str | None = None):
        conn = psycopg.connect(self.dsn, autocommit=False)
        register_vector(conn)
        if tenant_id:
            conn.execute("SELECT set_config('app.tenant_id', %s, true)", (tenant_id,))
        return conn

    def migrate(self) -> None:
        """Creates the table, indexes and the row-level security policy. The vector extension must exist (created by a superuser)."""
        with psycopg.connect(self.dsn, autocommit=True) as conn:
            if not conn.execute("SELECT 1 FROM pg_extension WHERE extname = 'vector'").fetchone():
                raise RuntimeError("The pgvector extension is not installed in this database. A superuser must run: CREATE EXTENSION vector;")
            register_vector(conn)
            conn.execute(f"""
                CREATE TABLE IF NOT EXISTS ai_chunks (
                    id bigserial PRIMARY KEY,
                    tenant_id uuid NOT NULL,
                    source_type text NOT NULL,
                    source_id text NOT NULL,
                    title text NOT NULL,
                    content text NOT NULL,
                    roles text[] NOT NULL DEFAULT '{{}}',
                    embedding vector({self.dim}) NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT now())""")
            conn.execute("CREATE INDEX IF NOT EXISTS ai_chunks_tenant_source ON ai_chunks (tenant_id, source_type, source_id)")
            conn.execute("CREATE INDEX IF NOT EXISTS ai_chunks_embedding ON ai_chunks USING hnsw (embedding vector_cosine_ops)")
            conn.execute("ALTER TABLE ai_chunks ENABLE ROW LEVEL SECURITY")
            conn.execute("ALTER TABLE ai_chunks FORCE ROW LEVEL SECURITY")
            if not conn.execute("SELECT 1 FROM pg_policies WHERE tablename = 'ai_chunks' AND policyname = 'tenant_isolation'").fetchone():
                conn.execute("CREATE POLICY tenant_isolation ON ai_chunks USING (tenant_id::text = current_setting('app.tenant_id', true)) "
                             "WITH CHECK (tenant_id::text = current_setting('app.tenant_id', true))")

    def ingest(self, tenant_id: str, source_type: str, source_id: str, title: str, text: str, roles: list[str], emb: Embedder) -> int:
        parts = chunk(text)
        if not parts:
            return 0
        vectors = emb.embed_documents(parts)
        with self._conn(tenant_id) as conn:
            conn.execute("DELETE FROM ai_chunks WHERE tenant_id = %s AND source_type = %s AND source_id = %s", (tenant_id, source_type, source_id))
            with conn.cursor() as cur:
                cur.executemany("INSERT INTO ai_chunks (tenant_id, source_type, source_id, title, content, roles, embedding) VALUES (%s, %s, %s, %s, %s, %s, %s)",
                                [(tenant_id, source_type, source_id, title, p, roles, v) for p, v in zip(parts, vectors)])
            conn.commit()
        return len(parts)

    def forget(self, tenant_id: str, source_type: str, source_id: str) -> int:
        with self._conn(tenant_id) as conn:
            n = conn.execute("DELETE FROM ai_chunks WHERE tenant_id = %s AND source_type = %s AND source_id = %s", (tenant_id, source_type, source_id)).rowcount
            conn.commit()
        return n

    def search(self, tenant_id: str, role: str, query: str, emb: Embedder, k: int = 5, min_score: float = 0.2) -> list[dict]:
        vec = emb.embed_query(query)
        with self._conn(tenant_id) as conn:
            rows = conn.execute(
                "SELECT title, content, source_type, source_id, 1 - (embedding <=> %s::vector) AS score FROM ai_chunks "
                "WHERE tenant_id = %s AND (cardinality(roles) = 0 OR %s = ANY(roles)) ORDER BY embedding <=> %s::vector LIMIT %s",
                (vec, tenant_id, role, vec, k)).fetchall()
            conn.commit()
        return [{"title": r[0], "content": r[1], "source_type": r[2], "source_id": r[3], "score": float(r[4])} for r in rows if float(r[4]) >= min_score]
