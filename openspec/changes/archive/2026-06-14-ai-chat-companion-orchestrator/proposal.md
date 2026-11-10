---
kind: code
depends_on: [ai-chat-companion]
chain:
  - ai-chat-companion              # hydra — cross-app contracts (predecessor)
  - ai-chat-companion-orchestrator # this spec (openregister)
  - ai-chat-companion-widget       # nextcloud-vue (parallel sibling)
---

## Why

The cross-app AI Chat Companion architecture is defined in hydra's `ai-chat-companion` change ([hydra/openspec/specs/ai-chat-companion/spec.md](https://github.com/ConductionNL/hydra/tree/development/openspec/specs/ai-chat-companion/) once archived; promoted as ADR-034). OpenRegister is the single orchestrator: it owns RAG, MCP tool fan-out, multi-turn tool loops, LLM provider selection (LLPhant), and conversation persistence. This change implements the OR-side contracts so the widget shipped by `nextcloud-vue/ai-chat-companion-widget` has a real backend to talk to.

## What Changes

- **NEW** `OCA\OpenRegister\Mcp\IMcpToolProvider` PHP interface — the per-app MCP tool registration contract defined in [ADR-034](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-034-ai-chat-companion.md). Apps that opt in implement it; OR's `McpToolsService` enumerates implementations in-process per turn. Tool IDs MUST be namespaced as `{appId}.{toolName}`; `McpToolsService` rejects descriptors whose prefix does not match the provider's owning app id.
- **REFACTOR** `McpToolsService` — migrate the existing static `registers` / `schemas` / `objects` tools onto the new provider contract as the first three built-in providers (`getAppId() === 'openregister'`, tool ids `openregister.registers`, `openregister.schemas`, `openregister.objects`).
- **NEW** `POST /index.php/apps/openregister/api/chat/stream` SSE endpoint emitting the six-event envelope (`token`, `tool_call`, `tool_result`, `heartbeat`, `final`, `error`) per the hydra spec. Non-streaming providers degrade to one `final` event. Reuses `ResponseGenerationHandler` (LLPhant) + `ContextRetrievalHandler` (RAG); adds the streaming wire format and `heartbeat` cadence.
- **NEW** `GET /index.php/apps/openregister/api/chat/health` lightweight health endpoint the widget can probe on mount to decide whether to render. Returns 200 + `{status: "ok", capabilities: [...]}` when chat is configured; 503 when no LLM provider is configured.
- **NEW** `Message.context` JSON column on the existing `oc_openregister_messages` table — stores the `CnAiContext` snapshot active at the moment the user message was sent, plus `capturedAt` ISO-8601 timestamp. Migration adds the column with default empty object; both `/api/chat/send` and `/api/chat/stream` persist this on every user-authored message.
- **NO BREAKING CHANGES.** Existing `POST /api/chat/send` non-streaming endpoint unchanged. Existing full-page chat at `/apps/openregister/chat` (`src/views/chat/ChatIndex.vue`) unchanged in this change — refactoring it onto the shared nc-vue primitives is tracked separately in [openregister#1459](https://github.com/ConductionNL/openregister/issues/1459).

## Capabilities

### Modified Capabilities
- `chat-ai`: existing capability gains the streaming endpoint, the Message.context metadata column, the IMcpToolProvider interface, and the McpToolsService refactor. Delta spec records the new requirements.

### New Capabilities
- (none — all new behaviour lives under the modified `chat-ai` capability)

## Impact

- **First task is a spike**: LLPhant Fireworks streaming parity (carried over from hydra `ai-chat-companion` task 2.2 — the spike couldn't run in hydra's dev env without a Fireworks API key). Block locking of this change's design.md until the spike outcome is captured. Outcome: `streaming` or `non-streaming-only`. If non-streaming, contract's non-streaming-provider clause (zero `token` events + one `final` event) covers it without contract changes.
- **Backend code changes** in `lib/Controller/`, `lib/Service/Chat/`, `lib/Service/Mcp/`, `lib/Db/`, plus a migration in `lib/Migration/`. Test coverage expected: ~3 unit tests for `McpToolsService` namespace enforcement, ~3 for the SSE controller envelope shape, ~3 for the migration. PHPUnit + `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) MUST pass.
- **No frontend changes in OR**. The widget lives in `nextcloud-vue/ai-chat-companion-widget`; OR's existing `ChatIndex.vue` is untouched (follow-up issue).
- **Apache + PHP-FPM streaming proven viable** in hydra's spike 2.1. Required headers + production-deployment notes (nginx `proxy_buffering off`, mod_deflate exception for the streaming path) are captured in hydra's design.md and MUST be documented in this change's design.md for operators.
- **Compatibility with [adr-022-apps-consume-or-abstractions.md](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-022-apps-consume-or-abstractions.md)**: this change builds the OR-side abstractions that consuming apps will use. ADR-022's table already lists "MCP discovery"; this change extends it with chat-stream and IMcpToolProvider.
- **Compatibility with [adr-005-security.md](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-005-security.md) Rule 3 (IDOR)**: every `IMcpToolProvider::invokeTool()` MUST run with the current Nextcloud session user's permissions. The controller middleware passes the session through unchanged.
- **Compatibility with [adr-032-spec-sizing-and-chaining.md](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-032-spec-sizing-and-chaining.md)**: this is `kind: code`, depends_on the hydra config spec. May run in parallel with `nextcloud-vue/ai-chat-companion-widget` (the sibling) but widget e2e tests block on this change shipping.
- **Rollback**: schema migration is additive (new column, default empty), reversible. Code rollback via `git revert`. Active users on the SSE endpoint would lose streaming on rollback; non-streaming `/api/chat/send` remains.
