---
kind: code
depends_on:
  - credential-broker
---

## Why

The credential broker's secret custody currently has exactly one leaf:
`NextcloudVaultCredentialStore`, backed by `OCP\Security\ICredentialsManager`
per-user rows. That was the right first leaf (credential-broker design D1/D3),
but the fleet now ships a dedicated secrets app — **Doriath** — with real vault
semantics: per-application EncryptionSuites provisioned from a CSR, ciphertext-
only storage (`rsa-oaep-sha256-chunked-v1`), audit events on every secret
mutation, folders, rotation metadata, and an admin surface. Where Doriath is
installed, broker secrets should live there; where it is not, the NC vault must
keep working unchanged. The `CredentialStore` interface was designed exactly for
this second leaf.

Two adjacent gaps close in the same change because they share the trust-boundary
design: (a) consuming apps still need a manual admin `registerApp` call before
they can use the broker — manifest-driven apps should onboard with no code and no
admin step (D-G); and (b) background jobs (e.g. scheduled ingestion using a
Doffin credential) have no user session, so the broker's owner guard can never
pass for them today (D-K).

## What Changes

All design decisions below are **signed off** (D-A, D-B, D-C, D-F, D-G, D-K) and
carried into design.md — they are inputs, not open questions.

- **(D-A) Backend resolution.** A `CredentialStoreResolver` selects the store the
  way `AnonymisationBackendService` selects anonymisation backends: prefer a new
  `DoriathCredentialStore` when `IAppManager::isEnabledForUser('doriath')` AND the
  Doriath service classes exist (`class_exists`, Deck-leaf pattern per
  `DeckLinkService`); otherwise fall back to `NextcloudVaultCredentialStore`. The
  DI alias in `lib/AppInfo/Application.php` (currently a static
  `CredentialStore → NextcloudVaultCredentialStore` alias) becomes a resolver-
  backed factory. **Lazy migration on read**: a Doriath miss that hits in the
  vault re-puts the secret into Doriath and deletes the vault row.
- **(D-B) OR self-registers with Doriath** via a new idempotent Repair step:
  generate an RSA-4096 keypair (openssl), keep the private key SYSTEM-scoped in
  `ICredentialsManager`, self-generate a CSR, and call Doriath's
  `ApplicationService::register(name: 'openregister', …, isAdmin: true)`
  in-process — admin registration auto-approves the row and provisions the
  EncryptionSuite from the CSR (Doriath validates PKCS#10 + ≥4096 bits). Doriath
  assigns the application row's UUID; OR persists that UUID (and its own public
  key PEM) in `IAppConfig`. Skips when already registered.
- **(D-C) Doriath-backed custody.** `DoriathCredentialStore::put()` encrypts the
  secret against OR's own public certificate using Doriath's
  `rsa-oaep-sha256-chunked-v1` scheme — preferring Doriath's stateless
  `EncryptService` invoked cross-app for scheme compatibility — and stores it as
  an application-owned Doriath secret (name = credential UUID, root folder).
  `get()` reads the ciphertext in-process and decrypts via Doriath
  `DecryptService::rsaDecrypt` with OR's private key. `delete()` calls Doriath's
  NEW `SecretService::deleteByApplication` — a **cross-repo dependency** on the
  Doriath change `application-secret-delete` (see Impact).
- **(D-F) Custody moves** from per-user NC vault rows to OR's single
  application-owned Doriath vault; per-user isolation is enforced at the
  broker/metadata layer (the owner IDOR guard on the credential OR object).
  The trade-off is documented explicitly in design.md.
- **(D-G) Manifest-driven no-code onboarding.** When an AppHost leaf initialises
  (`lib/AppHost/Repair/GenericInitializeSettings.php`) or a virtual-app manifest
  declaring `credentials[]` is registered, OR auto-registers that app with
  `CredentialAppTokenService::registerApp(appId)` — idempotently (never silently
  rotating an existing signing secret). In-process server-side consumers (e.g.
  openconnector) call `CredentialBrokerService::request` passing `appId` directly
  WITHOUT an HMAC token — same-instance PHP is trusted; signed tokens remain
  required for cross-runtime/HTTP callers. The HTTP controller path is unchanged.
- **(D-K) Background/system consumption.** `CredentialBrokerService::request`
  gains an optional `actingUserId` parameter honored ONLY for in-process trusted
  callers when no user session exists; the owner guard evaluates against it. The
  HTTP controller NEVER forwards an acting user — session-only there.

## Non-Goals

- No change to the four ordered guards' order or semantics (owner → allowedApps
  → allow-rules → host-lock, failing closed).
- No new HTTP endpoints; `CredentialController` routes and auth posture stay as
  they are (the broker endpoint still requires the `X-Credential-Token`).
- No Doriath UI work and no changes to Doriath-side code from this repo — the
  Doriath-side `deleteByApplication` (and the application-scoped in-process read
  it pairs with) land in Doriath's own change.
- No bulk/eager migration of existing vault secrets — migration is lazy-on-read
  only (D-A).
- No OAuth2 flows; Belgium OAuth2 and GitHub-for-spectr remain PARKED.

## Capabilities

### Modified Capabilities

- `credential-broker`: secret custody becomes leaf-resolved (Doriath preferred,
  NC vault fallback, lazy migration); OR self-registers as a Doriath application
  via Repair; consuming apps onboard from their manifest with no admin step;
  in-process trusted callers may pass `appId` (and, for background jobs,
  `actingUserId`) directly while the HTTP surface keeps signed-token-only
  identity. The credential metadata schema and the guard chain are unchanged.

## Impact

- **New code**: `lib/Service/Credential/CredentialStoreResolver.php`,
  `lib/Service/Credential/DoriathCredentialStore.php`, `lib/Repair/`
  self-registration step (+ `appinfo/info.xml` repair-steps entries).
- **Changed code**: `lib/AppInfo/Application.php` (DI alias → resolver factory,
  ~line 347); `lib/Service/Credential/CredentialBrokerService.php`
  (`actingUserId`, in-process trust path); `lib/Service/Credential/`
  `CredentialAppTokenService.php` (idempotent register guard for auto-onboarding);
  `lib/AppHost/Repair/GenericInitializeSettings.php` + the virtual-app manifest
  registration path (D-G hook).
- **Cross-repo dependency (prose, not `depends_on` — different repo):** Doriath
  change `application-secret-delete` must land `SecretService::deleteByApplication`
  before `delete()` custody is complete. The same in-process seam needs an
  application-scoped read-by-name: today only `SecretMapper::findByName('application', …)`
  exists (consumed by Doriath's machine HTTP controller), with no service-level
  equivalent — the Doriath change (or a sibling) must expose one for `get()`/
  `put()`-upsert. OR degrades gracefully until then (resolver falls back to the
  vault leaf when the required Doriath classes/methods are absent).
- **No OR schema changes** — no seed data, no register JSON edits.
- **Security surface**: net-neutral or better — secrets gain at-rest RSA
  envelope encryption + Doriath audit events; the owner IDOR guard remains the
  per-user isolation boundary (D-F trade-off in design.md).
