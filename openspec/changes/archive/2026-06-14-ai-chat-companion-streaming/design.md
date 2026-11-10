## Context

Parent capability: `openspec/specs/chat-ai/spec.md` (REQ-001 through
the existing SSE requirements set landed by `ai-chat-companion-orchestrator`).

Parent change: `openspec/changes/ai-chat-companion-orchestrator/` —
this is the explicit follow-up named in that change's `tasks.md`
deferral notes (§1, §5.3, §6.1-6.3, §9.2 partial).

Existing landed surface (do not re-spec):
- `POST /api/chat/stream` route + `ChatStreamController` shell
- `GET /api/chat/health` (`#[PublicPage]`, returns 200/503 + capabilities)
- `Message.context` column + getter/setter + persistence path
- `IMcpToolProvider` + 3 built-in providers + per-app discovery
- `McpToolsService::addProvider` idempotent on `getAppId()`

## Goals

- Token-by-token streaming for any provider whose LLPhant integration
  exposes a streaming surface (OpenAI, Ollama at minimum; Fireworks
  if/when LLPhant adds it).
- `tool_call` and `tool_result` SSE frames surfaced as soon as the LLM
  invokes / receives a tool result, not batched into the `final`.
- Periodic `heartbeat` frames every 15s during long calls — no proxy
  drop on a 60-second LLM exchange.
- Zero behaviour change on the non-streaming fallback path (providers
  without streaming support, the existing `POST /api/chat/send`).
- A unit test that proves the heartbeat ticker fires at least twice
  during a mocked 35s LLM call without spawning a real timer.

## Non-Goals

- Background-worker execution of the LLM call (would unblock
  heartbeats for non-streaming providers but doubles the deployment
  surface — accepted as a Phase-2 if needed).
- Per-argument tool-call streaming (buffer until the call is
  complete; one `tool_call` frame per invocation).
- Cancel / abort endpoint.
- Token usage accounting on the streaming path.

## Architecture

```
ChatStreamController::stream()
  │
  ├── createYieldChannel() ───────────────────────► StreamYieldChannel
  │                                                  ├ onToken(closure)
  │                                                  ├ onToolCall(closure)
  │                                                  ├ onToolResult(closure)
  │                                                  └ onHeartbeat(closure)
  │
  ├── register callbacks
  │     onToken     → emitSseEvent('token', ['delta' => $t])
  │     onToolCall  → emitSseEvent('tool_call', ['toolId', 'arguments'])
  │     onToolResult→ emitSseEvent('tool_result', ['toolId', 'result', 'isError'])
  │     onHeartbeat → emitSseEvent('heartbeat', ['ts' => gmdate('c')])
  │                  (each callback also resets $lastEventAt)
  │
  ├── start wall-clock ticker (see D3 below)
  │
  └── ChatService::processMessage(..., channel: $channel)
        │
        └── ResponseGenerationHandler::generateResponse(..., channel: $channel)
              │
              ├── if provider supports streaming:
              │      $chat->generateStreamOfText($prompt,
              │           onToken: fn($t) => $channel->emitToken($t),
              │           onToolCall: fn($call) => $channel->emitToolCall($call),
              │           onToolResult: fn($r) => $channel->emitToolResult($r))
              │
              └── else: blocking generateText() — channel never fires
                        (de-facto current behaviour, contract-compliant
                         via the orchestrator's degradation clause)
```

### D1 — StreamYieldChannel value object

Lives at `lib/Service/Chat/StreamYieldChannel.php`. Plain PHP object,
no DI dependencies. Four `on*(callable)` registration methods and four
`emit*(...)` methods. Multiple callbacks per event type allowed
(future-proofs for telemetry / logging interceptors). The channel is
*pure forwarding* — it does not buffer, format, or filter events;
those decisions belong to the controller (for SSE shape) and the
handler (for buffering tool-call argument streams).

The channel is *request-scoped*. ChatService instantiates it as null
when not provided; ChatStreamController constructs one and passes it
through. Background workers, the non-streaming controller, and unit
tests of the service can all keep passing null without any change.

### D2 — Tool-call buffering inside ResponseGenerationHandler

LLPhant's streaming surface emits partial tool-call frames as the
function name + each argument JSON chunk arrives. We buffer per
invocation in a local array keyed by the LLPhant frame id, emitting
one `tool_call` SSE frame only when the LLM signals
`finish_reason=tool_calls` for that invocation. Same shape for
`tool_result`: buffer until McpToolsService::callTool returns, emit
once. This keeps the SSE channel's frame count proportional to
user-visible actions, not LLPhant internals.

