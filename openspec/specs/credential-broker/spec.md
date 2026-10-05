---
status: in-progress
---

# credential-broker Specification

## OpenSpec changes

- `github-provider-shop-rules` (in-progress) — widens the runtime-immutable
  provider catalogue's `github` allow-rules for OpenBuild's redesigned app shop:
  adds `GET /search/repositories`, `GET /user`, `POST /user/repos`, and
  `POST /orgs/*/repos` (search, PAT identity, repo creation). Existing repo
  read/contents/git rules and the `gitlab`/`doffin` entries stay unchanged; the
  catalogue remains runtime-immutable, changed only via this reviewed release.
- `anthropic-cli-inject-only-provider` (in-progress) — registers `anthropic-cli`
  as the first `inject_only` provider justified by a NON-HTTP CONSUMER rather than
  an unbounded host: a Claude Max/Pro subscription token bound for the `claude`
  CLI's process environment, where there is no request to proxy and no header to
  substitute, so the constrained-proxy path cannot express it at any host.
  Generalises `inject_only` to mean "the broker cannot bound this call, so it
  refuses to make it". Records the personal-scope-only Anthropic ToS constraint
  (declared here; enforced by the consuming app, which owns the only resolution
  path). `anthropic`/`anthropic-oauth` and the `generic-*` entries stay unchanged;
  catalogue `1.5.0` → `1.6.0`.

## Purpose
A user gives a secret to OpenRegister once, and apps act on external providers on that user's behalf through OpenRegister without ever holding the secret, with per-app scoping, central revocation and an audit of every use.

## Requirements

### Requirement: Brokered credentials never expose the private key on disk

Loading a signing key for authorization SHALL NOT write the private key to a
predictable, world- or group-readable path. If a temporary file is used, it
SHALL be created with a random name and `0600` permissions before the key is
written, and removed immediately after use.

#### Scenario: No world-readable key window

- **WHEN** the service loads an RSA signing key
- **THEN** the key is never present in a file readable by other users
- **AND** any temporary file used is `0600` and deleted after use

### Requirement: Broker capability tokens are bound to the specific request

A broker capability token SHALL bind to the specific outbound call it authorises
(method + path, optionally body digest), not merely to `{appId, credentialId,
exp}`. A token presented for a call that does not match its bound request digest
SHALL be rejected, even within its TTL.

#### Scenario: Token cannot be replayed against a different call

- **WHEN** a token is issued for `GET /repos/x`
- **AND** it is replayed against `PUT /repos/x/contents/y` within its TTL
- **THEN** the broker rejects the call with HTTP 403 (digest mismatch)

#### Scenario: Matching call is authorised

- **WHEN** a token bound to `GET /repos/x` is used for exactly that call
- **THEN** the broker authorises it (subject to the four allow-rule guards)

### Requirement: Upstream transport failures carry a secret-free real reason

When the broker's outbound call fails at the transport level (after all four
guards passed and the secret was injected), the `CredentialUpstreamException`
thrown to in-process callers, and the corresponding server-side log line,
SHALL both carry the underlying exception's class and a redacted description
of its message — with the credential's own secret value removed — rather than
a single hardcoded generic string. The HTTP API response for the same failure
SHALL remain a static, generic message; this requirement governs only the
exception object and the log line, not the response returned across the HTTP
trust boundary.

#### Scenario: A header-format failure's real cause reaches the exception, not just the log

- **WHEN** the outbound HTTP client rejects the request because the injected
  auth header value is invalid (e.g. it contains a raw newline)
- **AND** the underlying transport exception's own message embeds the
  rejected header value, which contains the secret
- **THEN** the `CredentialUpstreamException` thrown by the broker contains
  the real failure reason (e.g. "is not valid header value") with the secret
  value redacted
- **AND** the log line the broker writes for this failure contains the same
  redacted reason, never the raw secret

#### Scenario: The HTTP response stays generic regardless of the improved diagnosis

- **WHEN** a brokered call made over `POST /api/credentials/{id}/request` or
  `.../session-request` fails at the transport level
