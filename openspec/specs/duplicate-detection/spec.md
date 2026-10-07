# duplicate-detection Specification

## Purpose
Find records in one register and schema that describe the same thing. A schema declares how its objects are matched, OpenRegister scores pairs of stored objects against those rules, a form can check a candidate against the stored objects before it saves, and the schema decides what a strong match does at create: warn, or block unless an allowed group overrides it.

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

### Requirement: A candidate can be checked against the stored objects before it is saved

`POST /api/objects/{register}/{schema}/dedup-check` SHALL score an unsaved
candidate body against the schema's stored objects with the declared rules,
RBAC- and tenant-scoped and bounded by the candidate cap, SHALL return the
matches with score, matched rules and matched fields, and SHALL save
nothing.

#### Scenario: an intake form is warned

- **GIVEN** a schema with dedup rules on `requester` and `subject` and one stored object matching both
- **WHEN** a candidate with the same values is checked
- **THEN** the stored object is returned with a strong score and both fields named
- @e2e exclude {proposal only; the dossiq change duplicate-warning-at-intake adds the form e2e}

### Requirement: A schema declares what a strong match does at create

`x-openregister-dedup` MAY declare `onCreate` (`warn` by default or
`block`) and `overrideGroups`. With `block`, a create whose candidate
strongly matches a stored object SHALL be refused with 409 and the matches,
unless the caller is in an override group and sends `_dedupOverride=true`,
in which case the create SHALL succeed and SHALL be audited with the matched
objects.

#### Scenario: a blocked create is refused

- **GIVEN** `onCreate: "block"` and a strongly matching stored object
- **WHEN** a user outside the override groups creates the candidate
- **THEN** the response is 409 listing the match and nothing is saved
- @e2e exclude {save path, covered by SaveObject unit tests}

#### Scenario: an override is on record

- **GIVEN** the same schema and a user in an override group
- **WHEN** the user creates with `_dedupOverride=true`
- **THEN** the object is saved and its audit trail holds a `dedup.overridden` entry naming the matched object
- @e2e exclude {audit entry, covered by unit tests}

### Requirement: A reviewed pair is recorded as not a duplicate and stops being offered (REQ-DMD-003)

Two objects SHALL be recordable as reviewed and not the same. The record
SHALL carry both uuids in a canonical order, the actor, a reason, the moment
and a fingerprint of the property values that were compared. The duplicate
scorer SHALL exclude a pair whose fingerprint still matches its dismissal,
and SHALL offer the pair again once the fingerprint differs. A dismissal
SHALL be reversible by a caller in the declared group, and both the
dismissal and its reversal SHALL be on the audit trail.

#### Scenario: the same false pair is not offered twice

- **GIVEN** two objects the scorer pairs, dismissed as not a duplicate
- **WHEN** the candidate list is read again with neither object changed
- **THEN** the pair is absent from the list

#### Scenario: a changed record is offered again

- **GIVEN** the same dismissed pair
- **WHEN** one of the two objects changes a compared property and the candidate list is read
- **THEN** the pair is offered again
- @e2e exclude {scorer behaviour, covered by unit tests}

#### Scenario: a dismissal names who made it

- **GIVEN** a dismissed pair
- **WHEN** an administrator reads the dismissal
- **THEN** it names the actor, the reason and the moment

### Requirement: A nominated property warns when its value already exists (REQ-DMD-004)

A property MAY carry `x-openregister-unique-hint`, validated at schema save
against the properties the schema declares. A create or an update whose
value for that property already exists on another object of the same schema
SHALL return a warning naming the objects that hold it, RBAC- and
tenant-scoped and bounded by the same candidate cap as the duplicate check.
The write SHALL still succeed: the hint is an alert, not a constraint. A
schema that requires a refusal declares `onCreate: "block"`, which is a
different declaration and keeps its meaning.

#### Scenario: a second case on the same KvK number is noticed at once

- **GIVEN** a schema nominating `kvkNumber` and an existing object holding `69599084`
- **WHEN** a second object is saved with the same value
- **THEN** the save succeeds and the response warns, naming the existing object

#### Scenario: the warning fires on an update too

- **GIVEN** the same schema and an existing object holding the value
- **WHEN** another object is updated to that value
- **THEN** the response warns, naming the existing object

#### Scenario: a hint on an undeclared property is refused at schema save

- **GIVEN** a schema nominating a property it does not declare
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the property
- @e2e exclude {annotation validator, covered by unit tests}

#### Scenario: the warning respects what the caller may see

- **GIVEN** an existing object holding the value that the caller may not read
- **WHEN** the caller saves an object with the same value
- **THEN** the response reports that a match exists without naming the object
- @e2e exclude {RBAC path, covered by unit tests}
