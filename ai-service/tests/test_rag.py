"""pgvector retrieval against a real PostgreSQL. Embeddings here are a deterministic bag-of-words test double."""
import hashlib
import math
import os

import psycopg
import pytest

from app.rag import KnowledgeStore, chunk

DSN = os.environ.get("QSA_AI_TEST_DATABASE_URL", "postgresql://qsa_ai:qsa_ai@127.0.0.1:5432/qsa_ai_test")
A, B = "11111111-1111-1111-1111-111111111111", "22222222-2222-2222-2222-222222222222"


class BagOfWords:
    dim = 64

    def _vec(self, text):
        v = [0.0] * self.dim
        for w in text.lower().split():
            v[int(hashlib.md5(w.strip(".,").encode()).hexdigest(), 16) % self.dim] += 1
        n = math.sqrt(sum(x * x for x in v)) or 1
        return [x / n for x in v]

    def embed_documents(self, texts):
        return [self._vec(t) for t in texts]

    def embed_query(self, text):
        return self._vec(text)


@pytest.fixture()
def ks():
    try:
        psycopg.connect(DSN).close()
    except psycopg.OperationalError:
        pytest.skip("No PostgreSQL with pgvector for the AI tests")
    with psycopg.connect(DSN, autocommit=True) as c:
        c.execute("DROP TABLE IF EXISTS ai_chunks")
    s = KnowledgeStore(DSN, BagOfWords.dim)
    s.migrate()
    return s


def test_chunking_keeps_paragraphs_and_overlaps():
    text = "\n\n".join(f"Paragraph {i}. " + "word " * 120 for i in range(5))
    parts = chunk(text, size=300, overlap=50)
    assert len(parts) > 5 and all(len(p) <= 600 for p in parts)


def test_search_is_isolated_by_tenant_and_role(ks):
    emb = BagOfWords()
    ks.ingest(A, "policy", "fees", "Fee policy", "A late fee of 200 rupees applies five days after the due date.", [], emb)
    ks.ingest(A, "policy", "salary", "Salary policy", "Salaries are paid on the last working day of the month.", ["owner", "admin", "hr"], emb)
    ks.ingest(B, "policy", "fees", "Other school fee policy", "A late fee of 500 rupees applies at once.", [], emb)

    hits = ks.search(A, "teacher", "late fee after the due date", emb)
    assert [h["title"] for h in hits][:1] == ["Fee policy"]
    assert all("500" not in h["content"] for h in hits), "never another institution's text"
    assert all(h["title"] != "Salary policy" for h in ks.search(A, "teacher", "when are salaries paid", emb)), "role-restricted"
    assert ks.search(A, "hr", "when are salaries paid", emb)[0]["title"] == "Salary policy"


def test_row_level_security_blocks_queries_without_the_tenant(ks):
    emb = BagOfWords()
    ks.ingest(A, "policy", "p", "P", "Some text about uniforms.", [], emb)
    with psycopg.connect(DSN) as c:
        assert c.execute("SELECT count(*) FROM ai_chunks").fetchone()[0] == 0, "RLS hides rows when app.tenant_id is not set"
        c.execute("SELECT set_config('app.tenant_id', %s, true)", (B,))
        assert c.execute("SELECT count(*) FROM ai_chunks").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        with psycopg.connect(DSN) as c:
            c.execute("SELECT set_config('app.tenant_id', %s, true)", (B,))
            c.execute("INSERT INTO ai_chunks (tenant_id, source_type, source_id, title, content, embedding) VALUES (%s, 'x', 'x', 'x', 'x', %s::vector)", (A, str([0.0] * 63 + [1.0])))


def test_reingest_replaces_and_forget_deletes(ks):
    emb = BagOfWords()
    assert ks.ingest(A, "circular", "c1", "Circular", "Holiday on Friday.", [], emb) == 1
    ks.ingest(A, "circular", "c1", "Circular", "Holiday moved to Monday.", [], emb)
    hits = ks.search(A, "owner", "holiday", emb, min_score=0)
    assert len(hits) == 1 and "Monday" in hits[0]["content"]
    assert ks.forget(A, "circular", "c1") == 1
