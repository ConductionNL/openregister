## Purpose

Defines the `oauth2-token-set` credential kind: a brokered credential whose stored secret is a complete OAuth2 token set rather than a single opaque string, which the broker refreshes and rotates on the caller's behalf so that no consuming app ever holds an access or refresh token.

## ADDED Requirements

### Requirement: A credential kind is declared by the provider catalogue, never by the credential object

The provider catalogue entry SHALL declare its credential kind in a `kind` field with the value `secret` or `oauth2-token-set`. An entry with no `kind` SHALL be treated as `secret`, which is the behaviour every entry has today. The credential object SHALL mirror the resolved kind in a non-secret `kind` property for display and filtering, and the broker SHALL take the catalogue's value as authoritative whenever the two disagree.

`@e2e exclude catalogue parsing has no UI surface; asserted by PHPUnit on ProviderCatalogue and CredentialBrokerService`

#### Scenario: An entry with no kind keeps the single-secret behaviour

- **WHEN** a brokered call runs against a catalogue entry that declares no `kind`
- **THEN** the broker injects the stored secret into the entry's `authScheme` template exactly as before
- **AND** no refresh, lock, or token-set decoding is attempted

#### Scenario: The catalogue wins over a stale object property

- **WHEN** a credential object records `kind: "secret"` but its provider entry declares `kind: "oauth2-token-set"`
- **THEN** the broker treats the credential as an OAuth2 token set
- **AND** the object's stale value is never used to select an injection path

### Requirement: An OAuth2 token set is stored as one opaque secret in the custody leaf

The secret for an `oauth2-token-set` credential SHALL be a single serialised document holding the access token, the refresh token when one was issued, the absolute expiry, the token type, the granted scopes, the provider account identity (`id`, `handle`, `displayName`) and the raw token response as returned by the provider. It SHALL be written to and read from `CredentialStore` as one value under the credential's UUID and its scope. No part of it SHALL be persisted to the credential object, written to a log line, returned by any API response, or included in an exception message.

`@e2e exclude custody-leaf storage has no UI surface; asserted by PHPUnit including a no-token-leaks assertion over object payloads and log records`

#### Scenario: Minting a token set writes nothing token-bearing to the object

- **WHEN** an `oauth2-token-set` credential is minted
- **THEN** the credential object carries only `kind`, `status`, `scopes`, `account`, `expiresAt` and the other declared non-secret metadata
- **AND** neither the access token nor the refresh token appears anywhere in the object's serialised form

#### Scenario: A malformed stored payload fails closed

- **WHEN** the stored secret for an `oauth2-token-set` credential cannot be decoded into a token set
- **THEN** the broker denies the call
- **AND** the denial message names the decode failure without quoting any part of the stored value

### Requirement: Non-secret connection metadata lives on the credential object

The `brokeredcredential` schema SHALL carry `kind`, `status`, `scopes`, `account`, `expiresAt`, `lastRefreshedAt`, `lastError`, `instanceBaseUrl`, `clientId` and `clientCredentialRef`. `status` SHALL be one of `pending`, `active`, `expired`, `relink_needed` or `disabled`. None of these properties SHALL ever hold a token, a client secret, or an authorization code.

`@e2e exclude schema declaration; asserted by the register JSON validator and PHPUnit`

#### Scenario: Status vocabulary is closed

- **WHEN** a credential is written with a `status` outside the declared enumeration
- **THEN** the schema rejects the value

#### Scenario: A client secret is referenced, never stored

- **WHEN** a tenant supplies its own OAuth2 client for a provider
- **THEN** the client identifier is stored on the object as `clientId`
- **AND** the client secret is stored as a separate brokered credential whose UUID is recorded in `clientCredentialRef`

### Requirement: A per-account host is pinned at mint and immutable afterwards

A catalogue entry MAY declare `baseUrlFrom: "instanceBaseUrl"` instead of a fixed `baseUrl`, for providers whose API host belongs to the connected account rather than to the provider. For such an entry the broker SHALL resolve the host from the credential's `instanceBaseUrl`, and SHALL accept it only when it is an absolute `https` URL with no userinfo, no query and no fragment, whose host is a registrable domain name rather than an IP literal, a loopback name, or a private-network address. The value SHALL be recorded on the credential at mint time and SHALL NOT be changeable afterwards; a later write that changes it SHALL be rejected. Every allow-rule still applies, so the host varies per credential while the permitted method and path set does not.

`@e2e exclude host binding is a broker guard with no UI surface; asserted by PHPUnit including rejection cases`

#### Scenario: A credential is locked to the host it was minted with

- **WHEN** a Mastodon credential minted against `https://mastodon.example` is used
- **THEN** the broker resolves the outbound URL against that host only
- **AND** a request that would resolve to any other host is denied

#### Scenario: An unsafe instance host is refused at mint

- **WHEN** a mint supplies `instanceBaseUrl` of `http://127.0.0.1:8080` or `https://192.168.1.10`
- **THEN** the mint is refused
- **AND** no credential object is created

#### Scenario: The pinned host cannot be edited later

- **WHEN** an update changes an existing credential's `instanceBaseUrl`
- **THEN** the update is rejected and the stored value is unchanged

### Requirement: A brokered call refreshes an expiring token set before it is used

