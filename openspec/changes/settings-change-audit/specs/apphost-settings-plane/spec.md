# apphost-settings-plane

## ADDED Requirements

### Requirement: Every settings change is on the audit chain

The generic settings `update` SHALL write one hash-chained audit entry per
changed key with the app, key, old value, new value, actor and time, and
`load(force)` SHALL write one entry naming the import and the number of keys
overwritten. Keys declared `x-openregister-secret` SHALL be recorded with
both values masked. Per-user preferences SHALL NOT be audited.

#### Scenario: a changed key is traceable

- **GIVEN** an app with `retentionDays` 30
- **WHEN** an administrator sets it to 90
- **THEN** the audit trail holds a `settings.updated` entry with app, `retentionDays`, 30, 90 and the administrator, verifiable on the chain
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/settings-audit.spec.ts when the filter ships}

#### Scenario: a rotated secret is recorded but not readable

- **GIVEN** a key declared secret
- **WHEN** its value changes
- **THEN** the entry names the key and shows `***` for both values
- @e2e exclude {masking, covered by unit tests}

### Requirement: The audit page lists settings changes

The instance-wide audit list SHALL offer a kind filter `settings`, SHALL
show the key and the diff per row, and SHALL carry the same rows in its
export.

#### Scenario: an officer filters to configuration changes

- **GIVEN** an audit trail with object and settings rows
- **WHEN** the officer filters on kind `settings`
- **THEN** only settings rows are listed, each with app, key and diff
- @e2e exclude {filter, covered by the e2e spec of task 3.1}
