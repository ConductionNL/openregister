## 1. StreamYieldChannel value object

- [ ] 1.1 Create `lib/Service/Chat/StreamYieldChannel.php`. Plain PHP, no DI. Four `on{Token,ToolCall,ToolResult,Heartbeat}(callable $fn): void` register methods. Four `emit{Token,ToolCall,ToolResult,Heartbeat}(...args)` invocation methods that iterate the registered callbacks. Multiple callbacks per event allowed. Late registration after first emit is allowed (no replay).
- [ ] 1.2 SPDX + class-level docblock noting the channel is pure forwarding (no buffering, formatting, or filtering — those decisions live in the controller and handler).
- [ ] 1.3 Unit test at `tests/Unit/Service/Chat/StreamYieldChannelTest.php`: register single callback per event type and verify it fires; register two callbacks for the same event and verify both fire in registration order; register after a prior emit and verify the late callback only sees subsequent events.

## 2. ResponseGenerationHandler streaming path

- [ ] 2.1 Add `?StreamYieldChannel $channel = null` as the last optional parameter on `generateResponse()`. Update the docblock + @psalm types.
- [ ] 2.2 Inside `generateResponse()`, when `$channel !== null` AND the active provider's chat instance has a `generateStreamOfText` method (introspect via `method_exists`), call the streaming surface instead of the blocking one. Pass through the channel's `emitToken` as the LLPhant token callback.
- [ ] 2.3 Wrap the streaming call in `try { ... } catch (\LLPhant\Exception\MissingFeatureException $e) { /* fall through to blocking */ }` so providers that report streaming but fail at runtime degrade gracefully.
- [ ] 2.4 Detect tool-call frames coming back from LLPhant during the stream. Buffer the partial frames keyed by LLPhant frame id; on `finish_reason=tool_calls` for a given id, emit ONE `channel->emitToolCall(['toolId' => ..., 'arguments' => ...])`. The orchestrator-side `ToolManagementHandler` already executes tool calls inside the blocking-or-streaming chat surface — wrap each `McpToolsService::callTool` site with `channel?->emitToolResult(['toolId', 'result', 'isError'])` after the call returns.
- [ ] 2.5 No channel → keep the existing blocking call exactly as-is. Add a `// non-streaming fallback` comment marking the branch as load-bearing for `POST /api/chat/send`.
- [ ] 2.6 Unit test with a mocked OpenAIChat: streaming surface invoked 5 times for tokens "Hel", "lo", " ", "wor", "ld" — assert channel `onToken` fires 5 times with those exact payloads. Same mock, return one tool-call frame — assert `onToolCall` fires once with the buffered shape.

## 3. ChatService threads the channel through

