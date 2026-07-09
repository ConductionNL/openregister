## ADDED Requirements

### Requirement: Credential scope

A `credential` object SHALL carry a `scope` property whose value is `personal` or `organisation`, defaulting to `personal` when absent, plus an `organisation` property (an OpenRegister organisation UUID) that MUST be present when `scope` is `organisation` and MUST NOT act as an access key for a personal credential.

#### Scenario: Absent scope defaults to personal

- **WHEN** a `credential` object is read with no `scope` property
- **THEN** it is treated as `scope = personal`
- **AND** its access control and secret storage behave exactly as the pre-existing personal credential

#### Scenario: Organisation credential requires an organisation

- **WHEN** a credential is created with `scope = organisation`
- **THEN** an `organisation` UUID MUST be supplied (defaulting to the caller's active organisation when omitted)
- **AND** the object records the provisioning admin in `owner` for attribution only, never as the access-control key

### Requirement: Organisation secret storage

An organisation credential's secret SHALL be stored in the Nextcloud vault under a single reserved system identity keyed by the credential UUID, never under an individual user, so that membership changes never orphan it and different organisations never collide.

#### Scenario: Organisation secret stored under the system identity

- **WHEN** an organisation credential is created or rotated
- **THEN** its secret is written to `ICredentialsManager` under the system identity keyed by the credential UUID
- **AND** no `credential` object property holds the secret value
- **AND** a personal credential's secret continues to be stored under its owning user, unchanged

#### Scenario: Vault owner is selected from scope at read and write

- **WHEN** the broker resolves, or the controller writes/rotates, a credential's secret
- **THEN** the vault owner is derived from the credential's `scope` by a single shared selector (system identity for organisation, owner uid for personal)
- **AND** the vault key is used only after the access guards have admitted the caller

### Requirement: Organisation broker guard

The broker's owner guard SHALL dispatch on `scope` such that a `personal` credential is admitted only when the acting user equals its `owner` (unchanged), and an `organisation` credential is admitted only when the acting user is a member of the credential's `organisation`; the existing allowedApps, provider allow-rule, and host-lock guards then apply to both scopes.

#### Scenario: Organisation member drives an organisation credential

- **WHEN** an authenticated member of the credential's organisation triggers a broker call for an app listed in `allowedApps`
- **THEN** the call is admitted and the secret is injected server-side from the system vault
- **AND** the personal owner-equality branch is not evaluated for this credential

#### Scenario: Non-member is denied an organisation credential

- **WHEN** a user who is not a member of the credential's organisation triggers a broker call against it
- **THEN** the broker denies the call before any provider call is made

#### Scenario: Organisation call requires a session

- **WHEN** a broker call for an organisation credential has no authenticated user session
- **THEN** the broker denies it (the sessionless `actingUserId` fallback applies to personal credentials only)

#### Scenario: Personal guard is unchanged

- **WHEN** a broker call resolves a personal (or scope-absent) credential
- **THEN** it is admitted only when the acting user equals the credential `owner`, identical to the behaviour before organisation scope existed

### Requirement: Organisation credential administration

Creating, updating, or deleting an `organisation` credential SHALL require the caller to be an administrator of that organisation (or a Nextcloud administrator), while listing organisation credentials returns the metadata of the caller's active organisation to any member and never returns a secret.

#### Scenario: Only an org admin creates an organisation credential

- **WHEN** a non-admin member POSTs a credential with `scope = organisation`
- **THEN** the request is denied
- **AND** an administrator of that organisation (or a Nextcloud admin) performing the same POST succeeds

#### Scenario: Members list organisation credentials without secrets

- **WHEN** a member requests `GET /api/credentials?scope=organisation`
- **THEN** the response lists the active organisation's credential metadata
- **AND** no secret value is present in any listed credential

#### Scenario: Personal API is unchanged

- **WHEN** a client calls `GET /api/credentials` with no scope, or `POST` with no `scope` field
- **THEN** the personal credential path is used with identical behaviour to before this change
