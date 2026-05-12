## 1. Fireworks Streaming Spike (HARD GATE — complete before all other tasks)

> This spike must be completed and its outcome documented before any implementation task is locked.
> Rationale: the SSE contract's non-streaming-provider clause handles either outcome without contract changes,
> but the implementation of `ChatStreamController` must know whether to expect streaming callbacks.

- [ ] 1.1 Using OR's existing LLPhant Fireworks integration, issue a request with `stream: true` and capture whether tokens arrive incrementally or the full response arrives in one call
- [ ] 1.2 Document the spike outcome as `streaming` or `non-streaming-only` in a one-line comment at the top of `lib/Service/Chat/ResponseGenerationHandler.php` and in a follow-up note on this task
- [ ] 1.3 If `non-streaming-only`: confirm the contract's degradation clause (zero `token` events + one `final` event) is sufficient and no contract amendment is needed

## 2. IMcpToolProvider Interface

> Spec: [specs/chat-ai/spec.md — Requirement: IMcpToolProvider PHP interface](specs/chat-ai/spec.md)

- [ ] 2.1 Create `lib/Mcp/IMcpToolProvider.php` with the exact PHP signature from the spec (namespace `OCA\OpenRegister\Mcp`, three methods: `getAppId(): string`, `getTools(): array`, `invokeTool(string $toolId, array $arguments): array`)
- [ ] 2.2 Add SPDX-License-Identifier and SPDX-FileCopyrightText inside the file docblock per [ADR-014](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-014-licensing.md)
- [ ] 2.3 Verify `composer check:strict` passes on the new file (PHPCS, PHPMD, Psalm, PHPStan)

## 3. Built-in Tool Providers and McpToolsService Refactor

> Spec: [specs/chat-ai/spec.md — Requirements: McpToolsService provider-discovery refactor + IMcpToolProvider built-in migration](specs/chat-ai/spec.md)
> Design: [design.md — D5 (namespace enforcement), D6 (built-in migration)](design.md)

- [ ] 3.1 Create `lib/Mcp/BuiltIn/RegistersToolProvider.php` implementing `IMcpToolProvider` — relocate existing `executeRegisters` logic from `McpToolsService` into `invokeTool()`; `getAppId()` returns `"openregister"`; tool id is `openregister.registers`
- [ ] 3.2 Create `lib/Mcp/BuiltIn/SchemasToolProvider.php` implementing `IMcpToolProvider` — relocate `executeSchemas` logic; tool id is `openregister.schemas`
- [ ] 3.3 Create `lib/Mcp/BuiltIn/ObjectsToolProvider.php` implementing `IMcpToolProvider` — relocate `executeObjects` logic; tool id is `openregister.objects`
- [ ] 3.4 Refactor `lib/Service/Mcp/McpToolsService.php` to accept `list<IMcpToolProvider>` via constructor injection; enumerate providers in order (built-ins first); aggregate `getTools()` results; validate namespace prefix per design D5 (`str_starts_with($id, $provider->getAppId() . '.')`) and drop non-conforming descriptors with a `warning`-level log
- [ ] 3.5 Register the three built-in providers in `lib/AppInfo/Application.php` (or via a service-container tag if the container supports it — see design Open Question 4); confirm the existing `McpServerController::tools/list` still returns the same three tools
- [ ] 3.6 Run existing MCP unit tests to confirm no regressions; fix any failures before proceeding

## 4. Health Probe Endpoint

> Spec: [specs/chat-ai/spec.md — Requirement: Health probe endpoint GET /api/chat/health](specs/chat-ai/spec.md)
> Design: [design.md — D7](design.md)

- [ ] 4.1 Create `lib/Controller/ChatHealthController.php` with a single `health()` action; annotate `#[PublicPage]` and `#[NoCSRFRequired]`; check whether a LLM provider config key is non-empty and return HTTP 200 + `{"status": "ok", "capabilities": ["chat", "stream"]}` or HTTP 503 + `{"status": "no_provider"}` accordingly
- [ ] 4.2 Register the route `GET /api/chat/health` in `appinfo/routes.php` mapping to `ChatHealthController::health`
- [ ] 4.3 Verify the endpoint is reachable without authentication: `curl -s http://localhost:8080/index.php/apps/openregister/api/chat/health` — expect HTTP 200 or 503 (not 401)

## 5. SSE Streaming Controller

> Spec: [specs/chat-ai/spec.md — Requirement: SSE streaming endpoint POST /api/chat/stream](specs/chat-ai/spec.md)
> Design: [design.md — D1 (exit; pattern), D2 (streaming mode), D3 (heartbeat)](design.md)

- [ ] 5.1 Create `lib/Controller/ChatStreamController.php`; inject `ResponseGenerationHandler`, `ContextRetrievalHandler`, `ConversationManagementHandler`, `MessageHistoryHandler`, and `LoggerInterface`
- [ ] 5.2 Implement the output-buffer-clear pattern: `while (ob_get_level() > 0) { ob_end_clean(); }` then set headers `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no` before emitting any event
- [ ] 5.3 Implement the 6-event envelope: `token` (per LLPhant streaming callback), `tool_call` (on LLM tool request), `tool_result` (after `McpToolsService` invokes the tool), `heartbeat` (every 15s with no other event — see Task 6), `final` (on success), `error` (on failure); each event written as `echo "event: {type}\ndata: {json}\n\n"; flush();`
- [ ] 5.4 Implement non-streaming-provider degradation: if the LLPhant provider returns the full response in one call (Fireworks outcome from Task 1), emit zero `token` events and one `final` event
- [ ] 5.5 Call `exit;` after emitting either `final` or `error` to bypass Nextcloud's Response handler; confirm DB connections are cleanly released before `exit;` (not after)
- [ ] 5.6 Register the route `POST /api/chat/stream` in `appinfo/routes.php` mapping to `ChatStreamController::stream`
- [ ] 5.7 Verify auth: `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/index.php/apps/openregister/api/chat/stream` without credentials must return 401

