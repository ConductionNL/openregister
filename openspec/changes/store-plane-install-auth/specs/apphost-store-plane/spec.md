## ADDED Requirements

### Requirement: A store MUST declare who may install, defaulting to admin

The `store` block MAY carry an `installAuth` key. Its value MUST be one of
`admin`, `authenticated`, or `action:<name>`. When the key is absent the
posture MUST be `admin`, so every store declared before this key existed keeps
the posture it has.

An unrecognised value MUST disable the block. Falling back to `admin` would
silently REMOVE a capability from an app that asked for a weaker gate, which is
as wrong as granting one it did not ask for and considerably harder to notice:
the store still works, for fewer people, for no stated reason.

#### Scenario: An absent installAuth stays admin-only

- **WHEN** a `store` block declares no `installAuth`
- **AND** a signed-in non-administrator posts an install
- **THEN** the response is 403

#### Scenario: An authenticated store admits a non-administrator

- **WHEN** a `store` block declares `"installAuth": "authenticated"`
- **AND** a signed-in non-administrator posts an install
- **THEN** the install proceeds

#### Scenario: Anonymous is refused whatever the posture

- **WHEN** a `store` block declares `"installAuth": "authenticated"`
- **AND** an anonymous caller posts an install
- **THEN** the response is 403, on the same guard as a permitted-but-unauthorised
  user

`authenticated` is the weakest posture the vocabulary offers and it still means
signed in. Anonymous and not-permitted share one guard deliberately, which is
the contract the endpoint already had.

#### Scenario: An unknown posture disables the store

- **WHEN** a `store` block declares `"installAuth": "everyone"`
- **THEN** the manifest reports `enabled: false`

### Requirement: A loosened install posture MUST NOT widen the allowlist

`installAuth` decides WHO may install. `installable` decides WHAT an install
may write. The two MUST remain independent: an `authenticated` install MUST
refuse every schema the allowlist omits, exactly as an `admin` install does,
and an empty allowlist MUST still refuse everything.

This is stated as its own requirement because the two keys sit side by side in
the same block and read like a pair. They are not one: relaxing the gate on the
door does not enlarge the room.

#### Scenario: A non-administrator cannot write a schema the allowlist omits

- **WHEN** a store declares `"installAuth": "authenticated"` and an
  `installable` list that omits `case`
- **AND** a signed-in non-administrator installs an item whose component names
  `case`
- **THEN** that component is refused
- **AND** the refusal is reported per component, as for an administrator
