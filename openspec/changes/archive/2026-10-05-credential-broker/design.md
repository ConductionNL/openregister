## Context

Apps in the Conduction fleet need to act on external providers (GitHub, GitLab, …)
on behalf of a signed-in user. Two concrete blockers exist today:

- **Hermiq** `publishToHub` wants to push a skill to a user's GitHub repo.
- **OpenBuild** wants to discover external apps via a provider's API.

The only current path is "paste your personal access token into the app", which
scatters long-lived secrets across every consuming app with no central revocation,
no per-app scoping, and no audit trail. OpenRegister is the foundation repository
and the natural place to hold secrets once and broker constrained outbound calls.

This design covers the **full three-spec chain**. Only the **config head**
(`credential-broker`, `kind: config`) is implemented by *this* change: the
`credential` schema, the runtime-immutable `lib/` provider catalogue JSON file, and
the additive manifest `credentials` field. The code phases (`credential-broker-service`,
`credential-broker-ui`) are designed here so reviewers see the whole arc, but their
tasks live in their own changes (ADR-032 chaining — a `mixed` config-plus-code
envelope is the anti-pattern this split avoids). Both surfaces in the head are
declarative JSON (an OR register file + a shipped catalogue file), so the head stays
`kind: config`.

**Constraints:**

- OR MUST NOT depend on OpenConnector (OR is the foundation). The outbound call is
  OR-native, built on Nextcloud's `OCP\Http\Client\IClientService`.
- Security model per hydra ADR-005: NC-native auth only; every endpoint
  `#[NoAdminRequired]` + a per-object owner IDOR check; identity from `IUserSession`;
  static generic error messages; no secret/PII in logs or responses; provider
  host-lock; constrained method/path allow-rules.
- Data model per ADR-001: domain metadata → OR objects; app config → `IAppConfig`;
  the *secret* is not domain data, so it lives in the NC vault, not an OR object.

## Goals / Non-Goals

**Goals:**

- Define an owner-scoped `credential` schema whose objects carry only metadata + a
  handle — never a secret value.
- Ship a runtime-immutable provider catalogue as a `lib/` JSON file
  (`lib/Settings/credential-providers.json`) capturing host-lock + auth scheme +
  constrained-proxy allow-rules, with `github` and `gitlab` entries.
- Add an additive `credentials[]` field to the shared app-manifest schema.
- Design (for the code phases) the `CredentialStore` abstraction + `ICredentialsManager`
  vault leaf, the `CredentialBrokerService` + guarded request endpoint, and the
  `CnCredentials` settings section — so the config head lands with a coherent plan.

**Non-Goals (this change):**

- No PHP, no Vue, no endpoints, no vault access — those are the code phases.
- No consuming-app wiring (Hermiq, OpenBuild adopt the broker in their own changes).
- No Vault/KMS backend — only the design seam for future leaves is described.
- No OAuth authorization-code flow — v1 brokers pre-issued tokens/PATs only.

## Decisions

### D1 — Credential metadata is an OR object; the secret is in the NC vault

The `credential` schema stores `{ name, provider, owner, allowedApps[], createdAt }`
as an owner-scoped OR object. It holds **no secret** — the secret is written to and
read from Nextcloud's `OCP\Security\ICredentialsManager`, keyed by the credential's
OR UUID (e.g. vault key `openregister/credential/<uuid>`). `ICredentialsManager`
encrypts at rest and scopes per user.

*Why:* ADR-001 says domain metadata belongs in OR objects, but a secret is not domain
data and MUST NOT be queryable/exportable/auditable like one. Splitting metadata (OR)
from secret (vault) means the token never appears in a list response, an export, an
audit trail, or a GraphQL result. *Alternative rejected:* storing the token in an OR
object property with a "sensitive" flag — OR has no field-level encryption-at-rest for
object JSON and every read path (search, export, audit, GraphQL) would risk leaking it.

### D2 — Provider catalogue is a runtime-immutable `lib/` JSON file, NOT an OR schema

The provider catalogue ships as a **declarative JSON file in OR's `lib/`**
(`lib/Settings/credential-providers.json`), read-only at runtime, NOT a
`credentialProvider` OR schema and NOT register-seeded objects. Each entry declares
`identifier`, `title`, `baseUrl` (host-lock), `authScheme` (header + `{secret}`
template), and `allowRules[]` (`{ method, pathPattern }`). The broker loads it from
disk server-side; there is no API that creates, edits, or deletes catalogue entries.

