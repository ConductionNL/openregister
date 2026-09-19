# rbac-scopes

## ADDED Requirements

### Requirement: A schema declares a department by role matrix keyed on an object field

A schema's `authorization` block SHALL accept a `matrix` declaration naming
an object field, a user source (a group prefix or a person-schema property),
and rows of (field value, role group, actions). The engine SHALL compile
each row into a conditional scope on that schema so that enforcement runs
through the existing PHP and SQL paths and stays identical between them.
The value `$self` in a row SHALL resolve to the current user's own values
from the user source at query time.

#### Scenario: a group reads only its department's objects

- **GIVEN** schema `case` with a matrix on field `department`, user source group prefix `dept:`, and a row `$self` for group `handlers` with actions `read`
- **AND** user A in groups `handlers` and `dept:VTH`, user B in groups `handlers` and `dept:Belastingen`
- **WHEN** each lists objects of schema `case`
- **THEN** A sees only cases whose `department` is `VTH` and B only cases whose `department` is `Belastingen`
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/ci/rbac-department-role-matrix.spec.ts when the compiler ships}

#### Scenario: a matrix naming a missing field is refused

- **GIVEN** a schema save with a matrix on field `afdeling` that the schema does not declare
- **WHEN** the schema is saved
- **THEN** the save is refused naming the field
- @e2e exclude {schema validation is a service boundary covered by unit tests}

### Requirement: The matrix action set includes a resolvable `handle` verb

The matrix SHALL accept the canonical verbs and the custom verb `handle`.
`handle` SHALL resolve through the existing custom-verb voting pair and
SHALL fall back to `update` when no voter claims it.

#### Scenario: handle without a voter behaves as update

- **GIVEN** a matrix row granting `handle` and no registered voter for `handle`
- **WHEN** the user in that row updates an object of their department
- **THEN** the update is allowed
- @e2e exclude {verb resolution is covered by unit tests on the authorization service}

### Requirement: An admin edits the matrix as a grid with a per-user preview

The schema admin page SHALL render the matrix as a grid of field values by
role groups with action checkboxes, SHALL write only the `matrix`
declaration, and SHALL show, for a chosen user, the scopes the compiler
produces.

#### Scenario: the preview names the compiled scopes

- **GIVEN** a saved matrix and an admin who picks user A in the preview
- **WHEN** the preview loads
- **THEN** it lists one scope per action group with the condition on the field and A's resolved values
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/ci/rbac-department-role-matrix.spec.ts when the surface ships}
