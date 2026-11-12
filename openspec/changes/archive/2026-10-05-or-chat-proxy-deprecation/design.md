## Context

Ground truth re-verified at HEAD (2026-07-06), not trusted from the plan text
per the "sonnet fabricates wave-X" project note. Confirmed by reading
`hermiq`'s git history (worktree `hermiq-data-migration`,
`origin/feat/agent-core-port`):

- `hermiq#12` — "feat(schemas): agent-engine schemas — Agent/Conversation/
  Message/Feedback in the hermiq register" — **merged**.
- `hermiq#13` — "feat(engine): agent-core port — chat engine, providers, API,
  SPA, parity harness (ADR-050 §7)" — **merged**. This includes a commit
  literally titled "mirror OR chat/conversation/agent routes + controllers
  onto the in-app engine" and a follow-on "hermiq.engine.enabled feature flag
  + ScheduleService pivot".
- A further `MigrateAgentData` repair step (copying OR's legacy tables into
  hermiq objects) has also landed in that branch.

None of this touched OpenRegister. OR's `ChatService`, the five `Chat`/
`ChatStream`/`ChatHealth`/`Conversation`/`Agents` controllers, and the
`openregister_{agents,conversations,messages,feedback}` tables are all still
present and functionally unchanged at HEAD. This change is the OR-side half
of the migration narrated as Appendix C in
`hermiq/openspec/changes/agent-engine-schemas/design.md` — a compat window,
not a cutover.

## Goals / Non-Goals

**Goals:**
- Every response from OR's chat/agents/conversations API carries deprecation
  metadata pointing at hermiq, with zero behavior change to the response
  body, status code, or existing headers.
- An operator MAY opt into forwarding that traffic to hermiq server-side,
  off by default, fail-safe (any failure falls back to local serving).
- Zero deletions. `ChatService`, the five controllers, the tables, the
  routes — all untouched.

**Non-Goals:**
- Deleting anything (tracked as a future, not-yet-specified removal change —
  see proposal.md).
- Fixing `ChatController::getChatStats()`'s org-filter bug (the multi-tenant
  count leak when no active organisation resolves) — that ships with the
  removal change, per the adaptation hermiq's own `agent-engine-schemas`
  design.md already recorded.
- A byte-relayed SSE proxy. See "Streaming is proxied via redirect, not
  relay" below for why.
- A dismissible in-SPA banner. A code comment plus this openspec proposal is
  the documentation surface for this change — see "SPA banner: descoped" below.
- `chatAppId` parameterization in `nextcloud-vue` (Appendix B, separate repo,
  separate change).

## Decisions

### The short-circuit is an exception, not an inline return

`ChatCompatMiddleware::beforeController()` cannot simply build a Response and
return it — `Middleware::beforeController()`'s return type is `void`; the
AppFramework `Dispatcher` always calls the routed controller method next
regardless of what `beforeController()` returns. The only way to prevent the
controller method from running at all is to throw, which the `Dispatcher`
catches and routes to `MiddlewareDispatcher::afterException()` (verified
against `OC\AppFramework\Http\Dispatcher::dispatch()` at
`lib/private/AppFramework/Http/Dispatcher.php` in the NC server tree this app
runs against — `beforeController()` → `executeController()` is wrapped in a
single try/catch that falls through to `afterException()` on any exception).

So the proxy short-circuit is: `beforeController()` attempts the proxy call;
on success it throws `ChatProxiedResponseException` (a plain internal
exception carrying the already-built `Response`); `afterException()` catches
exactly that exception type and returns its carried response; any other
exception is re-thrown (the default `Middleware::afterException()`
behaviour), so normal error handling for genuine controller-thrown exceptions
is completely unaffected.

**Why this also gets the deprecation headers on the proxied path for free**:
`OC\AppFramework\Http\Dispatcher::dispatch()` calls
`$this->middlewareDispatcher->afterController($controller, $methodName,
$response)` **unconditionally**, using whichever `$response` was resolved —
from the controller method directly, or from `afterException()`. So
`ChatCompatMiddleware::afterController()` decorates the response with the
three deprecation headers exactly once, regardless of whether that response
came from the local controller or the hermiq proxy. One decoration point, two
paths, no duplicated header logic.

