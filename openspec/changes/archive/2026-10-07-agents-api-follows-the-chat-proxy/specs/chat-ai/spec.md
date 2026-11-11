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

The agents API follows the same switch, by design: when hermiq is installed
and the proxy is on, hermiq owns agents. Every `AgentsController` API call
(`GET /api/agents`, `GET /api/agents/tools`, `GET /api/agents/stats`,
`GET|PUT|PATCH|DELETE /api/agents/{id}`, `POST /api/agents`) MUST be
forwarded to hermiq's mirrored route, so the agent tool-limits screen (spec
`agent-tool-governance`) lists hermiq's agents and its edits are written to
hermiq. The system MUST NOT carve the agents API out of the proxy, nor split
reads and writes between the two engines. With the proxy off, or hermiq
absent, OpenRegister MUST answer the agents API from its own agents table.

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

#### Scenario: Hermiq installed and proxy on, the agents API is answered by hermiq
- **GIVEN** `openregister.chat.proxyTo` is unset or set to `hermiq`
- **AND** hermiq is installed and reachable
- **WHEN** the agent tool-limits screen calls `GET /apps/openregister/api/agents`
  and `GET /apps/openregister/api/agents/tools`
- **THEN** OpenRegister MUST forward both calls to `/apps/hermiq/api/agents`
  and `/apps/hermiq/api/agents/tools` and return hermiq's status and body
- **AND** `AgentsController::index()` and `AgentsController::tools()` MUST NOT
  execute
- **AND** the limits screen MUST list hermiq's agents
- **WHEN** the user saves a limit from that screen
  (`PATCH /apps/openregister/api/agents/{id}`)
- **THEN** the call MUST be forwarded to `/apps/hermiq/api/agents/{id}`

#### Scenario: Proxy off, OpenRegister answers the agents API
- **GIVEN** an operator has set `openregister.chat.proxyTo` to `off`
- **AND** hermiq is installed
- **WHEN** the agent tool-limits screen calls `GET /apps/openregister/api/agents`
- **THEN** `AgentsController::index()` MUST execute and return OpenRegister's
  own agents
- **AND** no call to hermiq MUST be made
- **AND** the response MUST carry the three deprecation headers

#### Notes
- OpenRegister no longer ships its own chat page: `src/views/chat`, the
  `ui#chat` route and `UiController::chat()` were removed by
  or-chat-engine-decommission. Users chat through the AI companion widget,
  whose default backend is hermiq.
- `AgentsController::page()` was removed too. The `/agents` screen that
  OpenRegister shows today is the agent tool-limits screen from
  ai-agent-limits-screen (spec `agent-tool-governance`), rendered by the SPA.
  Its calls to `/api/agents` are chat-family API calls, so with the proxy on
  and hermiq installed they are answered by hermiq. This is intended
  (agents-api-follows-the-chat-proxy, build-all decision 92).
- `isHermiqInstalled()` uses `IAppManager::isInstalled('hermiq')`. A 4xx or
  5xx from hermiq is relayed as is; only a transport failure falls back to
  local serving.