## 6. Heartbeat Emission

> Spec: [specs/chat-ai/spec.md — SSE Requirement, heartbeat row](specs/chat-ai/spec.md)
> Design: [design.md — D3 (time-tracking approach)](design.md)

- [ ] 6.1 In `ChatStreamController`, track `$lastEventAt = microtime(true)` and update it after every emitted event
- [ ] 6.2 In the tool-loop and LLM streaming callback, check `microtime(true) - $lastEventAt >= 15.0` before each yield point; if true, emit `heartbeat: {"ts": "<ISO-8601>"}` and reset `$lastEventAt`
- [ ] 6.3 Validate in `ChatStreamControllerTest` that a mock LLM taking 35s (simulated via mock) triggers at least two heartbeat events

## 7. Message.context Schema Migration

> Spec: [specs/chat-ai/spec.md — Requirement: Message.context JSON column](specs/chat-ai/spec.md)
> Design: [design.md — D4 (text column, default '{}'), Migration Plan](design.md)

- [ ] 7.1 Create a new migration file in `lib/Migration/` (next version after `Version1Date20260502200000.php`) that adds column `context TEXT DEFAULT '{}'` to table `oc_openregister_messages` in `changeSchema()` and has a no-op `preSchemaChange()`
- [ ] 7.2 Add a `down()` method to the migration that drops the `context` column (rollback safety)
- [ ] 7.3 Add `context` property to `lib/Db/Message.php` with getter `getContext(): array` (returning `json_decode($this->context, true) ?? []`) and setter `setContext(array $context): void` (storing `json_encode($context)`)
- [ ] 7.4 Run `docker exec nextcloud php occ migrations:migrate openregister` in the dev environment to verify the migration applies cleanly

## 8. Persist Message.context on Send and Stream

> Spec: [specs/chat-ai/spec.md — Requirement: Message.context JSON column, persistence scenarios](specs/chat-ai/spec.md)

- [ ] 8.1 In `lib/Controller/ChatController.php` (`sendMessage` action): extract the `context` field from the request body; validate it is a JSON object (return HTTP 400 if not); call `$message->setContext($context)` before persisting the user-authored `Message` row
- [ ] 8.2 In `ChatStreamController::stream()`: perform the same `context` extraction, validation, and persistence on the user-authored `Message` row before the LLM pipeline starts
- [ ] 8.3 Confirm the seed data examples from design.md (§ Seed Data) are present in OR's test fixtures or seed scripts

## 9. Unit Tests

> Design: [design.md — Reuse Analysis](design.md)
> ADR-008 testing standards

- [ ] 9.1 `tests/Unit/Mcp/McpToolsServiceTest.php` — test: (a) provider enumeration returns aggregated tools in order; (b) namespace mismatch drops descriptor and logs warning; (c) built-in tools appear with expected ids after migration
- [ ] 9.2 `tests/Unit/Controller/ChatStreamControllerTest.php` — test: (a) 6-event envelope shape with mock OpenAI streaming LLM; (b) non-streaming degradation emits zero tokens + one final; (c) heartbeat fires after 15s (mock time); (d) unauthenticated call never reaches the event loop
- [ ] 9.3 `tests/Unit/Controller/ChatHealthControllerTest.php` — test: (a) configured provider returns 200 + capabilities; (b) no provider returns 503
- [ ] 9.4 `tests/Unit/Migration/Version*Test.php` — test: migration `changeSchema()` adds `context` column; `down()` removes it
- [ ] 9.5 `tests/Unit/Db/MessageTest.php` — test: `getContext()` deserializes JSON; `setContext()` serializes; null/empty defaults to `[]`

## 10. Quality Gates

- [ ] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — all MUST pass with zero new violations
- [ ] 10.2 Run `composer test:unit` (PHPUnit) — all MUST pass; no skipped tests in new test files
- [ ] 10.3 Fix any pre-existing quality issues encountered in touched files (per project policy — do not defer)
- [ ] 10.4 Verify no forbidden debug helpers (`var_dump`, `die`, `error_log`, `print_r`) are left in new/modified files

## 11. Browser-Side Smoke Tests

- [ ] 11.1 `GET /api/chat/health` from browser (no auth): expect HTTP 200 or 503 (not 401) — confirms `#[PublicPage]` is effective
- [ ] 11.2 `curl -N -X POST http://admin:admin@localhost:8080/index.php/apps/openregister/api/chat/stream -H "Content-Type: application/json" -d '{"agentUuid":"<uuid>","message":"hello"}'` — confirm `text/event-stream` response and at least one `token` or `final` event in the output
- [ ] 11.3 Confirm existing `POST /api/chat/send` still returns a non-streaming JSON response (regression guard)
- [ ] 11.4 Confirm `opencatalogi` and `softwarecatalog` show no regressions on their core workflows after the `McpToolsService` refactor (no broken tool calls or import failures)
