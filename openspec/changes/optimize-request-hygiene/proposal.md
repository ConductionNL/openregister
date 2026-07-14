## Why

Every object write and every search in OpenRegister pays for work that benefits nobody:

1. **Webhook interception blocks every create** — `ObjectsController::create()` always calls `WebhookService::interceptRequest()`, which scans the webhook table on EVERY create even when zero interception webhooks exist. When one does exist, delivery is synchronous through Guzzle with a 30s total / 10s connect timeout — one slow endpoint stalls all object writes for up to 30 seconds.
2. **Fake gzip headers** — both list branches in `ObjectsController::index()` add `Content-Encoding: gzip` + `Vary: Accept-Encoding` when more than 10 results are returned, but nothing ever compresses the body (there is no `gzencode` anywhere in `lib/`). Clients that honour the header receive a corrupt response; the webserver already negotiates compression correctly.
3. **Dead "performance" scaffolding** — `PerformanceHandler::getCachedEntities()` is a documented-as-caching no-op (it always calls the fallback), and `lib/Service/RequestScopedCache.php` is referenced by no production code at all. The mappers' request-scoped `findCache` already provides the real caching.
4. **Register/schema resolved twice per request** — `ObjectsController::resolveRegisterSchemaIds()` resolves the slugs via `ObjectService::setRegister()/setSchema()`, then re-fetches the SAME entities through `\OC::$server->get(RegisterMapper::class)->find(...)` + SchemaMapper.
5. **Search-trail overhead on every search** — `recordSearchTrail()` reads the recording-mode settings twice per search (`getEffectiveRecordingMode()` plus the enabled check inside `logSearchTrail()`) and INSERTs the trail row synchronously inside the read request.

## What Changes

- **Interception fast path**: cache a tenant-agnostic "has interception webhooks for event X" boolean in the distributed cache (`ICacheFactory`), invalidated on webhook create/update/delete, so the common zero-webhook case costs a cache read instead of a table scan per write. The flag is deliberately global (computed WITHOUT organisation filtering) so one tenant's "false" can never disable another tenant's hooks; a cached "true" still runs the organisation-filtered lookup.
- **Interception timeout cap**: synchronous interception deliveries are hard-capped at 2s connect + total (`WebhookService::INTERCEPTION_TIMEOUT_SECONDS`). Interception is request-blocking by design; a hook that cannot answer in 2s must not gate writes. Post-save delivery stays async via `WebhookDeliveryJob` and keeps per-webhook timeouts.
- **Delete the fake gzip header blocks** (both list branches) — webserver-level negotiation handles compression.
- **Remove dead scaffolding**: `PerformanceHandler::getCachedEntities()` removed and its two call sites unwrapped to direct mapper `find()` calls (the mappers' request-scoped `findCache` is the real cache); `lib/Service/RequestScopedCache.php` and its test deleted. `PerformanceHandler` keeps its genuinely-used methods (`extractRelatedData` etc.).
- **Resolve once**: `ObjectService` exposes `getCurrentRegisterEntity()` / `getCurrentSchemaEntity()`; `resolveRegisterSchemaIds()` reuses those entities instead of re-fetching via service locator. Remaining lookups in `crossTableSearch()` use the constructor-injected mappers instead of `\OC::$server->get()`.
- **Search-trail hygiene**: the effective recording mode is memoized per request (one settings read instead of two per search), and trail rows are buffered in-request and flushed by a shutdown function after the response (mirrors `ProcessingLogService`'s buffered emission). The trail stays best-effort — losing a buffered row on a fatal is acceptable; its schema is unchanged.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `webhook-payload-mapping`: request-interception gains the cached fast-path flag and the hard 2s delivery timeout cap.
- `search-trail-recording`: recording gains per-request mode memoization and deferred (post-response) persistence.

## Impact

- **Backend:** `lib/Service/WebhookService.php`, `lib/Service/Webhook/WebhookInterceptionCache.php` (new), `lib/Db/WebhookMapper.php`, `lib/Controller/ObjectsController.php`, `lib/Service/ObjectService.php`, `lib/Service/Object/PerformanceHandler.php`, `lib/Service/Object/SearchQueryHandler.php`; `lib/Service/RequestScopedCache.php` deleted.
- **Frontend:** none.
- **API:** no request/response shape changes; the bogus `Content-Encoding` header disappears (correctness fix).
- **Config:** none (cache TTL is a class constant, 300s safety net under eager CRUD invalidation).
- **Tests:** new unit tests for the interception cache (hit/miss/invalidation), the timeout cap, and search-trail deferral; existing tests updated where they asserted the removed no-op cache, the fake gzip headers, or the service-locator entity resolution.
