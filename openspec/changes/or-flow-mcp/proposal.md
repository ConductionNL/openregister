# Proposal: or-flow-mcp

## Summary

Expose flows to AI agents as MCP tools, so an agent can find and run a flow.

## Why, and the piece deliberately NOT built

The original #2071 listed three MCP pieces. Only one of them is genuinely
non-redundant, and this change builds that one.

- **An MCP Client step** — a flow node that calls an external MCP server's tool
  — is NOT built. In this fleet a flow reaches external tools by calling an
  *agent* node, and the agent reaches MCP servers agentically (the model
  decides which tools to use). A deterministic MCP-call step is a real but
  secondary capability, and a full MCP client (transport handshake, session
  management) is disproportionate effort for it. For the rare "deterministic
  external call" case a generic HTTP step would be more broadly useful, and is
  a separate change.

- **The MCP server side — flows as MCP tools — IS built here.** It goes the
  other way from the agent path: it makes a flow a thing an agent can discover
  and start. That is not redundant with an agent reaching MCP, and it reuses
  OpenRegister's existing `IMcpToolProvider` infrastructure, so it is small.

## What Changes

- **`FlowMcpToolProvider`**, a built-in MCP tool provider offering:
  - `openregister.runFlow` — queue a flow against an (optional) object, return
    the run uuid.
  - `openregister.flowRunStatus` — read a run's status, log and items by uuid.

Running a flow QUEUES it, the same as any trigger — the MCP call returns as soon
as the run is recorded, and the worker does the work off-request. The agent
polls status rather than blocking on an arbitrary graph.

## Out of scope (this change)

- **Flow authoring over MCP** — create / update / validate a flow from an agent.
  Useful, and a natural extension of this provider, but its own change.
- **An MCP Client step / generic HTTP step** — see above; the agent path covers
  the agentic case, and a deterministic HTTP step is separate.
