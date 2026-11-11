## ADDED Requirements

### Requirement: An update that omits a property the caller may not update keeps it

When an update payload omits a stored top-level property that carries property authorization and the caller may
not update it, the stored value SHALL be carried forward instead of being set to null. A caller who may update the
property SHALL keep PUT semantics.

#### Scenario: A PUT without the protected property keeps it
- **GIVEN** a document with `restricted: true`, writable only by group `service`
- **WHEN** a member of `staff` PUTs the document without `restricted`
- **THEN** the stored document still has `restricted: true`

#### Scenario: The group that may update it still clears it by omission
- **WHEN** a member of `service` PUTs the document without `restricted`
- **THEN** `restricted` is null
