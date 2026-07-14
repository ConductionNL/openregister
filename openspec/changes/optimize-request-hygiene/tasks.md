# Tasks: optimize-request-hygiene

## 1. Webhook interception fast path + timeout cap

- [x] 1.1 Create `lib/Service/Webhook/WebhookInterceptionCache.php` — distributed-cache flag (`has-interception-webhooks:{eventType}`, TTL 300s, `get`/`set`/`invalidate`).
- [x] 1.2 Add `WebhookMapper::findEnabledForInterceptionScan()` — enabled webhooks WITHOUT organisation filter (tenant-agnostic flag computation), documented why.
- [x] 1.3 Invalidate the flag cache in `WebhookMapper::insert()`, `update()`, `delete()` (nullable optional constructor dependency).
- [x] 1.4 `WebhookService::interceptRequest()` consults `hasInterceptionWebhooks()` first: cached false → return request params without any webhook query; miss → compute globally + cache; true → organisation-filtered lookup as before. Shared matching extracted to `matchesInterception()`.
- [x] 1.5 Add `WebhookService::INTERCEPTION_TIMEOUT_SECONDS = 2` (commented) and thread an optional `timeoutCapSeconds` through `deliverWebhook()` → `sendRequest()`; applied only on the interception path, `min()` with the per-webhook timeout, non-positive per-webhook timeouts forced to the cap, `connect_timeout` set to the cap. Async post-save delivery untouched.
- [x] 1.6 Unit tests: cache hit (true/false)/miss/per-event scoping/invalidation (`WebhookInterceptionCacheTest`), fast-path skip + miss-compute-store + no-backend fallback + timeout cap incl. never-raise (`WebhookInterceptionFastPathTest`), CRUD invalidation (`WebhookMapperInterceptionInvalidationTest`).
- [x] 1.7 Fix pre-existing bug encountered: `WebhookLog::setPayloadArray()` called the magic setter with a named argument, so `Entity::__call` never stored the payload (PHP "Undefined array key 0" warning) — switched to positional args like `Webhook.php`.

## 2. Fake gzip headers

- [x] 2.1 Delete both `Content-Encoding: gzip` + `Vary: Accept-Encoding` header blocks (and the "SUB-SECOND OPTIMIZATION" comment) from `ObjectsController::index()`; webserver negotiation owns compression.
- [x] 2.2 Update the tests that referenced the gzip headers (they now assert the plain large-result-set responses).

## 3. Dead performance scaffolding

- [x] 3.1 Remove `PerformanceHandler::getCachedEntities()` (documented-as-caching no-op) and unwrap both `ObjectService::setRegister()`/`setSchema()` call sites to direct mapper `find()` calls (request-scoped `findCache` in the mappers is the real cache).
- [x] 3.2 Delete `lib/Service/RequestScopedCache.php` and `tests/Unit/Service/RequestScopedCacheTest.php` (zero production references, verified by grep).
- [x] 3.3 Keep `PerformanceHandler` (still owns genuinely-used `extractRelatedData()` etc.); update tests that mocked the removed method.

## 4. Register/schema resolved once per request

- [x] 4.1 Add `ObjectService::getCurrentRegisterEntity()` / `getCurrentSchemaEntity()` exposing the entities resolved by `setRegister()`/`setSchema()`.
- [x] 4.2 `ObjectsController::resolveRegisterSchemaIds()` reuses those entities; the `\OC::$server->get(RegisterMapper|SchemaMapper)` re-fetch is gone.
- [x] 4.3 `crossTableSearch()` uses the constructor-injected mappers instead of the service locator for its remaining lookups.
- [x] 4.4 Update controller tests that stubbed the DI-container mappers to wire the entity getters / injected mappers instead.

## 5. Search-trail hygiene

- [x] 5.1 Memoize the effective recording mode per request in `SearchQueryHandler` (`getEffectiveRecordingMode()` reads settings once; `logSearchTrail()` reuses the memo instead of a second `isSearchTrailsEnabled()` read).
- [x] 5.2 Defer the trail INSERT: `logSearchTrail()` buffers entries; `flushSearchTrails()` persists them post-response via `register_shutdown_function` (mirrors `ProcessingLogService` buffered emission), fail-soft per entry, schema unchanged.
- [x] 5.3 Unit tests: memoization, no synchronous write, flush persists (values, counts, buffer cleared), disabled mode buffers nothing, fail-soft flush (`SearchTrailDeferralTest`).

## 6. Verification

- [x] 6.1 check:strict components (lint, phpcs, phpmd, psalm, phpstan) run in php:8.3 — zero new flags in touched files; phpstan repo error count IMPROVED 36 → 32 (mapper `@method` fixes); remaining repo-wide flags all pre-exist at base 40378fa37 (verified by running every analyzer on a baseline clone in the identical container).
- [x] 6.2 Full PHPUnit suite in php:8.3 at exact parity with base (57 errors / 12 failures, all environmental in the bare container — missing ext-zip/gd and NC-root-dependent tests; identical failure set at base). All new and updated tests green.
- [x] 6.3 `openspec validate optimize-request-hygiene --type change --strict` passes.