### D3 — Heartbeat ticker without pcntl

The proxy-survival problem is: between yield points the request can
sit idle for >15s (slow LLM tokens, slow tool execution). We can't
use pcntl alarms reliably under PHP-FPM. The pattern:

1. ChatStreamController exposes a protected `now(): float` hook
   returning `microtime(true)` in production; tests override.
2. After every SSE emit, `$lastEventAt = $this->now();`.
3. The channel's `onToken`, `onToolCall`, `onToolResult` callbacks
   are wrapped with a *pre-emit interleave*: before forwarding the
   incoming frame to `emitSseEvent`, check
   `$this->now() - $lastEventAt >= 15.0`; if true, emit a `heartbeat`
   frame first, reset `$lastEventAt`, then emit the original frame.

This satisfies §6 *exactly* — the heartbeat lands "every 15s with no
other event" because each token / tool_call / tool_result *is* an
event that resets the clock. A 30s stall between tokens triggers
exactly one interleaved heartbeat. A 35s stall triggers two.

For the *complete absence* of yield points (provider supports
streaming but the call simply hangs), the controller falls back to
the existing initial heartbeat — no further beats land. That's the
same behaviour as today and an acceptable degradation: the proxy
will time the call out and the user sees an `error` frame.

### D4 — Provider-capability detection

`SettingsService::getLLMSettingsOnly()` already exposes
`chatProvider`. We add a lookup table inside ResponseGenerationHandler:

| provider  | streaming surface                              |
|-----------|-----------------------------------------------|
| openai    | `generateStreamOfText` + `addFunction` exists |
| ollama    | `generateStreamOfText` exists                 |
| fireworks | none today — falls back to blocking           |

Detection is *attempt-then-fallback*: try the streaming surface
inside a try/catch on `LLPhant\Exception\MissingFeatureException`;
on miss, fall through to the blocking call. The table above is
informational — the runtime check is the authority.

### D5 — Backwards compatibility

- `ResponseGenerationHandler::generateResponse` signature gains an
  optional `?StreamYieldChannel $channel = null` last parameter.
  Existing callers pass nothing; behaviour unchanged.
- `ChatService::processMessage` gains the same optional parameter.
  Same compatibility.
- `Message.context`, `IMcpToolProvider`, `McpToolsService`,
  `ChatHealthController`, `ChatStreamController` shell — all
  unchanged.
- The non-streaming `POST /api/chat/send` path never touches the
  channel — strict regression guard via §6 of tasks.md.

### D6 — Test strategy

- Unit-test the channel itself (register, emit, multiple callbacks
  per event, late registration after first emit — all standard).
- Unit-test ResponseGenerationHandler's streaming path with a
  mocked `OpenAIChat` whose `generateStreamOfText` invokes the
  token callback 5 times — assert the channel fires `onToken` 5
  times.
- Unit-test ChatStreamController's heartbeat ticker with the
  `now()` hook overridden to a controllable clock — drive a fake
  35s stall, assert ≥2 heartbeat frames captured.
- End-to-end smoke against a live Ollama via curl: send a prompt,
  watch `event: token` lines appear in the response. Documented as
  a manual verification step (browser-side smokes in tasks §6.4).

## Standards & References

- Parent spec: `openspec/specs/chat-ai/spec.md`
- Parent change: `openspec/changes/ai-chat-companion-orchestrator/`
- LLPhant streaming API:
  - `LLPhant\Chat\OpenAIChat::generateStreamOfText`
  - `LLPhant\Chat\OllamaChat::generateStreamOfText`
- SSE event-stream encoding: <https://html.spec.whatwg.org/multipage/server-sent-events.html>
- MCP `tools/call` envelope (preserved on `tool_result`): existing
  `McpToolsService::callTool` return shape.

## Production notes

- **Single LLM provider per call.** The streaming path picks the
  provider at the top of `generateResponse` and commits — no
  mid-call provider swap. Same as today.
- **Memory pressure.** Streaming buffers stay constant-size per
  invocation (one in-flight tool-call buffer at a time per LLPhant
  contract). No risk of unbounded growth even on a 10-minute call.
- **Logging.** Every emitted SSE frame is *not* logged (would
  multiply log volume by ~10× for a typical 50-token response).
  Only the start-of-call + end-of-call summary is logged, matching
  the existing controller behaviour.
- **Browser proxy / dev-server.** `X-Accel-Buffering: no` is
  already set by the orchestrator. The nginx default proxy_read_timeout
  of 60s is well under the 15s heartbeat interval, so any properly-
  configured deployment survives. Apache mod_proxy with the default
  300s ProxyTimeout is also fine.
