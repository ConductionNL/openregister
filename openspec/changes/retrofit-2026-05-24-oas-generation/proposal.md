# Retrofit — Reverse-Spec `oas-generation` (sub-behaviors)

## Why

The `oas-generation` capability spec (`openspec/specs/oas-generation/spec.md`) covers REQ-001 (all-register OAS) and REQ-002 (single-register OAS) — the user-facing HTTP surface. The Bucket 2a scan flagged 33 methods across 9 files as not matching either REQ; on closer reading those methods implement **sub-behaviors** of the same capability that the spec never pins down:

- `OasETagComputer` — deterministic SHA-256 over canonicalised JSON, RFC 7232 `If-None-Match` short-circuit returning `304 Not Modified`. The controller already wires this in but no REQ describes the contract.
- `ProblemDetailsBuilder` — RFC 7807 `application/problem+json` shape used for `404`, `409`, and `422` responses from the OAS endpoint and its validation middleware.
- `OasValidationReport` value object — structured collection of error/warning/auto-correction issues with stable machine codes (`dangling_ref`, `duplicate_operation_id`, `relative_server_url`, ...) and a summary serializer that drives both the `x-validation-summary` extension field (when `?validate=true`) and the `422` response body (when `?strict=true`).
- `OasRequestValidator` + `OasValidationMiddleware` — opt-in JSON-Schema validation of incoming request bodies against per-operation schemas, gated by `?_validate=true` on POST/PUT/PATCH. The middleware's resolver is currently a stub returning `null`; the spec needs to document the contract so the future resolver implementation has a target.
- Internal validation passes inside `OasService` — meta-schema (OpenAPI 3.1) check, NLGov API-01 (allowed HTTP methods) + API-03 (allowed status codes) rules, property-definition sanitization (whitelist of OpenAPI 3.1 keywords with auto-coercion of malformed inputs).
- The controller's `?strict=` / `?validate=` boolean query-parameter parser (accepts `true|1|yes|on`, case-insensitive).

This retrofit lifts these observed sub-behaviors into the `oas-generation` spec and annotates the 19 backing methods that have substance. Pure DTO accessors and trivial wiring (`OasService::__construct`, `OasService::getLastValidationReport`, the exception getters, the middleware stub) are dropped as false positives.

## What Changes

- ADD 5 new requirements to `openspec/specs/oas-generation/spec.md`:
  1. ETag computation + `If-None-Match` short-circuit on the OAS endpoint.
  2. RFC 7807 problem-details shape for error responses.
  3. `OasValidationReport` issue collection, severity model, and summary serialization.
  4. Per-operation request-body validation middleware (opt-in via `?_validate=true`).
  5. Internal validation pipeline — meta-schema, NLGov API-01/API-03, property-definition sanitization.
- ADD a note on the controller-level boolean query-param parser (folded into REQ-001 since it's shared by `?strict=` and `?validate=`).
- ANNOTATE 19 substantive methods across 7 files with `@spec` tags pointing at the new tasks. 14 of the 33 cluster methods are DTO accessors, trivial constructors, exception getters, or a stub middleware resolver — those are dropped as false positives (see Notes).

## Impact

- `openspec/specs/oas-generation/spec.md` — gains 5 requirements.
- `lib/Service/Oas/OasETagComputer.php` — 3 method docblocks annotated (`hash`, `matches`, `canonicalise`).
- `lib/Service/Oas/ProblemDetailsBuilder.php` — 3 method docblocks annotated (`validationFailed`, `notFound`, `conflict`).
- `lib/Service/Oas/OasValidationReport.php` — 3 method docblocks annotated (`addError`, `addWarning`, `addAutoCorrection`) covering the contract surface; pure getters left unannotated.
- `lib/Service/Oas/OasRequestValidator.php` — 3 method docblocks annotated (`validate`, `isValid`, `collectErrors`).
- `lib/Middleware/OasValidationMiddleware.php` — 2 method docblocks annotated (`beforeController`, `afterException`).
- `lib/Service/OasService.php` — 3 method docblocks annotated (`sanitizePropertyDefinition`, `validateAgainstMetaSchema`, `validateNlGovRules`).
- `lib/Controller/OasController.php` — 1 method docblock annotated (`boolQueryParam`); other methods already carry `@spec` tags from the 2026-05-01 retrofit.
- **No behavior change.** Documentation/coverage only.

## Notes

**Dropped as false positives (14 methods):**

- `OasController::boolQueryParam` — KEPT (annotated; small but its semantics are load-bearing for `?strict=` and `?validate=`).
- `OasValidationReport` accessors (`getIssues`, `getErrors`, `getWarnings`, `getAutoCorrections`, `hasErrors`, `passed`, `isEmpty`, `toSummary`, `filterBySeverity`) — DTO read API. The setters (`addError`/`addWarning`/`addAutoCorrection`) are annotated because they define the issue shape; the readers are pure consequences and would clutter the spec with no signal.
- `OasValidationException::__construct`, `OasValidationException::getReport` — exception wiring.
- `OasValidationFailureException::__construct`, `OasValidationFailureException::getErrors` — internal carrier between middleware phases.
- `OasService::__construct`, `OasService::getLastValidationReport` — DI plumbing + trivial accessor.
- `OasValidationMiddleware::resolveOperationSchema` — explicit stub returning `null`; new REQ-006 documents the *contract* the future implementation must satisfy, but the stub itself is not part of the implemented surface.

**Drift flagged:**

- `OasValidationMiddleware::resolveOperationSchema` is a stub. The middleware therefore never validates today even when `?_validate=true` is set. REQ-006 documents the eventual contract; until the resolver is wired the middleware is a no-op. This is a known gap, not a regression.
- `INCLUDED_EXTENDED_ENDPOINTS` is empty (already noted in the existing REQ-001 Notes).