- [ ] 3.1 Add `?StreamYieldChannel $channel = null` as the last optional parameter on `processMessage()`. Update docblock + @psalm-return is unaffected (return shape doesn't change).
- [ ] 3.2 Forward `$channel` into `$this->responseHandler->generateResponse(... , channel: $channel)`. No other paths reference `$channel` — it bypasses RAG context retrieval, history building, and the Message persistence write.
- [ ] 3.3 Update the existing `ChatServiceTest.php` to add ONE test that verifies `processMessage(channel: $channel)` forwards the same channel instance to the response handler mock. No new behaviour, regression guard only.

## 4. ChatStreamController consumes the channel

- [ ] 4.1 At the top of `stream()`, construct a `StreamYieldChannel`. Register four callbacks. Each callback wraps `emitSseEvent($eventType, $payload)` with the heartbeat-interleave pre-check from §5.
- [ ] 4.2 Change the `ChatService::processMessage` call site to pass `channel: $channel`. The synchronous return is unchanged — the `final` event still emits after `processMessage` returns.
- [ ] 4.3 The initial post-headers heartbeat that already exists stays — it's still useful to confirm the connection before any LLM token arrives.
- [ ] 4.4 The `final` event payload stays unchanged shape-wise (`messageId`, `conversationUuid`, `fullText`, `context`). `fullText` now potentially duplicates the sum of `token` frames already emitted — that's intentional, the widget uses `fullText` as the persisted-message authoritative value and discards its in-flight `currentText` buffer on receiving `final`.

## 5. Heartbeat ticker (no pcntl)

- [ ] 5.1 Add a protected `now(): float` hook on `ChatStreamController` returning `microtime(true)` in production. Tests override.
- [ ] 5.2 Track `$lastEventAt = $this->now();` in `stream()`. Update after every emit (including the initial heartbeat, the token / tool_call / tool_result, and the final).
- [ ] 5.3 Wrap each channel callback registration with a *pre-emit interleave*: before forwarding the incoming frame to `emitSseEvent`, check `$this->now() - $lastEventAt >= 15.0`; if true, emit a `heartbeat` frame first, reset `$lastEventAt`, then emit the original frame.
- [ ] 5.4 Acceptance: a 30-second stall between tokens triggers exactly one interleaved heartbeat. A 35-second stall triggers two. A stall with no incoming frames at all sees no further heartbeats — the existing initial heartbeat is the only one — and the proxy timeout enforces a ceiling.

## 6. Tests

- [ ] 6.1 `tests/Unit/Service/Chat/StreamYieldChannelTest.php` — see §1.3 above.
- [ ] 6.2 `tests/Unit/Service/Chat/ResponseGenerationHandlerStreamingTest.php` — mock OpenAIChat with `generateStreamOfText`, drive 5 token deltas, assert channel callback hit 5 times. Second test: same mock returns a tool-call frame mid-stream, assert `onToolCall` fires once with the assembled arguments.
- [ ] 6.3 `tests/Unit/Controller/ChatStreamControllerHeartbeatTest.php` — extend the existing TestableChatStreamController pattern; override `now()` to return a controllable timestamp. Send 3 token frames separated by 7s + 8s + 7s of mocked clock time → assert 0 interleaved heartbeats (each gap under 15s). Send 1 token at +20s → assert 1 interleaved heartbeat. Send 1 token at +40s → assert 2 interleaved heartbeats (the second fires because the wall-clock-since-last is still > 15 after the first).
- [ ] 6.4 Manual end-to-end smoke (documented in PR description, not gated in CI): `curl -N -X POST http://admin:admin@localhost:8080/index.php/apps/openregister/api/chat/stream -H 'Content-Type: application/json' -d '{"agentUuid":"<ollama-agent>","message":"count to 10"}'` against a live Ollama agent — confirm multiple `event: token` lines appear interleaved with the response, not batched.
- [ ] 6.5 Regression guard: re-run `tests/Unit/Db/ tests/Unit/Controller/Chat*Test.php tests/Unit/Service/ChatServiceTest.php tests/Unit/Migration/Version1Date20260511130000Test.php` — all 1041+ tests still pass. Plus the new 3 streaming-side test files clean.
- [ ] 6.6 Persona-harness smoke: re-run the 11 scenarios under `tests/mcp-personas/scenarios/` — the LLM-driven OpenBuilt + Decidesk flows still complete (these go through the non-streaming `POST /api/chat/send` path via the harness, so they should be untouched, but verify).

## 7. Quality gates

- [ ] 7.1 PHPCS clean on every new/touched production file (`lib/Service/Chat/StreamYieldChannel.php`, `lib/Service/Chat/ResponseGenerationHandler.php`, `lib/Service/ChatService.php`, `lib/Controller/ChatStreamController.php`).
- [ ] 7.2 PHPStan: zero NEW violations on touched files. Pre-existing baseline complaints on ChatStreamController + ChatService stay as-is (out of scope per project policy — same as the orchestrator slice).
- [ ] 7.3 Composer test:unit — 1041+ tests pass, new test files add ≥10 assertions to the totals.
- [ ] 7.4 No forbidden debug helpers (`var_dump`, `die`, `error_log`, `print_r`) in new/modified files.

## 8. Cross-app regression

- [ ] 8.1 Confirm openbuilt + decidesk persona harness scenarios still pass on a single back-to-back sweep — both apps invoke the chat surface through MCP, not directly, but the persona harness drives the full chat pipeline so it's the right regression target.
- [ ] 8.2 Confirm `opencatalogi` and `softwarecatalog` show no regressions — both apps consume `McpToolsService` but neither registers an `IMcpToolProvider`; the streaming change touches the LLM side, not MCP discovery. Smoke: open `/apps/opencatalogi/` and `/apps/softwarecatalog/`, confirm HTTP 200.
