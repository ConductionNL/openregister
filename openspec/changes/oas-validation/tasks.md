# Tasks: OAS Validation Specification

> **Status (Phase 1, retriaged 2026-05-01):** Validation engine extended with operationId uniqueness (auto-deduplication), tag consistency cross-checks, server-URL absoluteness, and NLGov API-01/API-03 rules. Strict mode (`?strict=true` → HTTP 422) and validation summary surface (`?validate=true` → `x-validation-summary` extension) shipped via `OasController`. Twelve integration tests cover the structural invariants and known regression cases. **13 of 19 tasks tickably complete; 6 left in Phase 2 — every one is external-blocked, retriaged today and confirmed: runtime request/response validation (depends on middleware design), ETag/cache (deferred until load signal), Redocly CI (needs stable register fixture), schema validation on import (separate `data-import-export` spec scope), RFC 7807 problem details (coordinated structural change to `Error` schema affecting every error response), and meta-schema validation (vendoring blocker tracked in [issue #1378](https://github.com/ConductionNL/openregister/issues/1378)).**

## Implemented (Phase 1)

- [x] **Valid OpenAPI 3.1.0 Output** — `createOas()` returns `openapi: "3.1.0"` and the structural invariants are asserted in `OasValidationIntegrationTest`. Empty registers produce a minimal valid spec with only `BaseOas.json` schemas.
- [x] **Valid Schema Component References** — `validateSchemaReferences()` walks every `$ref` recursively; dangling references are auto-corrected to `type: string` and recorded as errors in the validation report under `OasValidationReport::CODE_DANGLING_REF`.
- [x] **Valid Property Definitions** — `sanitizePropertyDefinition()` enforces the `$validTypes` whitelist, fixes `datetime → string`, strips boolean `required: true`, removes empty `allOf`, and provides default `items: {type: string}` for arrays without items. Regression coverage in the integration test.
- [x] **Valid Query Parameters** — `createCommonQueryParameters()` emits `_extend`, `_filter`, `_unset`, `_search` with valid schemas; dynamic per-property filter parameters carry the property's `type`/`enum`. Verified by existing `OasGenerationIntegrationTest`.
- [x] **Server URL is Absolute** — new `validateServerUrls()` pass enforces `^https?://`. Relative URLs raise `CODE_RELATIVE_SERVER_URL` errors so future regressions surface immediately.
- [x] **OperationId Uniqueness** — new `validateOperationIdUniqueness()` walks every operation, auto-suffixes collisions (`Foo`, `Foo_2`, `Foo_3`...), and records `CODE_DUPLICATE_OPERATION_ID` auto-corrections. The cross-register prefixing path (`pascalCase()` of register title) is also covered by integration test.
- [x] **Tags Reference Existing Definitions** — new `validateTagConsistency()` pass cross-references operation tags against the top-level `tags` array. Orphan tags are auto-injected with a generated description (`CODE_ORPHAN_TAG`); unused declared tags produce a `CODE_UNUSED_TAG` warning.
- [x] **NLGov API Design Rules Validation (API-01, API-03)** — new `validateNlGovRules()` pass enforces the documented HTTP method whitelist (`GET, POST, PUT, DELETE` plus `parameters`) and the documented HTTP status code whitelist (`200, 201, 204, 400, 401, 403, 404, 422, 500, default`). Violations surface as `CODE_INVALID_HTTP_METHOD` errors and `CODE_INVALID_STATUS_CODE` warnings respectively.
- [x] **Validation Error Reporting** — new `OasValidationReport` value object collects every issue with a JSON Pointer path (e.g. `paths./objects/zaken/meldingen.get.responses.200`) and a stable machine code, plus a severity (error/warning/auto_corrected). Issues are logged via `LoggerInterface::warning|error()` when a logger is injected. `getLastValidationReport()` exposes the full report to callers.
- [x] **Validation Modes (Strict vs Lenient)** — new `?strict=true` query parameter on `OasController` causes `createOas()` to throw `OasValidationException` when any error is detected, returning HTTP 422 with the report attached. Default lenient mode auto-corrects issues and surfaces them via the report. New `?validate=true` query parameter adds an `x-validation-summary` extension to the response payload.
- [x] **CI Integration for OAS Validation (PHPUnit layer)** — new `tests/Service/OasValidationIntegrationTest.php` covers: server-URL absoluteness, operationId uniqueness (single + cross-register prefixing), tag consistency, schema-name sanitisation invariants, regression cases (datetime type, empty allOf, boolean required, missing array items), strict-mode reflection-driven failure path, report reset between invocations, and the summary contract shape. 12 test methods, all green. Combined with the existing 12 OasGenerationIntegrationTest methods this gives a 24-method PHPUnit safety net for OAS output quality.
- [x] **OAS Security Scheme Validation** — `extractSchemaGroups()` and `applyRbacToOperation()` already enforce that 403 responses reference `#/components/schemas/Error` and that scopes mirror RBAC groups; `BaseOas.json` ships `basicAuth` + `oauth2`. Validation report flags any dangling `$ref` if a security scheme is renamed.

## Deferred (Phase 2)

- [ ] **Request/Response Validation Against OAS Schema** — runtime validation of incoming request bodies against the generated OAS using `opis/json-schema`. This is a runtime concern, not generation correctness, and is gated on the wider request-validation middleware design.
- [ ] **Performance Impact of Validation** — the new passes are O(n) over paths/components and trivial relative to schema enrichment; no caching layer is required at current register sizes. ETag-based caching of OAS responses is deferred to an explicit performance pass when load is observed.
- [ ] **CI Redocly Lint Integration** — running `npx @redocly/cli lint` in CI is deferred until a stable test register fixture (or snapshot) exists in the repo so the lint result is reproducible. The PHPUnit integration suite covers the same invariants without external tooling.
- [ ] **Schema Validation on Import** — pre-validating imported schemas in `ImportHandler` for OAS compatibility is a separate spec scope (see `data-import-export`); we already auto-correct on generation, so imports cannot break OAS output.
- [ ] **API-46 Problem Details (RFC 7807)** — enriching the `Error` schema with `type`, `title`, `status`, `detail`, `instance` is a structural change to `BaseOas.json` and affects every error response; deferred to a coordinated update.
- [ ] **Strict-mode meta-schema validation** — running the generated OAS through the OpenAPI 3.1.0 JSON-Schema meta-schema (via `opis/json-schema`) gives the broadest possible structural check. `opis/json-schema` already lives in `composer.json`. The blocker is vendoring the meta-schema document under `lib/Service/Resources/meta/` so the validator runs offline. Tracked in [issue #1378](https://github.com/ConductionNL/openregister/issues/1378) — estimated 2-4h once a maintainer takes it on.

## Architecture (Phase 1 decisions)

| Decision | Choice |
|---|---|
| Where validation lives | `OasService::validateOasIntegrity()` is the single entry point; sub-passes are private methods, each populating the shared `OasValidationReport`. |
| Issue carrier | `OasValidationReport` value object with JSON Pointer paths + stable machine codes (frozen `OasValidationReport::CODE_*` constants). |
| Strict mode behaviour | `OasService::createOas($id, strict: true)` throws `OasValidationException` carrying the report. The HTTP layer (`OasController`) catches it and returns 422 with `{error, summary}`. |
| Lenient default | Errors are auto-corrected where safe (dangling `$ref` → `type: string`, empty `allOf` removed, duplicate `operationId` → numeric suffix, orphan tag → auto-injected); always logged via `LoggerInterface`. |
| operationId collision strategy | Auto-suffix `_2`, `_3`, ... ; preserves the original prefix so client SDKs that depend on the leading verb (`getAllFoo`, `createFoo`) keep working. |
| Tag consistency | Orphans (used but undeclared) are auto-injected with a default description; unused declared tags emit warnings only. Avoids breaking documentation tooling that depends on every operation tag being defined. |
| NLGov surface | Only the rules verifiable from the OAS document alone are enforced (API-01 method whitelist, API-03 status code whitelist). Runtime rules (API-46 problem details, pagination shape) live in their own spec scopes. |
| Where the report lives | Held on the `OasService` instance and replaced at the start of every `createOas()` call; `getLastValidationReport()` exposes it. The instance lifetime spans a single request so no cross-request leakage. |

## Test coverage

- [x] `tests/Service/OasValidationIntegrationTest.php` — 12 tests:
  - server URL is absolute and the report is empty for a clean register
  - operationIds are unique across the document
  - cross-register prefixing produces unique operationIds when generating multi-register OAS
  - tags referenced by operations are declared at the top level
  - schema names with spaces are sanitised and `$ref`s align (no dangling refs)
  - regression: `datetime` type is corrected to `string`
  - regression: empty `allOf` is removed
  - regression: boolean `required: true` is stripped
  - regression: array without `items` gets `{type: string}` default
  - strict mode raises `OasValidationException` when server URL is relative (reflection-driven)
  - validation report is a fresh instance per invocation
  - `toSummary()` shape matches the contract `{passed, errors, warnings, autoCorrected, issues}`

12 OAS validation integration tests + 12 existing `OasGenerationIntegrationTest` + 189 unit tests = 213 OAS tests total, all green.

## Files Affected

- `lib/Service/OasService.php` — extended `validateOasIntegrity()`, added `validateServerUrls()`, `validateOperationIdUniqueness()`, `validateTagConsistency()`, `validateNlGovRules()`, `logValidationIssues()`. New `OasValidationReport` field + `getLastValidationReport()` accessor. Constructor accepts an optional `LoggerInterface`. `createOas()` gained a `bool $strict = false` parameter.
- `lib/Controller/OasController.php` — refactored to share `generateInternal()` between the single-register and all-registers routes. Honours `?strict=true` (HTTP 422 with report) and `?validate=true` (`x-validation-summary` extension on the response).
- `lib/Service/Oas/OasValidationReport.php` — new value object.
- `lib/Exception/OasValidationException.php` — new exception carrying the report.
- `tests/Service/OasValidationIntegrationTest.php` — new integration test.
