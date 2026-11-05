"""The agent loop's nodes with a scripted model (test double only; production uses ChatOpenAI or ChatGoogleGenerativeAI)."""
import asyncio

from langchain_core.messages import AIMessage, ToolMessage

from app import graph as g
from app.agents import system_prompt


class ScriptedModel:
    def __init__(self, replies):
        self.replies, self.seen, self.tools = list(replies), [], None

    def bind_tools(self, specs):
        self.tools = specs
        return self

    async def ainvoke(self, messages):
        self.seen.append(list(messages))
        return self.replies.pop(0)


class FakeErp:
    def __init__(self, results):
        self.results, self.calls = results, []

    async def call(self, name, args):
        self.calls.append((name, args))
        return self.results[name]


def run(model, erp, names, knowledge=None, max_steps=4):
    agent, tools, route = g.make_agent_node(model, [{"type": "function", "function": {"name": n}} for n in names]), g.make_tools_node(erp, names, knowledge), g.should_continue(max_steps)
    state = {"messages": g.history(system_prompt("accounting", None, "hi"), [{"role": "user", "content": "Trial balance?"}]), "steps": 0, "usage": [], "trace": [], "approvals": []}

    def merge(update):
        for k, v in update.items():
            state[k] = state[k] + v if isinstance(v, list) else v

    async def loop():
        while True:
            merge(await agent(state))
            if route(state) == "end":
                return
            merge(await tools(state))

    asyncio.run(loop())
    return state


def test_agent_calls_erp_tools_and_answers_from_their_results():
    model = ScriptedModel([
        AIMessage("", tool_calls=[{"name": "accounting_trial_balance", "args": {"from": "2026-04-01"}, "id": "c1"}], usage_metadata={"input_tokens": 50, "output_tokens": 10, "total_tokens": 60}),
        AIMessage("Debits and credits are both ₹1,000.00.", usage_metadata={"input_tokens": 80, "output_tokens": 20, "total_tokens": 100}),
    ])
    erp = FakeErp({"accounting.trial_balance": {"ok": True, "debit": 100000, "credit": 100000}})
    state = run(model, erp, {"accounting_trial_balance": "accounting.trial_balance"})
    assert erp.calls == [("accounting.trial_balance", {"from": "2026-04-01"})]
    assert g.final_text(state) == "Debits and credits are both ₹1,000.00."
    assert g.totals(state) == {"input_tokens": 130, "output_tokens": 30}
    assert state["trace"] == [{"tool": "accounting.trial_balance", "args": {"from": "2026-04-01"}, "ok": True}]
    tool_msg = model.seen[1][-1]
    assert isinstance(tool_msg, ToolMessage) and '"debit": 100000' in tool_msg.content
    assert "Reply in Hindi." in model.seen[0][0].content and "propose_" in model.seen[0][0].content


def test_sensitive_actions_come_back_as_approval_requests():
    model = ScriptedModel([
        AIMessage("", tool_calls=[{"name": "accounting_propose_voucher", "args": {"type": "payment"}, "id": "c1"}]),
        AIMessage("I have sent the voucher for approval."),
    ])
    erp = FakeErp({"accounting.propose_voucher": {"ok": True, "approval_request": {"id": "AR1", "status": "pending", "summary": "Payment ₹5,000"}}})
    state = run(model, erp, {"accounting_propose_voucher": "accounting.propose_voucher"})
    assert state["approvals"] == [{"id": "AR1", "status": "pending", "summary": "Payment ₹5,000"}]


def test_knowledge_search_runs_locally_and_unknown_tools_are_refused():
    model = ScriptedModel([
        AIMessage("", tool_calls=[{"name": "search_knowledge", "args": {"query": "late fee"}, "id": "k"}, {"name": "drop_tables", "args": {}, "id": "x"}]),
        AIMessage("Late fee is ₹200 after five days."),
    ])
    state = run(model, FakeErp({}), {}, knowledge=lambda q: [{"title": "Fee policy", "content": f"About {q}: ₹200 after 5 days", "score": 0.91}])
    contents = [m.content for m in state["messages"] if isinstance(m, ToolMessage)]
    assert "Fee policy" in contents[0] and "Unknown tool drop_tables" in contents[1]


def test_the_loop_stops_at_max_steps():
    looping = [AIMessage("", tool_calls=[{"name": "t", "args": {}, "id": str(i)}]) for i in range(10)]
    state = run(ScriptedModel(looping), FakeErp({"t": {"ok": True}}), {"t": "t"}, max_steps=3)
    assert state["steps"] == 3
    assert "allowed number of steps" in g.final_text(state)
