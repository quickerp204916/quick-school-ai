"""Speech for the voice calling assistant: speech-to-text and text-to-speech through OpenAI's audio API.
The telephony provider (for example Exotel or Twilio) streams the caller's audio in and plays the reply."""
from __future__ import annotations

from .config import Settings


def _client(s: Settings):
    if not s.openai_api_key:
        raise RuntimeError("OPENAI_API_KEY is not set on the AI service.")
    from openai import OpenAI
    return OpenAI(api_key=s.openai_api_key, timeout=60)


def transcribe(s: Settings, audio: bytes, filename: str, language: str | None = None, model: str = "gpt-4o-mini-transcribe") -> str:
    r = _client(s).audio.transcriptions.create(model=model, file=(filename, audio), language=language or None)
    return r.text


def speak(s: Settings, text: str, voice: str = "alloy", model: str = "gpt-4o-mini-tts") -> bytes:
    r = _client(s).audio.speech.create(model=model, voice=voice, input=text[:4000], response_format="mp3")
    return r.read()
