---
kind: code
depends_on:
  - ai-chat-companion-orchestrator
---

## Why

The `ai-chat-companion-orchestrator` change shipped the SSE plumbing
(`POST /api/chat/stream`, `text/event-stream` response, the `final` and
`error` event types, the start-of-call heartbeat) and proved the full
chat → AI → MCP → tool loop works end-to-end. What it did not ship is
the *streaming* half of the contract: the LLM call inside
`ResponseGenerationHandler::generateResponse()` is synchronous, so the
controller emits zero `token` events and one `final` event for every
exchange. That's contract-compliant (the orchestrator's spec explicitly
allows the "non-streaming-provider degradation" envelope) but it leaves
three pieces of the original specification permanently inert:

- **No token-by-token rendering** in the widget. A 30-second response
  appears as a 30-second blank wait followed by a full bubble. The
  "Thinking..." indicator shipped via `feat/cn-ai-thinking-indicator`
  papers over this for short calls; longer prompts still feel broken.
- **No `tool_call` / `tool_result` SSE events.** When the LLM invokes
  an MCP tool (e.g. `decidesk.createMeeting`) the user sees nothing
  until the whole interaction completes. The widget already has
  `tool_calls` rendering logic in `CnAiMessageList`; the controller
  just doesn't feed it.
- **No periodic 15-second heartbeat during the in-flight call.** The
  start-of-call heartbeat keeps the connection alive past the initial
  handshake, but proxy timeouts (Apache default 300s, nginx default
  60s) will still drop long calls. For cold-load Ollama (10-30s) this
  is fine; for big-prompt RAG calls or external providers under load
  it is not.

The orchestrator's spec recorded these as known gaps. This change is
the follow-up that fills them.

## What Changes

- **`ResponseGenerationHandler` gains a streaming path.** When the
  configured provider supports it (OpenAI, Ollama via the existing
  LLPhant integrations) the handler invokes
  `$chat->generateStreamOfText(...)` / `$chat->generateStreamOfChat(...)`
  with a token callback. The callback yields each delta + every
  tool-call/tool-result triple back to the caller through a new
  `StreamYieldChannel` value object (a thin wrapper around a closure
  list — the controller registers callbacks, the handler fires them).
  For providers that don't support streaming the handler keeps the
  existing blocking call and the channel never fires `onToken` —
  zero behaviour change relative to today.

- **`ChatStreamController` consumes the channel.** Before calling
  `ChatService::processMessage` the controller registers four
  callbacks on the channel: `onToken`, `onToolCall`, `onToolResult`,
  `onHeartbeat`. Each callback `emitSseEvent`s the matching SSE frame
  and updates `$lastEventAt`. A wall-clock check between yields emits
  a `heartbeat` frame whenever 15 seconds have passed since the last
  event — satisfying §6 of the orchestrator.

- **`ChatService::processMessage` plumbs the channel through.** New
  optional argument `?StreamYieldChannel $channel = null`. When non-null
  it's forwarded to `ResponseGenerationHandler::generateResponse()`.
  Existing callers (`ChatController::sendMessage` non-streaming path,
  background workers) keep working unchanged because the default is
  null.

- **Frontend already supports this.** `useAiChatStream.js` already
  appends incoming `token` events to `streamState.currentText` and
  surfaces `tool_call` / `tool_result` to `streamState.messages[].toolCalls`.
  `CnAiMessageList` renders the streaming partial via the existing
  `currentText` slot and the tool entries via the existing
  `message.toolCalls` loop. No widget change required.

- **Heartbeat correctness in tests.** A new
  `ChatStreamControllerHeartbeatTest` uses a mock LLM that "takes"
  35s (driven via a fake `microtime` injection on a protected
  `now(): float` hook) and asserts that at least two `heartbeat`
  frames appear between the initial one and the `final`.

## Non-Goals

- **Background-worker LLM execution.** A separate-process or
  pcntl-alarm-based ticker would let us emit heartbeats during the
  synchronous fallback path too. This change keeps the LLM call in
  the request worker and accepts that non-streaming providers will
  still ride the initial heartbeat + proxy timeout. Tracked
  separately if it ever becomes an operational concern.

- **Replacing the synchronous fallback.** Non-streaming providers
  (and the in-process Fireworks blocking call) remain supported via
  the existing degradation clause. Switching them to background
  workers is out of scope.

- **Cancel / abort semantics.** EventSource doesn't have a clean
  client-initiated cancel; a Phase-2 follow-up would add a
  `DELETE /api/chat/stream/{conversationUuid}` endpoint and have the
  channel poll for cancellation between tokens. Out of scope here.

- **Token usage accounting.** LLPhant exposes per-call usage on the
  blocking path; the streaming path needs a separate aggregation
  step. Out of scope; tracked alongside the existing observability
  capability.

- **MCP tool argument streaming.** When LLPhant emits a partial
  tool-call (only the function name, arguments still streaming) we
  buffer until the call is complete before emitting one `tool_call`
  frame. Per-argument streaming is not required by the contract and
  the widget doesn't render it.
