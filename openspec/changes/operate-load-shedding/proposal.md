---
kind: code
---

# Proposal: operate-load-shedding

## Summary

When a dependency Open Register relies on starts failing, Open Register stops calling it for a while instead of piling up requests that wait for a timeout. A caller gets an immediate 503 with a `Retry-After` for the part of the work that needs the failing dependency, and everything else keeps answering. When Open Register's own database is under pressure, it refuses only the heavy work, such as exports, aggregations and GraphQL queries, and keeps single-record reads and writes going. A functional administrator sees every dependency's state on the operations console and can reset one after a fix. Webhook deliveries to a failing receiver wait for the receiver to recover instead of hammering it.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | op-backpressure | Keep the register responsive under heavy load by slowing down or refusing requests when a dependency is failing. | partial |

**op-backpressure** (openregister's matrix)

- Demand: feature request, https://github.com/maykinmedia/open-object/issues/534 (the row's origin), titled "Introduction of back pressure and circuit breaker". It describes a misconfigured open-zaak making 10,000 failing calls a minute to open-notificaties and degrading every component.
- Competitor yes cells:
  - directus (Directus), no evidence URL, source path cited: "source read at v12.4.1, not driven: pressure limiter on by default directus:packages/env/src/constants/defaults.ts:216-222 rejects requests when event loop utilisation, delay or memory pass thresholds directus:api/src/app.ts:162-172; a separate request rate limiter is configurable by env".

## Why

Open Register limits callers by rate, not by the health of what it depends on.

- Rate limits exist: `#[UserRateLimit]` attributes on the object endpoints (for example `lib/Controller/ObjectsController.php:3248`), per-caller ceilings in `lib/Service/ApiCaller/CallerRateLimiter.php`, applied by `ApiCallerMiddleware` (`lib/Middleware/ApiCallerMiddleware.php:121-151`), and tenant quotas through `TenantQuotaMiddleware` (`lib/AppInfo/Application.php:671`). None of them looks at a dependency.
- A failing outside database is mapped to 503 by `ObjectSourceErrorMiddleware` (`lib/Middleware/ObjectSourceErrorMiddleware.php`), but only after `DbalObjectSourceProvider::connect()` (`lib/Service/ObjectSource/DbalObjectSourceProvider.php:789`) has tried and timed out, on every request. A dead source costs one PHP worker per request for the whole connect timeout.
- Every outbound HTTP call goes through `OutboundHttpClient` (bound under `IClientService` at `lib/AppInfo/Application.php:769-783`), and webhooks through their own Guzzle client with a 30 second timeout (`lib/Service/WebhookService.php:217-230`, sent at `:1254`). Neither remembers that a host failed a second ago.
- LLM calls go through LLPhant directly, `ResponseGenerationHandler::generateResponse()` (`lib/Service/Chat/ResponseGenerationHandler.php:170`, chat at `:608` and `:670`) and the embedding handler (`lib/Service/Vectorization/Handlers/EmbeddingGeneratorHandler.php:248`). A provider outage makes every chat request wait for its timeout.
- The only thing called a circuit breaker is a cap on relation loading (`lib/Service/Object/RelationHandler.php:262`), which is about result size, not failure.

## What changes

- A circuit breaker per dependency: `database`, `llm`, one per outside database source (`source:{id}`), and one per outbound HTTP host (`outbound:{host}`), with an optional connection key a call site can name.
- A breaker opens after repeated failures in a window, answers at once while open, lets one probe through after a cool-down, and closes when the probe succeeds. Its state is shared by every PHP worker through the distributed cache.
- A request whose dependency's breaker is open gets 503 with `Retry-After` and a problem document naming the dependency, without an outbound attempt.
- Database pressure (a high share of slow Open Register requests, or database errors) sheds only routes marked `#[Sheddable]`: exports, aggregations, GraphQL, bulk jobs and reports.
- A webhook whose receiver's breaker is open is not sent; it is scheduled on the existing retry job for after the cool-down.
- `GET /api/operations/dependencies` lists every breaker's state, and `POST /api/operations/dependencies/{key}/reset` closes one. Both are administrator-only, and a reset is on the audit trail.
- The operations console gets a "Dependencies" section. A breaker that opens or closes on a declared connection is reported to the connection registry.
- Thresholds have defaults and are administered in the Open Register admin settings.

## Consumers

- Every fleet app whose data is served by Open Register gets the behaviour on Open Register's routes; none changes code.
- integriq's connection registry receives the `unavailable` and `configured` reports for declared connections through the existing `ConnectionReporter::report()` (`lib/Service/Connection/ConnectionReporter.php:147`).
- `api-client-libraries` (this pass): the official clients honour `Retry-After` on a 503.

## ADRs

- hydra ADR-105 (controller exception translation): an open breaker is a typed exception mapped to 503 in one middleware, never a generic 500.
- hydra ADR-005 (security) and ADR-082 (public endpoint throttling): the dependency list and reset are administrator-only; a public 503 names the dependency class, not a host or a source's connection details.
- hydra ADR-069 (background jobs): a deferred webhook rides the existing `WebhookRetryJob`.
- hydra ADR-102 (config fail mode): the thresholds are not security keys, and the fail mode is declared anyway. An unreadable threshold falls back to its default, and a breaker that cannot read its shared state fails open, so a broken cache never takes the API down.
- hydra ADR-115 (a green instrument is not a present feature): the console shows when state is only per worker because no distributed cache is configured.
- openregister ADR-009 (performance invariants): a closed breaker costs one cache read per guarded call.
- openregister ADR-003 (immutable audit trail): a reset is an audit fact.

## Impact

- New capability `load-shedding`.
- Affected code: new `lib/Service/Resilience/CircuitBreaker.php`, `BreakerRegistry.php`, `DatabasePressureMeter.php`, `lib/Middleware/LoadSheddingMiddleware.php`, a `#[Sheddable]` attribute, `lib/Service/Outbound/OutboundHttpClient.php`, `lib/Service/WebhookService.php`, `lib/Service/ObjectSource/DbalObjectSourceProvider.php`, `lib/Service/Chat/ResponseGenerationHandler.php`, `lib/Service/Vectorization/Handlers/EmbeddingGeneratorHandler.php`, a new `OperationsDependenciesController`, `appinfo/routes.php`, `src/views/operations/OperationsConsoleIndex.vue`, the admin settings section.
- Backwards compatible. With healthy dependencies every breaker stays closed and responses do not change. The 503 on an open breaker replaces a slower 503 or 500 that the same request gets today.
- Size: M.

## Out of scope

- Inbound brute force from one caller. Nextcloud's brute-force protection and `CallerRateLimiter` already refuse a caller that keeps failing or exceeds its ceiling.
- Queueing and replaying refused synchronous requests. A 503 with `Retry-After` hands the retry to the caller, as the NLGov and HTTP semantics expect.
- Breakers inside other fleet apps. An app that calls its own outside systems keeps its own resilience; integriq owns shared outside connections per hydra ADR-091.
