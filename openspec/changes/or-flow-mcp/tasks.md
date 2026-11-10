# Tasks: or-flow-mcp

- [x] `FlowMcpToolProvider` — runFlow (queues) + flowRunStatus (reads).
- [x] Registered as a built-in MCP provider.
- [x] Tests: app id, tool namespacing, runFlow queues, missing flowId refused,
      status returns / not-found, unknown tool throws.
- [x] Live-verified on 8080: the tools are in the MCP catalogue; runFlow queues
      a run and flowRunStatus reads it back.
- [x] Decision recorded: NO MCP Client step (agent path covers the agentic
      case; a generic HTTP step covers the deterministic case).
- [ ] Flow authoring over MCP (create/update/validate) — follow-up.
