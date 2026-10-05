# duplicate-detection

## ADDED Requirements

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
