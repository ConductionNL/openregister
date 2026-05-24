---
status: draft
---

# OAS Generation — Retrofit Delta (2026-05-24)

## ADDED Requirements

### Requirement: OAS responses MUST carry a deterministic ETag and short-circuit matching `If-None-Match` requests with 304

The OAS endpoint MUST compute a strong `ETag` per RFC 7232 over a canonical-JSON representation of the generated spec, return it in the response header, and respond with `304 Not Modified` (no body) when the client sends an `If-None-Match` header containing a matching ETag. Canonicalisation sorts object keys recursively so two structurally-equivalent specs differing only in key order produce the same ETag. The 304 short-circuit MUST be skipped when `?validate=true` is set so the validation summary is always fresh.

#### Scenario: First request returns the spec with an ETag header

- **GIVEN** an OAS endpoint configured with an `OasETagComputer`
- **WHEN** `GET /api/oas` (or `/api/oas/{id}`) is requested without `If-None-Match`
- **THEN** the response is `200 OK` with the OAS body
- **AND** the response carries an `ETag` header whose value is a strong tag of the form `"<sha256-hex>"`
- **AND** the ETag is the SHA-256 of `json_encode($canonicalised)` where `$canonicalised` recursively sorts associative-array keys and preserves list order

#### Scenario: Subsequent request with matching `If-None-Match` returns 304

- **GIVEN** a previously-returned ETag value `"abc123..."`
- **WHEN** `GET /api/oas` is requested with `If-None-Match: "abc123..."`
- **THEN** the response is `304 Not Modified`
- **AND** the response body is `null` / empty
- **AND** the response carries the same `ETag` header

#### Scenario: `If-None-Match: *` always short-circuits

- **WHEN** `If-None-Match: *` is sent
- **THEN** the endpoint SHALL return `304 Not Modified`

#### Scenario: Weak-tag prefix is stripped per RFC 7232

- **GIVEN** a current ETag `"abc"`
- **WHEN** the client sends `If-None-Match: W/"abc"`
- **THEN** the comparison strips the `W/` prefix and SHALL match

#### Scenario: Validation mode bypasses the 304 short-circuit

- **WHEN** `GET /api/oas?validate=true` is requested with a matching `If-None-Match`
- **THEN** the endpoint SHALL still compute and return the full body so the `x-validation-summary` extension is fresh

#### Notes

- The ETag computer is wired optionally on `OasController` — when not present the controller MUST fall back to a plain `200 OK` response without ETag handling.
- The boolean query parameter parser shared by `?strict=` and `?validate=` accepts `true|1|yes|on` (case-insensitive); everything else (including missing) is `false`.

---

### Requirement: Error responses from the OAS endpoint MUST follow RFC 7807 `application/problem+json`

Error responses produced by the OAS endpoint and its validation middleware MUST conform to RFC 7807. Every problem document carries `type` (default `about:blank`), `title`, `status`, plus optional `detail`, `instance`, and free-form extension fields. Custom extensions MUST NOT overwrite the standard fields. The HTTP response MUST advertise `Content-Type: application/problem+json`.

#### Scenario: Validation failure returns a 422 problem document

- **GIVEN** OAS request-body validation produced a list of `{path, message}` errors
- **WHEN** the middleware emits the failure response
- **THEN** the body SHALL be `{type: 'about:blank', title: 'Validation failed', status: 422, detail: <reason>, instance: <request URI>, errors: [...] }`
- **AND** the `Content-Type` header SHALL be `application/problem+json`

#### Scenario: Not-found problem document shape

- **WHEN** a `notFound(detail, instance)` problem is built
- **THEN** the document SHALL be `{type: 'about:blank', title: 'Not found', status: 404, ...}` with `detail` and `instance` omitted when empty

#### Scenario: Conflict problem document shape

- **WHEN** a `conflict(detail, instance)` problem is built (e.g. lock conflict, ETag mismatch)
- **THEN** the document SHALL be `{type: 'about:blank', title: 'Conflict', status: 409, ...}`

#### Scenario: Custom extensions cannot overwrite standard fields

- **GIVEN** a caller passes `extensions: ['title' => 'Spoof', 'errors' => [...]]`
- **WHEN** the problem document is built
- **THEN** the `title` SHALL remain the builder-controlled value
- **AND** the `errors` extension SHALL be added to the document

---

### Requirement: Validation issues MUST be collected in an `OasValidationReport` with stable severity and machine codes

Internal OAS validation passes MUST record findings in an `OasValidationReport` value object that distinguishes three severity levels (`error`, `warning`, `auto_corrected`) and carries stable machine codes so downstream consumers (CI, dashboards, the `x-validation-summary` extension) can filter and aggregate without parsing free-form text. The report MUST expose a compact summary shape suitable for embedding in the OAS document.

