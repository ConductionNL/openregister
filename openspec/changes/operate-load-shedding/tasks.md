# Tasks: operate-load-shedding

## 1. Breaker core

- [ ] 1.1 Add `lib/Service/Resilience/CircuitBreaker.php` and `BreakerRegistry.php`: closed, open and half-open states in 10 second buckets on a distributed `IMemcache`, local fallback, fail-open on cache errors, and thresholds from `IAppConfig` with defaults (design D-1, D-8). Verify: `tests/Unit/Service/Resilience/CircuitBreakerTest.php` with a clock fixture covers opening at 5 of 10, staying closed at 4 of 10, one half-open probe, doubling cool-down to 300 seconds, and a throwing cache letting calls through.
- [ ] 1.2 Add `DependencyUnavailableException` and `lib/Middleware/LoadSheddingMiddleware.php` mapping it to 503 with `Retry-After` and a problem document naming only the dependency class; register it before `ObjectSourceErrorMiddleware`. Verify: `tests/Unit/Middleware/LoadSheddingMiddlewareTest.php` asserts status, header, `Content-Type` and that no host appears in the body.

## 2. Guarded call sites

- [ ] 2.1 Guard `OutboundHttpClient` per host, honouring the `openregister_dependency` request option and a 429's `Retry-After` (design D-2, D-3). Verify: `tests/Unit/Service/Outbound/OutboundHttpClientBreakerTest.php` asserts no request is sent while open and a 404 does not count as a failure.
- [ ] 2.2 Guard `DbalObjectSourceProvider::connect()` per source, and make `find()` throw the 503 while the breaker is open instead of returning null. Verify: `tests/Unit/Service/ObjectSource/DbalObjectSourceBreakerTest.php` asserts the 503 on both `find()` and `findAll()` with no connect attempt.
- [ ] 2.3 Guard the LLM chat and embedding calls under key `llm`. Verify: `tests/Unit/Service/Chat/ResponseGenerationBreakerTest.php` asserts an open breaker refuses before LLPhant is constructed.
- [ ] 2.4 Guard webhook delivery per host: an open breaker schedules the delivery on `WebhookRetryJob` for after the cool-down without counting an attempt, and the interception webhook continues at once (design D-6). Verify: `tests/Unit/Service/WebhookServiceBreakerTest.php`.

## 3. Database pressure

- [ ] 3.1 Add `DatabasePressureMeter` and the `#[Sheddable]` attribute, stamp timings in `LoadSheddingMiddleware`, and mark the routes in design D-4. Verify: `tests/Unit/Service/Resilience/DatabasePressureMeterTest.php`; `tests/Unit/Middleware/LoadSheddingSheddableTest.php` asserts an export is refused under pressure while `objects#show` passes; a reflection test lists every `#[Sheddable]` method so the set cannot drift unseen.

## 4. Administration

- [ ] 4.1 Add `OperationsDependenciesController` with `GET /api/operations/dependencies` and `POST /api/operations/dependencies/{key}/reset`, administrator-only, the reset audited as `dependency.reset`, and report breaker transitions on declared connection keys through `ConnectionReporter::report()`. Verify: `tests/Unit/Controller/OperationsDependenciesControllerTest.php`; a Newman request asserts 403 for a non-administrator; hydra route-auth and route-reachability gates pass.
- [ ] 4.2 Add the "Dependencies" section to `src/views/operations/OperationsConsoleIndex.vue` and the "Load shedding" section to the admin settings with the thresholds and the pressure switch. Verify: `src/views/operations/OperationsConsoleIndex.spec.js` renders an open breaker with its next probe time and a reset button, and shows the per-worker notice.

## 5. Docs and end-to-end test

- [ ] 5.1 Document the breakers, the failure rules, the sheddable routes, the thresholds and the 503 contract in `docs/features/instance-hardening.md`. Verify: `npm run build` in `docs/` succeeds.
- [ ] 5.2 Add `tests/e2e/ci/load-shedding.spec.ts`: point an outside database source at an unreachable port, read its objects until the breaker opens, see an immediate 503 with `Retry-After`, then reset it from the operations console as an administrator. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- With healthy dependencies no response changes.
- An open breaker never makes an outbound attempt except the one half-open probe.
