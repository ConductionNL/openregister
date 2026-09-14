# object-interactions

## ADDED Requirements

### Requirement: A note carries a visibility, internal by default

A note SHALL accept `visibility` of `internal` or `public` on create and
update, defaulting to `internal` and reading as `internal` when absent. Only
a user with `update` on the object SHALL set or change it, and a change SHALL
write an audit entry on the object naming the note and both values.

#### Scenario: a note written without the flag is internal

- **GIVEN** a note created with only a message
- **WHEN** it is read
- **THEN** `visibility` is `internal`
- @e2e exclude {default, covered by NoteService unit tests}

#### Scenario: making a note public is audited

- **GIVEN** an internal note and a user with `update`
- **WHEN** the user sets `visibility` to `public`
- **THEN** the object's audit trail gains an entry naming the note, `internal` and `public`
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/timeline-visibility.spec.ts when the toggle ships}

#### Scenario: a reader cannot flip the flag

- **GIVEN** a user with `read` but not `update` on the object
- **WHEN** the user tries to set `visibility`
- **THEN** the response is 403 and the note is unchanged
- @e2e exclude {RBAC guard, covered by unit tests}
