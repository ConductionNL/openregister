# Design: operate-load-shedding

Read at openregister development c53dd0685c.

## D-1: one breaker per dependency, state in the distributed cache

`lib/Service/Resilience/CircuitBreaker.php` is a small state machine per key: `closed`, `open`, `half-open`.

- **Closed.** Calls pass. Each outcome is counted in the current 10 second bucket (`calls`, `failures`) with `IMemcache::inc()`. The breaker opens when, over the last 60 seconds, there were at least 10 calls, at least 5 failures, and failures are at least half of the calls.
- **Open.** Calls fail at once with `DependencyUnavailableException` carrying the key and the seconds until the cool-down ends. The first cool-down is 30 seconds; each reopening from half-open doubles it, up to 300 seconds.
- **Half-open.** After the cool-down one caller wins an `IMemcache::add()` on a probe key and goes through. Success closes the breaker and resets the cool-down; failure reopens it with the doubled cool-down. Every other caller in that moment still gets the immediate refusal.

State lives in `ICacheFactory::createDistributed('openregister_breakers')`, cast to `IMemcache` for atomic increments, the same pattern and for the same reason as `CallerRateLimiter` (`lib/Service/ApiCaller/CallerRateLimiter.php:66-79`, resolved at `:181-190`). When no distributed memcache is configured, the breaker uses `createLocal()`, so each PHP worker learns on its own. The console says so (D-7). When the cache throws, the breaker lets the call through and logs at warning: a broken cache must not take the API down.

`BreakerRegistry` names the keys and holds the thresholds, read once per request from `IAppConfig` with the defaults above as fallback.

## D-2: what counts as a failure

- Outbound HTTP: a connect error, a timeout, a 5xx, or a 429. A 429's `Retry-After` becomes the cool-down when it is longer. Any other 4xx is the caller's problem, not the dependency's, and counts as a success.
- Outside database source: `DbalConnectionException` from `DbalObjectSourceProvider::connect()` (`lib/Service/ObjectSource/DbalObjectSourceProvider.php:789-791`), and a query error that `findAll()` turns into a 502 or 503 (`:223-232`).
- LLM: an exception from the chat or embedding call.
- Database: see D-4.

## D-3: where the breakers sit

| key | guarded call site |
|---|---|
| `outbound:{host}` or a named connection key | `OutboundHttpClient::request()` and the verb methods that funnel into it (`lib/Service/Outbound/OutboundHttpClient.php:118-240`). A call site may pass the request option `openregister_dependency` (for example `brp`), which the client strips before sending; otherwise the key is the host. |
| `webhook:{host}` | `WebhookService`'s delivery call (`lib/Service/WebhookService.php:1254`). It has its own Guzzle client (`:217-230`), so it is wrapped there. |
| `source:{id}` | `DbalObjectSourceProvider::connect()` (`:789`). |
| `llm` | `ResponseGenerationHandler::generateResponse()` chat calls (`lib/Service/Chat/ResponseGenerationHandler.php:608`, `:670`) and `EmbeddingGeneratorHandler`'s `embedText()` (`lib/Service/Vectorization/Handlers/EmbeddingGeneratorHandler.php:248`). |

`DbalObjectSourceProvider::find()` today turns a connection failure into `null` (`:174-179`), which the caller reads as "not found". With the breaker open, `find()` throws the same 503 `DbalObjectSourceException` that `findAll()` throws (`:231`), so a dead source never reads as a missing record.

## D-4: database pressure sheds only the heavy routes

A breaker cannot guard Nextcloud's own database the way it guards a host: if the database is down, nothing answers. What Open Register can do is stop starting heavy work while the database is struggling.

`lib/Service/Resilience/DatabasePressureMeter.php` counts, per 10 second bucket, Open Register API requests, those that took longer than 2 seconds, and those that ended in a Doctrine `DriverException`. Pressure is high when, over the last 60 seconds, there were at least 50 requests and either a fifth were slow or a tenth hit a database error. The counts are taken in `LoadSheddingMiddleware::afterController()` and `afterException()`, with the request start stamped in `beforeController()`.

