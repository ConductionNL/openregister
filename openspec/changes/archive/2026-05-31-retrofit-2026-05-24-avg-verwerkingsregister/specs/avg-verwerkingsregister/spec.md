## ADDED Requirements

### Requirement: The system MUST surface schemas with detected PII but no processing-activity annotation

The system MUST provide a compliance check that lists every `(register, schema)` pair where personal-data entities have been recorded in the GDPR entity store but neither the schema nor its enclosing register carries the `x-openregister-processing-activity` annotation key in its `configuration` column. Each row returned MUST be operator-actionable: annotate the schema or register with a processing-activity reference, remove the personal data, or document a rationale for the gap.

`AvgComplianceService::findUnannotatedSchemasWithPii()` aggregates `oc_openregister_entities` joined against `oc_openregister_entity_relations` by `(register_id, schema_id)`, counts the distinct PII rows per pair, then filters out any pair where the schema OR the register carries a non-empty string value under the `x-openregister-processing-activity` key in its configuration. The check is also exposed via `AvgComplianceService::runAllChecks()` for a compliance-dashboard envelope.

#### Scenario: Schema and register both lack the annotation, PII present
- **GIVEN** schema `inwoners` and its enclosing register `brp` exist
- **AND** 12 `GdprEntity` rows are linked via `entity_relations` to objects in `(brp, inwoners)`
- **AND** neither `inwoners.configuration['x-openregister-processing-activity']` nor `brp.configuration['x-openregister-processing-activity']` is set to a non-empty string
- **WHEN** `findUnannotatedSchemasWithPii()` is called
- **THEN** the result MUST contain one envelope for `(brp, inwoners)` with `piiCount: 12`, `registerHasAnnotation: false`, `schemaHasAnnotation: false`, and `schemaTitle` populated from `SchemaMapper::find()` (or empty string on lookup failure)

#### Scenario: Schema carries the annotation
- **GIVEN** PII is detected for `(brp, inwoners)`
- **AND** `inwoners.configuration['x-openregister-processing-activity']` equals `"verwerkings-act-uuid-abc"`
- **WHEN** the check runs
- **THEN** the `(brp, inwoners)` pair MUST be omitted from the result

#### Scenario: Register carries the annotation (schema inherits)
- **GIVEN** PII is detected for `(brp, inwoners)`
- **AND** `inwoners.configuration` has no annotation key but `brp.configuration['x-openregister-processing-activity']` equals `"verwerkings-act-uuid-xyz"`
- **WHEN** the check runs
- **THEN** the `(brp, inwoners)` pair MUST be omitted (register-level annotation satisfies the check for all schemas in that register)

#### Scenario: Legacy entity_relations row without register_id or schema_id
- **GIVEN** an `entity_relations` row predates the disambiguation migration and has empty `register_id` or empty `schema_id`
- **WHEN** the aggregation result is iterated
- **THEN** the row MUST be skipped (it cannot be attributed to a `(register, schema)` pair) and MUST NOT contribute to the issues list

#### Scenario: Aggregation query failure degrades gracefully
- **GIVEN** the underlying database join throws (e.g., schema migration mid-flight)
- **WHEN** `findUnannotatedSchemasWithPii()` is called
- **THEN** the method MUST log a warning with message `[AVG compliance] Failed to aggregate PII by schema` and the exception message in context
- **AND** the method MUST return an empty array (the dashboard treats this as "no issues this run")

#### Scenario: Register or schema lookup failure during annotation check
- **GIVEN** a `(register_id, schema_id)` pair appears in the aggregation result
- **AND** `RegisterMapper::find()` throws `DoesNotExistException` or any other Throwable for that `register_id`
- **WHEN** `registerHasAnnotation()` evaluates the register
- **THEN** the method MUST treat the register as not annotated (return `false`) rather than propagating the exception
- **AND** the same fallback MUST apply to `schemaHasAnnotation()` when `SchemaMapper::find()` throws
- **AND** `resolveSchemaTitle()` MUST return an empty string on any lookup failure (best-effort title resolution)

#### Notes
- Both annotation checks call the mapper with `_rbac: false, _multitenancy: false` — the compliance scan deliberately bypasses tenant filtering so an organisation-scoped privacy officer sees gaps across all registers visible to the scan. The caller is responsible for restricting visibility of the result to authorised users.
- The annotation key is exposed as the public constant `AvgComplianceService::ANNOTATION_KEY` so other services may reuse the same key when writing the annotation. The value is expected to be a non-empty string (e.g., a verwerkingsactiviteit UUID); empty strings or non-string values do NOT satisfy the check.
