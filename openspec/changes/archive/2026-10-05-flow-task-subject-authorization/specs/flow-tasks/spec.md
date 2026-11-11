## ADDED Requirements

### Requirement: A task may only be created on an object its creator may read

Creating a task that names an object SHALL require that the creating
identity may READ that object. The decision SHALL be taken through the
canonical object read path under RBAC and multitenancy, so that it cannot
disagree with what `GET /api/objects/{register}/{schema}/{id}` answers the
same caller. A separate list of rules for this one endpoint SHALL NOT exist.

The check SHALL cover the generic anchor `objectUuid` AND every
`relations[].objectUuid` in the payload, because a relation attaches the task
to an object exactly as the anchor does.

The refusal SHALL be `404` with the wording the object endpoint uses for a
missing object. An object that is absent and an object that is merely
unreadable SHALL be indistinguishable in the response, so that creating a
task cannot be used to discover which objects exist.

The check SHALL run before any part of the task is validated, written or
audited, so a refused caller leaves no trace on the record.

Administrators SHALL be exempt, on the same grounds the object read path
exempts them: they may read every object, so the check could only pass.

The trusted in-process creation path used by the engine SHALL be unaffected,
because there the acting identity is a flow's attribution rather than the
session the read path resolves against.

A task that names no object SHALL be created exactly as before.

#### Scenario: An unrelated account is refused the object it cannot read

- **GIVEN** an account that gets 404 from `GET` on a case object
- **WHEN** it posts a task naming that case as `objectUuid`
- **THEN** the create SHALL be refused with 404 and the object endpoint's
  wording, and no task row, audit entry or candidate row SHALL be written

#### Scenario: An entitled account still creates its task

- **GIVEN** an account that may read the case object
- **WHEN** it posts a task naming that case as `objectUuid`
- **THEN** the task SHALL be created as before, anchored to that object

#### Scenario: A relation is checked like the anchor

- **GIVEN** an account that may read the object it names as the anchor and
  may not read the object it names in a relation
- **WHEN** it posts the task
- **THEN** the create SHALL be refused, naming the relation's object

#### Scenario: A standalone task is unaffected

- **GIVEN** an account creating a task that names no object at all
- **WHEN** it posts the task
- **THEN** the task SHALL be created, because there is no object to be
  entitled to
