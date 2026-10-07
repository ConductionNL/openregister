# chat-ai Specification (delta)

## MODIFIED Requirements

### Requirement: REQ-007 — OR's chat/agents API carries a deprecation posture and an optional proxy-to-hermiq compat mode

@e2e exclude REST middleware behaviour, covered by PHPUnit (ChatCompatMiddleware and ChatProxyHandler unit suites)

The system MUST keep OpenRegister's `/api/chat/*`, `/api/agents`, and
`/api/conversations` routes registered. No route, table, or engine service is
removed by this requirement. Every response from `ChatController`,
`ChatStreamController`, `ChatHealthController`, `ConversationController`, and
`AgentsController` (excluding any SPA-shell `page()` render method) MUST carry
three deprecation headers:

- `Deprecation`: an RFC 8594-style HTTP-date marking when the deprecation
  posture took effect.
- `Sunset`: an RFC 8594 HTTP-date at least one full release cycle out,
  marking the earliest date a future, separately specified removal change
  may ship.
- `Link: <...>; rel="successor-version"`: pointing at hermiq's mirrored API
  as the successor.

The proxy to hermiq is controlled by the `openregister.chat.proxyTo`
appconfig value (app id `openregister`) and SHALL be on by default: when the
value is unset, the system MUST behave as if it were set to `hermiq`. An
operator MUST be able to opt out, restoring local answering, by setting the
value to anything other than `hermiq` (for example
`occ config:app:set openregister chat.proxyTo --value=off`). While the proxy
is on, the system MUST:

1. Forward every JSON API call on the five controllers above (excluding any
   SPA-shell page render) server-side to hermiq's mirrored route, relaying
   the upstream status code, response body, and `Content-Type` verbatim.
2. Serve the SSE streaming endpoint (`ChatStreamController::stream()`) a 308
   Permanent Redirect (RFC 7538, which preserves the original request method
   and body) to hermiq's mirrored stream route, gated on a reachability
   probe against hermiq succeeding first.
3. Forward only the session `Cookie` header across the loopback call: no
   impersonation, no service-account substitution, matching hydra ADR-034
   Decision 7.
4. Fall back to serving the request locally, unchanged, whenever hermiq is
   not installed, unreachable, or the forward or probe fails at the
   transport level, logged as a warning and never surfaced to the caller as
   an error. The proxy MUST NOT be able to turn a request that would have
   succeeded locally into a failed request.

Deleting OpenRegister's own chat engine (`ChatService`, the `Chat/*`
handlers, the five controllers, the underlying `openregister_{agents,
conversations,messages,feedback}` tables) is out of scope for this
requirement. It is a separate, not yet specified removal change, gated on
hermiq's `MigrateAgentData` repair step no longer needing the legacy tables,
`Service/Chat/StreamYieldChannel` no longer carrying OpenRegister's MCP
streaming, and the LLM plumbing shared with `LlmSettingsController::testChat`
and the vectorization stack being split out.

#### Scenario: Unset proxy config forwards chat to hermiq
- **GIVEN** `openregister.chat.proxyTo` has never been set
- **AND** hermiq is installed and answering
- **WHEN** a client calls `POST /api/chat/send`
- **THEN** OpenRegister MUST forward the request server-side to hermiq's
  mirrored route and return hermiq's response with the three deprecation
  headers

#### Scenario: Proxy off — local serving is byte-identical except for the deprecation headers
- **GIVEN** an operator has set `openregister.chat.proxyTo` to `off`
- **WHEN** a client calls `POST /api/chat/send`
- **THEN** the response body, status code, and every pre-existing header MUST
  be identical to local serving before the compat window
- **AND** the response MUST additionally carry `Deprecation`, `Sunset`, and
  `Link: rel="successor-version"` headers

#### Scenario: Proxy on — a JSON API call is forwarded to hermiq
- **GIVEN** `openregister.chat.proxyTo` is unset or set to `hermiq`, and
  hermiq is installed and reachable
- **WHEN** a client calls `GET /api/chat/history?conversationId=5`
- **THEN** OpenRegister MUST forward the request server-side to
  `/apps/hermiq/api/chat/history?conversationId=5`
- **AND** the response returned to the client MUST carry hermiq's upstream
  status code and body, plus the three deprecation headers
- **AND** OpenRegister's own `ChatController::getHistory()` method body MUST
  NOT execute

#### Scenario: Proxy on — the streaming endpoint redirects rather than relays
- **GIVEN** `openregister.chat.proxyTo` is unset or set to `hermiq`, and
  hermiq answers its chat health probe
- **WHEN** a client calls `POST /api/chat/stream`
- **THEN** OpenRegister MUST respond with HTTP 308 and a `Location` header
  pointing at `/apps/hermiq/api/chat/stream` (including the original query
  string, if any)
- **AND** `ChatStreamController::stream()` MUST NOT execute

#### Scenario: Hermiq not installed — falls back to local serving
- **GIVEN** `openregister.chat.proxyTo` is unset or set to `hermiq`, but the
  hermiq app is not installed or enabled on this instance
- **WHEN** a client calls any chat, agents or conversations endpoint
- **THEN** the request MUST be served locally exactly as if the proxy were
  off, with no error surfaced to the caller

#### Scenario: Hermiq unreachable — falls back to local serving
- **GIVEN** `openregister.chat.proxyTo` is unset or set to `hermiq`, hermiq
  is installed, but the outbound call to hermiq fails at the transport level
  (connection refused, timeout, DNS failure)
- **WHEN** a client calls any chat, agents or conversations endpoint
- **THEN** the request MUST be served locally exactly as if the proxy were
  off
- **AND** the failure MUST be logged at warning level, never returned to the
  caller as an error response

#### Notes
- OpenRegister no longer ships its own chat page: `src/views/chat`, the
  `ui#chat` route and `UiController::chat()` were removed by
  or-chat-engine-decommission. Users chat through the AI companion widget,
  whose default backend is hermiq.
- `AgentsController::page()` was removed too. The `/agents` screen that
  OpenRegister shows today is the agent tool-limits screen from
  ai-agent-limits-screen (spec `agent-tool-governance`), rendered by the SPA.
  Its calls to `/api/agents` are chat-family API calls, so with the proxy on
  and hermiq installed they are answered by hermiq.

## ADDED Requirements

### Requirement: Usage statistics are organisation-scoped, never instance-wide

@e2e exclude REST API, covered by PHPUnit (ChatControllerTest)

`GET /api/chat/stats` SHALL scope the agent, conversation and message counts
(`total_agents`, `total_conversations`, `total_messages`) to the requesting
user's active organisation. Messages carry no organisation column, so they
MUST be counted through the conversations of that organisation. When no
active organisation resolves, the endpoint MUST return zero counts and MUST
NOT fall back to unscoped, instance-wide totals.

#### Scenario: No active organisation returns zeros
- **GIVEN** a user for whom `OrganisationService::getActiveOrganisation()` resolves `null`
- **WHEN** the user calls `GET /api/chat/stats`
- **THEN** `total_agents`, `total_conversations` and `total_messages` are all `0`
- **AND** no unscoped count query is executed

#### Scenario: Counts cover only the active organisation
- **GIVEN** organisations A and B each own agents, conversations and messages
- **AND** the user's active organisation is A
- **WHEN** the user calls `GET /api/chat/stats`
- **THEN** every count in the response covers only organisation A's rows