*Why (security):* the `allowRules[]` ARE the constrained proxy's security guardrails —
they define exactly which methods and paths the broker will ever call on a locked host.
If the catalogue were a register-seeded OR object it would be reachable by the object
CRUD API and therefore *widenable at runtime* (an admin — or, via any IDOR/authz slip,
worse — could add a rule like `PUT /repos/*` and broaden the blast radius of every
credential). Shipping the catalogue in `lib/`, fixed at release, is the strictly smaller
trust surface: new providers and new rules require a code review + a release, not a live
API write. This is a deliberate exception to ADR-031's declarative-first default —
justified because the artefact is a security control, not domain data. It is still a
declarative JSON config file (no imperative logic), so the change stays `kind: config`.
*Alternative rejected:* register-seeded `credentialProvider` objects — more flexible, but
runtime-mutable guardrails are the wrong default for a secret-brokering proxy. A PHP
catalogue class was also considered; a plain JSON file keeps the head declarative and
diffable.

### D3 — `CredentialStore` abstraction + `ICredentialsManager` leaf (code phase)

`credential-broker-service` introduces an abstract `CredentialStore` interface
(`put(uuid, secret)`, `get(uuid): ?string`, `delete(uuid)`) with a first concrete
`NextcloudVaultCredentialStore` backed by `OCP\Security\ICredentialsManager`. This is
the OR "leaf" pattern (cf. `integration-leaf-foundation`): future leaves (Vault, AWS
KMS) implement the same interface and slot in behind DI without touching the broker.

### D4 — The broker enforces four ordered guards, then injects the secret

`credential-broker-service` ships `CredentialBrokerService::request()` and
`POST /api/credentials/{id}/request` accepting `{ method, path, headers?, body? }` +
the calling app's id. The service MUST enforce, **in order, failing closed**:

1. **Owner** — `IUserSession` current user owns the credential (per-object IDOR check,
   ADR-005 Rule 3). Else `403`.
2. **Allowed app** — the calling app id is in the credential's `allowedApps[]`. Else `403`.
3. **Allow-rules** — the request `method` + `path` match one of the provider's
   `allowRules[]` (method equality + path-prefix/glob match). Else `403`.
4. **Host-lock** — the resolved target URL host equals the provider's `baseUrl` host
   (path is appended to `baseUrl`; the caller supplies only the path, never a full URL).
   Else `403`.

Only after all four pass does the broker read the secret from the store (D3) and inject
it per the provider's `authScheme` (e.g. `Authorization: token <secret>`), perform the
call via `IClientService`, and return the provider's status + body. **The secret never
appears in a response, a log line, or an error message.** Errors returned to the client
are static + generic (`'Request not permitted'`, `'Upstream request failed'`); the real
cause is logged server-side with `$this->logger->error()` and the secret redacted.

### D5 — App-identity trust: signed per-app tokens (in-process AND cross-runtime)

The broker must trust the "calling app id" it checks against `allowedApps[]`. The app id
is **never** read from a request body or a caller-supplied string. Instead the broker
authenticates the calling app via a **signed per-app token**:

- **App registration.** Each broker-consuming app is registered with the broker under
  its app id and a per-app signing key/secret (issued at registration, held by the broker;
  the app holds its own copy). Registration + key storage live in the
  `credential-broker-service` code phase (the broker's app registry; secrets held via
  `ICredentialsManager`, never in an OR object).
- **Token.** The calling app presents a short-lived token — a JWS/HMAC-signed assertion
  binding `{ appId, iat, exp, aud=openregister }` (and, for HTTP callers, a nonce to
  resist replay). The token is signed with the app's registered key.
- **Verification.** The broker verifies the signature against the registered key for the
  claimed `appId`, checks `exp`/`aud`/nonce, and only then treats `appId` as authentic and
  runs the `allowedApps[]` guard. A bad/absent/expired token → `403` (static error).

*Why this over an in-process-only scheme:* a signed token authenticates the caller
identically whether the call arrives as an in-process PHP service call OR as an HTTP /
cross-runtime request. There is therefore **no HTTP-only limitation** — the same
mechanism secures both, so ExApp sidecars and out-of-process consumers are first-class.
The broker still NEVER trusts an app id from the JSON body; identity comes only from the
verified token (and the current user still comes only from `IUserSession`). Token
issuance + verification are specified here but implemented in the
`credential-broker-service` code phase — this config-head change ships no token code.

### D6 — Manifest `credentials` field; catalogue is read-only, credentials are owner-scoped

The app-manifest schema gains an additive, optional `credentials` array of
`{ provider, reason, scopes }`. `provider` is a catalogue `identifier`; `reason` is a
human string for the settings UI; `scopes` is an advisory string array. The settings UI
(`credential-broker-ui`) aggregates installed apps' declarations to show "app X wants to
use provider Y for reason Z". `credential` objects are owner-scoped (any authenticated
user CRUDs their own via `#[NoAdminRequired]` + per-object owner check). The provider
catalogue is **read-only at runtime** for everyone (it is the `lib/` file, D2) — there is
no create/update/delete API for it, admin or otherwise.

