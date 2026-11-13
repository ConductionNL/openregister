# Credential Broker

The credential broker lets a user store a long-lived external secret (a GitHub
token, a Doffin subscription key, …) ONCE in OpenRegister and lets allowed apps
*use* it without ever holding it. Apps never receive the secret: the broker
performs the outbound call itself and injects the secret server-side.

## How a brokered call works

Given a `credential` id, a calling app id, and a method + path, the broker
(`lib/Service/Credential/CredentialBrokerService.php`) enforces **four ordered
guards, failing closed** with a static 403:

1. **Owner** — the acting user owns the credential object (per-object IDOR guard).
2. **Allowed apps** — the calling app id is in the credential's `allowedApps[]`.
3. **Allow-rules** — method + query-stripped path match one of the provider's
   `allowRules[]` (exact method, glob path).
4. **Host-lock** — the resolved URL host equals the provider `baseUrl` host;
   callers supply only a path, never a URL.

Only then is the secret read from the store, injected per the provider's
`authScheme` (the broker owns that header — caller-supplied values for it are
discarded), and the single outbound call performed. The secret never appears in
a response, log line, or error.

## Provider catalogue (runtime-immutable)

`lib/Settings/credential-providers.json` is a **read-only security control**
shipped with the app: it defines every reachable provider, its host-locked
`baseUrl`, auth header template, and allow-rules. There is NO API that writes
it — new providers/rules ship only through a reviewed release. Current entries:
`github`, `gitlab`, and `doffin` (Doffin Norway public-procurement Public API —
GET-only, `/notices` search, `Ocp-Apim-Subscription-Key` header carrying the
bare key).

## App-declared providers

An app that needs a host or a path the reviewed catalogue does not offer can
**declare** it, instead of keeping custody of a secret itself. The declaration
is a shipped file, read-only, in the app's OWN directory:
`<app>/lib/Settings/credential-providers.json`. It has the catalogue's shape —
a `providers` map — and nothing in it has any effect until it is admitted.
OpenRegister's own file at that path is the reviewed catalogue and is never
read as a declaration.

```json
{
    "providers": {
        "hydra_console:codeberg": {
            "identifier": "hydra_console:codeberg",
            "title": "Codeberg",
            "baseUrl": "https://codeberg.org/api/v1",
            "authScheme": { "header": "Authorization", "template": "token {secret}" },
            "allowRules": [
                { "method": "GET", "pathPattern": "/repos/*" },
                { "method": "POST", "pathPattern": "/repos/*/issues/*/labels" }
            ]
        }
    }
}
```

Rules every entry must meet, or the whole file admits nothing:

- **Namespaced.** The key and `identifier` are `<appId>:<localId>` for the
  declaring app. Reviewed identifiers carry no colon, so a declaration can never
  shadow or replace one; the reviewed catalogue is always consulted first.
- **A host-locked proxy entry.** `title`, an `https` `baseUrl` (no credentials,
  query or fragment), an `authScheme` of exactly `header` + `template` with
  `{secret}`, and a non-empty `allowRules[]` of `{method, pathPattern}` with an
  upper-case method and a `/`-rooted pattern. `inject_only` is refused: the
  reviewed `generic-*` entries already serve that case, and a declared one would
  be a secret-egress path with no bound at all. A declared credential is always a
  `request()` credential; `resolveInjectable()` returns `null` for it.
- **No other fields.** Only `identifier`, `title`, `baseUrl`, `authScheme`,
  `allowRules`, `extends` and a `$why` note (on the entry or on a rule).

Two admission lanes:

