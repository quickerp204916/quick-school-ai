from app.security import sign, verify

KEY = "k3y"


def test_signed_body_verifies():
    body = b'{"a":1}'
    assert verify(body, sign(body, KEY), KEY)


def test_tampered_wrong_key_stale_and_unconfigured_are_refused():
    body = b'{"a":1}'
    h = sign(body, KEY, timestamp=1_000_000)
    assert not verify(b'{"a":2}', h, KEY, now=1_000_000)
    assert not verify(body, h, "other", now=1_000_000)
    assert verify(body, h, KEY, now=1_000_100)
    assert not verify(body, h, KEY, now=1_000_000 + 301), "older than five minutes"
    assert not verify(body, h, "", now=1_000_000), "a service without a key accepts nothing"
    assert not verify(body, {}, KEY)
