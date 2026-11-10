# object-interactions

## ADDED Requirements

### Requirement: Only a person opening an object counts as recently opened

The `_recent` lens SHALL count an audit `read` row only when its cause is `person`, or empty for a row written before causes were recorded. A read the code makes on a person's behalf (a permission guard, a relation or reference lookup, a sub-resource of a page already opened, an agent tool, a data-management operation) SHALL be written with cause `lookup` and SHALL NOT appear in the person's recent list. A read inside an import, a rule, a migration or a scheduled job keeps its own cause and SHALL NOT appear either. The audit trail SHALL keep every one of these rows: excluding a read from the recent list SHALL NOT remove it from the audit trail.

#### Scenario: opening a case that resolves its client

- **GIVEN** audit trails are enabled and a user who has opened nothing
- **WHEN** the user opens case A, and rendering case A looks up its client B through `ObjectService::find()`
- **THEN** the user's `_recent=true` page holds case A and not client B
- **AND** the audit trail holds a `read` row for A with cause `person` and a `read` row for B with cause `lookup`
- @e2e exclude {which internal reads a page triggers depends on the schema's relations; the cause stamping is covered by WriteCauseTest and the call-site tests, the history filter by AuditTrailMapperReadHistoryTest against the migrated table}

#### Scenario: a guard before an action is not an open

- **GIVEN** a user who uploads a file to object A without opening it
- **WHEN** the file controller checks the user may read A
- **THEN** A is not on the user's `_recent=true` page
- **AND** the audit trail holds the guard's `read` row for A with cause `lookup`
- @e2e exclude {asserted from the caller in RecentLensLookupCallSitesTest; an e2e upload would also open the record page}

#### Scenario: a read inside an import keeps its cause

- **GIVEN** an import running with cause `import`
- **WHEN** code inside it reads an object through a lookup site
- **THEN** the `read` row carries cause `import`, not `lookup`
- @e2e exclude {ambient frame behaviour; covered by WriteCauseTest}

### Requirement: Cross-table searches honour the recent lens like one schema

A `_recent=true` search over several schemas or registers, and one over no register or schema at all, SHALL return only the objects in the caller's read history, ordered by the latest read descending unless the caller gives `_order`, paged after that ordering, with `@self.viewedAt` on every object, exactly as a single-schema search does. The cross-table list response SHALL carry `@self.lenses.recent`.

#### Scenario: a dashboard tile over two schemas

- **GIVEN** a user who opened case A in schema `zaak`, then task B in schema `taak`, then case C in schema `zaak`
- **WHEN** the dashboard queries both schemas with `_recent=true`
- **THEN** the page holds C, B and A in that order, and no object the user did not open
- **AND** each carries `@self.viewedAt`
- **AND** the response carries `@self.lenses.recent.available` = `true`
- @e2e exclude {the cross-table paths are covered by MagicMapperRecentLensTest (UNION order keys, sequential merge, global `_ids` page) and MagicSearchHandlerIdsSqlTest; the e2e instance has no second schema with read history to spare}

#### Scenario: an explicit order wins

- **GIVEN** the same user and history
- **WHEN** the dashboard queries both schemas with `_recent=true` and `_order[@self.name]=asc`
- **THEN** the page holds the three objects ordered by name
- **AND** each still carries `@self.viewedAt`
- @e2e exclude {precedence rule; covered by MagicMapperRecentLensTest}

#### Scenario: the second page continues the history

- **GIVEN** a user whose history across two schemas holds five objects
- **WHEN** the user asks `_recent=true` with `_limit=2` and `_offset=2`
- **THEN** the page holds the third and fourth most recently read objects
- @e2e exclude {paging after ordering; covered by MagicMapperRecentLensTest}
