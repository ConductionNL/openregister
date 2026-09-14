# platform-user-migration

## ADDED Requirements

### Requirement: A user's personal state is exported and imported (REQ-PUM-001)

The system SHALL register a user migrator that exports, for one user,
their saved views, favourites and recents, watches, notification
preferences and overrides, personal token metadata without secret values,
and their own notes and timeline entries as a readable archive. Import
SHALL restore what the target instance can hold.

#### Scenario: a caseworker's habits move with them

- **GIVEN** a user with saved views, favourites and watches
- **WHEN** their account is exported and imported into another instance holding the same registers
- **THEN** the saved views, favourites and watches are present

#### Scenario: token values do not travel

- **GIVEN** a user holding personal API tokens
- **WHEN** their account is exported
- **THEN** the export names the tokens and carries no secret value

### Requirement: Objects are not exported, and both ends say so (REQ-PUM-002)

The migrator SHALL NOT export objects. The export SHALL carry a manifest
naming what it contains and stating that objects were not included. The
import SHALL write a report naming anything it could not restore, and
SHALL NOT create a register or schema that the export referenced but the
target does not hold.

#### Scenario: a leaver's export is not a bulk extraction

- **GIVEN** a user who may read ten thousand objects
- **WHEN** their account is exported
- **THEN** the export holds no object, and its manifest says so

#### Scenario: an unrestorable view is reported, not invented

- **GIVEN** an export holding a saved view over a register the target does not have
- **WHEN** it is imported
- **THEN** the view is not created and the import report names it
