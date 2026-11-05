"""Request signing between the ERP and this service.

Every call carries X-QSA-Timestamp and X-QSA-Signature = hex HMAC-SHA256(key, "<timestamp>.<raw body>").
Requests older than the tolerance are refused, so a captured request cannot be replayed later.
"""
from __future__ import annotations

import hashlib
import hmac
import time


def sign(body: bytes, key: str, timestamp: int | None = None) -> dict[str, str]:
    ts = str(int(timestamp if timestamp is not None else time.time()))
    mac = hmac.new(key.encode(), ts.encode() + b"." + body, hashlib.sha256).hexdigest()
    return {"X-QSA-Timestamp": ts, "X-QSA-Signature": mac}


def verify(body: bytes, headers: dict[str, str], key: str, tolerance: int = 300, now: float | None = None) -> bool:
    if not key:
        return False   # a service with no key configured accepts nothing
    h = {k.lower(): v for k, v in headers.items()}
    ts, sig = h.get("x-qsa-timestamp", ""), h.get("x-qsa-signature", "")
    if not ts.isdigit() or not sig:
        return False
    if abs((now if now is not None else time.time()) - int(ts)) > tolerance:
        return False
    expected = hmac.new(key.encode(), ts.encode() + b"." + body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, sig)
