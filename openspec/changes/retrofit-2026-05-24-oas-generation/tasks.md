# Tasks: Retrofit — Reverse-Spec `oas-generation` (sub-behaviors)

All tasks are observation-only retrofit annotations. No behavior change.

## Task 1 — ETag computation and `If-None-Match` short-circuit

- [x] Document `OasETagComputer::hash($spec)` — recursive key-sort canonicalisation followed by SHA-256 of `json_encode($canonical)`; output is hex without quotes (the quoted strong tag is produced by `computeETag()` which wraps `hash()` in `"…"`).
- [x] Document `OasETagComputer::matches($ifNoneMatch, $currentETag)` — RFC 7232 weak comparison: `*` always matches, comma-separated list of candidates, leading `W/` stripped, whitespace trimmed.
- [x] Document `OasETagComputer::canonicalise($value)` — recursively sorts associative-array keys ascending (`sort($keys)`) and preserves list order (`array_is_list()` returns true → keep order); scalars pass through unchanged.
- [x] Document the `OasController` integration: `If-None-Match` short-circuit returns `JSONResponse(null, 304)` with the `ETag` header; the short-circuit is skipped when `?validate=true` so the `x-validation-summary` is always fresh.

## Task 2 — RFC 7807 problem-details builder

- [x] Document `ProblemDetailsBuilder::validationFailed($errors, $detail, $instance)` — returns the 422-shaped problem with title `"Validation failed"`, `type: about:blank`, and the validator's flat `{path, message}[]` list under `errors`.
- [x] Document `ProblemDetailsBuilder::notFound($detail, $instance)` — returns a 404 problem with title `"Not found"`; empty `detail`/`instance` are omitted from the body.
- [x] Document `ProblemDetailsBuilder::conflict($detail, $instance)` — returns a 409 problem with title `"Conflict"` (used for lock conflicts and ETag mismatches).
- [x] Document the global rule: custom extensions passed to `build()` MUST NOT overwrite the standard `type/title/status/detail/instance` fields (they're filtered out before merging); response `Content-Type` is always `application/problem+json` (constant `ProblemDetailsBuilder::CONTENT_TYPE`).

## Task 3 — `OasValidationReport` issue collection and severity model

- [x] Document `OasValidationReport::addError($path, $message, $code)` — appends `{path, message, code, severity: 'error'}` where `code` is one of the `CODE_*` constants (`dangling_ref`, `invalid_allof`, `duplicate_operation_id`, `orphan_tag`, `unused_tag`, `invalid_http_method`, `invalid_status_code`, `invalid_property_type`, `missing_array_items`, `relative_server_url`).
- [x] Document `OasValidationReport::addWarning($path, $message, $code)` — same shape with `severity: 'warning'`.
- [x] Document `OasValidationReport::addAutoCorrection($path, $message, $code)` — same shape with `severity: 'auto_corrected'`; used by passes that mutate the OAS in place (e.g. `validateOperationIdUniqueness` appending `_2`/`_3` suffixes).
- [x] Document the summary contract: `toSummary()` returns `{passed, errors, warnings, autoCorrected, issues}` where `passed = !hasErrors()`. `passed`/`hasErrors` are driven exclusively by error-severity issues; warnings and auto-corrections do not fail the pass.

## Task 4 — Opt-in OAS request-body validation middleware

- [x] Document `OasRequestValidator::validate($body, $schema)` — JSON round-trip to convert PHP arrays into the `stdClass` shape opis expects, run opis, return `[]` on success or a flat `{path, message}[]` list on failure.
- [x] Document `OasRequestValidator::isValid($body, $schema)` — convenience boolean wrapper around `validate()`.
- [x] Document `OasRequestValidator::collectErrors($error, &$errors)` — recursive flattener over opis `ValidationError` trees: uses defensive `method_exists` guards for shape stability across opis versions, extracts `path` from `$error->data()->fullPath()` (joined with `/`), prefers `$error->message()` over `$error->keyword()`, and falls back to `'/'` + `'value does not validate'`.
- [x] Document `OasValidationMiddleware::beforeController($controller, $methodName)` — opt-in gating: bypass unless verb is POST/PUT/PATCH AND `?_validate=` is `true|1|yes|on` (case-insensitive); resolver lookup returns `null` → pass-through; resolver returns a schema → validate `$request->getParams()` and throw `OasValidationFailureException` on a non-empty error list.
- [x] Document `OasValidationMiddleware::afterException()` — only handles `OasValidationFailureException`; builds `ProblemDetailsBuilder::validationFailed(errors, detail, instance)` and emits a `422 JSONResponse` with `Content-Type: application/problem+json`; rethrows any other exception.

## Task 5 — Internal validation pipeline (sanitization, NLGov rules, meta-schema)

- [x] Document `OasService::sanitizePropertyDefinition($propertyDefinition)` — full OpenAPI 3.1 keyword whitelist enforcement: non-array input becomes `{type: 'string', description: 'Property value'}`; copy only the 23 allowed keywords; recurse into `items`/`properties`/`oneOf`/`anyOf`/`allOf`; remove empty composition arrays and empty `enum`/`$ref`; normalise bare `$ref` to `#/components/schemas/<sanitized>`; coerce property-level `required: bool` (drop), unrecognised `type` (→`"string"`), `items` as sequential list (→ first element), missing `items` on array types (→ `{type: 'string'}`), missing `type`/`$ref` (→ `type: 'string'`), missing `description` (→ `'Property value'`).
- [x] Document `OasService::validateAgainstMetaSchema()` — when the optional `OasRequestValidator` is wired AND `Resources/meta/openapi-3.1.0.json` exists, runs the generated OAS document through it; opis errors are added with code `'meta-schema-violation'`; a `Throwable` from the validator is logged at warning level and swallowed (validation MUST NOT block generation).
- [x] Document `OasService::validateNlGovRules()` — NLGov API-01 (allowed methods: `get/post/put/delete/parameters`) records `CODE_INVALID_HTTP_METHOD` errors; NLGov API-03 (allowed status codes: `200/201/204/400/401/403/404/422/500/default`) records `CODE_INVALID_STATUS_CODE` warnings.
- [x] Document `OasController::boolQueryParam($name)` — boolean query-param parser shared by `?strict=` and `?validate=`: accepts `true|1|yes|on` (case-insensitive) as truthy; everything else (including missing/empty) is `false`.