### Path rewriting is prefix substitution, not a per-route template map

Hermiq's routes are a declared "route-for-route mirror" of OR's (per
`agent-engine-schemas/design.md`'s own Appendix C text and confirmed by the
`hermiq#13` commit "mirror OR chat/conversation/agent routes + controllers").
Rather than maintaining a per-route-name → hermiq-path lookup table (a
maintenance burden that silently drifts the moment either repo adds a route),
`ChatProxyHandler::rewritePathForHermiq()` does a single prefix substitution:
`IRequest::getPathInfo()` (e.g. `/apps/openregister/api/chat/send`) has its
`/apps/openregister/` prefix replaced with `/apps/hermiq/`. The query string
is extracted separately from `getRequestUri()` and appended verbatim. This is
correct for every route this middleware guards today and requires zero
changes if either app adds a new mirrored route later — the mirror property
is the thing that has to hold, not this change's rewrite logic.

### The JSON body is rebuilt from parameters, not relayed byte-for-byte

`OCP\IRequest` does not expose the original raw request body once NC's own
JSON-body parsing has consumed it into `getParams()` — there is no public
`getRawBody()` on the interface this app's PHPStan/PHPUnit config targets.
Rather than fighting that, `ChatProxyHandler::extractForwardableParams()`
rebuilds the JSON payload from `IRequest::getParams()` (which already merges
GET/POST/route parameters — the same source every controller method reads
from today), stripping NC-internal bookkeeping keys (`_route`,
`requesttoken`) that must never be replayed against another app. This is a
semantic re-serialization, not a byte relay, but since both ends parse the
identical NC request-parameter shape, it round-trips exactly what the local
controller method would have seen.

### Auth flowthrough: only the session cookie crosses the loopback

Per hydra ADR-034 Decision 7 ("session cookie unchanged"), the only header
carried across the server-to-server call is `Cookie` — hermiq's own session
handling authenticates the forwarded call as the same NC user. No
`requesttoken`/CSRF header is forwarded (both apps' mirrored routes are
`@NoCSRFRequired`, matching every OR chat/agents endpoint today — a
server-to-server call was never a CSRF vector to begin with). No
impersonation, no service-account substitution — identical posture to every
other in-process-turned-cross-app OR call this fleet makes (mirrors
`or-tool-registry-facade`'s "no impersonation" decision).

### Streaming is proxied via redirect, not relay

`ChatStreamController::stream()` is SSE (`text/event-stream`), but at HEAD
its v1 implementation is **not actually a token-by-token stream** — its own
docblock says it "executes the synchronous LLM call, then emits a single
`final` event carrying the full text" (degrades gracefully per the
non-streaming-provider clause of hydra ADR-034 §6). Faithfully relaying an
*eventual* real token stream server-side through `IClientService` would need
chunked, unbuffered read-as-it-arrives handling that this repo's outbound
HTTP client wrapper does not provide today, and building that machinery is
its own separable piece of work — disproportionate to a compat-window change
whose entire job is "don't break anything, add a deprecation signal."

Instead, `ChatProxyHandler::buildRedirectResponse()` returns a **308
Permanent Redirect** to hermiq's mirrored stream path. 308 (unlike
301/302/303) is required by RFC 7538 to preserve the request method and body
on redirect — critical here since the endpoint is an authenticated POST
carrying a JSON body. Because both apps live on the same NC instance
(same-origin), the browser's own `fetch()`-based SSE reader (the widget calls
this endpoint via `fetch()`, not `EventSource` — see ADR-034 Decision 6's own
rationale for why) follows the redirect and streams directly from hermiq,
carrying the session cookie automatically. OpenRegister never touches the
event-stream bytes at all in the proxied path — a strictly better outcome
than a byte relay, not a lesser one.

