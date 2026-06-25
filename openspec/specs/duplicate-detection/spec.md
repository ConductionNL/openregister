# duplicate-detection Specification

## Purpose
TBD - created by archiving change mdm-foundation. Update Purpose after archive.
## Requirements
### Requirement: Declarative duplicate detection over a register/schema
OpenRegister SHALL provide a DI-resolvable service `DuplicateDetectionService` exposing
`findDuplicates(register, schema, matchRules?, threshold?)` that returns scored
duplicate-candidate pairs for objects in a given register and schema. Each returned pair
SHALL carry `objectA`, `objectB`, a `score` in `[0,1]`, and a `matchedOn` list of the fields
that matched strongly. The candidate set SHALL be loaded through the object query path so that
it is RBAC- and tenant-scoped under the calling user's session, and SHALL be bounded by a
maximum candidate cap. Match rules SHALL be declared via a schema annotation
`x-openregister-dedup` (registered in `Schema::ANNOTATION_VOCABULARY`, shape-validated at
import with a malformed annotation degrading to a non-fatal warning) and used when the caller
omits `matchRules`; a caller MAY pass `matchRules` to override them ad hoc. Each match rule
declares a `field`, a `method` of `exact` (byte-identical), `normalized` (case/whitespace/
accent-folded equality), or `levenshtein` (`1 - editDistance/maxLen`), and an optional numeric
`weight` (default 1). The pair score SHALL be the weight-normalised mean of per-rule
similarities; a pair SHALL be reported only when its score is at or above the effective
`threshold` (the caller's value, else the annotation's, else `0.85`). The annotation MAY
declare `blockingKeys`; when present, only objects sharing an equal normalised composite
blocking token SHALL be compared, so detection does not degrade to an all-pairs scan. The
similarity primitives MUST be pure and null-safe (non-scalar or absent operands yield `0.0`),
and the service MUST be empty-safe (fewer than two candidates, or no usable rules, returns an
empty result).

#### Scenario: Near-duplicate pair is flagged
- **WHEN** `findDuplicates` runs over a schema with match rules on `email` (`exact`) and `name` (`normalized`) and two objects share the same email and case-insensitively-equal names
- **THEN** the result MUST contain exactly one pair for those two objects
- **AND** the pair's `score` MUST meet the threshold and `matchedOn` MUST include `email` and `name`

#### Scenario: Below-threshold pairs are excluded
- **WHEN** two objects differ on every match field so their weighted score is below the threshold
- **THEN** the result MUST NOT contain that pair

#### Scenario: Fewer than two candidates returns empty
- **WHEN** the register/schema contains zero or one object
- **THEN** the result MUST be empty

#### Scenario: Match rules fall back to the schema annotation
- **WHEN** `findDuplicates` is called with `matchRules` omitted and the schema declares `x-openregister-dedup` match rules
- **THEN** the declared rules MUST be used to score candidates

#### Scenario: Blocking keys restrict comparison
- **WHEN** the annotation declares a blocking key and two otherwise-matching objects have different blocking-key values
- **THEN** those two objects MUST NOT be compared and MUST NOT appear as a pair

#### Scenario: No usable rules returns empty
- **WHEN** neither the caller nor the schema annotation provides any well-formed match rule
- **THEN** the result MUST be empty

#### Scenario: Candidate loading is access-scoped
- **WHEN** the candidate set is loaded for detection
- **THEN** it MUST be retrieved through the RBAC- and tenant-scoped object query path under the calling user's session

