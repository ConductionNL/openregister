## Purpose

Makes a discarded `x-openregister-*` advisory-annotation declaration visible in the schema-save API
response, so a schema author cannot mistake "the save succeeded" for "the annotated feature works" —
closing a discard path that previously surfaced only as a `nextcloud.log` warning line.

## ADDED Requirements

### Requirement: A schema-save response MUST surface every discarded advisory-annotation validation error
When any of the schema-save annotation validators (`lifecycle`, `aggregations`, `calculations`,
`quality`, `dedup`, `survivorship`, `merge`) returns one or more validation errors for a schema being
saved, the schema-save response MUST include a `warnings` list entry for each discarded annotation,
naming the annotation family, the schema slug, and the validator's error messages. The schema import
MUST continue exactly as it does today (an advisory annotation failing validation MUST NOT abort the
save) — this requirement changes only what is surfaced in the response, not whether the save
succeeds.

#### Scenario: A discarded aggregations annotation appears in the save response warnings
- **GIVEN** a schema is saved with an `x-openregister-aggregations` block that fails validation
- **WHEN** the save completes
- **THEN** the response MUST include a `warnings` entry with `family: "aggregations"`, the schema
  slug, and the validator's error message(s)
- **AND** the schema MUST still be saved (the save MUST NOT fail)

#### Scenario: A schema with no annotation problems has an empty warnings list
- **GIVEN** a schema is saved with valid (or absent) `x-openregister-*` annotations
- **WHEN** the save completes
- **THEN** the response's `warnings` list MUST be empty

#### Scenario: Multiple discarded annotation families are each reported
- **GIVEN** a schema's save has both an invalid `x-openregister-calculations` block and an invalid
  `x-openregister-quality` block
- **WHEN** the save completes
- **THEN** the response MUST include two separate `warnings` entries, one per family, each with its
  own error messages

#### Scenario: A discarded annotation is still logged, unchanged
- **GIVEN** a schema save triggers a discarded annotation
- **WHEN** the save completes
- **THEN** a `nextcloud.log` warning MUST still be recorded naming the schema and annotation family,
  in addition to (not instead of) the response `warnings` entry
