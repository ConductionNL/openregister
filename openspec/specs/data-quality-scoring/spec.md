# data-quality-scoring Specification

## Purpose
TBD - created by archiving change mdm-foundation. Update Purpose after archive.
## Requirements
### Requirement: Declarative per-object data-quality scoring
OpenRegister SHALL support a schema annotation `x-openregister-quality` that declares a
non-empty list of quality `rules`, from which a per-object quality score in the range
`[0,1]` is computed and materialised onto the object on create and update. The annotation
key SHALL be registered in `Schema::ANNOTATION_VOCABULARY` and SHALL be shape-validated at
schema import; a malformed annotation MUST degrade to a non-fatal warning (the schema still
stores objects, the score simply is not materialised). The score SHALL be the
weight-normalised mean of each rule's sub-score, where each rule declares a `type`, a
`field`, and an optional numeric `weight` (default 1). Supported rule types: `required`
(present and non-empty → 1.0, else 0.0), `format` (value matches a named `format` of
`email`/`url`/`date` or a custom regex `pattern` → 1.0, else 0.0; absent field → 0.0), and
`freshness` (half-life decay of a date field against now → `[0,1]`; absent/unparseable → 0.0).
The score SHALL be written to the field named by the annotation's `field` (default
`qualityScore`); an optional `statusField` SHALL receive a `good`/`fair`/`poor` label derived
from optional `thresholds`. The scorer MUST be pure and null-safe: an unknown rule type or a
malformed rule contributes a zero sub-score rather than aborting, and scoring MUST never fail
the object save.

#### Scenario: Complete object scores high
- **WHEN** an object with `name`, a valid `email`, and a recent `updatedAt` is saved under a schema declaring `required` rules on `name`/`email`, a `format:email` rule, and a `freshness` rule
- **THEN** the materialised `qualityScore` MUST be at or near `1.0`

#### Scenario: Incomplete object scores lower
- **WHEN** an object missing a required field is saved under the same schema
- **THEN** the materialised `qualityScore` MUST be strictly less than `1.0`, reflecting the proportion of satisfied weighted rules

#### Scenario: Weighting biases the score
- **WHEN** two `required` rules apply with weights 3 and 1 and only the weight-3 field is present
- **THEN** the score MUST be `0.75`

#### Scenario: Invalid format scores zero for that rule
- **WHEN** a `format:email` rule applies to a field whose value is not a valid email
- **THEN** that rule's sub-score MUST be `0.0`

#### Scenario: Freshness decays with age
- **WHEN** a `freshness` rule with a 180-day half-life applies to a date field that is 180 days old
- **THEN** that rule's sub-score MUST be approximately `0.5`

#### Scenario: Status label derives from thresholds
- **WHEN** an annotation declares a `statusField` and `thresholds` `{ good: 0.8, fair: 0.5 }` and an object scores `0.9`
- **THEN** the materialised status MUST be `good`; a score of `0.6` MUST be `fair`; a score of `0.2` MUST be `poor`

#### Scenario: Malformed annotation is non-fatal
- **WHEN** a schema is imported with an `x-openregister-quality` annotation that has no `rules` or an unknown rule `type`
- **THEN** the schema import MUST still succeed
- **AND** the invalid annotation MUST be ignored with a logged warning

#### Scenario: Object with no usable rules is trivially compliant
- **WHEN** an object is scored against an empty or fully-unusable rule set
- **THEN** the score MUST be `1.0`

