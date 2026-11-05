import asyncio
import json

import httpx
import pytest
import respx

from app.erp_tools import ErpToolClient, host_allowed, safe_name, to_function_specs
from app.security import verify

BASE = "https://demo.quickschool.app/api/v1/ai/tools"


def test_names_specs_and_hosts():
    specs, names = to_function_specs([{"name": "accounting.trial_balance", "description": "TB", "parameters": {"type": "object", "properties": {"from": {"type": "string"}}}}])
    assert specs[0]["function"]["name"] == "accounting_trial_balance" and names == {"accounting_trial_balance": "accounting.trial_balance"}
    assert safe_name("a.b/c d") == "a_b_c_d"
    allowed = [".quickschool.app", "localhost"]
    assert host_allowed(BASE, allowed) and host_allowed("http://localhost:8000/x", allowed)
    assert not host_allowed("https://evil.example.com/x", allowed) and not host_allowed("https://quickschool.app.evil.com/x", allowed)
    with pytest.raises(ValueError):
        ErpToolClient("https://evil.example.com/tools", "t", "k", allowed)


@respx.mock
def test_tool_call_is_signed_and_carries_the_session():
    seen = {}

    def handler(request: httpx.Request):
        seen["ok"] = verify(request.content, dict(request.headers), "svc-key")
        seen["session"] = request.headers["X-AI-Session"]
        seen["args"] = json.loads(request.content)["arguments"]
        return httpx.Response(200, json={"data": {"debit": 100, "credit": 100}})

    respx.post(f"{BASE}/accounting.trial_balance").mock(side_effect=handler)
    respx.post(f"{BASE}/payroll.summary").mock(return_value=httpx.Response(403, json={"message": "Your role cannot do this."}))
    c = ErpToolClient(BASE, "opaque-session", "svc-key", [".quickschool.app"])
    r = asyncio.run(c.call("accounting.trial_balance", {"from": "2026-04-01"}))
    assert r == {"ok": True, "data": {"debit": 100, "credit": 100}}
    assert seen == {"ok": True, "session": "opaque-session", "args": {"from": "2026-04-01"}}
    denied = asyncio.run(c.call("payroll.summary", {}))
    assert denied["ok"] is False and denied["status"] == 403 and "cannot" in denied["error"]
