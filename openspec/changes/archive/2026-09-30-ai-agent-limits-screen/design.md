# Design: ai-agent-limits-screen

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| Tool grant resolver (no caller) | `lib/Service/Capability/ToolGrantResolver.php` |
| Chat tool enforcement | `lib/Service/Chat/ToolManagementHandler.php` |
| MCP dispatch | `lib/Service/Mcp/` |

## Approach

1. Call ToolGrantResolver from the MCP tool dispatch, red test first through the real dispatcher; add a manifest page for agents.

## Declarative or imperative

The agent entity carries the grant; no new declaration.

## Tests

- PHPUnit: an MCP call to a tool outside the agent grant is refused; one inside it runs.
- vitest: the agents screen saves tools and views.

## Built (30 Sep 2026, at development 574a0f35f4)

- The MCP path had no agent at all: a session was a user. A client now names the agent in `initialize` (`agent`, or the `X-OpenRegister-Agent` header). `McpAgentScope` checks the agent is known, active and available to the user before a session exists, binds it to the session, filters `tools/list` and refuses a `tools/call` outside the grant or its argument constraints before the tool runs. The grant is re-read on every call.
- Binding an agent automatically from its `user` field (a service account) was NOT done: an agent with no configured tools resolves to no tools, so every existing service-account user would lose its MCP tools. That is a question for Ruben, recorded in the lane state.
- Views are not on the screen. `ContextRetrievalHandler` computes an agent's view filters and then leaves them as a TODO (it never applies them), so the matrix evidence "views enforced in chat" was wrong. Showing a limit nothing applies would lie; change `ai-agent-view-limits` builds that half.
- Only the agent's owner may change it (`AgentMapper::canUserModifyAgent`), so the screen offers editing to the owner. A structured (per-app) grant is shown read-only: saving a list over it would drop its structure.