For an `oauth2-token-set` credential the broker SHALL compare the stored `expiresAt` against the current time plus a refresh margin. When the margin has been crossed and a refresh token is present, the broker SHALL refresh before injecting. The header injected on every call SHALL be `Authorization: Bearer <access token>`, taking the access token from the token set in force after any refresh.

`@e2e exclude refresh-on-read is broker-internal; asserted by PHPUnit against a mock token endpoint`

#### Scenario: A token still inside its margin is used unchanged

- **WHEN** a brokered call runs against a token set whose expiry is further away than the margin
- **THEN** no token request is made
- **AND** the stored access token is injected as a bearer token

#### Scenario: A token past its margin is refreshed first

- **WHEN** a brokered call runs against a token set whose expiry is inside the margin
- **THEN** the broker exchanges the refresh token at the provider's token endpoint before the outbound call
- **AND** the outbound call carries the newly issued access token

### Requirement: A refresh runs under a per-credential lock and rotates atomically

A refresh SHALL be performed while holding a lock keyed by the credential UUID. A caller that cannot take the lock SHALL wait for the holder to finish and then re-read the stored token set rather than starting a second refresh. The rotated token set SHALL be written to the custody leaf in one `put`, carrying the new access token, the new expiry, and the new refresh token when the provider issued one or the previous refresh token when it did not. The object's `expiresAt`, `lastRefreshedAt`, `scopes` and `status` SHALL be updated only after the custody write succeeded, so a failed write never leaves the object claiming a token the leaf does not hold.

`@e2e exclude lock and rotation are broker-internal; asserted by PHPUnit including a contention case`

#### Scenario: Two concurrent calls perform one refresh

- **WHEN** two brokered calls on the same credential both find the token past its margin
- **THEN** exactly one token request is made to the provider
- **AND** the second call uses the token set the first one stored

#### Scenario: A rotation that omits a new refresh token keeps the old one

- **WHEN** a provider's refresh response carries an access token but no `refresh_token`
- **THEN** the stored token set keeps the refresh token it already held
- **AND** the credential stays refreshable on the next cycle

#### Scenario: A failed custody write leaves the object untouched

- **WHEN** the custody leaf rejects the rotated token set
- **THEN** the credential object's `expiresAt` and `lastRefreshedAt` are unchanged
- **AND** the call fails rather than reporting success

### Requirement: An invalid grant moves the credential to relink needed and fails closed

When a refresh is rejected by the provider with the OAuth2 error `invalid_grant`, the broker SHALL set the credential's `status` to `relink_needed`, record a secret-free `lastError`, emit `CredentialRelinkRequiredEvent`, and send a notification to the credential's owner. The refresh token SHALL NOT be deleted, and the credential object SHALL NOT be deleted, so re-authorisation can restore the same credential id. Every subsequent brokered call on a `relink_needed` or `disabled` credential SHALL fail closed with a typed relink exception before any outbound request is made.

`@e2e exclude relink lifecycle is broker-internal; asserted by PHPUnit on the event, the notification and the fail-closed path`

#### Scenario: A revoked grant is reported once and fails closed thereafter

- **WHEN** a refresh returns `invalid_grant`
- **THEN** the credential's status becomes `relink_needed` and its owner is notified
- **AND** a later brokered call on the same credential is refused without contacting the provider

#### Scenario: A transient refresh failure does not relink

- **WHEN** a refresh fails with a network timeout or an HTTP 503
- **THEN** the credential keeps its `active` status
- **AND** the failure is reported to the caller as an upstream failure

#### Scenario: No token value reaches the notification or the event

- **WHEN** the relink notification and event are produced
- **THEN** neither carries an access token, a refresh token, or a client secret

### Requirement: A daily job refreshes active token sets before they expire

A `TimedJob` SHALL run once per day and refresh every credential whose kind is `oauth2-token-set`, whose status is `active`, and whose `expiresAt` falls inside the job's refresh window. It SHALL take the same per-credential lock as the read path, SHALL continue past a failure on one credential, and SHALL apply the same `invalid_grant` handling as the read path.

`@e2e exclude background job; asserted by PHPUnit on the job and observable with occ in dev`

#### Scenario: An expiring token set is refreshed ahead of use

- **WHEN** the daily job runs and a credential expires inside the window
- **THEN** the credential's token set is refreshed and its `lastRefreshedAt` moves forward

#### Scenario: One bad credential does not stop the sweep

- **WHEN** one credential's refresh throws
- **THEN** the job records the failure and continues with the remaining credentials

#### Scenario: Credentials outside the window are left alone

- **WHEN** the job runs and a credential expires beyond the window
- **THEN** no token request is made for it

### Requirement: Token-set credentials are scoped by who the account belongs to

A token set for a person's own account SHALL be minted with `personal` scope. A token set for an organisation's account, such as a company page or a shared Search Console property, SHALL be minted with `organisation` scope so that no individual owns a shared connection, per ADR-064 rule 4. The scope selects the custody-leaf owner for both the initial write and every rotation.

`@e2e exclude scope selection follows the existing broker guard chain; asserted by PHPUnit`

#### Scenario: A rotation stays in the scope the credential was minted with

- **WHEN** an organisation-scoped token set is refreshed
- **THEN** the rotated set is written under the same reserved system identity the original used
- **AND** it is never rewritten under the refreshing user