#### Scenario: Recorded issue shape

- **GIVEN** `addError(path, message, code)`, `addWarning(path, message, code)`, or `addAutoCorrection(path, message, code)` is called
- **THEN** the report SHALL store an entry `{path, message, code, severity}` where `severity` is one of `'error' | 'warning' | 'auto_corrected'`
- **AND** `path` is a JSON Pointer (RFC 6901) identifying the location in the OAS document
- **AND** `code` is one of the stable `CODE_*` constants (e.g. `dangling_ref`, `invalid_allof`, `duplicate_operation_id`, `orphan_tag`, `unused_tag`, `invalid_http_method`, `invalid_status_code`, `invalid_property_type`, `missing_array_items`, `relative_server_url`)

#### Scenario: Summary shape embedded as `x-validation-summary`

- **WHEN** `toSummary()` is called on a report
- **THEN** the return SHALL be `{passed: bool, errors: int, warnings: int, autoCorrected: int, issues: [...] }`
- **AND** `passed` SHALL be `true` iff no error-severity issues were recorded
- **AND** the per-severity counts SHALL equal the counts when filtered by `getErrors()` / `getWarnings()` / `getAutoCorrections()`

#### Scenario: Strict mode surfaces the report on the exception

- **GIVEN** `OasService::createOas(registerId, strict: true)` is called
- **WHEN** the validation pass records one or more error-severity issues
- **THEN** the service SHALL throw `OasValidationException` carrying the report
- **AND** the controller SHALL translate the exception into a `422` response with body `{error, summary}` where `summary` is the report's `toSummary()` output

---

### Requirement: An opt-in middleware MUST validate JSON request bodies against per-operation OAS schemas

OpenRegister MUST provide a `before-controller` middleware that, when opted in via the `?_validate=true` query parameter on POST/PUT/PATCH requests, looks up the operation's request-body schema and validates the decoded body against it. On a validation miss the middleware MUST short-circuit with an RFC 7807 `422` problem-json response carrying the flat `{path, message}` error list. GET/HEAD/DELETE/OPTIONS requests SHALL bypass validation. When the resolver returns `null` (no schema known for the operation) the middleware SHALL pass the request through unmodified.

#### Scenario: Non-write verb bypasses validation

- **GIVEN** a `GET` / `HEAD` / `DELETE` / `OPTIONS` request
- **WHEN** the middleware's `beforeController` runs
- **THEN** the request SHALL pass through with no validation

#### Scenario: Missing opt-in bypasses validation

- **GIVEN** a `POST` request without `?_validate=true`
- **WHEN** the middleware runs
- **THEN** the request SHALL pass through with no validation

#### Scenario: Opt-in truthy values

- **GIVEN** `?_validate=` is set to one of `true | 1 | yes | on` (case-insensitive)
- **THEN** validation SHALL be enabled

#### Scenario: No resolver schema means pass-through

- **GIVEN** validation is opted in
- **AND** the per-operation schema resolver returns `null`
- **WHEN** the middleware runs
- **THEN** the request SHALL pass through unmodified

#### Scenario: Validation failure becomes a 422 problem-json response

- **GIVEN** the resolver returns a schema and the request body fails validation
- **WHEN** the middleware runs
- **THEN** it SHALL throw an internal `OasValidationFailureException` carrying the flat `{path, message}[]` error list
- **AND** its `afterException` handler SHALL convert that exception into a `422 JSONResponse` whose body is built by `ProblemDetailsBuilder::validationFailed()` and whose `Content-Type` header is `application/problem+json`

#### Scenario: opis/json-schema errors are flattened

- **GIVEN** the body validator wraps `opis/json-schema`
- **WHEN** validation fails with a tree of `ValidationError` instances
- **THEN** the validator SHALL flatten the tree into a list of `{path, message}` entries where `path` is the JSON Pointer of the offending field (or `/` when unknown) and `message` is the opis message (or the keyword name when no message is available)

#### Notes

- The resolver `OasValidationMiddleware::resolveOperationSchema()` is currently a stub returning `null` — when `OasService` exposes a per-operation schema lookup the resolver will use that. Until then this middleware is effectively a no-op; consumers needing validation can still call `OasRequestValidator::validate()` directly with a schema in hand.

---

### Requirement: The OAS generator MUST run an internal validation pipeline producing structured issues

