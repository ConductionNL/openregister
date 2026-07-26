## ADDED Requirements

### Requirement: Apps declare shareable configuration types (REQ-FCS-001)

OpenRegister SHALL provide `IShareableConfigType` and a
`RegisterShareableConfigTypesEvent`, so any app contributes a shareable type the
same way it contributes a flow node. A type SHALL declare how to select its
configuration, serialise it to canonical repository files, deserialise it on
install, exclude secrets, and its discovery topic. The contract SHALL be
storage-agnostic: a type owns (de)serialisation, so it works for OpenRegister
objects and for app-specific storage alike.

#### Scenario: A type is contributed and listed

- **GIVEN** an app registers an `IShareableConfigType` on the event
- **WHEN** the shareable-type catalogue is read
- **THEN** the type appears with its topic and display metadata

#### Scenario: A non-OpenRegister-object type works

- **GIVEN** a type whose config lives in app storage (e.g. NL Design theme token
  sets in `IConfig`), not OpenRegister objects
- **WHEN** it is serialised and later deserialised
- **THEN** the round trip applies the config with no OpenRegister object required

### Requirement: Publish a configuration to GitHub with credentials in Doriath (REQ-FCS-002)

A user with the right to share SHALL publish a configuration of any registered
type to a GitHub repository — serialise to canonical files, push via the Git
data/contents API, tag the repository with the type's topic. The GitHub token
SHALL be resolved through the credential broker's Doriath vault leaf per
user/org; where Doriath is absent the broker's Nextcloud-vault leaf is used. No
shared app-level GitHub token SHALL be required for user publishing. Secrets
SHALL be excluded from the published payload by the type.

#### Scenario: A user publishes without a shared token

- **GIVEN** a user whose GitHub credential is custodied in Doriath
- **WHEN** they publish a shareable configuration
- **THEN** it is pushed to their repository using the Doriath-resolved token
- **AND** no shared app-level token is used
- **AND** no secret value appears in the published files

### Requirement: Discover and install with preview and pinning (REQ-FCS-003)

OpenRegister SHALL discover shared configurations by GitHub topic (per type) and
by the `x-openregister` code search, present a preview diff (create/update/skip
per entity) before applying, install via the type's deserialiser, and let an
instance pin a version. Version tracking and update checks SHALL reuse the
existing configuration machinery.

#### Scenario: Preview before install

- **GIVEN** a discovered configuration
- **WHEN** a user requests to install it
- **THEN** a preview of what would be created/updated/skipped is shown before anything is written

#### Scenario: A pinned configuration does not auto-advance

- **GIVEN** an installed configuration pinned to a version
- **WHEN** a newer version is published upstream
- **THEN** the instance stays on the pinned version until explicitly updated

### Requirement: Trust — org allowlist and pinning (REQ-FCS-004)

Installing a configuration SHALL be governed by an organisation allowlist of
sources: an install from a source not on the org's allowlist SHALL be refused. An
installed configuration SHALL NOT be able to widen its own declared grant. (v1
trust is allowlist + version pinning + secret exclusion; cryptographic signing is
a later hardening.)

#### Scenario: A non-allowlisted source is refused

- **GIVEN** an organisation with an allowlist of GitHub sources
- **WHEN** a user tries to install from a source not on the allowlist
- **THEN** the install is refused

#### Scenario: A config cannot widen its own grant

- **GIVEN** an installed configuration with declared scopes
- **WHEN** it attempts to touch a register or host outside its declaration
- **THEN** the engine refuses

### Requirement: Sharing is user/org-scoped, not admin-only (REQ-FCS-005)

Publishing and installing SHALL be available to a user within their
organisation's governance, not gated to Nextcloud admins. The organisation and
owner of a configuration SHALL scope who may share and install it.

#### Scenario: A non-admin org member shares

- **GIVEN** a non-admin user permitted to share in their organisation
- **WHEN** they publish or install a configuration
- **THEN** the action succeeds within the org's governance

@e2e exclude backend service + fleet contract — covered by unit tests
(IShareableConfigType registry, allowlist enforcement, secret exclusion) and
Playwright e2e on the first two consumers (OpenBuild apps + Flows: publish →
discover → install → run); design-first change, no code in this proposal
