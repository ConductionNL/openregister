---
retrofit: true
---

# OAS Validation — RFC 7807 Problem Details (retrofit)

## Purpose

Records the observed, already-shipping RFC 7807 problem-details behaviour that
satisfies `oas-validation#API-46`. The coverage scanner flagged API-46 as
having no annotated implementation; investigation found a complete
implementation in `lib/Service/Oas/ProblemDetailsBuilder.php` and the `Error`
schema in `lib/Service/Resources/BaseOas.json`. This retrofit annotates the
builder and specifies the observed behaviour.

## ADDED Requirements

### Requirement: Error responses MUST follow RFC 7807 problem details (API-46)

OpenRegister error responses MUST be emitted as RFC 7807 problem documents.
`ProblemDetailsBuilder::build()` MUST produce a payload carrying `type`
(defaulting to `about:blank`), `title`, and `status`, with optional `detail`
and `instance`, plus free-form extensions (e.g. `errors`, legacy `code`). The
response MUST carry `Content-Type: application/problem+json`. The `Error`
schema in `BaseOas.json` MUST document these fields, retaining the legacy
`error` and `code` aliases for backward compatibility.

#### Scenario: Validation failure returns an RFC 7807 422 document
- **GIVEN** a request fails OAS schema validation
- **WHEN** `ProblemDetailsBuilder::validationFailed()` builds the response
- **THEN** the payload MUST include `type: "about:blank"`, `title: "Validation failed"`, and `status: 422`
- **AND** the per-field errors MUST be carried in the `errors` extension array
- **AND** the response MUST be sent with `Content-Type: application/problem+json`

#### Scenario: Not-found error carries problem-details shape
- **GIVEN** an object lookup misses
- **WHEN** `ProblemDetailsBuilder::notFound()` builds the response
- **THEN** the payload MUST include `title: "Not found"` and `status: 404`
- **AND** the `Error` schema in `BaseOas.json` MUST declare `type`, `title`, `status`, `detail`, and `instance` per RFC 7807