Inside `OasService::createOas()` the generator MUST run a multi-pass validation pipeline that records issues on the `OasValidationReport`. The pipeline covers (1) OpenAPI 3.1 meta-schema conformance, (2) NLGov API-01 (allowed HTTP methods) and API-03 (allowed status codes) rules, and (3) property-definition sanitization that strips non-OpenAPI keywords and coerces malformed inputs to valid shapes. Validation MUST NOT block generation in the default mode; issues are auto-corrected where possible and surfaced via the report. Strict mode (`strict: true`) MUST throw `OasValidationException` when any error-severity issue is recorded.

#### Scenario: Property definitions are stripped to the OpenAPI 3.1 keyword whitelist

- **GIVEN** a raw schema property definition containing OR-internal fields (`objectConfiguration`, `inversedBy`, `authorization`, `defaultBehavior`, ...) alongside OpenAPI keywords
- **WHEN** the generator sanitizes the definition
- **THEN** only the OpenAPI 3.1 keyword whitelist SHALL survive: `type`, `format`, `description`, `example(s)`, `default`, `enum`, `const`, `multipleOf`, `maximum`/`minimum` (+`exclusive*`), `maxLength`/`minLength`, `pattern`, `maxItems`/`minItems`, `uniqueItems`, `maxProperties`/`minProperties`, `required`, `properties`, `items`, `additionalProperties`, `allOf`/`anyOf`/`oneOf`/`not`, `$ref`, `nullable`, `readOnly`/`writeOnly`, `title`
- **AND** non-array values for the `items`, `properties`, and composition keywords SHALL recurse and be sanitized too
- **AND** empty `oneOf`/`anyOf`/`allOf`/`enum` SHALL be removed
- **AND** an empty `$ref` SHALL be removed; a bare `$ref` value (no leading `#/`) SHALL be normalised to `#/components/schemas/<sanitized-name>`
- **AND** property-level `required: true` (a boolean) SHALL be removed because OpenAPI 3.1 requires `required` to be an array of property names
- **AND** an unrecognised `type` value SHALL be coerced to `"string"`
- **AND** an `items` value that is a sequential list SHALL be collapsed to its first element (or `{type: "string"}` when the list is empty)
- **AND** a property with `type: "array"` SHALL gain a default `items: {type: "string"}` when none is set
- **AND** a property with neither `type` nor `$ref` SHALL gain `type: "string"`

#### Scenario: NLGov API-01 — only standard HTTP methods are allowed

- **GIVEN** an operation under `paths` with a method outside `{get, post, put, delete, parameters}`
- **WHEN** the NLGov-rules pass runs
- **THEN** the report SHALL record an error with code `CODE_INVALID_HTTP_METHOD` at path `paths.<path>.<method>`

#### Scenario: NLGov API-03 — only the allowed status-code set may appear

- **GIVEN** an operation declaring a response with a status code outside `{200, 201, 204, 400, 401, 403, 404, 422, 500, default}`
- **WHEN** the NLGov-rules pass runs
- **THEN** the report SHALL record a warning with code `CODE_INVALID_STATUS_CODE` at path `paths.<path>.<method>.responses.<code>`

#### Scenario: OpenAPI 3.1 meta-schema validation surfaces errors via the report

- **GIVEN** an `OasRequestValidator` is wired as the meta-validator
- **AND** `lib/Service/Oas/Resources/meta/openapi-3.1.0.json` exists
- **WHEN** the generated OAS document fails the OpenAPI 3.1 meta-schema
- **THEN** every opis-reported violation SHALL be recorded on the report with code `'meta-schema-violation'` and a message prefixed `OpenAPI 3.1 meta-schema violation: `

#### Scenario: Meta-schema validator errors MUST NOT block generation

- **GIVEN** the meta-validator itself throws (e.g. a `Throwable` while validating)
- **WHEN** generation runs
- **THEN** the exception SHALL be logged at warning level on the OAS service's logger
- **AND** generation SHALL continue without recording the failure on the report

#### Scenario: Strict mode converts error-severity issues into a 422 response

- **GIVEN** the pipeline records at least one error-severity issue
- **WHEN** `createOas(strict: true)` is invoked
- **THEN** the service SHALL throw `OasValidationException`
- **AND** the controller SHALL respond `422` with body `{error, summary}` where `summary` is the report's `toSummary()` output

#### Notes

- The validation pipeline also runs `validateServerUrls`, `validateOperationIdUniqueness` (auto-suffixes collisions), `validateTagConsistency`, and `validateSchemaReferences` (recursively fixes empty/invalid `allOf`/`$ref`). Those passes are existing implementation details supporting this requirement; only the property-definition sanitization, NLGov rules, and meta-schema check are described here because they encode policy decisions (the OpenAPI keyword whitelist; the NLGov allowed-method/status-code sets) rather than mechanical clean-up.
