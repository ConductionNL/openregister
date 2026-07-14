# Design: optimize-request-hygiene

## 1. Interception fast-path cache

### Problem
`ObjectsController::create()` unconditionally calls `WebhookService::interceptRequest()`, which called `WebhookMapper::findEnabled()` (a table scan plus organisation filtering) on every object create — even on the overwhelmingly common install with zero interception webhooks.

### Cache design
- **Component:** `lib/Service/Webhook/WebhookInterceptionCache.php`, backed by `ICacheFactory::createDistributed('openregister-webhook-interception')`.
- **Key:** `has-interception-webhooks:{eventType}` (e.g. `has-interception-webhooks:object.creating`).
- **Value:** `0`/`1` (scalar for backend portability), surfaced as `bool`; `null` = miss.
- **TTL:** 60s (`CACHE_TTL_SECONDS`) — a safety net for nodes that miss an invalidation; eager invalidation is the primary mechanism. Kept short so a missed invalidation can delay a new interception webhook by at most a minute.
- **Distributed-only gate:** the cache is only armed when `ICacheFactory::isAvailable()` reports a genuinely distributed memory cache. Without one, `createDistributed()` silently falls back to a node-LOCAL cache (APCu); on multi-node deployments a cached `false` on node B would survive a webhook created on node A for up to the TTL, letting node B's writes bypass a real interception hook. With no distributed backend the cache is disabled (`get()` always misses, `set()`/`invalidate()` no-op) and every write computes the flag from the database — pre-cache behaviour, slower but always correct.
- **Invalidation points:** `WebhookMapper::insert()`, `update()`, `delete()` each call `WebhookInterceptionCache::invalidate()` (clears the key prefix). Any CRUD can change any event type's answer, so all flags are cleared. `updateStatistics()` routes through `update()` and therefore also invalidates — harmless: the flag is only load-bearing on installs with zero interception webhooks, which never deliver.
- **Nullable DI:** both `WebhookService` and `WebhookMapper` take the cache as an optional trailing constructor argument. Without it, behaviour degrades to the pre-change lookup (no crash in degraded environments or hand-constructed test instances).

### Tenancy correctness (the key decision)
`findEnabled()` is organisation-filtered. Caching a per-organisation answer under a global key would let tenant A's "no webhooks" poison tenant B's interception. Instead the cached flag is **deliberately tenant-agnostic**: it is computed from `WebhookMapper::findEnabledForInterceptionScan()` (same query WITHOUT the organisation filter) and answers "does ANY enabled interception webhook exist for this event type, in any organisation".

- Cached **false** → provably no interception webhook anywhere → skip everything (zero cost, correct for every tenant).
- Cached **true** → superset signal → still run the organisation-filtered `findWebhooksForInterception()` to select the hooks that actually apply.

### Timeout cap
`WebhookService::INTERCEPTION_TIMEOUT_SECONDS = 2`. Interception is request-blocking by design; the previous client defaults (30s total / 10s connect) let one dead endpoint stall every create. The cap is threaded as an optional `timeoutCapSeconds` parameter through `deliverWebhook()` → `sendRequest()` and applied ONLY on the interception path: `timeout = min(perWebhookTimeout, cap)` (with non-positive per-webhook timeouts — Guzzle "wait forever" — forced to the cap) and `connect_timeout = cap`. Post-save deliveries stay async via `WebhookDeliveryJob` with per-webhook timeouts, untouched.

## 2. Search-trail deferral

- `SearchQueryHandler::getEffectiveRecordingMode()` memoizes the resolved mode in an instance property, so the mode gate in `ObjectService::recordSearchTrail()` and the enabled gate in `logSearchTrail()` cost one settings read per request instead of two per search.
- `logSearchTrail()` buffers entries; the first buffered entry registers `flushSearchTrails()` via `register_shutdown_function`, mirroring `ProcessingLogService`'s buffered-emission pattern. The flush persists entries through the existing `SearchTrailService::createSearchTrail()` (schema unchanged), fail-soft per entry.
- Best-effort contract preserved: rows buffered when the process dies fatally are lost, which the spec explicitly allows; a flush failure is logged and never surfaces.

## 3. Resolve-once entities

`ObjectService::setRegister()/setSchema()` already hold the resolved `Register`/`Schema` in `currentRegister`/`currentSchema`. New getters `getCurrentRegisterEntity()`/`getCurrentSchemaEntity()` expose them; `ObjectsController::resolveRegisterSchemaIds()` reuses them instead of re-fetching through `\OC::$server->get(RegisterMapper::class)`. `crossTableSearch()`'s remaining multi-entity lookups switch from the service locator to the constructor-injected mappers.

## 4. Removals (no replacement needed)

- Fake `Content-Encoding: gzip` header blocks in both `index()` list branches — nothing compressed the body; the webserver negotiates compression.
- `PerformanceHandler::getCachedEntities()` — documented-as-caching no-op; call sites unwrapped to direct `find()` calls (the mappers' request-scoped `findCache` is the real cache). `PerformanceHandler` retains its genuinely-used methods (`extractRelatedData`).
- `lib/Service/RequestScopedCache.php` + its unit test — referenced by no production code.

## Alternatives considered

- **Per-organisation cache keys** for the interception flag: rejected — requires resolving the active organisation on the hot path (another lookup) and multiplies keys; the tenant-agnostic superset flag gets the zero-webhook install to zero cost with strictly correct behaviour.
- **Queued job per search-trail row**: rejected — an `oc_jobs` INSERT costs as much as the trail INSERT it defers; the shutdown-function buffer defers the cost past the response without new infrastructure.
- **Config-driven interception timeout**: rejected for now — a class constant with a clear comment is enough; making a request-blocking hook slower should be a deliberate code change, not a knob.
