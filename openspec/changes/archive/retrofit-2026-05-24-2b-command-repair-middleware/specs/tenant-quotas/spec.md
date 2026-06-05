---
retrofit_extensions:
  - REQ-001
---

# Tenant Quotas — Retrofit Delta

Adds 1 REQ extending `tenant-quotas` with the controller-middleware `afterException` envelope that turns `TenantStatusException` and `TenantQuotaExceededException` into JSON error responses. The existing `tenant-quotas` spec covers the *enforcement* side (request/storage/bandwidth gates) but does not describe the on-wire shape of the rejection response or the lifecycle hook that produces it.

## Requirements

### REQ-001: The middleware SHALL convert tenant status and quota exceptions raised during the request lifecycle into deterministic JSON error responses

`OCA\OpenRegister\Middleware\TenantQuotaMiddleware::afterException(controller, methodName, \Exception $exception)` is the Nextcloud controller-middleware exception hook. When the request pipeline throws — including from `beforeController()` (status / quota checks) and from controller bodies — the framework invokes `afterException`. The middleware translates two exception families into 4xx responses and re-throws everything else.

`TenantStatusException` — raised by `beforeController()` when the active organisation's status is `SUSPENDED`, `DEPROVISIONING`, or `PROVISIONING` (the last only for non-admins). The middleware returns a `JSONResponse` with body `{ "error": <exception-message>, "status": <organisation-status> }` and the HTTP status taken from the exception's own `getCode()` (always `403` in current call sites). No headers beyond the JSON envelope are added.

`TenantQuotaExceededException` — raised by `checkRequestQuota()` (and, structurally, by future bandwidth / storage gates). The middleware returns a `JSONResponse` with body `{ "error": <message>, "quota": <effective-quota>, "resetAt": <ISO8601-of-next-hour> }`, HTTP status hard-coded to `429`, and a `Retry-After` header containing the integer second count until the next hour boundary (`max(1, <next-hour> - <now>)`).

Any other exception type is re-thrown so the upstream pipeline can apply its own handling (`OCP\AppFramework\Http`'s default exception middleware, audit logging, etc.).

#### Scenario: Suspended organisation rejection

- **GIVEN** `beforeController()` threw `TenantStatusException("Organisation is suspended", "suspended", 403)`
- **WHEN** the controller middleware pipeline invokes `afterException()`
- **THEN** the returned response is a `JSONResponse` with body `{ "error": "Organisation is suspended", "status": "suspended" }`
- **AND** the HTTP status is `403`
- **AND** no `Retry-After` header is set

#### Scenario: Provisioning organisation, non-admin caller

- **GIVEN** the active organisation has `status = STATUS_PROVISIONING` and the caller is not in the admin group
- **AND** `beforeController()` threw `TenantStatusException("Organisation is being provisioned", "provisioning", 403)`
- **WHEN** `afterException()` runs
- **THEN** the response body is `{ "error": "Organisation is being provisioned", "status": "provisioning" }` with HTTP 403

#### Scenario: Request quota exceeded — Retry-After header is set

- **GIVEN** `checkRequestQuota()` threw `TenantQuotaExceededException("Request quota exceeded", 10000, "2026-05-24T15:00:00+00:00", 1234)`
- **WHEN** `afterException()` runs
- **THEN** the response is `JSONResponse({ "error": "Request quota exceeded", "quota": 10000, "resetAt": "2026-05-24T15:00:00+00:00" }, 429)`
- **AND** the response carries header `Retry-After: 1234`

#### Scenario: Non-tenant exception is re-thrown

- **GIVEN** the controller body threw `\OCP\AppFramework\Http\BadRequestException("bad input")`
- **WHEN** `afterException()` runs
- **THEN** the middleware does NOT match either tenant family
- **AND** the original exception is re-thrown so upstream middleware can handle it

### Notes

- **Status code is read from the status exception, not hard-coded.** `TenantStatusException::getCode()` is always `403` in current call sites; the middleware does not enforce this. A future status family wanting `423 Locked` (for `DEPROVISIONING`) could change the exception code without touching the middleware. Surfaced so reviewers don't "lock down" the code to 403.
- **`afterException()` is paired with `beforeController()` / `afterController()`** (already covered by `tenant-quotas` REQs on quota enforcement and APCu bandwidth tracking). This delta documents only the exception-to-response envelope.
- **Existing `@spec` annotations point at the 2026-04-23 and 2026-04-30 annotate-openregister retrofits**, but `afterException()` itself carries no method-level `@spec`. This delta adds the missing per-method tag.