## Declarative-vs-imperative decision (ADR-031)

- **Provider catalogue → declarative JSON, but runtime-immutable in `lib/`** (D2): a shipped
  `credential-providers.json`, NOT a register-seeded `credentialProvider` schema. This is a
  deliberate, security-justified exception to ADR-031's declarative-first-*in-the-register*
  default — the allow-rules are a security control that MUST NOT be widenable at runtime, so
  they ship fixed at release. Still declarative JSON (no imperative logic), so the head stays
  `kind: config`.
- **Credential metadata → declarative**: plain OR `credential` objects via ObjectService;
  no bespoke mapper/entity (ADR-001).
- **`createdAt` / any derived count fields → declarative**: use OR object metadata
  (`@self.created`) and `x-openregister-calculations` if a "credentials in use" count is
  ever surfaced; no service method that loops and counts.
- **The broker call + the four auth guards → imperative (justified exception)**: reaching
  an external HTTP host and enforcing an ordered security guard is a genuine external-
  integration + security-guard concern that no `x-openregister-*` extension expresses
  (ADR-031 "what apps SHOULD still write in PHP": external API integrations; ADR-003).
  `CredentialBrokerService` + `NextcloudVaultCredentialStore` are therefore legitimately
  imperative and live in the `credential-broker-service` code phase. Documented here so
  the reviewer sees the exception is deliberate, not an oversight.

## Reuse Analysis (ADR-011 / ADR-001 dedup)

- **`OCP\Http\Client\IClientService`** — OR already uses it across `WebhookService`,
  `ConfigurationService`, `UploadService`, `Configuration/GitLabHandler`. The broker's
  outbound call reuses this, NOT a new Guzzle wrapper.
- **`OCP\Security\ICredentialsManager`** — the NC-native encrypted per-user vault. The
  first `CredentialStore` leaf wraps it; no custom secret storage (ADR-005: no custom
  token/password storage).
- **`ObjectService`** (`saveObject` / `findAll` / `deleteObject`) — all `credential`
  metadata CRUD goes through it; no custom Entity/Mapper (ADR-001).
- **`ConfigurationService::importFromApp()` + repair step** — seeds the `credential`
  schema + example credential objects idempotently (slug-matched, `version_compare`),
  exactly like the existing `*_register.json` files. The provider catalogue is NOT
  imported this way — it is the read-only `lib/` JSON file loaded directly by the broker.
- **`AuthorizationService` / per-object owner check** — the broker's owner guard reuses
  OR's existing owner/IDOR pattern rather than a new access-control layer.
- **`@conduction/nextcloud-vue` `CnAppRoot` user-settings slot** — `CnCredentials` mounts
  as an `NcAppSettingsSection` between `CnNotificationPreferences` and the walkthrough
  section (`CnAppRoot.vue` `user-settings` slot), reusing `CnSettingsSection` primitives.
- **NOT reused: OpenConnector `AuthenticationService`/`CallService`** — OR must not depend
  on OpenConnector (foundation-direction rule). OR's own `AuthenticationService` handles
  outbound OAuth/JWT but is not a general proxy; the broker is a small new OR-native seam.

## Seed Data (ADR-001)

All values are SAFE placeholders. No real token, secret, or UUID appears. The `credential`
metadata objects are seeded via `lib/Settings/credential_broker_register.json`
`components.objects[]` with the `@self` envelope, imported idempotently (matched by slug).

**Register:** `credential-broker` → schema `credential` (single schema; the provider
catalogue is NOT a schema, see below).

**Provider catalogue — shipped `lib/Settings/credential-providers.json` (NOT seeded objects,
read-only at runtime, D2):**

```jsonc
{
  "providers": {
    "github": {
      "identifier": "github",
      "title": "GitHub",
      "baseUrl": "https://api.github.com",
      "authScheme": { "header": "Authorization", "template": "token {secret}" },
      "allowRules": [
        { "method": "GET",  "pathPattern": "/repos/*" },
        { "method": "GET",  "pathPattern": "/user/repos" },
        { "method": "PUT",  "pathPattern": "/repos/*/contents/*" },
        { "method": "POST", "pathPattern": "/repos/*/git/*" }
      ]
    },
    "gitlab": {
      "identifier": "gitlab",
      "title": "GitLab",
      "baseUrl": "https://gitlab.com/api/v4",
      "authScheme": { "header": "Authorization", "template": "Bearer {secret}" },
      "allowRules": [
        { "method": "GET",  "pathPattern": "/projects/*" },
        { "method": "POST", "pathPattern": "/projects/*/repository/commits" }
      ]
    }
  }
}
```

