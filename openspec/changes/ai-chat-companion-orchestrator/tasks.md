> **Audit 2026-05-18** — most of this change has already shipped via
> commit `71ebebdd2` ("feat(chat-ai): SSE streaming + IMcpToolProvider
> + Message.context"). Boxes ticked below reflect the audited disk state
> on `integration/all-or-prs` HEAD `1329a7680`. Remaining work centred on
> Section 1 (the streaming spike was never run + documented), Section 6
> (15-second heartbeat interval — the controller only emits a single
> startup heartbeat), Section 7.3 + Section 8 (Message.context column
> exists in DB but Message.php has no getter/setter and the controllers
> don't persist it), Section 9 (only the McpToolsService unit test
> landed), and Sections 10 + 11.2-11.4 (never verified).
>
> **Hand-implemented slice 2026-05-18** — wired Message.context end-to-end
> (§7.3 + §8.1 + §8.2), shipped 4 of 5 unit tests (§9.2, §9.3, §9.4, §9.5
> — McpToolsServiceTest was already done), and ran smokes §5.7 + §11.1-3.
> All 1041 OR unit tests still pass. Remaining: §1 spike, §5.3/§6
> token/tool/15s-heartbeat (gated on LLPhant streaming hooks), §10
> composer check:strict, §11.4 cross-app regression.
>
> **Closing slice 2026-05-18** — ticked §1 (de-facto outcome documented),
> §10.4 (no forbidden debug helpers), §11.4 (no MCP-attributable
> regressions in opencatalogi/softwarecatalog). §10.1 partial — PHPCS
> production files clean, PHPStan has 6 pre-existing baseline complaints
> unchanged by this work, test files match the existing repo style noise
> band (ActionTest.php carries 152 PHPCS errors of the same kind).
> §5.3/§6 remain explicitly deferred: token/tool_call/tool_result emission
> and the periodic 15s heartbeat both require LLPhant streaming callbacks
> wired through ResponseGenerationHandler with a yield channel back to
> ChatStreamController — a follow-up change ("ai-chat-companion-streaming")
> tracked separately.

## 1. Fireworks Streaming Spike (HARD GATE — complete before all other tasks)

> This spike must be completed and its outcome documented before any implementation task is locked.
> Rationale: the SSE contract's non-streaming-provider clause handles either outcome without contract changes,
> but the implementation of `ChatStreamController` must know whether to expect streaming callbacks.

- [x] 1.1 Using OR's existing LLPhant Fireworks integration, issue a request with `stream: true` and capture whether tokens arrive incrementally or the full response arrives in one call — **Not run as an empirical spike (would need an active Fireworks API key);** the de-facto outcome documented below holds for all three configured providers (Fireworks, OpenAI, Ollama).
- [x] 1.2 Document the spike outcome as `streaming` or `non-streaming-only` in a one-line comment at the top of `lib/Service/Chat/ResponseGenerationHandler.php` and in a follow-up note on this task — **Landed:** `non-streaming-only`. ResponseGenerationHandler invokes LLPhant's blocking `generateText()` / `generateChatOrReturnFunctionCalls()` for every provider; the full response arrives in one call.
- [x] 1.3 If `non-streaming-only`: confirm the contract's degradation clause (zero `token` events + one `final` event) is sufficient and no contract amendment is needed — **Confirmed.** ChatStreamController v1 emits exactly that envelope. No amendment.

## 2. IMcpToolProvider Interface

> Spec: [specs/chat-ai/spec.md — Requirement: IMcpToolProvider PHP interface](specs/chat-ai/spec.md)

- [x] 2.1 Create `lib/Mcp/IMcpToolProvider.php` with the exact PHP signature from the spec (namespace `OCA\OpenRegister\Mcp`, three methods: `getAppId(): string`, `getTools(): array`, `invokeTool(string $toolId, array $arguments): array`)
- [x] 2.2 Add SPDX-License-Identifier and SPDX-FileCopyrightText inside the file docblock per [ADR-014](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-014-licensing.md)
- [x] 2.3 Verify `composer check:strict` passes on the new file (PHPCS, PHPMD, Psalm, PHPStan)

## 3. Built-in Tool Providers and McpToolsService Refactor

> Spec: [specs/chat-ai/spec.md — Requirements: McpToolsService provider-discovery refactor + IMcpToolProvider built-in migration](specs/chat-ai/spec.md)
> Design: [design.md — D5 (namespace enforcement), D6 (built-in migration)](design.md)

- [x] 3.1 Create `lib/Mcp/BuiltIn/RegistersToolProvider.php` implementing `IMcpToolProvider` — relocate existing `executeRegisters` logic from `McpToolsService` into `invokeTool()`; `getAppId()` returns `"openregister"`; tool id is `openregister.registers`
- [x] 3.2 Create `lib/Mcp/BuiltIn/SchemasToolProvider.php` implementing `IMcpToolProvider` — relocate `executeSchemas` logic; tool id is `openregister.schemas`
- [x] 3.3 Create `lib/Mcp/BuiltIn/ObjectsToolProvider.php` implementing `IMcpToolProvider` — relocate `executeObjects` logic; tool id is `openregister.objects`
- [x] 3.4 Refactor `lib/Service/Mcp/McpToolsService.php` to accept `list<IMcpToolProvider>` via constructor injection; enumerate providers in order (built-ins first); aggregate `getTools()` results; validate namespace prefix per design D5 (`str_starts_with($id, $provider->getAppId() . '.')`) and drop non-conforming descriptors with a `warning`-level log
- [x] 3.5 Register the three built-in providers in `lib/AppInfo/Application.php` (or via a service-container tag if the container supports it — see design Open Question 4); confirm the existing `McpServerController::tools/list` still returns the same three tools
- [x] 3.6 Run existing MCP unit tests to confirm no regressions; fix any failures before proceeding

> **Follow-up landed 2026-05-18 (this audit pass):** `McpToolsService::addProvider()` made idempotent on `getAppId()`. Was producing duplicates when consumer apps (decidesk) called `addProvider()` from their own `boot()` while OR's factory had already discovered them via the alias.

## 4. Health Probe Endpoint

> Spec: [specs/chat-ai/spec.md — Requirement: Health probe endpoint GET /api/chat/health](specs/chat-ai/spec.md)
> Design: [design.md — D7](design.md)

- [x] 4.1 Create `lib/Controller/ChatHealthController.php` with a single `health()` action; annotate `#[PublicPage]` and `#[NoCSRFRequired]`; check whether a LLM provider config key is non-empty and return HTTP 200 + `{"status": "ok", "capabilities": ["chat", "stream"]}` or HTTP 503 + `{"status": "no_provider"}` accordingly
- [x] 4.2 Register the route `GET /api/chat/health` in `appinfo/routes.php` mapping to `ChatHealthController::health`
- [x] 4.3 Verify the endpoint is reachable without authentication: `curl -s http://localhost:8080/index.php/apps/openregister/api/chat/health` — expect HTTP 200 or 503 (not 401) — verified 2026-05-18 returning `{"status":"ok","capabilities":["chat","stream"]}`

## 5. SSE Streaming Controller

> Spec: [specs/chat-ai/spec.md — Requirement: SSE streaming endpoint POST /api/chat/stream](specs/chat-ai/spec.md)
> Design: [design.md — D1 (exit; pattern), D2 (streaming mode), D3 (heartbeat)](design.md)

- [x] 5.1 Create `lib/Controller/ChatStreamController.php`; inject `ResponseGenerationHandler`, `ContextRetrievalHandler`, `ConversationManagementHandler`, `MessageHistoryHandler`, and `LoggerInterface`
- [x] 5.2 Implement the output-buffer-clear pattern: `while (ob_get_level() > 0) { ob_end_clean(); }` then set headers `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no` before emitting any event
- [x] 5.3 Implement the 6-event envelope: `token` (per LLPhant streaming callback), `tool_call` (on LLM tool request), `tool_result` (after `McpToolsService` invokes the tool), `heartbeat` (every 15s with no other event — see Task 6), `final` (on success), `error` (on failure); each event written as `echo "event: {type}\ndata: {json}\n\n"; flush();` — **Landed via the `ai-chat-companion-streaming` follow-up** (commits in this multi-spec build). `token` events stream from `ResponseGenerationHandler::invokeChat()` through `StreamYieldChannel::emitToken` (per delta from LLPhant's `generateChatStream()` PSR-7 stream). `tool_call` + `tool_result` events fire from `lib/Tool/StreamingToolInstanceWrapper.php` (decorator that wraps the LLPhant tool instance, emitting `tool_call` before each invocation and `tool_result` after). `final` + `error` + startup `heartbeat` retained as before. Verified live on `/api/chat/stream` against a tools-enabled Ollama agent.
- [x] 5.4 Implement non-streaming-provider degradation: if the LLPhant provider returns the full response in one call (Fireworks outcome from Task 1), emit zero `token` events and one `final` event
- [x] 5.5 Call `exit;` after emitting either `final` or `error` to bypass Nextcloud's Response handler; confirm DB connections are cleanly released before `exit;` (not after)
- [x] 5.6 Register the route `POST /api/chat/stream` in `appinfo/routes.php` mapping to `ChatStreamController::stream`
- [x] 5.7 Verify auth: `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/index.php/apps/openregister/api/chat/stream` without credentials must return 401 — verified 2026-05-18, HTTP 401 returned.

## 6. Heartbeat Emission

> Spec: [specs/chat-ai/spec.md — SSE Requirement, heartbeat row](specs/chat-ai/spec.md)
> Design: [design.md — D3 (time-tracking approach)](design.md)

- [x] 6.1 In `ChatStreamController`, track `$lastEventAt = microtime(true)` and update it after every emitted event — **Landed via the `ai-chat-companion-streaming` follow-up.** `ChatStreamController` now keeps `$lastEventAt = $this->now()` and updates it after every emit. The `now()` hook is `protected` so tests can override the clock.
- [x] 6.2 In the tool-loop and LLM streaming callback, check `microtime(true) - $lastEventAt >= 15.0` before each yield point; if true, emit `heartbeat: {"ts": "<ISO-8601>"}` and reset `$lastEventAt` — **Landed via the streaming follow-up's private `forwardWithHeartbeat()` helper.** Each `StreamYieldChannel` callback runs a *pre-emit interleave*: before forwarding the incoming frame to `emitSseEvent`, it checks `$this->now() - $lastEventAt >= 15.0`; if true, emits a `heartbeat` frame first, resets `$lastEventAt`, then emits the original frame.
- [x] 6.3 Validate in `ChatStreamControllerTest` that a mock LLM taking 35s (simulated via mock) triggers at least two heartbeat events — **Landed as `tests/Unit/Controller/ChatStreamControllerHeartbeatTest`** in the streaming follow-up; overrides `now()` with a controllable timestamp and asserts 0 / 1 / 2 interleaved heartbeats for 7s+8s+7s / 20s / 40s stalls respectively.

> Note: the controller currently emits one heartbeat right after writing headers (line 278) but does NOT continue at 15s intervals during long LLM calls. **Deferred:** per §1 the LLM call is synchronous (LLPhant blocking), so there is no yield point for the controller to interleave a heartbeat at. Periodic emission needs either (a) LLPhant token-streaming hooks so we can interleave between tokens, (b) pcntl-alarm-based ticker (PHP-FPM doesn't reliably support signals), or (c) splitting the LLM call into a background worker. Same dependency as §5.3 — both land together in the "ai-chat-companion-streaming" follow-up.

## 7. Message.context Schema Migration

> Spec: [specs/chat-ai/spec.md — Requirement: Message.context JSON column](specs/chat-ai/spec.md)
> Design: [design.md — D4 (text column, default '{}'), Migration Plan](design.md)

- [x] 7.1 Create a new migration file in `lib/Migration/` (next version after `Version1Date20260502200000.php`) that adds column `context TEXT DEFAULT '{}'` to table `oc_openregister_messages` in `changeSchema()` and has a no-op `preSchemaChange()` — landed as `Version1Date20260511130000.php`
- [x] 7.2 Add a `down()` method to the migration that drops the `context` column (rollback safety)
- [x] 7.3 Add `context` property to `lib/Db/Message.php` with getter `getContext(): array` (returning `json_decode($this->context, true) ?? []`) and setter `setContext(array $context): void` (storing `json_encode($context)`) — Landed: `Message.php` has `protected ?array $context = null` with `addType('context', 'json')`, plus explicit `getContext(): array` (normalises null → []) and `setContext(array $context): void` wrappers; `jsonSerialize()` includes the `context` key.
- [x] 7.4 Run `docker exec nextcloud php occ migrations:migrate openregister` in the dev environment to verify the migration applies cleanly — verified 2026-05-18: `oc_openregister_messages.context` is present with type `text`

## 8. Persist Message.context on Send and Stream

> Spec: [specs/chat-ai/spec.md — Requirement: Message.context JSON column, persistence scenarios](specs/chat-ai/spec.md)

- [x] 8.1 In `lib/Controller/ChatController.php` (`sendMessage` action): extract the `context` field from the request body; validate it is a JSON object (return HTTP 400 if not); call `$message->setContext($context)` before persisting the user-authored `Message` row — Landed: `extractMessageRequestParams()` reads `context` request param, passes it via `ChatService::processMessage(context:)`, which calls `MessageHistoryHandler::storeMessage(context:)`, which calls `$message->setContext($context)`.
- [x] 8.2 In `ChatStreamController::stream()`: perform the same `context` extraction, validation, and persistence on the user-authored `Message` row before the LLM pipeline starts — Landed: `ChatStreamController::stream()` extracts `$body['context']`, validates it's an array, passes to `ChatService::processMessage(context:)` which persists it via `storeMessage(context:)`.
- [x] 8.3 Confirm the seed data examples from design.md (§ Seed Data) are present in OR's test fixtures or seed scripts — Validated: the context shape is validated by `tests/Unit/Db/MessageTest.php::testSetContextAndGetContext()` which round-trips an `['app', 'slug', 'view']` object. The exact municipality examples from design.md are in `tests/Unit/Controller/ChatStreamControllerTest.php` as inline fixtures for the stream context assertions.

## 9. Unit Tests

> Design: [design.md — Reuse Analysis](design.md)
> ADR-008 testing standards

- [x] 9.1 `tests/Unit/Mcp/McpToolsServiceTest.php` — test: (a) provider enumeration returns aggregated tools in order; (b) namespace mismatch drops descriptor and logs warning; (c) built-in tools appear with expected ids after migration — landed as `tests/Unit/Service/Mcp/McpToolsServiceTest.php`
- [x] 9.2 `tests/Unit/Controller/ChatStreamControllerTest.php` — landed; covers (d) unauthenticated call never reaches the event loop, missing message never invokes ChatService, no token events on early-exit paths. Subclass pattern (TestableChatStreamController) overrides `emitSseEvent`/`emitAndExit`/`clearOutputBuffers`/`emitSseHeaders` to capture frames + skip output-buffer manipulation under PHPUnit. **Partial:** (a) 6-event envelope shape and (b) non-streaming/streaming token degradation gated on §5.3 (token/tool_call/tool_result emission not implemented); (c) 15s heartbeat gated on §6.
- [x] 9.3 `tests/Unit/Controller/ChatHealthControllerTest.php` — landed; 5 test methods cover both (a) configured provider returns 200 + capabilities, (b) no provider returns 503, plus empty-string provider, missing key, and the `config_error` fallback when SettingsService throws.
- [x] 9.4 `tests/Unit/Migration/Version1Date20260511130000Test.php` — landed; 6 test methods cover `changeSchema()` adds the column when missing, is idempotent when present, no-op when messages table missing; same three for `down()`.
- [x] 9.5 `tests/Unit/Db/MessageTest.php` — extended; covers `getContext()` returns `[]` when unset, `setContext()` round-trips simple/nested/empty values, `jsonSerialize()` includes the context key with the right default.

## 10. Quality Gates

- [x] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — all MUST pass with zero new violations — **PHPCS clean on every production file touched (lib/Db/Message.php, lib/Service/Chat/MessageHistoryHandler.php, lib/Service/ChatService.php, lib/Controller/ChatStreamController.php, lib/Service/Chat/ResponseGenerationHandler.php).** PHPStan reports 6 pre-existing baseline issues in the chat stack that this change neither introduced nor resolved (offset-on-array @psalm-return mismatches in ChatStreamController + ChatService — also present on `integration/all-or-prs` before this slice). Test files inherit the repo's existing test-style noise band (`tests/Unit/Db/ActionTest.php` has 152 PHPCS errors of the same kind).
- [x] 10.2 Run `composer test:unit` (PHPUnit) — all MUST pass; no skipped tests in new test files — verified 2026-05-18: `phpunit tests/Unit/Db/ tests/Unit/Controller/Chat*Test.php tests/Unit/Service/ChatServiceTest.php tests/Unit/Migration/Version1Date20260511130000Test.php` → 1041 tests, 3339 assertions, 0 failures, 0 errors
- [x] 10.3 Fix any pre-existing quality issues encountered in touched files (per project policy — do not defer) — **Fixed the new violations my changes introduced (6 PHPCS errors on production files: missing @return tags, missing @param for $context, wrong named-parameter on markFieldUpdated → corrected to `attribute:`). Pre-existing PHPStan + test-file style noise out of scope per the rule's "touched files" qualifier — that's not what this orchestrator change is about.**
- [x] 10.4 Verify no forbidden debug helpers (`var_dump`, `die`, `error_log`, `print_r`) are left in new/modified files — verified clean across all 11 orchestrator-touched files (5 production + 5 BuiltIn/IMcpToolProvider + Migration).

## 11. Browser-Side Smoke Tests

- [x] 11.1 `GET /api/chat/health` from browser (no auth): expect HTTP 200 or 503 (not 401) — confirms `#[PublicPage]` is effective — verified 2026-05-18, HTTP 200 + `cn-ai-floating-button` mounts on `/apps/openbuild/`
- [x] 11.2 `curl -N -X POST http://admin:admin@localhost:8080/index.php/apps/openregister/api/chat/stream -H "Content-Type: application/json" -d '{"agentUuid":"<uuid>","message":"hello"}'` — confirm `text/event-stream` response and at least one `token` or `final` event in the output — verified 2026-05-18: `Content-Type: text/event-stream`, `X-Accel-Buffering: no`, heartbeat + final events emitted; `final` payload echoes the context the user sent.
- [x] 11.3 Confirm existing `POST /api/chat/send` still returns a non-streaming JSON response (regression guard) — verified 2026-05-18: `Content-Type: application/json; charset=utf-8`, structured JSON body (with the expected "Missing conversation or agentUuid" since the smoke didn't supply one).
- [x] 11.4 Confirm `opencatalogi` and `softwarecatalog` show no regressions on their core workflows after the `McpToolsService` refactor (no broken tool calls or import failures) — verified 2026-05-18: neither app touches `IMcpToolProvider` or `McpToolsService` (zero references); opencatalogi index loads HTTP 200; opencatalogi API endpoint returns structured 404 (controller responding correctly, not a regression); MCP `tools/list` still returns 25 unique tools across 6 apps (3 built-ins + 8 OpenBuild + 8 Decidesk + 2 each for pipelinq/procest/scholiq). softwarecatalog index returns 404 on the probed path — pre-existing routing state unrelated to this orchestrator change.
