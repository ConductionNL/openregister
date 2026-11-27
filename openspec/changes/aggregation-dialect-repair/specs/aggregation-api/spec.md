## ADDED Requirements

### Requirement: The named-annotation aggregation surface MUST validate filter/groupBy fields against the schema that owns them
`AggregationAnnotationValidator::validate()` MUST resolve each `filter`/`where`/`groupBy.field`
reference against the schema that declares the field: the related schema when the aggregation
addresses one (an explicit `from`, or a field reached through the declaring schema's relations),
otherwise the declaring schema. A field on a related schema MUST NOT be rejected as unknown.

#### Scenario: A filter field on a related schema is accepted
- **GIVEN** schema `meeting` declares an aggregation referencing a filter field that lives on a
  schema `meeting` has a relation to (not a property of `meeting` itself)
- **WHEN** the schema is saved
- **THEN** the aggregation MUST be accepted with no `aggregation-filter-field-unknown` error

#### Scenario: A genuinely unknown field is still rejected
- **GIVEN** schema `meeting` declares an aggregation whose filter references a field that is neither
  a property of `meeting` nor of any schema `meeting` relates to (a typo)
- **WHEN** the schema is saved
- **THEN** the aggregation MUST be rejected with an `aggregation-filter-field-unknown` error

#### Scenario: An explicit cross-schema `from` spec continues to validate as before
- **GIVEN** an aggregation spec carrying a top-level `from` key naming a foreign schema slug
- **WHEN** the schema is saved
- **THEN** field-existence checks MUST continue to be skipped for that spec's filter/groupBy fields,
  unchanged from current behavior

#### Scenario: A previously-silently-discarded aggregation now runs
- **GIVEN** schema `meeting` declares `quorumPercentage`/`actionItemCount` aggregations referencing
  fields on a related schema, previously discarded with an `annotation on schema "meeting" is
  invalid` warning
- **WHEN** the schema is saved after this fix
- **THEN** the aggregation MUST validate successfully and MUST be resolvable via
  `/api/objects/aggregations/{register}/meeting/{name}`
