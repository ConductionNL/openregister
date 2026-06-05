# audit-trail-immutable (delta)

## ADDED Requirements

### Requirement: Append-only schema-write refusals MUST return a structured 405 envelope
The system MUST raise `AppendOnlyException` carrying HTTP code 405 and a machine-readable response body when an UPDATE or DELETE is attempted on an object whose schema is declared `appendOnly: true` (used for audit logs, xAPI statements, compliance attestations where past-record immutability is required). The body MUST let audit-log clients distinguish an "append-only refusal" from a lock (423) or a not-found (404) without parsing the human-readable message.

This is distinct from the audit-trail *API endpoint* 405 (see "Audit trail
entries MUST NOT be deletable or modifiable"), which returns a prose-only
`{"error": "..."}` body; the append-only schema-write refusal carries a stable
error *code* plus schema/operation context.

#### Scenario: toResponseBody envelope shape

- GIVEN an `AppendOnlyException` constructed with
  `schemaIdentifier = "xapi-statements"` and `operation = "delete"`
- WHEN `toResponseBody()` is called
- THEN it MUST return exactly the keys `error`, `message`, `schema`, `operation`
- AND `error` MUST be the canonical code `"SCHEMA_APPEND_ONLY"`
- AND `schema` MUST be `"xapi-statements"`
- AND `operation` MUST be `"delete"`

#### Scenario: HTTP code and default operation

- GIVEN an `AppendOnlyException` constructed with only a `schemaIdentifier`
- WHEN the exception is inspected
- THEN `getCode()` MUST be `405`
- AND `getOperation()` MUST default to `"update"`
- AND `getMessage()` MUST be
  `"SCHEMA_APPEND_ONLY: Schema \"<schema>\" is append-only; <operation> operations are not permitted."`

#### Scenario: Getters expose constructor args

- GIVEN an `AppendOnlyException` constructed with
  `schemaIdentifier = "attestations"` and `operation = "update"`
- WHEN `getSchemaIdentifier()` and `getOperation()` are called
- THEN they MUST return `"attestations"` and `"update"` unchanged
