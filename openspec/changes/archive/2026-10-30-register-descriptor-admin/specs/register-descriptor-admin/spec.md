## Purpose

Make every app-declared register descriptor visible in one place — present or absent, current or behind — and let an administrator re-import one on demand and be told what happened. Seeding remains the job of the Repair steps ADR-005 assigns it to; this capability supplies the trigger and the visibility that decision left out.

## ADDED Requirements

### Requirement: The inventory lists every declaring app, including those whose register is absent

The system SHALL enumerate every app that declares a register descriptor and report a row for each, regardless of whether the register resolves. An app whose descriptor never landed SHALL appear, marked absent.

This is the requirement the capability exists for. An inventory that lists only registers that already resolve reproduces exactly the silence it was built to break: the absent register is invisible in both, and the reader cannot tell "this app declares nothing" from "this app's seed never ran".

#### Scenario: An app whose register was never imported

- **WHEN** an app declares a register descriptor and no register with that slug exists
- **THEN** the inventory includes a row for that app
- **AND** the row's state is `absent`
- **AND** the row reports the version the app currently ships
- **AND** the row reports no installed version

#### Scenario: An app whose register is present and current

- **WHEN** an app declares a descriptor and a register with that slug exists at the same version the app ships
- **THEN** the row's state is `current`
- **AND** the row reports the same value for installed and shipped version

#### Scenario: An app whose register is behind the shipped descriptor

- **WHEN** an app declares a descriptor at a version higher than the installed register's version
- **THEN** the row's state is `behind`
- **AND** the row reports both versions, so the reader can see the size of the gap

#### Scenario: An app that declares no descriptor

- **WHEN** an app declares no register descriptor
- **THEN** the inventory omits it entirely rather than listing it as absent

### Requirement: The inventory distinguishes absent from behind from current

The system SHALL report the three states as distinct values and SHALL NOT collapse them into a single boolean.

An `absent` register and a `behind` register need different actions from an administrator and carry different risk: absent means a code path is dead, behind means it is running against an older contract. A capability that reports only "needs attention" forces the reader back to the diagnosis this one exists to replace.

#### Scenario: The three states are separately addressable

- **WHEN** the inventory contains one register in each state
- **THEN** each row carries its own state value
- **AND** a consumer can select rows by state without inspecting version strings

### Requirement: Re-import bypasses the version gate

The system SHALL re-import a descriptor with the version comparison forced, so that a descriptor whose shipped version equals the installed version is still written.

Without forcing, the import short-circuits whenever the shipped version is not greater than the installed one. That is the precise condition under which an administrator presses the button — a register that is absent or that failed to write while the version counter says it is current — so an unforced re-import would be a no-op in every case that motivates the action.

#### Scenario: Re-importing a register whose versions already match

- **WHEN** an administrator re-imports a descriptor whose shipped version equals the installed version
- **THEN** the descriptor is written
- **AND** the outcome reports that it was imported, not that it was skipped

#### Scenario: Re-importing an absent register

- **WHEN** an administrator re-imports a descriptor for a register that does not exist
- **THEN** the register is created
- **AND** a subsequent inventory reports the row as `current`

### Requirement: Re-import reports its outcome

The system SHALL report the result of a re-import as one of imported, unchanged, or failed, and SHALL include the reason when it failed.

The Repair steps this capability complements are documented to never throw — a failure logs a warning and leaves the instance looking healthy. That is a defensible trade at boot, where the alternative is an app that will not install. It is not defensible for an action an administrator just took: an operation that reports nothing is indistinguishable from one that did nothing, which is the state this capability exists to end.

#### Scenario: The import fails

- **WHEN** a re-import fails
- **THEN** the response reports the failure
- **AND** the response includes the reason
- **AND** the failure does not leave the panel reporting the register as current

#### Scenario: The import succeeds

- **WHEN** a re-import succeeds
- **THEN** the response reports success
- **AND** a subsequent read of the inventory reflects the new installed version

### Requirement: Re-importing a base schema does not disturb schemas that extend it

The system SHALL preserve extending schemas across a forced re-import of the schema they extend.

An extension refers to its base — `allOf` holds schema ids, uuids or slugs, not copied definitions — so updating the base row leaves the extension resolving against the new base. This requirement pins that property rather than assuming it: were an extension ever materialised as a copy at import time, a re-import would silently revert somebody's customisation, and it would revert it through the very button offered as a repair.

#### Scenario: A customised extension survives a forced re-import of its base

- **WHEN** a schema extends a base schema shipped by an app
- **AND** an administrator forces a re-import of that app's descriptor
- **THEN** the extending schema still exists
- **AND** its own properties are unchanged
- **AND** it still resolves against the base

### Requirement: Only administrators may read the inventory or re-import

The system SHALL restrict both the inventory and the re-import action to administrators.

The inventory names every app installed on the instance and the state of its data model, and the action rewrites schema definitions instance-wide.

#### Scenario: A non-administrator requests the inventory

- **WHEN** a signed-in non-administrator requests the inventory
- **THEN** the request is refused

#### Scenario: A non-administrator attempts a re-import

- **WHEN** a signed-in non-administrator attempts a re-import
- **THEN** the request is refused
- **AND** no descriptor is written

### Requirement: The inventory is available without a browser

The system SHALL expose the same inventory through a command-line command.

The condition this capability diagnoses is most often met on an instance being set up or repaired, where reaching the admin UI may itself depend on the thing that is broken. A diagnosis reachable only through the surface under repair is not reliably reachable.

#### Scenario: Reading the inventory from the command line

- **WHEN** an operator runs the inventory command
- **THEN** the output lists the same rows as the API, with each row's state and both versions
