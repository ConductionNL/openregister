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

When a user session exists it is AUTHORITATIVE for the organisation branch: membership is resolved against the session user and any asserted acting organisation is ignored, so a request-context caller can never escalate via an assertion. When NO session exists, an `organisation` credential MAY be admitted for a trusted in-process caller that asserts an `actingOrganisationId` equal to the credential's `organisation` (openregister#450); this is the only sessionless admit path, it is honored ONLY without a session, and the value SHALL NOT be settable from request input — the HTTP-routed broker call passes none, and the assertion is reachable only through the non-routed in-process resolution path. The match keeps resolution decoupled from any individual user's membership (ADR-064 Rule 4): the sessionless `actingUserId` fallback SHALL NOT be consulted for the organisation branch, so org scope is never recoupled to one user.

#### Scenario: Organisation member drives an organisation credential

- **WHEN** an authenticated member of the credential's organisation triggers a broker call for an app listed in `allowedApps`
- **THEN** the call is admitted and the secret is injected server-side from the system vault
- **AND** the personal owner-equality branch is not evaluated for this credential

#### Scenario: Non-member is denied an organisation credential

- **WHEN** a user who is not a member of the credential's organisation triggers a broker call against it
- **THEN** the broker denies the call before any provider call is made

#### Scenario: Routed organisation call still requires a session

- **WHEN** an HTTP-routed broker call for an organisation credential has no authenticated user session
- **THEN** the broker denies it (the routed path asserts no acting organisation, and the sessionless `actingUserId` fallback applies to personal credentials only)

#### Scenario: Sessionless in-process caller resolves an organisation credential by matching assertion

- **WHEN** a trusted in-process caller with no user session resolves an organisation-scoped inject-only credential and asserts an `actingOrganisationId`
- **THEN** the credential is admitted and its organisation-scoped secret is returned only when the asserted organisation equals the credential's `organisation`
- **AND** it is denied when the assertion is absent, empty, or does not match

#### Scenario: A session ignores an asserted acting organisation

- **WHEN** a broker call for an organisation credential has an authenticated user session AND also carries an `actingOrganisationId`
- **THEN** membership is resolved against the session user and the asserted organisation is ignored, so a session member is admitted despite a wrong assertion and a session non-member is denied despite a matching one

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