- **Narrowing** (`"extends": "<reviewed id>"`): the `baseUrl` and `authScheme`
  must be the base entry's, verbatim, and every rule must be provably narrower
  than a base rule with the same method — each `*` in the declared pattern may
  only be absorbed by a `*` in the base pattern, and a pattern with `?`, `[`,
  `\`, `..`, `.` or an empty segment is never provably narrower. Such a
  declaration grants nothing the catalogue does not already permit and is
  admitted without a click. One that is not provably narrower is **invalid**,
  never turned into an approval request.
- **Approval**: every other declaration is `pending`, and the broker denies it,
  until an administrator approves it. A decision is a `credentialProviderApproval`
  object in the credential-broker register, pinned to a SHA-256 digest over the
  canonical serialisation of that one entry. Editing the entry changes its
  digest, so it is pending again; an unrelated entry in the same file does not
  disturb it. The latest decision on the current digest counts: `approved`
  admits, `rejected` and `revoked` deny.

A credential on a declared provider is usable by the declaring app only: the
broker denies any call unless the credential's `allowedApps` is exactly that
app. Disabling or uninstalling the app makes its declarations unknown, and every
call on them is denied; the approval records stay in the audit trail.

## Secret custody: resolved store leaves

The secret itself lives behind the `CredentialStore` interface (`put`/`get`/
`delete`, keyed by the credential object's UUID). Which leaf holds it is
decided at runtime by **`CredentialStoreResolver`** (DI factory in
`lib/AppInfo/Application.php`):

- **Doriath leaf** (`DoriathCredentialStore`) — preferred when the `doriath`
  secrets app is enabled, its service classes and application-scoped seam
  methods (`SecretService::getByNameForApplication` / `deleteByApplication`,
  landing via Doriath's `application-secret-delete` change) exist, AND
  OpenRegister has self-registered with Doriath. Secrets are stored as
  **application-owned ciphertext** in OpenRegister's single Doriath vault
  (secret name = credential UUID, root folder), envelope-encrypted with
  Doriath's `rsa-oaep-sha256-chunked-v1` scheme against OpenRegister's own
  public key. Only OpenRegister's system-scoped private key can decrypt them;
  Doriath adds at-rest encryption, audit events, and rotation metadata.
- **Nextcloud-vault leaf** (`NextcloudVaultCredentialStore`) — the fallback:
  per-user rows in Nextcloud's encrypted credentials vault. Used whenever any
  Doriath condition fails, so instances without Doriath behave exactly as
  before.

**Lazy migration:** with the Doriath leaf active, a read that misses in Doriath
but hits in the legacy per-user vault re-puts the secret into Doriath, deletes
the vault row, and returns it. This only happens in a user-session context (the
vault is session-scoped); a background read of an un-migrated secret fails
closed until its owner uses it once interactively. Deletes always clear both
stores.

**Operator note:** disabling Doriath after secrets have migrated makes those
secrets unreachable (broker fails closed with 403) until Doriath is re-enabled;
nothing is lost or exposed. If OpenRegister's private key is ever lost, affected
credentials fail closed and owners simply re-enter the secret.

## Doriath self-registration (repair step)

`lib/Repair/RegisterOpenRegisterWithDoriath.php` (install + post-migration)
makes OpenRegister a first-class Doriath application, idempotently:

1. Skips fast when the persisted application UUID still matches a live Doriath
   row; warns and completes (never throws) when Doriath is unavailable.
2. Otherwise: generates an RSA-4096 keypair, stores the private key
   SYSTEM-scoped in `ICredentialsManager` (`openregister/doriath/private-key`),
   self-generates a PKCS#10 CSR (CN `openregister`), and calls Doriath's
   `ApplicationService::register(..., isAdmin: true)` in-process — the
   registration auto-approves and provisions the EncryptionSuite from the CSR.
3. Persists the Doriath-assigned application UUID and OpenRegister's public key
   PEM (public material only) in `IAppConfig`.

## App onboarding and identity

- **HTTP / cross-runtime callers** authenticate per app with a short-lived
  HMAC token (`X-Credential-Token`, minted from a per-app signing secret
  registered via `POST /api/credentials/apps/{appId}/register`, admin-only).
  The app id checked against `allowedApps[]` comes ONLY from verified token
  claims.
- **Trusted in-process callers** (same-instance PHP: openconnector, background
  jobs) call `CredentialBrokerService::request` directly, passing their own
  `appId` — no token. The token authenticates claims across the HTTP trust
  boundary, which an in-process call does not cross; all four guards run
  identically.
- **No-code onboarding:** when an AppHost leaf (or generated virtual app)
  initialises and its bundled `src/manifest.json` declares a non-empty
  `credentials[]`, `GenericInitializeSettings` auto-registers the app with the
  broker — guarded by `isRegistered()` so an auto-run never rotates an existing
  signing secret. Rotation stays an explicit admin action.

## Background jobs: `actingUserId`

`CredentialBrokerService::request` accepts an optional `actingUserId` for
**sessionless in-process callers only** (e.g. a cron job running a scheduled
ingestion for the user who configured it). Semantics:

- Honored ONLY when no user session exists; the owner guard then evaluates
  against it. All other guards are unchanged.
- When a session exists, the **session identity wins unconditionally** — a
  session caller cannot impersonate anyone by passing `actingUserId`.
- The HTTP controller **never forwards** an acting user: on the HTTP path
  identity is session-only, and any request-supplied acting-user value is
  ignored entirely.
- The value is an assertion by trusted same-instance code — derive it from
  durable job context (the configuring user), never from request input.
