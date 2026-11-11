# audit-trail-immutable

## MODIFIED Requirements

### Requirement: Every user-facing read of an object is logged as a read

`GetObject::find()` (`lib/Service/Object/GetObject.php`) SHALL write an audit trail entry with action `read` for every object it returns, on any schema, sensitive or not, while the instance setting `auditTrailsEnabled` is on (default on, `lib/Service/Settings/ConfigurationSettingsHandler.php:327`). The entry SHALL be written through `ReadHistoryService::registerAuditRead()` (`lib/Service/Interaction/ReadHistoryService.php`), the one read registration, which reads the setting and falls back to on when it cannot be read. The entry MUST name the reader and the object and MUST NOT carry a field diff (`AuditTrailMapper::buildAuditTrail()` treats `read` as a no-new-state action). A caller that loads an object as part of another operation MUST be able to skip the entry: `find()` takes `$_audit` (default `true`), passed through by `ObjectService::find()`, and `findSilent()` never logs. Read entries MUST NOT be throttled or merged: each audited read is its own row, and features built on reads (the `_recent` lens) collapse repeats at query time. The audit statistics and chart count `read` beside create, update and delete, so `/audit-trails` shows who viewed a record.

#### Scenario: opening a record writes a read entry

- **GIVEN** audit trails are enabled and user `medewerker-1` may read object `inwoner-123`
- **WHEN** `medewerker-1` opens the object through `GET /api/objects/{register}/{schema}/inwoner-123`
- **THEN** the audit trail MUST hold a new entry with action `read`, user `medewerker-1` and object `inwoner-123`
- **AND** the entry MUST carry no changed fields
- @e2e exclude {read logging is a backend side effect; asserted by reading /api/audit-trails after a GET, no page renders the write}

#### Scenario: an internal load skips the entry

- **GIVEN** audit trails are enabled
- **WHEN** a flow node invocation loads its subject object only to check the boundary, calling `find()` with `_audit: false` (`lib/Controller/FlowNodeRunController.php:402`)
- **THEN** no `read` entry MUST be written for that load
- @e2e exclude {internal call path with no page; covered by ReadHistoryServiceTest and ReadRegistrationWiringTest}

#### Scenario: the instance setting switches read logging off

- **GIVEN** the retention setting `auditTrailsEnabled` is `false`
- **WHEN** any user opens an object
- **THEN** no `read` entry MUST be written
- @e2e exclude {instance setting read on the server; no page renders the absence of an entry}

#### Scenario: opening the same record twice writes two entries

- **GIVEN** audit trails are enabled
- **WHEN** a user opens the same object twice within one minute
- **THEN** the audit trail MUST hold two `read` entries
- @e2e exclude {no throttle exists to observe; covered by ReadHistoryServiceTest, which asserts one write per registered read}
