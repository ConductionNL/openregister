# Single flow engine

## ADDED Requirements

### Requirement: OpenRegister drives no external workflow engine (REQ-SFE-101)

OpenRegister MUST NOT contain an adapter, registry, settings screen or API for
driving an external workflow engine. ADR-065 makes OpenRegister the only home
for a flow engine in this fleet, and a second dispatch path is a second engine.

The Nextcloud NATIVE workflow engine integration is explicitly NOT covered by
this requirement. Registering an OpenRegister flow as a Nextcloud workflow
operation is how our engine reaches the Files UI, and it MUST remain.

#### Scenario: No engine adapter is reachable

- **WHEN** the application boots
- **THEN** no external workflow engine adapter, registry or route exists.

#### Scenario: The native integration survives

- **WHEN** a Nextcloud workflow operation list is built
- **THEN** the OpenRegister flow operation is still offered.

### Requirement: The external engine tables are removed (REQ-SFE-102)

The tables the external engine layer owned MUST be dropped, idempotently.

Before dropping them, every scheduled workflow MUST be reported. A schedule
records intent that a person wrote down, and destroying it with no trace in the
upgrade output leaves nothing to re-create it from.

The report MUST work on every supported database. A report that exists to
prevent silent loss, and silently does not run, is worse than no report.

#### Scenario: Scheduled workflows are named before removal

- **GIVEN** an instance with scheduled workflows
- **WHEN** the migration runs
- **THEN** each one's name, engine and interval appears in the output before the
  tables are dropped.

#### Scenario: Re-running the migration changes nothing

- **GIVEN** an instance where the tables are already gone
- **WHEN** the migration runs again
- **THEN** it drops nothing and reports nothing.
