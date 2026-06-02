## ADDED Requirements

### REQ-010: ArchivalService::setRetentionMetadata Validates and Merges Object Retention

The `ArchivalService::setRetentionMetadata()` write-path MUST validate caller-supplied retention metadata against the `VALID_NOMINATIONS` and `VALID_STATUSES` enums, normalise `archiefactiedatum` to ISO-8601, default missing keys, and merge with the object's existing retention payload rather than replacing it. The method MUST return the mutated `ObjectEntity` without persisting it (the caller is responsible for the save).

#### Scenario: Missing archiefnominatie defaults to nog_niet_bepaald
- **GIVEN** an `ObjectEntity` with no existing `retention` field
- **WHEN** `setRetentionMetadata($object, [])` is called
- **THEN** the resulting `retention.archiefnominatie` MUST equal `nog_niet_bepaald`
- **AND** `retention.archiefstatus` MUST equal `nog_te_archiveren`

#### Scenario: Invalid archiefnominatie is rejected
- **GIVEN** `retention.archiefnominatie` is set to `"weggooien"` (not in `VALID_NOMINATIONS = ['vernietigen', 'bewaren', 'nog_niet_bepaald']`)
- **WHEN** `setRetentionMetadata()` is called
- **THEN** the method MUST throw `InvalidArgumentException` whose message contains the invalid value and the allowed set
- **AND** the object's existing retention MUST NOT be modified

#### Scenario: Invalid archiefstatus is rejected
- **GIVEN** `retention.archiefstatus` is set to `"klaar"` (not in `VALID_STATUSES = ['nog_te_archiveren', 'gearchiveerd', 'vernietigd', 'overgebracht']`)
- **WHEN** `setRetentionMetadata()` is called
- **THEN** the method MUST throw `InvalidArgumentException` whose message contains the invalid value and the allowed set

#### Scenario: archiefactiedatum accepts Y-m-d and ISO 8601, normalises to ISO 8601
- **GIVEN** `retention.archiefactiedatum` is `"2030-12-31"`
- **WHEN** `setRetentionMetadata()` is called
- **THEN** the stored `retention.archiefactiedatum` MUST be the ISO 8601 (`DateTime::format('c')`) representation of `2030-12-31T00:00:00`
- **AND** an input already in ISO 8601 (`DateTime::ATOM`) format MUST round-trip unchanged

#### Scenario: archiefactiedatum with unparseable format is rejected
- **GIVEN** `retention.archiefactiedatum` is `"31-12-2030"` (neither `Y-m-d` nor ISO 8601 atomic)
- **WHEN** `setRetentionMetadata()` is called
- **THEN** the method MUST throw `InvalidArgumentException` whose message contains `"Invalid archiefactiedatum format"`

#### Scenario: Existing retention fields outside the input payload are preserved
- **GIVEN** an `ObjectEntity` with existing `retention = { archiefnominatie: "bewaren", legalHold: { active: true, reason: "WOO" } }`
- **WHEN** `setRetentionMetadata($object, ['archiefactiedatum' => '2030-01-01'])` is called
- **THEN** the resulting retention MUST still contain `legalHold.active = true` and `legalHold.reason = "WOO"`
- **AND** `archiefnominatie` MUST remain `"bewaren"` (preserved from existing — the input only sets `archiefactiedatum`)
- **AND** `archiefactiedatum` MUST be the ISO 8601 string for `2030-01-01`

### REQ-011: extendRetentionForObject Recomputes archiefactiedatum from Classificatie's SelectionList

When a destruction-list rejection requires extending an object's retention, `ArchivalService::extendRetentionForObject()` MUST look up the object's `retention.classificatie`, resolve the matching `SelectionList` entry via `SelectionListMapper::findByCategory()`, take its `retentionYears`, and add that many years to the current `retention.archiefactiedatum` (or to `now()` if no current date is set). The mutated retention MUST be persisted via a direct UPDATE on `openregister_objects.retention`. Failures MUST be caught and surface as a `logger->warning()` rather than propagating to the caller (so a single bad object does not abort a multi-object rejection batch).

#### Scenario: Object with classificatie B1 extends by selection-list retentionYears
- **GIVEN** an object with `retention = { classificatie: "B1", archiefactiedatum: "2026-01-01T00:00:00+00:00" }`
- **AND** `SelectionListMapper::findByCategory("B1")` returns an entry with `retentionYears = 5`
- **WHEN** `extendRetentionForObject($uuid)` is called
- **THEN** the row's `retention.archiefactiedatum` in `openregister_objects` MUST be updated to the ISO 8601 string for `2031-01-01T00:00:00+00:00`

#### Scenario: Object with no current archiefactiedatum extends from today
- **GIVEN** an object with `retention = { classificatie: "B1" }` (no `archiefactiedatum`)
- **AND** `SelectionListMapper::findByCategory("B1")` returns `retentionYears = 5`
- **WHEN** `extendRetentionForObject($uuid)` is called on `2026-05-24`
- **THEN** the row's `retention.archiefactiedatum` MUST be set to the ISO 8601 string for `2031-05-24` (today + 5 years)

#### Scenario: Object without classificatie is silently skipped
- **GIVEN** an object with `retention = { archiefnominatie: "vernietigen" }` (no `classificatie`)
- **WHEN** `extendRetentionForObject($uuid)` is called
- **THEN** the retention row MUST NOT be modified
- **AND** no exception MUST propagate to the caller

#### Scenario: Object UUID not in database is silently skipped
- **GIVEN** no row in `openregister_objects` matches `$uuid`
- **WHEN** `extendRetentionForObject($uuid)` is called
- **THEN** the method MUST return without raising
- **AND** no UPDATE statement MUST be executed

#### Scenario: Mapper exceptions are logged, not propagated
- **GIVEN** `SelectionListMapper::findByCategory()` throws a `\Exception`
- **WHEN** `extendRetentionForObject($uuid)` is called
- **THEN** the exception MUST be caught
- **AND** a `LoggerInterface::warning()` entry MUST be emitted containing the UUID and the exception message
- **AND** the method MUST return normally so a batch rejection of N objects continues processing the remaining N-1