This file is a shipped release artefact, not seed data — it is never written through any
API, so it has no `@self` envelope and is not idempotently "re-imported"; it is simply
present on disk and read by the broker.

**`credential` metadata (2 secret-less seed examples — general org data):**

```jsonc
{
  "@self": { "register": "credential-broker", "schema": "credential", "slug": "gemeente-example-github-publish" },
  "name": "Gemeente Example — GitHub publisher",
  "provider": "github",
  "owner": "00000000-0000-0000-0000-000000000000",
  "allowedApps": ["hermiq"],
  "createdAt": "2026-01-01T00:00:00+00:00"
}
{
  "@self": { "register": "credential-broker", "schema": "credential", "slug": "reisbureau-example-gitlab-discovery" },
  "name": "Reisbureau Example — GitLab discovery",
  "provider": "gitlab",
  "owner": "00000000-0000-0000-0000-000000000000",
  "allowedApps": ["openbuild"],
  "createdAt": "2026-01-01T00:00:00+00:00"
}
```

The paired secret for each example credential is NOT seeded (a seed has no vault entry);
in a real flow the user pastes `YOUR_TOKEN_HERE` into the OR settings UI, which writes it
to the vault under the credential UUID. `owner` is the nil UUID placeholder in seed data;
real objects carry the creating user's OR-resolved owner.

## Risks / Trade-offs

- [Runtime-widenable allow-rules] → the catalogue is a read-only `lib/` file (D2); there is
  no API to create/edit/delete a provider or a rule, so the guardrails cannot be broadened at
  runtime. New providers/rules ship in a reviewed release only. The broker reads rules from
  disk server-side, never from the request body.
- [Secret leakage via logs/errors] → broker uses static generic client errors + server-side
  logging with the secret redacted; the secret is only ever in memory during the injected
  call, never persisted to an OR object (D1).
- [Path-glob allow-rule bypass] → path matching normalises the path (reject `..`, decode
  once, require leading `/`, match prefix/glob against the *normalised* path) and host-lock
  is checked on the resolved URL, not the caller's string — the caller supplies only a path,
  never a full URL (D4).
- [App-identity spoofing] → the calling app id is taken only from a verified signed per-app
  token (D5), never from a body field; the token scheme secures in-process AND cross-runtime
  callers identically, so there is no HTTP-only gap.
- [Per-app signing-key compromise] → keys are per-app and stored in `ICredentialsManager`
  (never an OR object); tokens are short-lived with `exp` + nonce; a compromised app key is
  revoked/rotated at the broker's app registry without touching other apps' keys (service phase).
- [Config-head lands before consumers] → expand-then-contract: the `credential` schema, the
  `lib/` catalogue, and the manifest field are additive and inert until the service/UI phases
  consume them; no regression to existing apps (opencatalogi, softwarecatalog manifests keep
  validating).

## Migration Plan

1. This change (config head): add `lib/Settings/credential_broker_register.json` (the `credential`
   schema descriptor + secret-less example objects) and `lib/Settings/credential-providers.json`
   (the read-only provider catalogue), and add the additive `credentials` field to the manifest
   schema(s). These are inert declarative descriptors — **OpenRegister does NOT self-import its own
   register JSON at boot (ADR-037)**, so nothing lands in the DB from this change; the schema is
   materialised by the service-phase Repair step. No data migration. Rollback = delete the files.
2. `credential-broker-service` (code, `depends_on` this): a `lib/Repair/` step that imports
   `credential_broker_register.json` into OR (`importFromFilePath()`, idempotent, slug-matched,
   mirroring `SeedAppVirtualSchemas`); the `CredentialStore` + vault leaf; the broker service +
   guarded endpoint + credential CRUD endpoints; the signed per-app token registry (D5).
3. `credential-broker-ui` (code, `depends_on` service): `CnCredentials` settings section +
   per-app manifest `credentials` declarations; then consuming apps (Hermiq, OpenBuild) adopt.

## Resolved Decisions (signed off)

- **Catalogue placement:** runtime-immutable `lib/Settings/credential-providers.json` (D2), NOT
  register-seeded objects — chosen for the strictly smaller trust surface (the allow-rules are a
  security control that must not be widenable at runtime).
- **App-identity trust:** signed per-app tokens (D5) — secures in-process and HTTP / cross-runtime
  callers identically; no HTTP-only limitation.
- **Credential owner:** per-user — `owner` = the creating NC user (via the OR-resolved owner); the
  secret is stored per-user in `ICredentialsManager`. Managed from Personal settings.
- **Chain shape:** three specs — `credential-broker` (config head) → `credential-broker-service`
  (code) → `credential-broker-ui` (code), each `depends_on` the previous.
