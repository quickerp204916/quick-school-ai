import json

import pytest
from fastapi.testclient import TestClient

from app.extraction import SCHEMAS, messages, review
from app.main import app
from app.security import sign

client = TestClient(app)


def test_review_flags_missing_fields_and_bad_totals():
    r = review("vendor_invoice", {"vendor_name": "Bright Print", "invoice_number": None, "subtotal": 1000, "cgst": 90, "sgst": 90, "total": 1300, "junk": 1})
    assert r["missing"] == ["invoice_number"] and "does not match" in r["warnings"][0] and "junk" not in r["fields"]
    m = review("marksheet", {"student_name": "A", "subjects": [{"subject": "Maths", "marks": 105, "max_marks": 100}]})
    assert "more than the maximum" in m["warnings"][0]


def test_document_messages_use_image_or_file_blocks():
    assert messages("marksheet", "image/png", "aGk=")[1].content[1]["type"] == "image"
    assert messages("vendor_invoice", "application/pdf", "aGk=")[1].content[1]["type"] == "file"
    with pytest.raises(ValueError):
        messages("passport", "image/png", "aGk=")
    assert set(SCHEMAS) == {"marksheet", "transfer_certificate", "birth_certificate", "vendor_invoice"}


def test_health_and_signature_required():
    assert client.get("/health").json() == {"status": "ok"}
    body = json.dumps({"agent": "school"}).encode()
    assert client.post("/v1/chat", content=body, headers={"Content-Type": "application/json"}).status_code == 401
    bad = sign(body, "wrong-key")
    assert client.post("/v1/chat", content=body, headers={"Content-Type": "application/json", **bad}).status_code == 401


def test_signed_requests_are_validated():
    payload = {"agent": "nope", "provider": "openai", "model": "gpt-4o-mini", "messages": [{"role": "user", "content": "hi"}], "tool_base_url": "https://demo.quickschool.app/api/v1/ai/tools",
               "session_token": "s", "claims": {"tenant_id": "t", "user_id": "u", "role": "owner"}}
    body = json.dumps(payload).encode()
    r = client.post("/v1/chat", content=body, headers={"Content-Type": "application/json", **sign(body, "test-service-key")})
    assert r.status_code == 422 and r.json()["detail"] == "Unknown agent."
    payload |= {"agent": "school", "tool_base_url": "https://evil.example.com/x"}
    body = json.dumps(payload).encode()
    r = client.post("/v1/chat", content=body, headers={"Content-Type": "application/json", **sign(body, "test-service-key")})
    assert r.status_code == 422 and ("allowed host" in r.json()["detail"] or "OPENAI_API_KEY" in r.json()["detail"])
