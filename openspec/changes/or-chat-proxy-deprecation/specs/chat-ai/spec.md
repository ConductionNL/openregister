## ADDED Requirements

### Requirement: REQ-007 — OR's chat/agents API carries a deprecation posture and an optional proxy-to-hermiq compat mode

The system MUST keep OpenRegister's `/api/chat/*`, `/api/agents`, and
`/api/conversations` routes working exactly as they do today — no route,
controller, table, or service is removed by this requirement — now that
hermiq owns its own copy of the agent-core chat engine (merged: `hermiq#12`
schemas, `hermiq#13` engine port + mirrored routes under
`/apps/hermiq/api/...`). Every response from `ChatController`,
`ChatStreamController`, `ChatHealthController`, `ConversationController`, and
`AgentsController` (excluding `AgentsController::page()`, which renders the
SPA shell rather than API data) MUST carry three deprecation headers:

- `Deprecation` — an RFC 8594-style HTTP-date marking when the deprecation
  posture took effect.
- `Sunset` — an RFC 8594 HTTP-date at least one full release cycle out,
  marking the earliest date a *future, separately-specified* removal change
  may ship.
- `Link: <...>; rel="successor-version"` — pointing at hermiq's mirrored API
  as the successor.

The system MUST also support an **optional**, operator-controlled proxy mode
via the `openregister.chat.proxyTo` appconfig value (app id `openregister`).
The default (empty string) MUST preserve today's behavior exactly — every
request served locally, byte-identical except for the three headers above.
When set to `hermiq`, the system MUST:

1. Forward every JSON API call on the five controllers above (excluding the
   SPA-shell page render) server-side to hermiq's mirrored route, relaying
   the upstream status code, response body, and `Content-Type` verbatim.
2. Serve the SSE streaming endpoint (`ChatStreamController::stream()`) a 308
   Permanent Redirect (RFC 7538 — preserves the original request method and
   body) to hermiq's mirrored stream route, gated on a reachability probe
   against hermiq succeeding first.
3. Forward only the session `Cookie` header across the loopback call — no
   impersonation, no service-account substitution, matching hydra ADR-034
   Decision 7.
4. **Fall back to serving the request locally, unchanged, whenever hermiq is
   not installed, unreachable, or the forward/probe fails at the transport
   level** — logged as a warning, never surfaced to the caller as an error.
   The proxy mode MUST NOT be able to turn a request that would have
   succeeded locally into a failed request.

Deleting OpenRegister's own chat engine (`ChatService`, the `Chat/*`
handlers, the five controllers, the underlying `openregister_{agents,
conversations,messages,feedback}` tables) is explicitly **out of scope** for
this requirement — it is a separate, not-yet-specified future removal change,
gated on this compat window running for at least one full release cycle, the
`chatAppId` flip landing in `nextcloud-vue` (parameterizing which app's
routes the shared `CnAiCompanion` widget targets), and the agent-data
migration completing.

#### Scenario: Proxy off — local serving is byte-identical except for the deprecation headers
- **GIVEN** `openregister.chat.proxyTo` is unset (the default)
- **WHEN** a client calls `POST /api/chat/send`
- **THEN** the response body, status code, and every pre-existing header MUST
  be identical to before this change
- **AND** the response MUST additionally carry `Deprecation`, `Sunset`, and
  `Link: rel="successor-version"` headers

#### Scenario: Proxy on — a JSON API call is forwarded to hermiq
- **GIVEN** `openregister.chat.proxyTo` is set to `hermiq` and hermiq is
  installed and reachable
- **WHEN** a client calls `GET /api/chat/history?conversationId=5`
- **THEN** OpenRegister MUST forward the request server-side to
  `/apps/hermiq/api/chat/history?conversationId=5`
- **AND** the response returned to the client MUST carry hermiq's upstream
  status code and body, plus the three deprecation headers
- **AND** OpenRegister's own `ChatController::getHistory()` method body MUST
  NOT execute

#### Scenario: Proxy on — the streaming endpoint redirects rather than relays
- **GIVEN** `openregister.chat.proxyTo` is set to `hermiq` and hermiq answers
  its chat health probe
- **WHEN** a client calls `POST /api/chat/stream`
- **THEN** OpenRegister MUST respond with HTTP 308 and a `Location` header
  pointing at `/apps/hermiq/api/chat/stream` (including the original query
  string, if any)
- **AND** `ChatStreamController::stream()` MUST NOT execute

#### Scenario: Hermiq not installed — falls back to local serving
- **GIVEN** `openregister.chat.proxyTo` is set to `hermiq` but the hermiq app
  is not installed/enabled on this instance
- **WHEN** a client calls any chat/agents/conversations endpoint
- **THEN** the request MUST be served locally exactly as if the proxy were
  off, with no error surfaced to the caller

#### Scenario: Hermiq unreachable — falls back to local serving
- **GIVEN** `openregister.chat.proxyTo` is set to `hermiq`, hermiq is
  installed, but the outbound call to hermiq fails at the transport level
  (connection refused, timeout, DNS failure)
- **WHEN** a client calls any chat/agents/conversations endpoint
- **THEN** the request MUST be served locally exactly as if the proxy were
  off
- **AND** the failure MUST be logged at warning level, never returned to the
  caller as an error response

#### Notes
- This requirement's out-of-scope removal is tracked as a future change, not
  a follow-up issue in this repo yet — see this change's proposal.md and
  design.md for the full gating sequence.
- `AgentsController::page()` (the `/agents` SPA-shell route) is excluded from
  the proxy leg — the shell's static assets ship with OpenRegister during the
  compat window regardless of `chat.proxyTo`; only the `chatAppId`
  parameterization in `nextcloud-vue` (a separate, not-yet-materialized
  change) changes which backend the widget itself talks to.
