## Purpose

An import never writes an access policy that neither the app nor the instance wrote.

@e2e exclude backend merge and guard, covered by PHPUnit

## ADDED Requirements

### Requirement: An authorization block settles whole (REQ-LCA-005)

On import, a schema's `authorization` block and each property's `authorization` block SHALL take one side for all their keys. When only the app changed the block, the app's block SHALL be written whole. When only the instance changed it, the instance's block SHALL stay whole. When both changed it, or each changed a different key, the instance's block SHALL stay whole and one conflict SHALL be reported on the block's root path.

#### Scenario: The app closes read and export; the instance had added export

- **GIVEN** the baseline is `authorization: {read: [authenticated]}`
- **AND** the instance runs `{read: [authenticated], export: [authenticated]}`
- **WHEN** an unattended upgrade ships `{read: [portaliq-medewerkers], export: [portaliq-medewerkers]}`
- **THEN** the schema keeps `{read: [authenticated], export: [authenticated]}`
- **AND** one conflict is reported, on `authorization`
- **AND** the schema is never written with `read` from the app and `export` from the instance

#### Scenario: Only the app changed the block

- **GIVEN** the instance runs the baseline block `{read: [authenticated], export: [authenticated]}`
- **WHEN** the app ships `{read: [staff]}`
- **THEN** the schema's block is `{read: [staff]}`

#### Scenario: A property's authorization is its own block

- **WHEN** both sides changed `properties.bsn.authorization`
- **THEN** one conflict is reported on `properties.bsn.authorization`
- **AND** the property's other keys merge as before

### Requirement: An administrator's re-import takes the shipped authorization (REQ-LCA-006)

An import an administrator asked for (the occ re-import, the register descriptors panel, an example-set install) SHALL count as the explicit decision of REQ-LCA-003 for every authorization block. The shipped block SHALL be written whole. Each block replaced over a local change SHALL be recorded on the audit trail as `configuration.conflict.decided`, with who asked.

#### Scenario: The administrator re-imports portaliq

- **GIVEN** the conflict of REQ-LCA-005's first scenario
- **WHEN** an administrator runs `occ openregister:descriptors:list --app=portaliq --import=portaliq`
- **THEN** `portalAccount` gets `{read: [portaliq-medewerkers], export: [portaliq-medewerkers]}`
- **AND** the audit trail records `configuration.conflict.decided` for `authorization` with `via: occ openregister:descriptors:list --import`

#### Scenario: The decision ends with the import

- **WHEN** the re-import finishes, or throws
- **THEN** a later unattended import keeps local authorization again