- **THEN** the JSON response is the static `{"message": "Upstream request
  failed"}` body with HTTP 502, unchanged by how much diagnostic detail the
  exception object itself now carries

### Requirement: A credential secret is trimmed of surrounding whitespace before storage

Both places a caller-supplied secret is written to the credential vault
(minting a new credential, and rotating an existing credential's secret)
SHALL trim leading and trailing whitespace (including newlines) from the
secret before it is passed to `CredentialStore::put()`. A secret that trims
to an empty string SHALL be treated as no secret supplied.

#### Scenario: A secret rotated with a trailing newline is stored trimmed

- **WHEN** a credential's secret is rotated via `PUT /api/credentials/{id}`
  with a value ending in `"\n"`
- **THEN** the value stored in the vault has the trailing newline removed
- **AND** a subsequent brokered call injects the trimmed value into the
  outbound header, which is a valid header value

### Requirement: Credential metadata schema

OpenRegister SHALL define a `credential` schema whose objects hold owner-scoped
metadata for a stored secret and MUST NOT contain the secret value. Each object
SHALL carry `name` (string), `provider` (a catalogue provider identifier),
`owner` (the owning user's OR-resolved identity), `allowedApps` (array of app
ids), and `createdAt` (ISO-8601 timestamp). The secret itself SHALL live behind
the `CredentialStore` abstraction, keyed by the credential's UUID — in
OpenRegister's application-owned Doriath vault when the Doriath leaf is active,
otherwise in the Nextcloud vault — and never in any `credential` object
property.

#### Scenario: Credential schema declared in the register descriptor

- **WHEN** the `credential-broker` register descriptor (`lib/Settings/credential_broker_register.json`) is read
- **THEN** it declares the `credential` schema with properties `name`, `provider`, `owner`, `allowedApps`, `createdAt`
- **AND** the schema declares no property that stores a secret/token value

#### Scenario: Metadata never carries a secret

- **WHEN** a `credential` object is inspected via list, export, audit, or GraphQL
- **THEN** no secret or token value is present in the object
- **AND** only the metadata handle (`name`, `provider`, `owner`, `allowedApps`, `createdAt`) is returned

#### Scenario: Secret custody is store-resolved, never object-resident

- **WHEN** a credential's secret is stored or rotated
- **THEN** it is written only through the resolved `CredentialStore` leaf, keyed by the credential UUID
- **AND** no OR object, log line, export, or API response ever contains the secret value

### Requirement: Provider catalogue as a runtime-immutable lib file

OpenRegister SHALL ship the curated provider catalogue as a runtime-immutable JSON file in
`lib/` (`lib/Settings/credential-providers.json`), NOT as an OR schema and NOT as
register-seeded objects. Each entry SHALL declare `identifier` (slug), `title`, `baseUrl`
(the host-locked API base), `authScheme` (an object with the header name and a `template`
containing the `{secret}` placeholder), and `allowRules` (an array of `{ method, pathPattern }`
entries defining the permitted HTTP method + path-prefix patterns for the constrained proxy).
The catalogue SHALL be read-only at runtime — there SHALL be no API that creates, updates, or
deletes a provider or an allow-rule (the allow-rules are a security control and MUST NOT be
widenable without a code review + release). The catalogue SHALL include `github` and `gitlab`
entries, each host-locked with allow-rules scoped to the minimum methods and path prefixes needed.

#### Scenario: Catalogue shipped and read-only

- **WHEN** OpenRegister loads the provider catalogue
- **THEN** it reads `lib/Settings/credential-providers.json` from disk, with each entry declaring
  `identifier`, `title`, `baseUrl`, `authScheme`, and `allowRules`
- **AND** no API exists to create, update, or delete a catalogue provider or allow-rule

#### Scenario: Provider declares host-lock and auth scheme

- **WHEN** a catalogue entry is read
- **THEN** its `baseUrl` defines the single host the broker is permitted to call
- **AND** its `authScheme.template` contains a `{secret}` placeholder for secret injection

#### Scenario: GitHub entry present

- **WHEN** the catalogue is loaded
- **THEN** a `github` entry with `baseUrl` `https://api.github.com` and an
  `Authorization: token {secret}` auth scheme exists
- **AND** its `allowRules` permit `PUT /repos/*/contents/*` and `GET /repos/*`

#### Scenario: GitLab entry present

- **WHEN** the catalogue is loaded
- **THEN** a `gitlab` entry with `baseUrl` `https://gitlab.com/api/v4` and a `Bearer {secret}`
  auth scheme exists

### Requirement: Seeded secret-less example credentials

OpenRegister SHALL seed example `credential` metadata objects using general
organisation data (municipality, travel agency) so the capability is testable on a
fresh install. Seed credentials SHALL carry only metadata and MUST use safe placeholder
values (nil UUID for `owner`); no secret is seeded for them.

#### Scenario: Example credentials present after install

- **WHEN** the register import completes
- **THEN** at least two `credential` objects exist, each referencing a seeded provider
- **AND** each seeded credential contains no secret value and uses the nil-UUID `owner` placeholder

### Requirement: App manifest declares provider usage

The shared app-manifest schema (`@conduction/nextcloud-vue`) SHALL gain an optional,
additive `credentials` array. Each entry SHALL declare `provider` (a catalogue provider
identifier), `reason` (human-readable string), and `scopes` (advisory
string array). The addition MUST be back-compatible — existing manifests without a
`credentials` field SHALL keep validating.

#### Scenario: Manifest with credentials validates

- **WHEN** an app manifest declares `credentials: [{ provider: "github", reason: "publish skills", scopes: ["repo"] }]`
- **THEN** `npm run check:manifest` validates the manifest successfully

#### Scenario: Manifest without credentials still validates

- **WHEN** an existing app manifest has no `credentials` field
- **THEN** the manifest continues to validate against the updated schema

### Requirement: Credential store backend resolution

OpenRegister SHALL resolve the active `CredentialStore` leaf at runtime through a
resolver that prefers a Doriath-backed store when the `doriath` app is enabled for
the instance (`IAppManager::isEnabledForUser('doriath')`), the required Doriath
service classes exist (`class_exists` — no compile-time dependency), the required
application-scoped service methods exist, and OpenRegister's Doriath
self-registration state is present. When any condition fails, the resolver SHALL
fall back to the Nextcloud-vault store. Consumers (broker, controller) SHALL keep
depending only on the `CredentialStore` interface; the selection SHALL be wired in
DI (replacing the static alias), with zero call-site changes.

#### Scenario: Doriath eligible selects the Doriath store

- **WHEN** the `doriath` app is enabled, its service classes and application-scoped methods exist, and OpenRegister is registered as a Doriath application
- **THEN** the resolver yields the Doriath-backed `CredentialStore` leaf

#### Scenario: Doriath absent falls back to the vault store

- **WHEN** the `doriath` app is not installed, is disabled, or lacks the required classes/methods
- **THEN** the resolver yields the Nextcloud-vault `CredentialStore` leaf
- **AND** all broker and credential CRUD behaviour continues unchanged

### Requirement: Lazy migration of vault secrets to Doriath

When the Doriath leaf is active, a secret read that misses in Doriath SHALL fall
back to the Nextcloud vault; on a vault hit, the store SHALL re-put the secret
into Doriath and delete the vault row, then return the secret. Writes and deletes
SHALL target Doriath (deletes SHALL also clear any residual vault row). Migration
SHALL only occur in a user-session context (the vault is session-scoped); a
sessionless read of an un-migrated secret SHALL fail closed.

#### Scenario: Read migrates a legacy vault secret

- **WHEN** the Doriath leaf is active and `get(<credential-uuid>)` misses in Doriath but hits in the Nextcloud vault
- **THEN** the secret is stored into Doriath, deleted from the vault, and returned to the caller
- **AND** a subsequent `get` is served from Doriath alone

#### Scenario: Sessionless read of an un-migrated secret fails closed

- **WHEN** a background (no-session) broker call reaches a credential whose secret still lives only in the per-user Nextcloud vault
- **THEN** the read misses in both stores and the broker denies with the static 403
- **AND** no partial migration occurs

### Requirement: OpenRegister self-registration as a Doriath application

OpenRegister SHALL register itself with Doriath through an idempotent repair step
(install + post-migration) that: generates an RSA-4096 keypair; stores the private
key SYSTEM-scoped in `ICredentialsManager` (never in `IAppConfig`, an OR object, or
a log); self-generates a PKCS#10 CSR from the keypair; and calls Doriath's
`ApplicationService::register` in-process with the name `openregister`, type
`internal`, the CSR, and `isAdmin: true` — so the registration auto-approves and
Doriath provisions the EncryptionSuite from the CSR. OpenRegister SHALL persist
the Doriath-assigned application UUID and its own public key PEM in `IAppConfig`.
The step SHALL skip when already registered and SHALL degrade (warn, not fail)
when Doriath is unavailable.

#### Scenario: First run registers and provisions

- **WHEN** the repair step runs on an instance with an eligible Doriath and no prior registration
- **THEN** an RSA-4096 keypair is generated, the private key is stored system-scoped in `ICredentialsManager`, and Doriath registers an active `openregister` application with an EncryptionSuite provisioned from the CSR
- **AND** the Doriath-assigned application UUID and the public key PEM are persisted in `IAppConfig`

#### Scenario: Re-run is a no-op

- **WHEN** the repair step runs again after a successful registration
- **THEN** it detects the existing registration and makes no new keypair, CSR, registration call, or suite

#### Scenario: Doriath unavailable degrades

- **WHEN** the repair step runs while Doriath is absent or disabled
- **THEN** it logs/outputs a warning and completes without error
- **AND** the credential broker keeps operating on the Nextcloud-vault leaf

### Requirement: Doriath-backed secret custody

The Doriath-backed store SHALL keep custody as application-owned Doriath secrets
in OpenRegister's single application vault: secret name equal to the credential
UUID, root folder. `put` SHALL encrypt the secret against OpenRegister's own
public certificate using Doriath's `rsa-oaep-sha256-chunked-v1` scheme —
preferring Doriath's stateless `EncryptService` invoked cross-app for scheme
compatibility — and SHALL upsert by name (create via
`SecretService::createByApplication`, rotate via `updateByApplication`). `get`
SHALL read the ciphertext in-process from the application vault and decrypt via
Doriath's `DecryptService::rsaDecrypt` with OpenRegister's private key from the
system-scoped `ICredentialsManager`. `delete` SHALL call Doriath's
`SecretService::deleteByApplication` (cross-repo dependency: Doriath change
`application-secret-delete`) and SHALL be idempotent. Doriath SHALL only ever
hold ciphertext for brokered secrets; the plaintext SHALL exist only in
OpenRegister process memory and never in a log, response, or OR object.
OpenRegister SHALL access Doriath through service-level seams only, never
Doriath's database mappers.

#### Scenario: Put stores ciphertext under the credential UUID

- **WHEN** `put(<credential-uuid>, <secret>)` runs on the Doriath leaf
- **THEN** the stored Doriath secret is application-owned, named `<credential-uuid>`, in the root folder, and its key material is the `rsa-oaep-sha256-chunked-v1` ciphertext of the secret under OpenRegister's public certificate
- **AND** a repeated `put` for the same UUID rotates the same row instead of creating a duplicate

#### Scenario: Get round-trips the secret

- **WHEN** `get(<credential-uuid>)` runs on the Doriath leaf for a stored secret
- **THEN** the in-process read returns the ciphertext and decryption with OpenRegister's private key yields the original secret
- **AND** the plaintext appears in no log line, error, or API response

#### Scenario: Delete is idempotent and complete

- **WHEN** `delete(<credential-uuid>)` runs on the Doriath leaf
- **THEN** the application-owned Doriath secret is removed via `deleteByApplication` and any residual Nextcloud-vault row is cleared
- **AND** deleting an absent secret is a no-op

#### Scenario: Per-user isolation enforced at the broker layer

- **WHEN** an authenticated user attempts to broker a call with another user's credential id while custody lives in the shared application vault
- **THEN** the owner guard (guard 1) denies with the static 403 before any store read occurs

### Requirement: Manifest-driven credential app onboarding

OpenRegister SHALL auto-register a consuming app with the credential broker
(`CredentialAppTokenService::registerApp(appId)`) when an AppHost leaf
initialises or a virtual-app manifest declaring `credentials[]` is registered —
idempotently: auto-onboarding SHALL skip apps that already hold a signing secret
and SHALL never silently rotate one. In-process server-side consumers MAY call
`CredentialBrokerService::request` passing their `appId` directly without an HMAC
token — same-instance PHP is trusted. Signed tokens SHALL remain required for all
cross-runtime and HTTP callers, and the HTTP controller path SHALL be unchanged
(app identity taken only from the verified `X-Credential-Token`, never a body
field).

#### Scenario: Leaf initialisation registers the app once

- **WHEN** an AppHost leaf whose manifest declares `credentials[]` initialises for the first time
- **THEN** the app is registered with the broker without any admin action
- **AND** a subsequent initialisation run detects the existing registration and does not rotate the signing secret

#### Scenario: In-process consumer calls without a token

- **WHEN** an in-process server-side consumer invokes `CredentialBrokerService::request` with its own `appId` and no HMAC token
- **THEN** the four ordered guards run unchanged against that `appId` (including `allowedApps[]`)
- **AND** the call succeeds or fails solely on the guards

#### Scenario: HTTP path still requires the signed token

- **WHEN** an HTTP caller posts to the broker endpoint without a valid `X-Credential-Token`
- **THEN** the request is denied with the static 403 exactly as before this change

### Requirement: Background acting-user resolution

`CredentialBrokerService::request` SHALL accept an optional `actingUserId`
honored ONLY for in-process trusted callers when no user session exists; the
owner guard SHALL then evaluate ownership against `actingUserId`. When a user
session exists, the session identity SHALL win unconditionally. The HTTP
controller SHALL NEVER forward an acting user — on the HTTP path identity
remains session-only. All guard ordering and fail-closed behaviour SHALL be
unchanged.

#### Scenario: Background job acts for its configuring user

- **WHEN** a background job (no user session) invokes the broker in-process with `actingUserId` set to the credential owner's id
- **THEN** the owner guard evaluates against `actingUserId` and the call proceeds through the remaining guards

#### Scenario: Session identity cannot be overridden

- **WHEN** a caller with an active user session passes an `actingUserId` differing from the session user
- **THEN** the owner guard evaluates against the session user, ignoring `actingUserId`

#### Scenario: HTTP callers cannot supply an acting user

- **WHEN** an HTTP request to the broker endpoint carries any acting-user parameter
- **THEN** the controller ignores it entirely and the owner guard uses only the session identity

### Requirement: The catalogue may describe an OAuth2 provider

A provider catalogue entry MAY carry a `kind` field and, when that kind is `oauth2-token-set`, an `oauth2` block declaring the authorization endpoint, the token endpoint, the revoke endpoint where the provider has one, the refresh grant shape, the scope separator, and whether the token request authenticates with a client secret or with PKCE alone. These fields SHALL be read only from the shipped, runtime-immutable file, so an endpoint the broker will exchange a code or a refresh token at can only ever change in a reviewed release.

`@e2e exclude catalogue file contents; asserted by PHPUnit on ProviderCatalogue and by the JSON strictness check`

#### Scenario: An OAuth2 endpoint cannot be supplied at runtime

- **WHEN** a caller supplies a token endpoint in a request body
- **THEN** the broker ignores it and uses the catalogue's value

#### Scenario: An entry missing its oauth2 block cannot act as a token set

- **WHEN** an entry declares `kind: "oauth2-token-set"` but carries no `oauth2` block
- **THEN** the broker denies every call on credentials for that provider

### Requirement: Injection is selected by kind

`CredentialBrokerService::request()` SHALL resolve the value substituted into the provider's `authScheme` template through a kind-aware step: for a `secret` kind the stored secret verbatim, for an `oauth2-token-set` kind the access token in force after any refresh required by the margin. All four existing guards SHALL run before that step, unchanged and in the same order.

`@e2e exclude broker injection path; asserted by PHPUnit including a guard-ordering test`

#### Scenario: Guards still run before any refresh

- **WHEN** a caller is not admitted by the owner or allowed-app guard
- **THEN** the call is denied before any token endpoint is contacted

#### Scenario: An inject-only provider is still refused by the proxy

- **WHEN** `request()` is called for an `inject_only` provider
- **THEN** it is denied exactly as before, whatever kind the entry declares

### Requirement: A token set is never resolved app-side

`CredentialBrokerService::resolveInjectable()` SHALL refuse a credential whose catalogue entry declares the `oauth2-token-set` kind, and SHALL decide that on the kind rather than on the entry's `inject_only` flag. A refusal SHALL return the same "call `request()` instead" signal every other proxied credential returns, so a caller learns nothing about why. The stored document for this kind is a whole token set including a long-lived refresh token, and handing it to an app is the case ADR-064 decision #8 closes.

`@e2e exclude in-process resolution path with no HTTP route; asserted by PHPUnit`

#### Scenario: A token set marked inject-only is still refused

- **WHEN** an `oauth2-token-set` entry also carries `inject_only: true`
- **THEN** `resolveInjectable()` resolves nothing and `request()` denies as well, so the credential fails closed on both paths

#### Scenario: An ordinary inject-only secret is unaffected

- **WHEN** `resolveInjectable()` is called for an entry with no `kind`
- **THEN** it behaves exactly as before, so an OpenConnector source still receives its client secret

### Requirement: Doffin provider entry

The provider catalogue SHALL include a `doffin` entry declaring the Doffin Public
API as a host-locked, GET-only provider. The entry SHALL declare `baseUrl`
`https://betaapi.doffin.no/public/v2`, an `authScheme` injecting the header
`Ocp-Apim-Subscription-Key` whose template is exactly the `{secret}` placeholder
(no prefix), and `allowRules` containing ONLY GET rules limited to the
notice-search path used by the spectr ingestion connector (`/notices`). No rule
for the `doffin` provider SHALL permit a non-GET method, and the entry SHALL be
mutable only through a reviewed release like every other catalogue entry.

#### Scenario: Doffin entry present and host-locked

- **WHEN** the provider catalogue is loaded
- **THEN** a `doffin` entry exists with `identifier` `doffin` and `baseUrl`
  `https://betaapi.doffin.no/public/v2`
- **AND** any brokered call for a `doffin` credential resolves only to the
  `betaapi.doffin.no` host (host-lock guard)

#### Scenario: Subscription-key header injected

- **WHEN** the broker performs a permitted call for a `doffin` credential whose
  stored secret is `YOUR_API_KEY_HERE`
- **THEN** the outbound request carries the header
  `Ocp-Apim-Subscription-Key: YOUR_API_KEY_HERE`
- **AND** any caller-supplied `Ocp-Apim-Subscription-Key` header is discarded
  before injection

#### Scenario: Notice search allowed with query parameters

- **WHEN** a brokered call requests `GET /notices?cpvCodes=48000000&pageNumber=1&pageSize=25`
- **THEN** the query string is stripped for rule matching and the `GET /notices`
  allow-rule matches, so the call proceeds

#### Scenario: Non-GET and unlisted paths are denied

- **WHEN** a brokered call requests `POST /notices`, or `GET` on any path other
  than `/notices`
- **THEN** no `doffin` allow-rule matches and the broker denies the call with the
  static 403, failing closed
