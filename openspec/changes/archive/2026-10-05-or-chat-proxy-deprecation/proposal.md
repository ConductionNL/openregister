---
kind: code
depends_on: ["ConductionNL/hermiq#12", "ConductionNL/hermiq#13"]
---

## Why

Per the amended hermiq ADR-001 (Workstream E, `SPECTR-NEXTCLOUD-PLAN.md` §7.4
step 6) and hydra ADR-034's amendment ("Hermiq is the sole orchestrator"),
Hermiq now owns its own copy of the agent-core chat engine — merged in
`hermiq#12` (schemas: `Agent`/`Conversation`/`Message`/`Feedback` in the
`hermiq` register) and `hermiq#13` (the engine port: `ChatService`, the
`Chat/*` handlers, the LLM provider layer, the `Chat`/`ChatStream`/
`ChatHealth`/`Conversation`/`Agents` controllers, mirrored routes under
`/apps/hermiq/api/...`). Both are merged.

OpenRegister's **own** original chat engine (`ChatService` + `Chat/*` +
`Chat`/`ChatStream`/`ChatHealth`/`Conversation`/`Agents` controllers +
`/api/chat/*` + `/api/agents` + `/api/conversations` routes) was **copied**
to hermiq, not deleted. Re-verified at HEAD (2026-07-06): both engines exist
today, side by side. Consuming apps and any bookmarked/cached URLs may still
hit OR's routes for at least one release after Hermiq's engine goes live —
per plan §7.4 step 6, OR keeps a compat window rather than a hard cutover.

This change stands up that compat window: OR's chat/agents API keeps working
exactly as it does today (nothing is removed), but every response now
announces the deprecation and, for operators who want to migrate traffic
early, an **optional** proxy-to-hermiq mode.

## What Changes

- **Deprecation metadata on every chat-family response.** A new
  `ChatCompatMiddleware` decorates every response from `ChatController`,
  `ChatStreamController`, `ChatHealthController`, `ConversationController`,
  and `AgentsController` (excluding the `AgentsController::page()` SPA-shell
  render) with three RFC 8594-style headers:
  - `Deprecation: Mon, 06 Jul 2026 00:00:00 GMT` — the date this posture took
    effect.
  - `Sunset: Wed, 06 Jan 2027 00:00:00 GMT` — the earliest date the
    *following* removal change may ship (≥1 full release cycle out).
  - `Link: </apps/hermiq/api/chat>; rel="successor-version"` — points
    consumers at hermiq's mirrored API.

  This leg is unconditional and non-invasive: responses are byte-for-byte
  identical to today except for the three added headers.

- **Optional proxy-to-hermiq mode**, OFF by default. A new appconfig key
  `openregister.chat.proxyTo` (empty = serve locally, exactly as today).
  When an operator sets it to `hermiq`:
  - JSON API calls (`ChatController`, `ChatHealthController`,
    `ConversationController`, `AgentsController`) are forwarded server-side
    to hermiq's mirrored route via `OCP\Http\Client\IClientService`, and the
    upstream status/body/`Content-Type` are relayed back verbatim.
  - The SSE streaming endpoint (`ChatStreamController::stream()`) is instead
    served a **308 Permanent Redirect** to hermiq's mirrored stream route —
    preserving the POST method + body (RFC 7538) and letting the browser's
    own `fetch()`-based SSE reader stream directly from hermiq, rather than
    OpenRegister relaying event-stream bytes itself.
  - **Fail-safe by construction**: hermiq not installed, unreachable, or any
    transport-level failure falls back to serving the request locally —
    logged as a warning, never surfaced as an error to the caller. The proxy
    can never make a working request fail.
  - No auth flowthrough drama: only the session `Cookie` header carries
    across the loopback call (same-instance, same-session), matching hydra
    ADR-034 Decision 7 ("session cookie unchanged").

- **Implementation mechanism**: a single new `OCP\AppFramework\Middleware`
  (`ChatCompatMiddleware`) plus one new service (`ChatProxyHandler`, in
  `Service\Chat`, next to the existing `Chat\*Handler` classes). The
  middleware's `beforeController()` attempts the proxy and, on success,
  throws an internal `ChatProxiedResponseException` carrying the built
  response; `afterException()` catches it and returns that response;
  `afterController()` decorates every chat-family response — proxied or
  local — with the three deprecation headers from one place. No controller
  method's body is touched, except `ChatStreamController::emitSseHeaders()`
  gains the same three headers as a best-effort addition for the
  local-serving path (that method bypasses the Response/middleware pipeline
  entirely via `exit;`, so `afterController()` never reaches it when the
  proxy is off — see design.md).

## Explicitly Out of Scope (future removal change)

This change does **not** delete anything. `ChatService`, every `Chat/*`
handler, the five controllers, the underlying `openregister_{agents,
conversations,messages,feedback}` tables, and the routes themselves all keep
working exactly as before. Deleting OR's own engine — plus fixing the
`getChatStats` org-filter bug (multi-tenant leak, tracked separately) — is a
**separate, not-yet-specified future change**, gated on:
1. This compat window shipping and at least one full release cycle passing
   (matching the `Sunset` header above).
2. The nextcloud-vue `chatAppId` flip (Appendix B of
   `hermiq/openspec/changes/agent-engine-schemas/design.md`) landing, so the
   shared `CnAiCompanion` widget itself is pointed at hermiq by default.
3. The data migration (`agent-data-migration`, tracked in hermiq) copying any
   remaining live OR-side conversation history into hermiq's register objects
   before the OR tables are dropped.

## MCP coverage

No MCP surface — this change touches no `IMcpToolProvider` implementation and
adds no new user-actionable surface. It is a deprecation/compat mechanism over
an existing HTTP API.

## Impact

- **PHP (new)**: `lib/Middleware/ChatCompatMiddleware.php`,
  `lib/Middleware/Exception/ChatProxiedResponseException.php`,
  `lib/Service/Chat/ChatProxyHandler.php`.
- **PHP (changed)**: `lib/AppInfo/Application.php` (one
  `registerMiddleware()` call), `lib/Controller/ChatStreamController.php`
  (three added `header()` calls in `emitSseHeaders()` — see design.md for why
  this is the one place the middleware's header-decoration can't reach).
- **Config**: new appconfig key `openregister.chat.proxyTo` (app id
  `openregister`), default `''`. No migration — appconfig keys need none.
- **Routes**: none added, none changed, none removed. `appinfo/routes.php` is
  untouched.
- **Tests**: `tests/Unit/Service/Chat/ChatProxyHandlerTest.php`,
  `tests/Unit/Middleware/ChatCompatMiddlewareTest.php`. Existing
  `tests/Unit/Controller/ChatStreamControllerTest.php` +
  `ChatStreamControllerHeartbeatTest.php` re-verified green (no regression).
- **Docs**: this proposal + design.md carry the deprecation timeline; no
  separate changelog mechanism exists in this repo today, so the `Sunset`/
  `Deprecation` headers themselves are the operator-facing signal, backed by
  `openspec/specs/chat-ai/spec.md`'s new requirement.
