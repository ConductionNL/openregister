## ADDED Requirements

### Requirement: Token-by-token streaming SSE event

The system SHALL emit one `event: token` SSE frame per LLM token
delta received from a streaming-capable LLPhant provider, written
to the wire as it arrives — NOT buffered until the full response
completes. Each frame's `data:` payload is a JSON object with at
minimum a `delta` field carrying the new token string.

The token event MUST NOT carry the running concatenation of all
tokens so far — only the new delta. Concatenation is the consumer's
responsibility; the widget's `useAiChatStream.js` already appends
to `streamState.currentText`.

The system SHALL detect provider streaming capability at call time
via `method_exists($chatInstance, 'generateStreamOfText')`. When
detection fails — either the method is absent OR the method throws
`LLPhant\Exception\MissingFeatureException` at runtime — the system
MUST fall back to the existing blocking `generateText()` surface and
emit zero `token` frames. This is the "non-streaming-provider
degradation" clause already established by the orchestrator change.

#### Scenario: Streaming-capable provider emits one token frame per delta
- **GIVEN** an authenticated user POSTs `/api/chat/stream` with `{"message":"count 1 to 5"}` against an Ollama agent whose `OllamaChat` exposes `generateStreamOfText`
- **WHEN** the LLM streams the deltas `["1", " 2", " 3", " 4", " 5"]`
- **THEN** the SSE response MUST contain exactly five `event: token` frames in order
- **AND** each frame's data payload MUST be `{"delta":"<the-delta>"}`
- **AND** a single `event: final` MUST follow with `fullText` equal to the concatenation

#### Scenario: Non-streaming provider degrades to one final frame
- **GIVEN** a Fireworks agent whose `FireworksChat` has no `generateStreamOfText` method
- **WHEN** the same request runs
- **THEN** zero `event: token` frames MUST appear
- **AND** exactly one `event: final` frame MUST be emitted with the full response in `fullText`
- **AND** the SSE response MUST otherwise match the contract (`event: heartbeat` immediately after headers, `Content-Type: text/event-stream`, etc.)

