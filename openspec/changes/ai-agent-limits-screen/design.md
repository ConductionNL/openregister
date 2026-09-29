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
