# example-sets Delta: example-set-refresh-trusted-writes

## ADDED Requirements

### Requirement: A refresh sends only what the schema lets a write change

On `--refresh` a kept object SHALL be rewritten without sending a new value for a field its schema keeps as first written (`readOnly`, `immutable`): such a field SHALL carry its stored value. A lens field SHALL NOT be sent. A row of an append-only schema SHALL be kept as written and SHALL NOT be reported as not refreshed.

#### Scenario: A readOnly field and a lens
- **GIVEN** a kept object whose schema keeps `since` readOnly and reads `objectTitle` through a lens
- **WHEN** the set is refreshed on a later day
- **THEN** the object SHALL be refreshed, `since` SHALL keep its stored value and no lens value SHALL be stored

### Requirement: A refresh writes declared state as the system

The example-set store SHALL write in the system scope, so a read in the save path is never refused as an anonymous caller. An update by the store SHALL be marked as a write of declared state while it runs; the lifecycle guard SHALL let such a write set the lifecycle value the declaration names without a matching transition, and only inside the system scope.

#### Scenario: A declared status that no transition reaches
- **GIVEN** a kept advice request whose stored status is `expired` and whose declaration says `requested`
- **WHEN** the set is refreshed
- **THEN** the status SHALL be written as `requested`

### Requirement: A refresh keeps the record of what it wrote

After a refresh rewrites a kept object, the record of its references and of its `#references` change SHALL hold the values the rewrite wrote, so the proof does not read them as lost. The proof SHALL compare a value declared as JSON text with the value the store answers decoded.

#### Scenario: A history quoting a date
- **GIVEN** a kept object with a `#references` change from an earlier run and a history field quoting a date
- **WHEN** the set is refreshed on a later day
- **THEN** the install SHALL report ok and the change's recorded value SHALL be the refreshed history