#### Scenario: Streaming method throws MissingFeatureException at runtime
- **GIVEN** a provider whose `generateStreamOfText` exists but throws `LLPhant\Exception\MissingFeatureException` on first call (e.g. a future Fireworks integration that ships the method but hasn't enabled it)
- **WHEN** the request runs
- **THEN** the system MUST catch the exception inside the response handler
- **AND** transparently fall back to `generateText()`
- **AND** emit zero `token` frames followed by one `final` frame
- **AND** log the fallback decision at `info` level for ops visibility

### Requirement: Tool-call and tool-result SSE events

The system SHALL emit one `event: tool_call` SSE frame each time the
LLM invokes an MCP tool during a streaming response, and one
`event: tool_result` frame after `McpToolsService::callTool` returns
for that invocation. The frames MUST surface as they happen, not
batched into the `final` frame.

The `tool_call` frame's data payload is a JSON object with
`toolId` (the full namespaced id, e.g. `decidesk.createMeeting`)
and `arguments` (the assembled JSON object the LLM passed).

The `tool_result` frame's data payload is a JSON object with
`toolId` (matching the prior `tool_call`), `result` (the inner
JSON the tool returned), and `isError` (boolean — true when the
tool returned an error envelope).

Partial tool-call argument streams (LLPhant emits the function
name and JSON argument chunks separately) MUST be buffered per
LLPhant frame id and emitted as ONE `tool_call` SSE frame on the
LLM's `finish_reason=tool_calls` signal for that id. Per-argument
streaming is NOT a contract guarantee.

When no streaming surface is available (non-streaming-provider
degradation), the system MUST NOT emit `tool_call` or `tool_result`
frames; the tool invocations still happen but their outcomes
surface only in the `final` frame's `fullText`. The widget's
`CnAiMessageList` accepts that mode.

#### Scenario: Streaming tool call surfaces both frames
- **GIVEN** an Ollama agent + the LLM decides to call `decidesk.createMeeting` with arguments `{"title":"sync","scheduledDate":"2026-07-02T14:00:00+02:00"}`
- **WHEN** the LLM emits the tool-call frame mid-stream
- **THEN** the SSE response MUST contain one `event: tool_call` frame with `{"toolId":"decidesk.createMeeting","arguments":{"title":"sync","scheduledDate":"2026-07-02T14:00:00+02:00"}}`
- **AND** after `McpToolsService::callTool` returns, exactly one `event: tool_result` frame MUST follow with `{"toolId":"decidesk.createMeeting","result":{...},"isError":false}`
- **AND** subsequent `token` frames may interleave as the LLM resumes the response

#### Scenario: Partial tool-call argument stream is buffered to one frame
- **GIVEN** an LLM that streams the tool call as 3 partial frames: `{"name":"decidesk.createMeeting"}`, `{"arguments_delta":"{\"title\":\""}`, `{"arguments_delta":"sync\"}","finish_reason":"tool_calls"}`
- **WHEN** the streaming response is processed
- **THEN** the SSE response MUST contain exactly one `event: tool_call` frame for that invocation (not three)
- **AND** the frame's `arguments` payload MUST be the assembled `{"title":"sync"}`

#### Scenario: Tool error envelope sets isError true
- **GIVEN** an MCP tool that returns `{"isError":true,"error":"forbidden","message":"You are not signed in."}`
- **WHEN** that tool is invoked during a streaming response
- **THEN** the `tool_result` SSE frame's payload MUST have `isError:true` AND `result` MUST contain the full inner envelope verbatim

### Requirement: Periodic heartbeat during the in-flight call

The system SHALL emit `event: heartbeat` frames at most 15 seconds
apart for the duration of any `/api/chat/stream` response, measured
from the most recently emitted event of any type (including the
initial post-headers heartbeat and the eventual `final` or `error`).

The heartbeat interleaves with normal frames: it MUST appear
immediately before the next outgoing frame (`token`, `tool_call`,
`tool_result`, or `final`) when the wall-clock interval since the
last event exceeds 15.0 seconds. It MUST reset the wall-clock and
not fire again until another 15s elapses.

In the degenerate case where the LLM call produces no yield points
at all (the synchronous-fallback path on a non-streaming provider),
the system MAY NOT emit periodic heartbeats — the initial post-
headers heartbeat is the only one, and the proxy timeout enforces
the connection-lifetime ceiling. The widget treats this as a
contract-conformant degraded mode.

The 15-second interval is fixed per the design D3; no operator-
configurable override is required by this change.

#### Scenario: 35-second LLM call emits at least two interleaved heartbeats
- **GIVEN** an Ollama agent and a prompt that causes the LLM to take 35s before emitting any token
- **WHEN** the system streams the response
- **THEN** between the initial post-headers heartbeat and the first `token` frame, at least two additional `heartbeat` frames MUST be emitted (one around t=15s, one around t=30s)
- **AND** each subsequent heartbeat MUST be emitted strictly before the next non-heartbeat frame

#### Scenario: Sub-15s tokens never trigger an interleaved heartbeat
- **GIVEN** a steady token stream with each delta arriving within 5s of the previous one
- **WHEN** 10 such tokens stream
- **THEN** zero additional heartbeat frames MUST appear between them (the initial post-headers heartbeat is the only one)
- **AND** the SSE response ends with `final` with no trailing heartbeat

#### Scenario: Synchronous-fallback path emits only the initial heartbeat
- **GIVEN** a non-streaming provider on a request that takes 20s
- **WHEN** the system processes the request
- **THEN** the SSE response MUST contain exactly one `heartbeat` frame (the initial one immediately after headers)
- **AND** one `final` frame
- **AND** zero additional heartbeats during the synchronous wait — the degradation is contract-conformant
