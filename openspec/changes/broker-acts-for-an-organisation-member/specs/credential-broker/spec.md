## MODIFIED Requirements

### Requirement: Background acting-user resolution

`CredentialBrokerService::request` SHALL accept an optional `actingUserId`
honored ONLY for in-process trusted callers when no user session exists. For a
`personal` credential the owner guard SHALL then evaluate ownership against
`actingUserId`. For an `organisation` credential the organisation guard SHALL
admit a sessionless caller asserting `actingUserId` only when that user exists,
is enabled, and has access to the credential's organisation (a member, or a
Nextcloud administrator, exactly the rule the session path applies). When a user
session exists, the session identity SHALL win unconditionally. The HTTP
controller SHALL NEVER forward an acting user, and both broker endpoints SHALL
refuse a caller without a session, so on the HTTP path identity remains
session-only. All guard ordering, the provider allow-rules, the host-lock and
the fail-closed behaviour SHALL be unchanged.

#### Scenario: Background job acts for its configuring user

- **WHEN** a background job (no user session) invokes the broker in-process with `actingUserId` set to the credential owner's id
- **THEN** the owner guard evaluates against `actingUserId` and the call proceeds through the remaining guards

#### Scenario: Background task acts for a member of the credential's organisation

- **GIVEN** an `organisation` credential for organisation O that allows the calling app
- **AND** an enabled user U who is a member of O
- **WHEN** a background task (no user session) invokes `request()` in-process with `actingUserId` U
- **THEN** the organisation guard admits the call
- **AND** the secret is read from the organisation vault and injected by the broker

#### Scenario: Background task for a non-member is refused

- **GIVEN** an `organisation` credential for organisation O
- **WHEN** a background task invokes `request()` with an `actingUserId` that is not a member of O, does not exist, or is disabled
- **THEN** the broker denies the call before any secret is read

#### Scenario: A personal credential serves only its owner in the background

- **GIVEN** a `personal` credential owned by user A
- **WHEN** a background task invokes `request()` with `actingUserId` B
- **THEN** the broker denies the call before any secret is read

#### Scenario: Session identity cannot be overridden

- **WHEN** a caller with an active user session passes an `actingUserId` differing from the session user
- **THEN** the owner and organisation guards evaluate against the session user, ignoring `actingUserId`

#### Scenario: HTTP callers cannot supply an acting user

- **WHEN** an HTTP request to the broker endpoint carries any acting-user parameter
- **THEN** the controller ignores it entirely and the guards use only the session identity
- **AND** a request without a session is refused before the broker is called
