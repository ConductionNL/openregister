## 1. Deprecation headers

- [x] 1.1 Create `lib/Middleware/ChatCompatMiddleware.php` — `afterController()`
  adds `Deprecation`/`Sunset`/`Link` headers on every response from
  `ChatController`, `ChatStreamController`, `ChatHealthController`,
  `ConversationController`, `AgentsController` (membership via `instanceof`
  so PHPUnit mocks match).
  - Acceptance: non-chat-family controller responses pass through
    byte-identical (no headers added).
- [x] 1.2 Add the same three headers directly in
  `ChatStreamController::emitSseHeaders()` for the local-serving path, since
  `stream()` always `exit;`s and bypasses the Response/middleware pipeline —
  see design.md's dedicated decision on why this is the one exception.
- [x] 1.3 Register `ChatCompatMiddleware` in `lib/AppInfo/Application.php` via
  `$context->registerMiddleware(...)`.

## 2. Optional proxy-to-hermiq

- [x] 2.1 Create `lib/Service/Chat/ChatProxyHandler.php` — `isProxyConfigured()`
  reads the `openregister.chat.proxyTo` appconfig value (default `''`,
  `'hermiq'` is the only supported non-empty value today).
  - Acceptance: default (unset) config → `isProxyConfigured()` returns false.
- [x] 2.2 `isHermiqInstalled()` — `IAppManager::isInstalled('hermiq')`, checked
  before any outbound call.
- [x] 2.3 `rewritePathForHermiq(string $orPathInfo): ?string` — prefix
  substitution `/apps/openregister/` → `/apps/hermiq/`; null on non-matching
  input (defensive).
- [x] 2.4 `forwardJsonRequest(IRequest $request, string $hermiqPath): ?Response`
  — forwards via `IClientService`, rebuilds a `DataDisplayResponse` from the
  upstream status/body/`Content-Type`, strips the `Content-Disposition`
  default header. Returns null (never throws) on any transport failure,
  logging a warning.
  - Acceptance: GET/DELETE forward with no body; POST/PATCH forward a JSON
    body rebuilt from `IRequest::getParams()` with `_route`/`requesttoken`
    stripped; only the `Cookie` header (plus `Accept`/`Content-Type`) is
    forwarded.
- [x] 2.5 `probeReachable(): bool` — short-timeout `GET` against hermiq's chat
  health endpoint; false on any non-2xx-class server error or transport
  exception.
- [x] 2.6 `buildRedirectResponse(IRequest $request, string $hermiqPath):
  Response` — 308 Permanent Redirect (RFC 7538, preserves method+body) to the
  absolute hermiq URL including the original query string.

## 3. Middleware orchestration

- [x] 3.1 `ChatCompatMiddleware::beforeController()` — resolves the guard
  chain (chat-family controller? not the `page()` SPA-shell method? proxy
  configured? hermiq installed? path rewrites cleanly?) via
  `resolveHermiqPath()`, then dispatches to `attemptProxy()`
  (reachability-gated redirect for `ChatStreamController`, JSON forward for
  everything else). A non-null result throws
  `ChatProxiedResponseException` carrying the response; a null result
  returns normally (local serving proceeds unchanged).
- [x] 3.2 `lib/Middleware/Exception/ChatProxiedResponseException.php` — thin
  carrier exception; `afterException()` unwraps it, any other exception is
  re-thrown (default `Middleware::afterException()` behaviour preserved).

## 4. Unit tests

- [x] 4.1 `tests/Unit/Service/Chat/ChatProxyHandlerTest.php` — config
  resolution, path rewriting (match + no-match), successful JSON forward
  (status/body/Content-Type rebuilt, `Content-Disposition` stripped,
  NC-internal keys stripped from the forwarded body, Cookie forwarded,
  GET/DELETE skip the body), transport-failure fallback (null, no
  exception), `probeReachable()` true/false/exception, `buildRedirectResponse()`
  shape (308 + `Location` with query string).
- [x] 4.2 `tests/Unit/Middleware/ChatCompatMiddlewareTest.php` — deprecation
  headers added for chat-family / left untouched for non-chat-family;
  `beforeController()` no-ops when proxy unconfigured, for non-chat
  controllers, and for `AgentsController::page()`; throws
  `ChatProxiedResponseException` on a successful JSON forward and on a
  successful streaming redirect; falls back silently (no throw) when the
  forward fails, when hermiq is not installed, and when the streaming probe
  is unreachable; `afterException()` unwraps the carried response and
  re-throws anything else.
- [x] 4.3 Re-run `tests/Unit/Controller/ChatStreamControllerTest.php` +
  `ChatStreamControllerHeartbeatTest.php` — confirm the `emitSseHeaders()`
  edit introduces no regression (both suites green, unchanged assertion
  counts).

## 5. Quality + verification

- [x] 5.1 `phpcs --standard=phpcs.xml --warning-severity=0` clean on every
  touched `lib/` file.
- [x] 5.2 `phpstan analyse` clean on every touched `lib/` file — three
  `@phpstan-ignore-next-line` uses documented inline (NC's own `Response`
  generic status-code template doesn't include 308 at all, and
  `addHeader()`'s docblock documents `null` as valid despite its declared
  `string` param type).
- [x] 5.3 `phpmd` clean on every touched `lib/` file (refactored
  `beforeController()` into `resolveHermiqPath()` + `attemptProxy()` to clear
  both the NPath-complexity and else-expression findings).
- [x] 5.4 `hydra-gate-route-auth` — `appinfo/routes.php` has zero diff vs
  `origin/development`; every routed method on the five chat-family
  controllers still carries its pre-existing auth attribute.
- [x] 5.5 Touched-tests-only PHPUnit run green: 30/30 (23 new + 7
  pre-existing `ChatStreamController` tests), 0 failures.

## 6. Docs

- [x] 6.1 Add `REQ-007` to `openspec/specs/chat-ai/spec.md` documenting the
  deprecation-header + optional-proxy contract, and the explicit
  out-of-scope removal note.
