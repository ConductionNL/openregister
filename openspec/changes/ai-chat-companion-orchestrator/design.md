## Context

This design document describes the OpenRegister-side **HOW** for the `ai-chat-companion-orchestrator` change. The cross-app contracts (what must be built) are defined in:

- [hydra/openspec/changes/archive/2026-05-11-ai-chat-companion/specs/ai-chat-companion/spec.md](https://github.com/ConductionNL/hydra/blob/development/openspec/changes/archive/2026-05-11-ai-chat-companion/specs/ai-chat-companion/spec.md) — 6 locked requirements covering `CnAiContext`, `IMcpToolProvider`, SSE envelope (6 event types including heartbeat), `Message.context`, auth flowthrough
- [hydra/openspec/architecture/adr-034-ai-chat-companion.md](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-034-ai-chat-companion.md) — ADR with full rationale and architectural decisions

OpenRegister is the sole orchestrator: it owns RAG retrieval (`ContextRetrievalHandler`), MCP tool fan-out (`McpToolsService`), multi-turn tool loops, LLM provider selection (LLPhant), and conversation persistence. This change adds the streaming wire format, the tool-provider discovery interface, the health probe, and the `Message.context` metadata column.

**SSE streaming spike (2026-05-10) — PASS:** Apache + `mod_php` / PHP-FPM streams SSE cleanly. Required headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`. Required PHP pattern: `while (ob_get_level() > 0) { ob_end_clean(); }` before headers, then `echo "event: {type}\ndata: {json}\n\n"` per event with `flush()`, then `exit;` to bypass NC's Response handler. Nginx production deployments need `proxy_buffering off`; HAProxy needs `option http-no-delay`; `mod_deflate` MUST be disabled on the `/api/chat/stream` path.

**Fireworks streaming spike — NOT YET RUN.** See Tasks §1.

## Goals / Non-Goals

**Goals:**

- Publish `OCA\OpenRegister\Mcp\IMcpToolProvider` PHP interface so consuming apps can register MCP tools in-process.
- Refactor `McpToolsService` to enumerate providers via the interface (built-ins first) instead of a static list.
- Migrate the existing static `registers` / `schemas` / `objects` tools onto the new interface as three built-in providers.
- Expose `POST /api/chat/stream` SSE endpoint emitting the 6-event envelope per the hydra contract.
- Expose `GET /api/chat/health` lightweight probe (`#[PublicPage]`).
- Add `Message.context` JSON column via a schema migration; persist on both `/send` and `/stream`.
- Enforce MCP tool auth flowthrough (no impersonation, per-object IDOR is the implementer's responsibility).

**Non-Goals:**

- Refactoring `ChatIndex.vue` onto the shared `@conduction/nextcloud-vue` primitives — tracked separately as [openregister#1459](https://github.com/ConductionNL/openregister/issues/1459).
- Integrating Nextcloud Task Processing (`OCP\TaskProcessing\IManager`) — separate research issue [openregister#1460](https://github.com/ConductionNL/openregister/issues/1460).
- Per-app `IMcpToolProvider` implementations inside other apps (`opencatalogi`, `docudesk`, etc.) — each consuming app writes its own after this change ships.
- Widget implementation (`CnAiCompanion`, `CnAiInput`, etc.) — that is the sibling change in `nextcloud-vue/ai-chat-companion-widget`.
- Changing the existing `POST /api/chat/send` non-streaming endpoint contract.

## Decisions

### D1: SSE controller bypasses Nextcloud's Response handler via `exit;`

**Decision:** `ChatStreamController::stream()` uses the PHP output-buffering pattern from the spike: clear buffers → set headers → emit events with `flush()` per event → call `exit;` to prevent the Nextcloud framework from appending its own response object.

**Rationale:** Nextcloud's `JSONResponse` and `Response` classes buffer output until the controller returns. For a streaming response there is no return value that can carry an open stream — the framework has no SSE-native response type. Using `exit;` is the established pattern for streaming from within Nextcloud PHP (also used by the Nextcloud Talk polling endpoint). The spike confirmed this works cleanly through Apache + mod_php and PHP-FPM.

**Alternative considered:** Implementing a custom `StreamResponse` class extending NC's `Response`. Rejected because NC's dispatcher calls `render()` after the controller returns, which would require hooking deep into private NC internals to suppress buffering — more fragile than a single `exit;`.

**Operator notes (MUST document in app docs):**
- nginx: add `proxy_buffering off;` to the `location ~ ^/apps/openregister/api/chat/stream` block.
- HAProxy: add `option http-no-delay` to the backend.
- Apache `mod_deflate`: add `SetEnvIf Request_URI "^/index.php/apps/openregister/api/chat/stream" no-gzip` to prevent gzip buffering the stream.

### D2: LLPhant streaming mode — OpenAI and Ollama confirmed; Fireworks unverified

**Decision:** The SSE controller invokes `ResponseGenerationHandler` in streaming mode where the LLPhant provider supports it. The handler callback emits `token` events. For providers that return a complete response in one call (Fireworks at time of writing — parity unverified), the controller emits zero `token` events and one `final` event, matching the contract's non-streaming-provider clause.

**Detection strategy:** Check whether the LLPhant chat model instance implements a streaming callback interface (or similar); if not, fall back to a single-shot call wrapped in the `final` event. This avoids hard-coding a provider allowlist.

**Fireworks spike required (Task 1 — hard gate):** The spike must complete before design.md is considered fully resolved. If Fireworks streaming is confirmed, the detection strategy is unchanged (it would now be classified as streaming-capable). If non-streaming-only, the fallback path covers it without contract changes.

### D3: Heartbeat implementation via time-tracking in the event loop

**Decision:** The SSE controller tracks the timestamp of the last emitted event using `microtime(true)`. In the tool-loop awaiting `invokeTool()` or an LLM response, the controller emits a `heartbeat` event whenever 15 seconds elapse with no other event fired. This is done inline without `pcntl_alarm`.

**Rationale:** `pcntl_alarm` delivers a `SIGALRM` UNIX signal; PHP-FPM and Apache mod_php may not receive signals cleanly inside a request handler. Time-tracking in the loop is portable and does not require the `pcntl` extension, which is not guaranteed to be enabled in all NC deployments.

**Trade-off:** Time-tracking only fires during yield points (between LLM token callbacks and between tool-loop iterations). If a single `invokeTool()` call blocks for longer than 15s internally, the heartbeat will not fire until the call returns. This is acceptable because the heartbeat's purpose is defeating proxy idle-timeout timers, not providing millisecond precision.

### D4: Message.context migration shape

**Decision:** The migration adds a `context` column to `oc_openregister_messages` of type `text` (not `json`/`jsonb`), defaulting to `'{}'`. The `Message` entity getter deserializes via `json_decode`; the setter serializes via `json_encode`. This matches the pattern used for other JSON columns in OR (e.g., `sources` on `Message`).

**Rationale:** Nextcloud's `ISchemaWrapper` does not have a portable `json` column type across MySQL/MariaDB and PostgreSQL. Using `text` is already the convention in OR's existing JSON storage fields. PostgreSQL deployments can cast to `jsonb` for query filtering if needed in the future without a migration.

### D5: IMcpToolProvider namespace enforcement strategy

**Decision:** `McpToolsService` iterates over each provider, calls `getTools()`, and for each returned descriptor checks whether `str_starts_with($descriptor['id'], $provider->getAppId() . '.')`. Descriptors failing the check are dropped and logged at `warning` level with the provider class name and the offending tool id. This check runs on every enumeration (i.e., per turn), not at registration time.

**Rationale:** Checking per-turn rather than once at registration time means a provider that is updated to add a mis-namespaced tool is caught immediately rather than requiring an OR restart. The performance cost is negligible (string prefix check on a small list).

### D6: How built-in tools migrate onto IMcpToolProvider

**Decision:** Three new classes are created at `lib/Mcp/BuiltIn/{Registers,Schemas,Objects}ToolProvider.php`. Each implements `IMcpToolProvider`. `McpToolsService` is updated to accept a `list<IMcpToolProvider>` constructor argument; the three built-in providers are injected first via the service container, followed by any externally registered providers. The existing tool-execution logic in `McpToolsService` (`executeRegisters`, `executeSchemas`, `executeObjects`) is relocated into the respective built-in provider's `invokeTool()` method.

**Alternative considered:** Keeping the existing static tool methods in `McpToolsService` and wrapping them in a synthetic adapter at call time. Rejected because it does not actually prove the `IMcpToolProvider` contract works end-to-end and would leave dead code behind.

### D7: Health probe controller placement

**Decision:** Add a new `ChatHealthController` at `lib/Controller/ChatHealthController.php` with a single `GET /api/chat/health` route. Annotated `#[PublicPage]` and `#[NoCSRFRequired]`. LLM-configured check: inspect the same config key OR uses for provider selection; if a non-empty provider is set, return 200; otherwise return 503.

**Alternative considered:** Adding the `health` action to the existing `ChatController`. Rejected because `ChatController` is authenticated-only; mixing `#[PublicPage]` and authenticated actions in one controller requires per-action annotations that are error-prone.

## Reuse Analysis

| Component | Location | Status | Notes |
|---|---|---|---|
| `Conversation` entity | `lib/Db/Conversation.php` | **Reused** | No changes |
| `Message` entity | `lib/Db/Message.php` | **Modified** — new `context` field | Add JSON `context` property + getter/setter |
| `ConversationMapper` | `lib/Db/ConversationMapper.php` | **Reused** | No changes |
| `MessageMapper` | `lib/Db/MessageMapper.php` | **Reused** | No changes |
| `Agent` entity | `lib/Db/Agent.php` | **Reused** | No changes |
| `McpServerController` | `lib/Controller/McpServerController.php` | **Reused** — `McpToolsService` inside is refactored | JSON-RPC 2.0 dispatch logic untouched |
| `McpToolsService` | `lib/Service/Mcp/McpToolsService.php` | **Refactored** — provider enumeration added | Built-in tool execution relocated to BuiltIn providers |
| `ResponseGenerationHandler` | `lib/Service/Chat/ResponseGenerationHandler.php` | **Reused** | Called from `ChatStreamController` in streaming mode |
| `ContextRetrievalHandler` | `lib/Service/Chat/ContextRetrievalHandler.php` | **Reused** | Called from `ChatStreamController`; MAY consult `Message.context` in a future iteration |
| `ChatController` existing `sendMessage` | `lib/Controller/ChatController.php` | **Modified** — adds `context` persistence | Persist `context` field from request body |
| `ConversationManagementHandler` | `lib/Service/Chat/ConversationManagementHandler.php` | **Reused** | No changes |
| `MessageHistoryHandler` | `lib/Service/Chat/MessageHistoryHandler.php` | **Reused** | No changes |
| `ToolManagementHandler` | `lib/Service/Chat/ToolManagementHandler.php` | **Reused or replaced** | May be superseded by `McpToolsService` provider enumeration; review during apply |

## Seed Data

Per ADR-001, seed data MUST accompany schema changes. The following seed examples are realistic `Message.context` JSON values for a municipality deployment. The implementer MUST include at least these 2 examples in OR's seed/fixture data (or unit test fixtures) to validate the context shape.

**Example 1 — User on a Permits detail page asking about permit status:**
```json
{
  "appId": "openregister",
  "pageKind": "detail",
  "objectUuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "registerSlug": "vergunningen",
  "schemaSlug": "omgevingsvergunning",
  "route": {
    "path": "/apps/openregister/registers/vergunningen/schemas/omgevingsvergunning/objects/a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "object-detail",
    "params": { "register": "vergunningen", "schema": "omgevingsvergunning", "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890" }
  },
  "capturedAt": "2026-05-11T09:32:00Z"
}
```

**Example 2 — User on the Subsidies index page browsing a list:**
```json
{
  "appId": "openregister",
  "pageKind": "index",
  "objectUuid": null,
  "registerSlug": "subsidies",
  "schemaSlug": "subsidieaanvraag",
  "route": {
    "path": "/apps/openregister/registers/subsidies/schemas/subsidieaanvraag",
    "name": "object-index",
    "params": { "register": "subsidies", "schema": "subsidieaanvraag" }
  },
  "capturedAt": "2026-05-11T10:15:30Z"
}
```

**Example 3 — User in `opencatalogi` detail page (cross-app widget usage):**
```json
{
  "appId": "opencatalogi",
  "pageKind": "detail",
  "objectUuid": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "registerSlug": "catalogus",
  "schemaSlug": "organisation",
  "route": {
    "path": "/apps/opencatalogi/catalogus/organisation/b2c3d4e5-f6a7-8901-bcde-f12345678901",
    "name": "catalogue-detail",
    "params": {}
  },
  "capturedAt": "2026-05-11T11:00:00Z"
}
```

## Declarative-vs-Imperative (ADR-031)

**N/A.** ADR-031 applies to schema-level business logic (lifecycle hooks, aggregations, derived fields, notifications, declarative relations, dashboard widgets configured as OpenRegister data). This change adds:

- A PHP interface (`IMcpToolProvider`) — a code contract, not schema logic.
- A service refactor (`McpToolsService`) — imperative service code.
- A controller (`ChatStreamController`) — HTTP request handler.
- A migration (`Message.context` column) — additive column, no computed derivation.
- A health probe controller — HTTP handler.

None of these match ADR-031's trigger list. No declarative schema logic is introduced or modified.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| SSE through reverse proxies in customer deployments: nginx/HAProxy/Apache mod_deflate can buffer the stream silently | Documented operator config (D1 above) MUST be included in the OR release notes and in `docs/configuration/ai-chat.md`. The spike confirmed feasibility in the dev environment. |
| LLPhant Fireworks streaming parity unverified | Fireworks spike is task 1 (hard gate). The contract's non-streaming-provider clause (zero `token` events + one `final` event) handles the fallback without contract changes. |
| Heartbeat does not fire during a blocking `invokeTool()` call longer than 15s | Time-tracking only yields between async callbacks. Implementations of `IMcpToolProvider::invokeTool()` that may take >15s SHOULD emit heartbeats internally or use async patterns. This is documented as a known limitation in the interface's docblock. |
| `exit;` in the stream controller may interfere with NC's shutdown handlers | NC uses `register_shutdown_function` for DB connection cleanup, query logging, and error handlers. Testing must confirm no DB connection leaks. Mitigated by calling `exit;` only after the LLM pipeline and persistence are complete. |
| IMcpToolProvider is a PHP install-time coupling for opt-in apps | Explicitly accepted in ADR-034. Apps that only use the widget remain fully decoupled. The interface can be extracted to a standalone composer package if a non-OR-dependent app needs it in the future. |
| `Message.context` column adds non-indexed JSON to every message row | Context is stored but not queried by default; no performance impact on list/search paths. If RAG filtering against context is added later, add a generated column or GIN index at that time. |

## Migration Plan

1. **Schema migration** (`lib/Migration/Version*.php`): Adds `context TEXT DEFAULT '{}'` to `oc_openregister_messages`. Additive only — no existing rows are modified. Rollback: run the `down()` method to drop the column (safe — no other code depends on it before this change ships).

2. **Deployment order** (within this OR release):
   - Migration runs first (Nextcloud's `occ upgrade` mechanism).
   - New controllers and service refactor are deployed atomically as part of the OR app update.
   - No runtime coordination with the `nextcloud-vue` widget release is required — the widget's health probe gracefully handles the endpoint being absent (returns 404, widget renders nothing).

3. **Chain coordination**:
   - This change (OR orchestrator) and `nextcloud-vue/ai-chat-companion-widget` are parallel siblings.
   - Widget e2e tests that probe `/api/chat/health` and `/api/chat/stream` are blocked on this OR change shipping.
   - Per-app `IMcpToolProvider` pilots can begin as soon as this change is merged to OR's `development` branch and deployed to the test environment.

4. **Rollback**:
   - Code rollback: `git revert` the OR commit. The `context` column stays (harmless); the new endpoints disappear; the widget falls back to no-render.
   - Schema rollback: run `occ migrations:execute openregister <version> --down`. Safe because the column is additive.
   - Active SSE connections during rollback will drop; clients should handle disconnects gracefully.

## Open Questions

1. **Fireworks streaming parity** (Task 1 outcome): Does LLPhant's Fireworks integration support streaming callbacks (`stream: true`)? Answer determines whether `token` events will be emitted for Fireworks-backed deployments. Deadline: before any other task's implementation is locked.

2. **`exit;` and NC shutdown handlers**: Does calling `exit;` in the SSE controller cause DB connection leaks or suppress NC's error logging? To be validated in Task 5 (SSE controller) during unit and integration testing.

3. **`ContextRetrievalHandler` use of `Message.context`**: Should the RAG layer pre-filter by `registerSlug`/`schemaSlug` from the most-recent user message's context? Deferred to a follow-up change after this one ships — adding it now would expand scope beyond what the hydra contract mandates. Track as a separate issue.

4. **Built-in provider injection via service container vs. tagged services**: Does OR's container support Nextcloud's tagged-service pattern for auto-wiring `IMcpToolProvider` implementations, or must all providers be explicitly registered in `Application.php`? To be confirmed during Task 3; if tagged services are not available, use an explicit registration list.
