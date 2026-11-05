"""Structured extraction from uploaded documents (images or PDFs) with a vision model. The output is a proposal
for a person to review in the ERP; nothing is saved or posted from here."""
from __future__ import annotations

from langchain_core.messages import HumanMessage, SystemMessage

S = {"type": "string"}
N = {"type": "number"}

SCHEMAS: dict[str, dict] = {
    "marksheet": {"title": "Marksheet", "type": "object", "properties": {
        "student_name": S, "father_name": S, "mother_name": S, "date_of_birth": {"type": "string", "description": "YYYY-MM-DD"}, "board": S, "school": S,
        "class": S, "year": S, "roll_number": S, "result": S, "percentage": N,
        "subjects": {"type": "array", "items": {"type": "object", "properties": {"subject": S, "marks": N, "max_marks": N, "grade": S}}}},
        "required": ["student_name"]},
    "transfer_certificate": {"title": "TransferCertificate", "type": "object", "properties": {
        "student_name": S, "father_name": S, "date_of_birth": S, "school": S, "class_last_studied": S, "date_of_leaving": S, "tc_number": S, "conduct": S, "reason": S},
        "required": ["student_name"]},
    "birth_certificate": {"title": "BirthCertificate", "type": "object", "properties": {
        "name": S, "date_of_birth": S, "place_of_birth": S, "father_name": S, "mother_name": S, "registration_number": S}, "required": ["name", "date_of_birth"]},
    "vendor_invoice": {"title": "VendorInvoice", "type": "object", "properties": {
        "vendor_name": S, "vendor_gstin": S, "invoice_number": S, "invoice_date": S, "place_of_supply": S, "subtotal": N, "cgst": N, "sgst": N, "igst": N, "total": N,
        "lines": {"type": "array", "items": {"type": "object", "properties": {"description": S, "quantity": N, "rate": N, "amount": N, "gst_rate": N}}}},
        "required": ["vendor_name", "invoice_number", "total"]},
}

PROMPT = ("Extract the fields of this {kind} exactly as printed. Use null for anything you cannot read; never guess. Dates as YYYY-MM-DD. "
          "Amounts in rupees as numbers. Do not include Aadhaar or bank account numbers even if they appear.")


def messages(doc_type: str, mime: str, data_b64: str):
    if doc_type not in SCHEMAS:
        raise ValueError(f"Unknown document type {doc_type!r}.")
    block = ({"type": "image", "source_type": "base64", "mime_type": mime, "data": data_b64} if mime.startswith("image/")
             else {"type": "file", "source_type": "base64", "mime_type": mime, "data": data_b64})
    return [SystemMessage("You read Indian school and accounting documents carefully."),
            HumanMessage(content=[{"type": "text", "text": PROMPT.format(kind=doc_type.replace("_", " "))}, block])]


def review(doc_type: str, data: dict) -> dict:
    """Flags what a person must check: missing required fields and totals that do not add up."""
    schema = SCHEMAS[doc_type]
    missing = [f for f in schema.get("required", []) if data.get(f) in (None, "", [])]
    warnings = []
    if doc_type == "vendor_invoice" and isinstance(data.get("total"), (int, float)):
        parts = sum(float(data.get(k) or 0) for k in ("subtotal", "cgst", "sgst", "igst"))
        if parts and abs(parts - float(data["total"])) > 1:
            warnings.append(f"Subtotal plus tax ({parts:.2f}) does not match the total ({float(data['total']):.2f}).")
    if doc_type == "marksheet":
        for s in data.get("subjects") or []:
            if isinstance(s.get("marks"), (int, float)) and isinstance(s.get("max_marks"), (int, float)) and s["marks"] > s["max_marks"]:
                warnings.append(f"{s.get('subject')}: marks are more than the maximum.")
    return {"fields": {k: v for k, v in data.items() if k in schema["properties"]}, "missing": missing, "warnings": warnings}


async def extract(model, doc_type: str, mime: str, data_b64: str) -> dict:
    structured = model.with_structured_output(SCHEMAS[doc_type], include_raw=True)
    out = await structured.ainvoke(messages(doc_type, mime, data_b64))
    from .llm import usage_of
    return review(doc_type, out.get("parsed") or {}) | {"usage": usage_of(out.get("raw"))}
