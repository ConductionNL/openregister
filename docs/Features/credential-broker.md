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