While pressure is high, `LoadSheddingMiddleware::beforeController()` refuses methods carrying a new `#[Sheddable]` attribute with 503 and `Retry-After: 30`. The attribute is placed on:

- `ObjectsController::export` (`appinfo/routes.php:1175`);
- the four aggregation routes (`:618-623`);
- `graphQL#execute` (`:1993`);
- `bulk#save`, the bulk delete routes and `bulk#runSchemaValidation` (`:1285-1290`), and `bulkJobs#create` (`:1295`);
- report runs.

Single-object reads and writes are never marked, so a caseworker keeps working while an export waits.

## D-5: the refusal

`LoadSheddingMiddleware::afterException()` maps `DependencyUnavailableException` to:

```json
HTTP/1.1 503 Service Unavailable
Retry-After: 27
Content-Type: application/problem+json

{"type": "about:blank", "title": "Dependency unavailable", "status": 503,
 "detail": "The outside database this schema reads from is not answering. Try again in 27 seconds.",
 "dependency": "source"}
```

`dependency` is the key's class (`database`, `llm`, `source`, `outbound`, `webhook`), never the host or the source's connection details, because the refusal can reach an anonymous caller on a public route. The problem document follows `ProblemDetailsBuilder` (`lib/Service/Oas/ProblemDetailsBuilder.php`). The middleware is registered next to `ObjectSourceErrorMiddleware` (`lib/AppInfo/Application.php:713`), before it, so the breaker's refusal is not rewritten.

## D-6: a webhook waits instead of hammering

When `webhook:{host}` is open, `WebhookService` does not send. It records the delivery for retry with `next_retry_at` set to the end of the cool-down, which `WebhookRetryJob` (`lib/BackgroundJob/WebhookRetryJob.php:51`) already picks up, and it does not count an attempt. The synchronous interception webhook in `ObjectsController::create()` (`lib/Controller/ObjectsController.php:3267-3289`) already continues with the original request when the webhook fails; with the breaker open it continues at once instead of waiting for the 2 second timeout (`lib/Service/WebhookService.php:203`).

This is the open-object#534 case from the other side: Open Register stops being the component that sends 10,000 failing calls a minute.

## D-7: administrators see and reset

`OperationsDependenciesController`:

- `GET /api/operations/dependencies` lists every key that has state: class, key, state, calls and failures in the window, opened at, next probe at, and whether the state is shared or per worker.
- `POST /api/operations/dependencies/{key}/reset` closes a breaker and writes a `dependency.reset` audit row through `AuditTrailMapper::insertAuditTrails()`.

Both carry no `#[NoAdminRequired]`, so Nextcloud refuses a non-administrator with 403. `src/views/operations/OperationsConsoleIndex.vue` gets a "Dependencies" section beside the ones at `:52-388`, with a reset button per open breaker.

When a breaker on a declared connection key (`lib/Settings/connections.json`, for example `llm` or `brp`) opens, `ConnectionReporter::report()` (`lib/Service/Connection/ConnectionReporter.php:147`) is called with status `unavailable` and a message naming the cool-down; when it closes, with `configured`. `report()` never throws, so the breaker does not depend on integriq being installed.

## D-8: thresholds are administered

The defaults in D-1 and D-4 are stored under `IAppConfig` keys in a `load_shedding` group and edited in a "Load shedding" section of the Open Register admin settings. An unreadable or out-of-range value falls back to its default and logs at warning. A switch turns database-pressure shedding off entirely for an instance that prefers slow answers to refusals; dependency breakers cannot be switched off, only tuned.

## Risks

- **Security.** The 503 names a dependency class only (D-5). The dependency list and the reset are administrator-only, and a reset is audited.
- **False opens.** A burst of legitimate 5xx from one host opens only that host's breaker, for 30 seconds at first. The minimum of 10 calls stops a single failure from opening a quiet breaker.
- **Performance.** A closed breaker costs one cache read and one increment per guarded call. The pressure meter costs two increments per request. Both are atomic memcache operations, no database query (openregister ADR-009).
- **Per-worker state.** Without a distributed memcache each worker opens its own breaker after its own failures. That still sheds most of the load, and the console shows the weaker mode instead of implying a shared one.
