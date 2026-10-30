# rbac-scopes

## ADDED Requirements

### Requirement: A matrix row may add conditions and an end

A department matrix row SHALL accept `where`, extra conditions in the scope vocabulary joined with AND to the row's field condition, and `until`, after which the row grants nothing. The compiled scope SHALL be an ordinary conditional scope, evaluated identically on the PHP and SQL paths. A row whose `where` holds an empty `$in` SHALL be dropped whole.

#### Scenario: an edit right limited to statuses

- **GIVEN** schema `case` with a matrix on `assignedGroup`, a row for group `intakers` with value `team-a`, actions `update` and `where` `{status: {$in: ["s1"]}}`, and a second row for `intakers` with actions `read` and no `where`
- **AND** user A in groups `intakers` and `team-a`
- **WHEN** A updates a `team-a` case in status `s1`, then one in status `s2`
- **THEN** the first update is allowed and the second refused
- **AND** A still reads the case in `s2`
- @e2e exclude {enforcement is a service boundary; covered by PHPUnit on both the PHP and the SQL path}

#### Scenario: a row past its end

- **GIVEN** a row with `until` yesterday
- **WHEN** its group lists objects
- **THEN** the row admits nobody
- @e2e exclude {covered by unit tests on the compiler}

### Requirement: `$self` can mean a group the caller is in

A matrix user source SHALL accept `{groups: true}`, making the caller's own values their Nextcloud group ids without a prefix.

#### Scenario: own team

- **GIVEN** a matrix on `assignedGroup` with user source `{groups: true}` and a row `$self` for group `handlers` with `read`
- **AND** user A in `handlers` and `team-a`
- **WHEN** A lists cases
- **THEN** A sees the cases assigned to `team-a` and none assigned to `team-b`
- @e2e exclude {covered by PHPUnit on both paths}

### Requirement: An app contributes matrix rows at check time

A matrix SHALL accept `rowsFrom`, a provider id. At check time OpenRegister SHALL dispatch `MatrixRowsRequestedEvent` once per request and schema and compile the rows listeners add beside the declared rows, through the same validator. A listener failure SHALL contribute no rows.

#### Scenario: a provider row admits, a failing provider admits nobody

- **GIVEN** a schema whose matrix declares `rowsFrom: "dossiq.case-type-rights"` and no rows of its own
- **WHEN** a listener adds a row granting `read` to group `inspectors` on value `team-a`
- **THEN** a member of `inspectors` and `team-a` reads a `team-a` object
- **AND** when the listener throws, the same member reads nothing through the matrix
- @e2e exclude {covered by PHPUnit with a real event dispatcher}
