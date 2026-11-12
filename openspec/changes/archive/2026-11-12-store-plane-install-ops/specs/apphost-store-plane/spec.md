## ADDED Requirements

### Requirement: A component MUST declare its install operation, defaulting to writing an object

A component MAY carry an `op` key. Its value MUST be one of `writeObject` or
`setAppConfig`. Absent, it MUST be `writeObject`, so every item that installs
today installs unchanged.

An unrecognised `op` MUST be refused for that component and reported, and MUST
NOT abort the remaining components. A component whose operation this server
does not understand is the registry's mistake, not a reason to deny an
administrator the components it does understand.

#### Scenario: A component with no op writes an object

- **WHEN** a component declares no `op`
- **THEN** it is installed through the object write path
- **AND** the `installable` allowlist applies to it

#### Scenario: An unknown op is refused, and the rest still install

- **WHEN** an item carries one component with `"op": "summonDaemon"` and one
  ordinary object component
- **THEN** the unknown component is reported as refused
- **AND** the object component is still installed

### Requirement: A config write MUST be allowlisted by key and scoped to the declaring app

`setAppConfig` MUST write only into the declaring app's own config namespace,
and only for a key named in the store block's `configurable` list.

An empty or absent `configurable` list MUST refuse every key.

🔴 This is a second security boundary, not a convenience. `installable` stops a
remote registry naming any schema the app owns; an app's config namespace holds
registry URLs, tokens and feature flags, so an unallowlisted config write is a
remote actor toggling whatever it names. The default must therefore be "refuse
everything", exactly as it is for schemas.

#### Scenario: An allowlisted key is written

- **WHEN** a store declares `"configurable": ["enableSoapAdapter"]`
- **AND** a component sets that key
- **THEN** the value is written to the declaring app's config

#### Scenario: A key outside the allowlist is refused

- **WHEN** a component sets a key the `configurable` list omits
- **THEN** the component is refused and reported

#### Scenario: An absent configurable list refuses everything

- **WHEN** a store declares no `configurable` list
- **AND** a component declares `setAppConfig`
- **THEN** the component is refused

#### Scenario: A config write cannot address another app

- **WHEN** a component's key names another app's namespace
- **THEN** the key is treated as a plain key of the declaring app, never as a
  cross-app write

### Requirement: A config value MUST be a scalar

`setAppConfig` MUST accept only a boolean, string or number. An array or object
MUST be refused.

Config is a flat key-value store, and silently serialising a structure into it
produces a value the app will later read back as a string it never wrote.

#### Scenario: A structured value is refused

- **WHEN** a component sets a key to an object
- **THEN** the component is refused and reported
