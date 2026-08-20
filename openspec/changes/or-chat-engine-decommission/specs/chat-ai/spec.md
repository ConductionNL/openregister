# chat-ai Specification (delta)

## Purpose

Decommission OpenRegister's chat *product* surface now that Hermiq is the agent
engine's canonical home (hydra ADR-034 Amendment 2026-07-05): the #305 compat
proxy becomes the default answerer, OR's own chat/agents SPA is removed, and the
`getChatStats` multi-tenant leak is fixed in the remaining fallback engine. Full
engine deletion (plan §7.4 step 7) stays a follow-up change.

## MODIFIED Requirements

### Requirement: Chat compat window proxies to Hermiq by default

@e2e exclude REST middleware behaviour — covered by PHPUnit (ChatCompatMiddleware/ChatProxyHandler unit suites)

OR's chat, conversation, and agents API routes SHALL remain registered and
SHALL, by default, be answered by Hermiq through the compat middleware
introduced in #305: when `openregister`.`chat.proxyTo` is **unset**, the proxy
MUST behave as if it were set to `hermiq`. An operator MUST be able to opt out
(restoring local-engine answering) by setting `chat.proxyTo` to any value other
than `hermiq` (e.g. `off`). The #305 non-destructive failure mode is preserved:
when hermiq is not installed, unreachable, or returns a proxy-leg error, the
request MUST fall through to OR's local engine, and every response MUST carry
the deprecation headers naming hermiq as the successor.

#### Scenario: Unset proxy config forwards chat to hermiq

- **GIVEN** a deployment where `openregister`.`chat.proxyTo` has never been set
- **AND** hermiq is installed and answering
- **WHEN** a client POSTs to `/apps/openregister/api/chat/send`
- **THEN** the request is forwarded server-side to hermiq's mirrored route and
  hermiq's response is returned with the deprecation headers

#### Scenario: Operator opts out of the proxy

- **GIVEN** an operator has run `occ config:app:set openregister chat.proxyTo --value=off`
- **WHEN** a client POSTs to `/apps/openregister/api/chat/send`
- **THEN** OR's local engine answers exactly as before #305, still carrying the
  deprecation headers

#### Scenario: Hermiq unreachable falls through non-destructively

- **GIVEN** default (unset) proxy config and hermiq not installed
- **WHEN** a client calls any proxied chat route
- **THEN** OR's local engine answers the request (no 5xx caused by the proxy leg)

### Requirement: Usage statistics are organisation-scoped, never instance-wide

@e2e exclude REST API — covered by PHPUnit

`GET /api/chat/stats` MUST scope agent, conversation, message, and feedback
counts to the requesting user's active organisation. When no active
organisation resolves, the endpoint MUST return zero counts — it MUST NOT fall
back to unscoped, instance-wide totals (multi-tenant information leak).

#### Scenario: No active organisation returns zeros

- **GIVEN** a user for whom `OrganisationService::getActiveOrganisation()` resolves `null`
- **WHEN** the user calls `GET /api/chat/stats`
- **THEN** every count in the response is `0`
- **AND** no unscoped `COUNT(*)` over another organisation's rows is executed

## REMOVED Requirements

### Requirement: OpenRegister ships its own AI Chat and Agents UI

**Reason**: Hermiq is the agent engine's canonical home; chat happens through
the fleet-wide AI companion widget (default backend `hermiq` since nc-vue
`chat-appid-default-flip`) and agents are managed in hermiq's Agent management
UI. Keeping a second, OR-local chat/agents UI would write to the legacy engine
and fork conversation history across two backends during the compat window.

**Migration**: users open the AI companion (any fleet app) for chat; agent
administration moves to hermiq's `/apps/hermiq` Agents UI. Legacy OR
conversation data reaches hermiq via hermiq's `MigrateAgentData` repair step
(`agent-data-migration`). The OR SPA pages `/chat` and `/agents`, their
navigation entries, sidebars, modals, stores, and entities are removed;
`ui#chat` is unrouted.