A redirect can't itself detect an unreachable target the way a forwarded
call's own transport exception can (the browser would just get a connection
error following the redirect). So `ChatProxyHandler::probeReachable()` does a
cheap, short-timeout `GET` against hermiq's chat health endpoint first;
`ChatCompatMiddleware` only builds the redirect when that probe succeeds,
falling back to local serving otherwise — the same fail-safe contract as the
JSON-forward path, just gated one step earlier because a redirect has no
"try and see" fallback of its own.

### Deprecation headers on the local-serving SSE path: a documented, honest gap

`ChatStreamController::stream()` always terminates via `exit;` (its own
docblock: "it bypasses the NC response pipeline because the SSE framing must
reach the wire before that pipeline would buffer it"). This means that on the
**local-serving** path (proxy off, or proxy on but hermiq unreachable),
`stream()` never returns control to the `Dispatcher`, so
`ChatCompatMiddleware::afterController()` — which decorates every other
chat-family response — never runs for this one method. This is a pre-existing
architectural property of `stream()`, not something this change introduces.

Rather than leaving the gap silent, the same three headers are added directly
inside `emitSseHeaders()` (the method that already emits the other three raw
`header()` calls for this exact reason — see its docblock). This is
necessarily untested by the touched-tests-only PHPUnit run:
`tests/Unit/Controller/ChatStreamControllerTest.php` already overrides
`emitSseHeaders()` to a no-op in its test double specifically because
`header()` calls fail under PHPUnit ("headers already sent") — a convention
this change did not invent and does not change. On the **proxied** path
(proxy on + hermiq reachable), `beforeController()`'s redirect short-circuit
fires before `stream()` ever runs, so `afterController()` applies normally
there and the deprecation headers on that path *are* covered by
`ChatCompatMiddlewareTest`.

### SPA banner: descoped in favor of headers + this proposal

Per the task brief's own framing, a dismissible in-SPA deprecation banner
"risks scope creep" — a shared floating banner component, i18n strings, a
dismiss-state store entry, and Playwright coverage for a UI element whose
entire audience (per ADR-034's design) is going to stop hitting this SPA at
all once the `chatAppId` flip (Appendix B) ships, is a lot of throwaway
surface for a compat window. The `Deprecation`/`Sunset`/`Link` HTTP headers
are the actual mechanically-enforceable signal (tooling, monitoring, and
API-aware clients can act on them today); this proposal + the `chat-ai` spec
requirement below are the human-readable documentation. If a future
iteration wants the in-SPA banner, `src/views/chat/ChatIndex.vue` (already
noted in ADR-034 as untouched by the shared-primitives migration) is the
right file — not in scope here.

## Risks / Trade-offs

- **Session-cookie-only forwarding assumes hermiq shares the same NC session
  store** — true for any same-instance install (the only topology this
  change supports; hermiq as a genuinely separate NC instance is out of
  scope and not what "compat window" means here).
- **Prefix-substitution path rewriting silently no-ops for any future OR
  route that doesn't start with `/apps/openregister/`** — not possible for
  NC app routes, so this is a theoretical, not practical, risk.
- **The 308 redirect changes the streaming endpoint's failure surface
  slightly**: a client naively treating the 308 as an error (rather than
  following it, which every `fetch()` implementation does by default) would
  see a redirect it didn't expect. Mitigated by the reachability probe (only
  redirect when hermiq actually answers) and by this being strictly opt-in
  (`chat.proxyTo=hermiq`).
- **Deprecation headers on the local-serving SSE path are untested** — see
  the dedicated Decision above. Accepted: it mirrors an existing, unrelated
  gap in this file's own test suite (the `emitSseHeaders()` no-op override),
  not a new one this change introduces.

## Migration

None — purely additive. `chat.proxyTo` defaults to `''` (unset), so a fresh
deploy of this change is behaviourally identical to before it, except for the
three new response headers. An operator opts into the proxy leg explicitly
via `occ config:app:set openregister chat.proxyTo --value=hermiq`; reverting
is `occ config:app:delete openregister chat.proxyTo` (or setting it back to
`''`). `git revert` on this change's commit removes the middleware
registration and the new files with no other side effects — nothing else in
the app depends on `ChatCompatMiddleware` or `ChatProxyHandler` existing.
