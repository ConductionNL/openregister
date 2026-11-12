## MODIFIED Requirements

### Requirement: Background acting-user resolution

`CredentialBrokerService::request` SHALL accept an optional `actingUserId`
honored ONLY for in-process trusted callers when no user session exists. For a
`personal` credential the owner guard SHALL then evaluate ownership against
`actingUserId`. For an `organisation` credential the organisation guard SHALL
admit a sessionless caller asserting `actingUserId` only when that user exists,
is enabled, and is a REAL member of the credential's organisation. A Nextcloud
administrator who is not on the organisation's member list SHALL NOT be admitted
on this path, although the session path keeps admitting administrators. When a user
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

#### Scenario: A background task for an administrator outside the organisation is refused

- **GIVEN** an `organisation` credential for organisation O
- **AND** a Nextcloud administrator A who is not on O's member list
- **WHEN** a background task invokes `request()` with `actingUserId` A and no session
- **THEN** the broker denies the call before any secret is read
- **AND** A, signed in, may still use the credential through the session path

#### Scenario: A background call inside a user switch is still judged as background

- **GIVEN** trusted code answers a background task for user U inside a `runAs()` switch, so a session user exists
- **WHEN** it calls the PHP-internal `requestForBackgroundUser()` with `actingUserId` U
- **THEN** every guard ignores the session and only the sessionless rules apply
- **AND** an administrator switched in by `runAs()` who is not a member of the credential's organisation is refused
- **AND** a call naming no user is refused

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

## ADDED Requirements

### Requirement: An organisation credential names and lets the admin choose its organisation

`GET /api/credentials/organisations` SHALL list the organisations the caller may
manage (a Nextcloud administrator: every organisation; anyone else: the ones they
own), each with uuid, name and whether it is the caller's active organisation.
`GET /api/credentials?scope=organisation&organisation=<uuid>` SHALL list that
organisation's credentials only when the caller has access to it, and SHALL answer
403 otherwise. `POST /api/credentials` with `scope=organisation` SHALL keep
re-checking that the caller may manage the organisation it names, whatever the
client sent, defaulting to the caller's active organisation when none is named.

#### Scenario: The picker lists the organisations the admin may manage

- **GIVEN** an administrator whose active organisation is O
- **WHEN** they request `GET /api/credentials/organisations`
- **THEN** every organisation is listed, and only O is flagged active

#### Scenario: Another organisation's credentials are listed on request

- **GIVEN** a caller with access to organisation P
- **WHEN** they request `GET /api/credentials?scope=organisation&organisation=P`
- **THEN** only P's organisation credentials are returned

#### Scenario: A foreign organisation is never trusted from the client

- **GIVEN** a caller without access to organisation Q
- **WHEN** they list `scope=organisation&organisation=Q`, or create a credential naming Q
- **THEN** the server answers 403 and stores nothing
