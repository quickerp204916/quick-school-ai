"""End to end through the compiled LangGraph graph. Needs the langgraph package (installed from requirements.txt in CI)."""
import asyncio

import pytest

pytest.importorskip("langgraph.graph")

from langchain_core.messages import AIMessage  # noqa: E402

from app import graph as g  # noqa: E402
from tests.test_graph_nodes import FakeErp, ScriptedModel  # noqa: E402


def test_compiled_graph_runs_tools_then_answers():
    model = ScriptedModel([AIMessage("", tool_calls=[{"name": "fees_dues", "args": {}, "id": "1"}]), AIMessage("₹4,000 is due.")])
    erp = FakeErp({"fees.dues": {"ok": True, "total": 400000}})
    app_graph = g.build_graph(model, [], erp, {"fees_dues": "fees.dues"}, None, 5)
    state = asyncio.run(app_graph.ainvoke({"messages": g.history("sys", [{"role": "user", "content": "Dues?"}]), "steps": 0, "usage": [], "trace": [], "approvals": []}))
    assert g.final_text(state) == "₹4,000 is due." and erp.calls == [("fees.dues", {})] and state["steps"] == 2
