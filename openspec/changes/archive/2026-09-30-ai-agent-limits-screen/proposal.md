---
kind: code
depends_on: []
---

# Proposal: ai-agent-limits-screen

## Summary

An administrator sets, on an agent screen, which tools an AI agent may call and which registers and views it may read, and the same limits apply when the agent is reached over MCP. Today the limits exist in the agents API and are enforced in chat only; an MCP caller gets the user full rights.

## The rows this closes

Source matrix: openregister `openspec/parity/capabilities.json` (comparedOn 2026-09-25). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### ai-agent-limits, limit which tools and which data an AI agent may use

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `ai`, source `own-code-derived`.

Matrix evidence, verbatim:

> Agent tools and views enforced in chat: lib/Service/Chat/ToolManagementHandler.php:119, lib/Service/Chat/ContextRetrievalHandler.php:141; agents API appinfo/routes.php:10. No agent screen in src; MCP callers get the user's full rights (lib/Service/Capability/ToolGrantResolver.php only referenced by its own siblings)

Competitor cells rated `yes`, verbatim:

- directus: source read at v12.4.1, not driven: directus:app/src/ai/stores/use-ai-tools.ts:35 per-tool approval mode (disabled, ask, always) for the studio assistant; MCP: mcp_allow_deletes (packages/system-data/src/fields/settings.yaml:1180), OAuth scopes (api/src/ai/mcp/server.ts:94) and the calling user's permissions (server.ts:133) limit data
- strapi: driven at v5.55.1 on 2026-09-26: an admin token created with only content-manager read on melding (fields [title]) got tools/list = log, list_melding, get_melding; no create, update, delete or other types. source read at v5.55.1: strapi:packages/core/admin/server/src/services/api-token.ts:421 admin token permissions clamped to the owner's ceiling (:359 "Cannot assign admin permissions that exceed your own"), so an MCP client gets only the actions and types granted to its token; strapi:packages/core/content-manager/server/src/mcp/handlers/collection-handlers.ts:84 cannot.read refusal; strapi:packages/core/core/src/services/mcp/tool-registry.ts:23 devModeOnly vs auth tools

## Why

Two competitors limit what an agent can touch. OpenRegister enforces agent tools and views inside its chat, but an administrator can only set them over the API, and the tool grant resolver that would apply them to MCP calls is referenced by nothing outside its own classes.

## What is built today

- Chat enforcement: `lib/Service/Chat/ToolManagementHandler.php`, `lib/Service/Chat/ContextRetrievalHandler.php`.
- Agents API (`appinfo/routes.php` agents resource).
- `lib/Service/Capability/ToolGrantResolver.php` with no caller outside its siblings.

## What changes

1. An agents screen lists agents and edits their allowed tools, registers and views.
2. The MCP tool dispatch resolves the calling agent and applies `ToolGrantResolver`; a call outside the grant is refused with a message naming the tool.

## Out of scope

- Per-field limits inside a register.
