# Proposal: mcp-discovery-endpoint

## Summary
Add a tiered MCP (Model Context Protocol) discovery endpoint to OpenRegister that lets AI agents efficiently discover available capabilities and learn how to use the existing REST API — without consuming excessive tokens on a single massive response.

## Motivation
AI agents (like Claude, GPT, etc.) need to understand what an API can do before they can use it. Currently, the only option is to feed the entire routes file or OAS spec into the agent's context, which wastes thousands of tokens on endpoints the agent may never use. A tiered discovery approach lets the agent first see a compact catalog (~200 tokens), then drill into only the capability areas it needs (~500 tokens each), keeping total context lean.

This also positions OpenRegister as an AI-native data platform — agents can self-serve without human-written integration guides.

## Affected Projects
- [x] Project: `openregister` — New controller, routes, and service for the discovery endpoint

## Scope
### In Scope
- **Tier 1**: `GET /api/mcp/v1/discover` — Public, compact catalog listing all capability areas with one-line descriptions and drill-down URLs
- **Tier 2**: `GET /api/mcp/v1/discover/{capability}` — Authenticated, detailed API documentation with live data for a specific capability area (endpoints, parameters, request/response examples, actual register/schema names and IDs)
- Dynamic discovery: the endpoint reflects actual registered schemas, registers, and configurations — not just hardcoded route lists
- Authentication-aware: only show capabilities the current user/agent has access to
- Testing: use Claude Code itself as the test agent — call the endpoint and attempt real API operations

### Out of Scope
- Full MCP JSON-RPC server (stdio/SSE transport) — future enhancement
- Tool execution proxy (agents call the existing REST endpoints directly)
- UI changes
- Changes to existing API endpoints

## Approach
1. Create a `McpController` with two actions: `discover()` and `discoverCapability($capability)`
2. Create a `McpDiscoveryService` that builds capability descriptions by inspecting registered routes, schemas, and registers
3. Group the ~50+ API areas into ~10 logical capability categories (e.g., "objects", "schemas", "registers", "search", "files", "audit", "settings", "bulk", "webhooks", "chat")
4. Tier 1 response: JSON array of `{ id, name, description, href }` — one entry per capability
5. Tier 2 response: JSON with `{ endpoints: [{ method, url, description, parameters, example }] }` for a specific capability
6. Register routes: `/api/mcp/v1/discover` (public, no auth) and `/api/mcp/v1/discover/{capability}` (authenticated)

## Cross-Project Dependencies
None — this is purely additive to OpenRegister and only reads existing route/schema metadata.

## Rollback Strategy
Remove the `McpController`, `McpDiscoveryService`, and the two route entries. No database changes, no migrations, no side effects.

## Decisions
1. **Tier 2 includes live data** — e.g., "you have 3 registers: X, Y, Z" with IDs, so agents can immediately act on real data without extra lookup calls.
2. **Tier 1 is public** (no auth required) — agents can discover capabilities before authenticating. Tier 2 requires auth since it exposes live data.
3. **Versioned URL** — `/api/mcp/v1/discover` and `/api/mcp/v1/discover/{capability}` to allow future MCP protocol evolution.
