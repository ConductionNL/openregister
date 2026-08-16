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

### Requirement: A schema marks its objects shareable with data, not code (REQ-FCS-006)

A schema SHALL be able to opt its objects into the store with a single marker on
its configuration — `x-openregister-shareable` (either `true` for derived
defaults, or an `{id, topic, name}` object refining them) — rather than each app
shipping a near-identical `IShareableConfigType`. OpenRegister SHALL scan marked schemas
and auto-register one shareable type per register+schema pair. The marker key
SHALL be a declared entry in the schema configuration vocabulary, so it survives
`setConfiguration()` rather than being silently dropped.

#### Scenario: A marked schema auto-surfaces as a type

- **GIVEN** a schema whose configuration carries `x-openregister-shareable`
- **WHEN** the shareable-type catalogue is read
- **THEN** a type appears for that schema's objects with no per-app code
- **AND** a pinned `{id, topic}` in the marker is honoured (preserving an existing published corpus)

### Requirement: Published bundles are signed and verified (REQ-FCS-007)

Publishing SHALL sign the canonical (key-sorted, provenance-excluded) bundle with
the instance's Ed25519 key, minted and stored once, attaching an
`{alg, publicKey, signature}` provenance block. Installing SHALL verify it: a
tampered signature SHALL always be refused; an unsigned or untrusted-key bundle
SHALL be refused once the organisation populates a trusted-keys allowlist (empty =
not yet enforced). The instance SHALL expose its public key so other
organisations can add it to their trust list.

#### Scenario: A tampered bundle is refused

- **GIVEN** a published, signed bundle whose contents are altered after signing
- **WHEN** a user installs it
- **THEN** the install is refused for a bad signature

#### Scenario: Signing enforcement pins publishers

- **GIVEN** an organisation with a non-empty trusted-keys allowlist
- **WHEN** a bundle signed by a key not on the list (or unsigned) is installed
- **THEN** the install is refused as untrusted

### Requirement: Per-organisation role gating for publish and install (REQ-FCS-008)

Beyond "any admin", an organisation SHALL be able to nominate the groups permitted
to publish and to install via configuration (`federated_config_publish_groups` /
`federated_config_install_groups`). An empty list means not-yet-enforced (any
signed-in user may act); admins are always allowed.

#### Scenario: A non-member is denied publish

- **GIVEN** an org whose publish-groups list is set and does not include the user's groups
- **WHEN** the non-admin user publishes
- **THEN** the action is refused

### Requirement: A whole configuration set is shareable as one type (REQ-FCS-009)

OpenRegister SHALL make a whole configuration set — an app's worth of registers,
schemas, objects, views, flows, sources and mappings — shareable as one type
(`openregister.configset`), wrapping the existing multi-entity export/import. A
repository MAY hold many such files (a config-set repository).

#### Scenario: A configuration set round-trips

- **GIVEN** an OpenRegister configuration spanning several registers and schemas
- **WHEN** it is bundled as a configuration set and installed elsewhere
- **THEN** every entity is installed as one unit

### Requirement: Publish creates the repository, tags it, and can be fetched back (REQ-FCS-010)

Publishing SHALL ensure the target repository exists (creating it when absent) and
carry the type's discovery topic, so a freshly published configuration is findable
via topic discovery. A published bundle file SHALL be fetchable from a repository
(the bridge from discovery to install), read anonymously for public repositories
or through the broker when a credential is supplied.

#### Scenario: A published config is discoverable and installable

- **GIVEN** a configuration published to a new repository
- **WHEN** another instance discovers it by topic, fetches the bundle, and installs it
- **THEN** the repository was created, topic-tagged, and the bundle installs

### Requirement: A config-set repository can also be an installable standalone app (REQ-FCS-011)

The store SHALL support a published config-set repository that additionally
carries the files making it an app-store-installable, standalone Nextcloud app —
an `info.xml` declaring OpenRegister its data-layer dependency, a build that
packages the shared Vue component library, and a runtime that renders the set's
manifest without the generating app present. The app-generating app (e.g.
OpenBuild) emits the scaffolding; OpenRegister owns the configuration + federation.

#### Scenario: A published app repository is installable

- **GIVEN** an app published to a repository through the store
- **WHEN** the repository is inspected
- **THEN** it carries both the configuration set and the installable app scaffold (info.xml with OpenRegister required, the packaged Vue runtime)

@e2e exclude backend service + fleet contract — covered by unit tests
(IShareableConfigType registry, allowlist enforcement, secret exclusion) and
Playwright e2e on the first two consumers (OpenBuild apps + Flows: publish →
discover → install → run); design-first change, no code in this proposal
