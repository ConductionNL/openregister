# deep-link-registry

## ADDED Requirements

### Requirement: The instance publishes a capability block, with one block per claiming app (REQ-PCA-001)

The system SHALL publish a capability block carrying the app version, the
API versions served with their status, the registered deep link patterns,
the limits an integrator needs, and which platform integrations are
enabled. Each app that claims a (register, schema) pair SHALL appear as
its own nested block carrying its id, display name and enabled
integrations.

#### Scenario: a client learns the limits before it tries

- **GIVEN** an instance with an upload limit and two API versions
- **WHEN** a client reads the platform capabilities
- **THEN** both versions with their status and the upload limit are present

#### Scenario: a leaf app is discoverable by its own name

- **GIVEN** an app claiming a register and schema
- **WHEN** the capabilities are read
- **THEN** a block named for that app is present

### Requirement: The capability block names no register and no secret, and agrees with the API answer (REQ-PCA-002)

The capability block SHALL carry no register name, no schema name and no
secret value. The versions and limits it publishes SHALL be read from the
same source as the API capabilities endpoint, so the two answers cannot
differ.

#### Scenario: capabilities are not an enumeration of the data model

- **GIVEN** an instance holding many registers
- **WHEN** the capability block is read
- **THEN** no register or schema name appears in it

#### Scenario: one source, one answer

- **GIVEN** an upload limit
- **WHEN** the capability block and the API capabilities endpoint are both read
- **THEN** they report the same value
- @e2e exclude {cross-surface assertion, covered by unit tests}
