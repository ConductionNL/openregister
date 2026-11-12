## ADDED Requirements

### Requirement: A task says what sort of work it is, and the inbox can be asked for one sort

A task SHALL carry an OPTIONAL `kind`: a short free label naming what sort
of work it is, as its creator named it. Null SHALL be the ordinary value and
SHALL mean "work", not "unknown".

`kind` SHALL be accepted on create, SHALL be returned on every task read, and
SHALL be filterable: `GET /api/flow-tasks?kind=<value>` SHALL answer only the
tasks carrying that kind, under the same visibility rules as every other
inbox read.

No lifecycle, authorization, notification or routing rule SHALL read `kind`.
A kinded task SHALL be offered, claimed, completed, audited and notified
exactly as an unkinded one is. `kind` SHALL NOT be stored in `metadata`,
which this capability already declares carried and never interpreted.

#### Scenario: A kind travels from create to read

- **GIVEN** a caller creating a task with `kind: reminder`
- **WHEN** the task is read back
- **THEN** the row SHALL carry `kind` as `reminder`

#### Scenario: The inbox answers one kind

- **GIVEN** an open reminder and an open task with no kind, both visible to
  the caller
- **WHEN** the caller asks the inbox for `kind=reminder`
- **THEN** only the reminder SHALL be in the results

#### Scenario: A kind changes nothing about the lifecycle

- **GIVEN** a task carrying a kind
- **WHEN** it is claimed and completed
- **THEN** it SHALL reach the same states, write the same audit entries and
  refuse the same callers as the identical task without one
