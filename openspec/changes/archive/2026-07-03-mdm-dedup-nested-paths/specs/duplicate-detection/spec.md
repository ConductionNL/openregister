## MODIFIED Requirements

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
`weight` (default 1). A rule's (and a blocking key's) `field` MAY be a dotted path (e.g.
`goldenRecord.email`) to address a value nested under the object payload's top level; resolution
SHALL traverse each dot-separated segment in order and yield `null` (never throw) as soon as any
segment is missing or its container is not an array, and a plain, dot-free key SHALL resolve
identically to a direct top-level read. The pair score SHALL be the weight-normalised mean of
per-rule similarities; a pair SHALL be reported only when its score is at or above the effective
`threshold` (the caller's value, else the annotation's, else `0.85`). The annotation MAY
declare `blockingKeys`; when present, only objects sharing an equal normalised composite
blocking token SHALL be compared, so detection does not degrade to an all-pairs scan; blocking
keys are resolved through the same dot-path-aware accessor as match-rule fields. The
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

#### Scenario: Match rule resolves a nested dot-path field
- **WHEN** a match rule declares `field: "goldenRecord.email"` (`exact`) and two objects share the same value at `object.goldenRecord.email`, with different top-level `email` values (or none at all)
- **THEN** the two objects MUST be compared on the nested value and MUST be flagged as a pair when the score meets the threshold
- **AND** `matchedOn` MUST include `"goldenRecord.email"`

#### Scenario: Blocking key resolves a nested dot-path field
- **WHEN** the annotation declares `blockingKeys: ["goldenRecord.postalCode"]` and two objects share an equal value at `object.goldenRecord.postalCode`
- **THEN** the two objects MUST land in the same blocking bucket and be eligible for comparison

#### Scenario: Missing segment in a nested path resolves to null, not an error
- **WHEN** a match rule declares `field: "goldenRecord.email"` and an object's payload has no `goldenRecord` key at all (or `goldenRecord` is not an array)
- **THEN** the resolved value for that object MUST be `null`
- **AND** the comparison MUST proceed without throwing, scoring that field `0.0` similarity for the pair

#### Scenario: Plain top-level field resolution is unchanged
- **WHEN** a match rule or blocking key declares a plain, dot-free field name (e.g. `"email"`)
- **THEN** resolution MUST behave exactly as a direct top-level array read, with no change in outcome from prior behaviour
