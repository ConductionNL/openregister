# schema-import

## ADDED Requirements

### Requirement: An app-shipped descriptor keeps the definition it shipped (REQ-LCA-001)

When a register descriptor shipped by an app is imported, the system SHALL
store the shipped definition as a baseline beside the live one, naming the
app, the app version and the moment of the import. The baseline SHALL be
kept for registers, schemas and the configuration blocks they declare, and
SHALL be replaced only when a later version of the same app is imported and
its parts are applied.

#### Scenario: the shipped definition is kept

- **GIVEN** an app importing its register descriptor on install
- **WHEN** the schemas are read back
- **THEN** each carries a baseline naming the app and the app version it shipped with

#### Scenario: an upgrade moves the baseline only for what it applied

- **GIVEN** a schema with one locally changed property and an upgrade that changes another
- **WHEN** the upgrade applies the second property
- **THEN** the baseline records the new shipped definition for that property
- **AND** the baseline of the locally changed property still names the version it diverged from
- @e2e exclude {import machinery, covered by unit tests}

### Requirement: The instance reports where it differs from what was shipped (REQ-LCA-002)

The system SHALL answer, per register, schema and configuration block,
whether each part is unchanged, changed locally only, changed upstream only,
or changed on both sides. A locally changed part SHALL name the actor and
the moment of the change. The answer SHALL be readable for the whole
instance and for one app, and SHALL be reported beside the effective
configuration.

#### Scenario: an administrator sees the local configuration in one place

- **GIVEN** an instance where two properties of one shipped schema were edited locally
- **WHEN** the divergence report is read
- **THEN** both properties are listed as changed locally, naming who changed them and when

#### Scenario: an untouched instance reports nothing

- **GIVEN** an instance where nothing shipped has been edited
- **WHEN** the divergence report is read
- **THEN** every part reports as unchanged

### Requirement: An upgrade never silently discards a local change (REQ-LCA-003)

When a new version of an app ships a changed descriptor, the import SHALL
apply the parts changed upstream only, SHALL preserve the parts changed
locally only, and SHALL report as a conflict any part changed on both sides.
A conflicting part SHALL NOT be applied without an explicit decision for
that part, and the decision SHALL be recorded with its actor. An import
running unattended SHALL complete, leaving the conflicting parts as they
are and reporting them, rather than applying them or failing the upgrade.

#### Scenario: the extra field survives the release

- **GIVEN** a shipped schema to which the municipality added a property, and a new app version that changes a different property
- **WHEN** the upgrade runs
- **THEN** the added property is still present
- **AND** the upstream change is applied

#### Scenario: a part changed on both sides waits for a person

- **GIVEN** a property whose constraints were tightened locally and which the new version also changes
- **WHEN** the upgrade runs
- **THEN** the property keeps its local definition
- **AND** it is reported as a conflict naming both definitions

#### Scenario: an unattended upgrade still finishes

- **GIVEN** an `occ upgrade` with conflicts and nobody to answer
- **WHEN** it runs
- **THEN** it completes
- **AND** the conflicts are readable in the divergence report afterwards
- @e2e exclude {upgrade path, covered by unit tests over the import service}

#### Scenario: a decision is on the record

- **GIVEN** a reported conflict
- **WHEN** an administrator accepts the shipped definition for that part
- **THEN** it is applied
- **AND** an audit entry names the part, the actor and the definition that was accepted

### Requirement: A diverged part can be reset to what was shipped (REQ-LCA-004)

A part that differs from its baseline SHALL be resettable to the shipped
definition as a deliberate act with an actor. The reset SHALL be audited and
SHALL report what it would change before it is confirmed. A reset SHALL NOT
be performed by a repair step or by any unattended path.

#### Scenario: a local change is taken back deliberately

- **GIVEN** a locally changed property
- **WHEN** an administrator resets it to the shipped baseline
- **THEN** the property matches the baseline
- **AND** the audit trail names the actor and both definitions

#### Scenario: a reset shows its effect first

- **GIVEN** the same property
- **WHEN** a reset is requested without confirmation
- **THEN** the change it would make is reported and nothing is written
- @e2e exclude {preview path, covered by unit tests}
