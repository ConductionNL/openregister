# notificatie-engine

## ADDED Requirements

### Requirement: A rule can fire when a note is added

`x-openregister-notifications` SHALL accept the trigger `noteAdded`, which
fires when a note is added to an object of the schema, optionally restricted
to `public` or `internal` notes. A delivery for this trigger SHALL NOT reach the
note's author and SHALL NOT reach anyone who cannot read the object. Templates
SHALL be able to use the note's author and a plain-text excerpt of at most 140
characters.

#### Scenario: a watcher hears about a new comment

- **GIVEN** a task schema with a `noteAdded` rule addressing `watchers` on the nc-notification channel
- **AND** a colleague who watches task "Replace boiler" and can read it
- **WHEN** a planner posts `POST /api/objects/{register}/{schema}/{id}/notes` with a message on that task
- **THEN** the colleague receives a notification naming the planner and the task, linking to the task
- **AND** the planner receives no notification for their own note
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/notify-new-note.spec.ts}

#### Scenario: a watcher who lost access is not told

- **GIVEN** the same rule and a watcher whose read access to the task was removed
- **WHEN** a note is added to the task
- **THEN** that watcher receives nothing
- @e2e exclude {specified only; task 1.3 adds the dispatcher test, task 3.1 adds tests/e2e/ci/notify-new-note.spec.ts}

### Requirement: A rule can address the people behind referring objects

`x-openregister-notifications` SHALL accept a recipient kind `referrers` that
names a register, a schema and a property. It SHALL find the objects of that
schema whose property refers to the triggering object, or to the objects the
triggering object points at through an optional `of` property, and SHALL
resolve a nested recipient block of any other kind against each of them. It
SHALL read at most 200 referring objects and SHALL record when it stopped at
that cap. A person it reaches SHALL be told only when they can read the
triggering object.

#### Scenario: organisations using an application hear about a new version

- **GIVEN** a `moduleVersion` rule `module-version-published` with a `referrers` recipient of `of: module`, schema `usage`, property `module`, and nested `object-acl` with `read`
- **AND** two `usage` objects pointing at module "Zaaksysteem X", readable by the members of two municipalities
- **WHEN** a supplier creates a new `moduleVersion` for "Zaaksysteem X"
- **THEN** the members of both municipalities receive the new version notification
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/notify-new-note.spec.ts}

#### Scenario: a nested referrers block is refused

- **GIVEN** a functional administrator importing a schema
- **WHEN** a rule's `referrers` recipient nests another `referrers` block
- **THEN** the import reports the rule invalid, naming `recipients`
- @e2e exclude {specified only; task 2.1 adds the validator tests, task 3.1 adds tests/e2e/ci/notify-new-note.spec.ts}
