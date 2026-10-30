# object-lifecycle

## ADDED Requirements

### Requirement: A magic table missing metadata columns heals itself on first use (REQ-MTH-001)

When a request first uses an existing magic table, OpenRegister SHALL check
that the table carries every metadata column (`_archived`, `_frozen`,
`_status` and the rest of the declared list) and SHALL add the ones it lacks
before the table is queried. The check SHALL NOT depend on an app upgrade or
a repair step having run. After the first use the answer SHALL come from a
per-process memo, and across requests from the distributed cache under a key
that changes when the metadata column list changes, so a complete table costs
no schema query per request. Two requests adding the same column at once
SHALL both succeed.

#### Scenario: a list of a table without `_archived` succeeds

- **GIVEN** a magic table that has every metadata column except `_archived`, `_frozen` and `_status`
- **WHEN** a user lists the objects of its schema
- **THEN** the three columns are added to the table
- **AND** the list answers with the table's objects instead of an SQL error
- @e2e exclude {needs a table altered underneath the app; covered by the SQLite unit test through existsTableForRegisterSchema()}

#### Scenario: a complete table is not inspected on every request

- **GIVEN** a table that was found complete
- **WHEN** it is used again in the same process, or in another request within the cache lifetime
- **THEN** no information_schema query is made for it
- @e2e exclude {query counts are not observable from a browser; covered by unit tests}

#### Scenario: two requests add the same column

- **GIVEN** two requests that both find `_archived` missing
- **WHEN** both try to add it
- **THEN** neither fails and no warning is logged
- @e2e exclude {a race cannot be staged from a browser; covered by unit tests}

### Requirement: A table that cannot be healed degrades quietly (REQ-MTH-002)

When a missing metadata column cannot be added, OpenRegister SHALL log one
warning naming the table and the remedy, SHALL NOT log it again on every
request while the table stays degraded, and SHALL leave the missing column
out of the archive and draft predicates for that table. A table without
`_archived` holds no archived row, so the default list SHALL show all its
rows and the archived-only lens SHALL show none.

#### Scenario: no permission to alter

- **GIVEN** a table without `_archived` and a database user that may not alter it
- **WHEN** its objects are listed three times
- **THEN** one warning is logged
- **AND** each list answers with the table's rows
- @e2e exclude {needs a database user without ALTER; covered by unit tests}

### Requirement: Reconcile reports and repairs missing metadata columns (REQ-MTH-003)

`occ openregister:tables:reconcile` SHALL report every physical magic table
that lacks metadata columns, including tables whose schema no register lists,
and with `--apply` SHALL add them, without maintenance mode. `--register`
SHALL limit both passes to that register's tables. A metadata column SHALL be
reported once, not again by the property pass.

#### Scenario: an orphaned table is reported and repaired

- **GIVEN** a magic table lacking `_archived` whose schema no register lists
- **WHEN** an administrator runs the command without `--apply`
- **THEN** the output names the table and `_archived` and the exit code is 0
- **AND** with `--apply` the column is added and the summary counts the table as repaired
- @e2e exclude {occ command; covered by unit tests}

### Requirement: The upgrade step and reconcile share the healer (REQ-MTH-004)

The `AddObjectStateColumns` repair step, the reconcile command and the request
path SHALL decide which columns a table lacks, and add them, through the same
helper, so the three cannot disagree. The repair step SHALL walk every physical
magic table and SHALL NOT abort the upgrade when one table cannot be altered.

#### Scenario: the upgrade step heals an orphaned table

- **GIVEN** a magic table lacking `_archived` whose schema no register lists
- **WHEN** the app is upgraded
- **THEN** the repair step adds the column
- @e2e exclude {repair step runs on upgrade; covered by unit tests}
