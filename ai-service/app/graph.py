"""The agent loop as a LangGraph state graph:

    START → agent ──(tool calls?)──▶ tools ──▶ agent … ──▶ END

The agent node asks the model (with the ERP tools bound) for the next step. The tools node runs each requested tool:
ERP tools go back to the ERP over a signed call made as the signed-in user, so the ERP's permission checks and tenant
isolation apply; `search_knowledge` runs here against pgvector, filtered by tenant and role. Proposals for sensitive
actions come back from the ERP as approval requests and are collected for the reply. The loop stops at max_steps.

The node functions are plain async functions so they can be tested without a graph runtime.
"""
from __future__ import annotations

import asyncio
import json
import operator
from typing import Annotated, Any, Callable, TypedDict

from langchain_core.messages import AIMessage, BaseMessage, HumanMessage, SystemMessage, ToolMessage

from .llm import usage_of

KNOWLEDGE_TOOL = {"type": "function", "function": {"name": "search_knowledge", "description": "Search the institution's own documents and policies "
                  "(fee rules, admission criteria, handbooks, circulars). Use for questions about policies or procedures.",
                  "parameters": {"type": "object", "properties": {"query": {"type": "string", "description": "What to look for"}}, "required": ["query"]}}}


class AgentState(TypedDict):
    messages: Annotated[list[BaseMessage], operator.add]
    steps: int
    usage: Annotated[list[dict], operator.add]
    trace: Annotated[list[dict], operator.add]
    approvals: Annotated[list[dict], operator.add]


def history(system: str, turns: list[dict]) -> list[BaseMessage]:
    msgs: list[BaseMessage] = [SystemMessage(system)]
    for t in turns:
        msgs.append(HumanMessage(t["content"]) if t["role"] == "user" else AIMessage(t["content"]))
    return msgs


def make_agent_node(model, specs: list[dict]):
    bound = model.bind_tools(specs) if specs else model

    async def agent(state: AgentState) -> dict:
        reply = await bound.ainvoke(state["messages"])
        return {"messages": [reply], "steps": state["steps"] + 1, "usage": [usage_of(reply)]}

    return agent


def make_tools_node(erp, names: dict[str, str], knowledge: Callable[[str], list[dict]] | None):
    async def tools(state: AgentState) -> dict:
        last = state["messages"][-1]
        out, trace, approvals = [], [], []
        for call in getattr(last, "tool_calls", []) or []:
            fn, args = call["name"], call.get("args") or {}
            if fn == "search_knowledge" and knowledge:
                hits = await asyncio.to_thread(knowledge, str(args.get("query", "")))
                result: dict[str, Any] = {"ok": True, "results": [{"title": h["title"], "text": h["content"], "score": round(h["score"], 3)} for h in hits]}
            elif fn in names:
                result = await erp.call(names[fn], args)
                if result.get("approval_request"):
                    approvals.append(result["approval_request"])
            else:
                result = {"ok": False, "error": f"Unknown tool {fn}."}
            out.append(ToolMessage(json.dumps(result, ensure_ascii=False, default=str)[:12000], tool_call_id=call["id"], name=fn))
            trace.append({"tool": names.get(fn, fn), "args": args, "ok": bool(result.get("ok"))})
        return {"messages": out, "trace": trace, "approvals": approvals}

    return tools


def should_continue(max_steps: int):
    def route(state: AgentState) -> str:
        last = state["messages"][-1]
        return "tools" if getattr(last, "tool_calls", None) and state["steps"] < max_steps else "end"

    return route


def build_graph(model, specs: list[dict], erp, names: dict[str, str], knowledge, max_steps: int):
    from langgraph.graph import END, START, StateGraph

    g = StateGraph(AgentState)
    g.add_node("agent", make_agent_node(model, specs))
    g.add_node("tools", make_tools_node(erp, names, knowledge))
    g.add_edge(START, "agent")
    g.add_conditional_edges("agent", should_continue(max_steps), {"tools": "tools", "end": END})
    g.add_edge("tools", "agent")
    return g.compile()


def final_text(state: AgentState) -> str:
    for m in reversed(state["messages"]):
        if isinstance(m, AIMessage) and not m.tool_calls:
            c = m.content
            return c if isinstance(c, str) else "".join(p.get("text", "") for p in c if isinstance(p, dict))
    return "I could not finish this within the allowed number of steps. Please narrow the question."


def totals(state: AgentState) -> dict[str, int]:
    return {"input_tokens": sum(u["input_tokens"] for u in state["usage"]), "output_tokens": sum(u["output_tokens"] for u in state["usage"])}
