## Context

The credential broker (capability `credential-broker`, head change still active in
`openspec/changes/credential-broker/`) shipped with a single custody leaf:
`NextcloudVaultCredentialStore` behind the `CredentialStore` interface
(`put/get/delete` keyed by the credential OR object's UUID), bound by a static DI
alias in `lib/AppInfo/Application.php` (~line 347). The broker
(`CredentialBrokerService::request`) enforces four ordered guards failing closed
(owner via `IUserSession` → `allowedApps[]` → catalogue allow-rules → host-lock),
and app identity on the HTTP path is proven by short-lived HMAC tokens
(`CredentialAppTokenService`, per-app signing secrets system-scoped in
`ICredentialsManager` under `openregister/credential-app-key/<appId>`).

Doriath is the fleet's secrets app. Verified contract (doriath
`origin/development`):

- `ApplicationService::register(string $name, ?string $description, string $type,
  ?string $csr, ?string $userId, bool $isAdmin): Application` — `type` ∈
  `internal|external`; **admin callers auto-approve** (row becomes `active`,
  `approvedBy`/`approvedAt` set) and, when a CSR is supplied, it is validated
  (PKCS#10, **≥4096-bit key**) and an EncryptionSuite is provisioned immediately
  via `EncryptionSuiteService::provisionForApplication(applicationId, csrPem)`.
  **Doriath generates the application row id (`Uuid::uuid4()`)** — the caller
  supplies a *name*, not an id.
- `SecretService::createByApplication(array $data, string $applicationId)` /
  `updateByApplication(string $id, array $data, string $applicationId)` —
  application-owned secrets (`ownerType='application'`); `createByApplication`
  requires an ACTIVE EncryptionSuite for the application and stores `key` (the
  ciphertext) verbatim; audit events dispatched.
- `MachineSecretEnvelopeService::SCHEME = 'rsa-oaep-sha256-chunked-v1'` — the
  encryption scheme identifier; `EncryptService::rsaEncrypt(plaintext,
  publicKeyPem)` / `DecryptService::rsaDecrypt(ciphertext, privateKeyPem)` are
  **stateless** (no DB, no entity awareness) and implement OAEP-SHA256 with
  manual padding + 512-byte chunking (WebCrypto-compatible — PHP's built-in OAEP
  is SHA-1-locked, so reimplementing in OR would risk scheme drift).
- **Gap (verified):** `SecretService` has NO application-scoped read or delete
  today. The only application-scoped read-by-name is
  `SecretMapper::findByName('application', $ownerId, $name, ?$folderId)`,
  consumed by Doriath's machine HTTP controller. `deleteByApplication` is being
  specced as the Doriath change `application-secret-delete` (cross-repo
  dependency); the service-level read-by-name that `get()`/upsert need must land
  with it (see D-C).

All decisions below (D-A, D-B, D-C, D-F, D-G, D-K) are **signed off** — recorded
here with their code-verified mechanics, not re-litigated.

## Goals / Non-Goals

**Goals:**

- Doriath-preferred, vault-fallback custody behind the existing `CredentialStore`
  interface, with lazy read-time migration (D-A).
- OR registered as a first-class Doriath application with its own EncryptionSuite,
  idempotently, via Repair (D-B).
- Scheme-compatible envelope custody: Doriath stores ciphertext only; OR holds the
  only private key (D-C); custody trade-off documented (D-F).
- Zero-admin-step onboarding for manifest-driven apps + a trusted in-process call
  path (D-G) and a background acting-user seam (D-K) with an explicit trust
  boundary.

**Non-Goals:**

- No guard-order/semantics changes; no new HTTP endpoints; no HTTP contract
  changes (the broker endpoint keeps `X-Credential-Token`).
- No eager migration job; no Doriath-side code in this repo; no OR schema/seed
  changes.

## Decisions

### D-A — `CredentialStoreResolver` with Doriath-preferred resolution + lazy migration

A new `CredentialStoreResolver` mirrors the backend-selection role of
`lib/Service/Anonymisation/AnonymisationBackendService.php` (single source of
truth for "which backend is active and is it actually usable") combined with the
Deck-leaf availability idiom of `lib/Service/DeckLinkService.php`
(`IAppManager::isEnabledForUser('deck')`) and the lazy-class-resolution idiom of
`AnonymisationBackendService::requestOpenAnonymiser()` (`class_exists` +
`OCP\Server::get`, so OR carries no compile-time dependency on the optional app):

- **Doriath eligible** ⇔ `IAppManager::isEnabledForUser('doriath')` AND
  `class_exists` succeeds for every Doriath service class the leaf calls
  (`ApplicationService`, `SecretService`, `EncryptService`, `DecryptService`)
  AND the required application-scoped methods exist (`method_exists` for
  `deleteByApplication` and the read-by-name seam — this is what makes the
  cross-repo rollout order safe: an older Doriath yields the vault leaf, not a
  broken one) AND OR's self-registration state exists (D-B `IAppConfig` id).
- Eligible → `DoriathCredentialStore`; otherwise → `NextcloudVaultCredentialStore`.
  The DI alias at `lib/AppInfo/Application.php` (~line 347) becomes a
  `registerService(CredentialStore::class, fn → resolver->resolve())` factory;
  the broker and controller keep depending only on the `CredentialStore`
  interface — zero call-site changes.
- **Lazy migration on read** (inside `DoriathCredentialStore`, which composes the
  vault leaf as its fallback): `get(uuid)` → Doriath miss → vault `get(uuid)`
  hit → `put()` into Doriath → vault `delete(uuid)` → return the secret. Writes
  and deletes go to Doriath only (delete additionally clears any leftover vault
  row so a later fallback can never resurrect a deleted secret).
- **Known limitation (documented, accepted):** the vault leaf is scoped to the
  *session* user, so lazy migration only fires in a session context. A
  background call (D-K) against a not-yet-migrated credential misses in both
  stores and fails CLOSED (static 403 via the existing "no secret stored"
  deny). First interactive use migrates; background use then works.

*Alternative rejected:* a separate `MigratingCredentialStore` decorator — one more
DI seam for behaviour that only exists on the Doriath path; composing the vault
leaf inside `DoriathCredentialStore` keeps the resolver a two-outcome switch.

### D-B — OR self-registration with Doriath via an idempotent Repair step

A new `lib/Repair/` step (registered in `appinfo/info.xml` under both `<install>`
and `<post-migration>`, like `ImportCredentialBrokerRegister`):

1. **Skip fast** when already registered: `IAppConfig` holds the Doriath
   application UUID and the Doriath row still exists — the step is a no-op
   (idempotent, safe on every `occ upgrade`). Also skip (warn, don't fail) when
   Doriath is not eligible (app absent/disabled, classes missing) — mirroring
   `GenericInitializeSettings`' degrade-don't-throw posture.
2. Generate an **RSA-4096 keypair** via openssl (`openssl_pkey_new`).
3. Store the **private key SYSTEM-scoped** in `ICredentialsManager` —
   `store('', 'openregister/doriath/private-key', $pem)` — the same system-scope
   idiom (`userId = ''`) `CredentialAppTokenService` already uses for per-app
   signing secrets. The private key NEVER leaves `ICredentialsManager`, is never
   logged, and never lands in `IAppConfig` or an OR object.
4. Self-generate a **CSR** (`openssl_csr_new`, CN `openregister`) from the
   keypair — Doriath validates PKCS#10 format + ≥4096-bit key.
5. Call `ApplicationService::register(name: 'openregister', description: …,
   type: 'internal', csr: $csrPem, userId: null, isAdmin: true)` **in-process**
   — admin registration auto-approves the row (`active`) and provisions the
   EncryptionSuite from the CSR in the same call.
6. Persist in `IAppConfig` (non-secret): the **Doriath-assigned application UUID**
   (Doriath generates the row id; `'openregister'` is the application *name* —
   verified against `ApplicationService::register`, which calls
   `Uuid::uuid4()`), plus OR's **public key PEM** (derived from the keypair;
   public material, needed by `put()` for envelope encryption without a Doriath
   round-trip).

`userId: null` is intentional: the repair step runs without a session; Doriath
records the registration as a system-actored audit event (verified: its audit
dispatch handles the null registrant).

### D-C — Envelope custody: OR encrypts to itself, Doriath stores ciphertext only

`DoriathCredentialStore` (implements `CredentialStore`):

- **`put(uuid, secret)`** — encrypt `secret` against OR's own public key PEM
  (from `IAppConfig`, D-B) using Doriath's `rsa-oaep-sha256-chunked-v1` scheme,
  by invoking Doriath's **stateless `EncryptService::rsaEncrypt`** cross-app
  (resolved via `class_exists` + `Server::get`). Reusing Doriath's implementation
  is deliberate: the scheme does manual OAEP-SHA256 padding + chunked raw RSA to
  stay WebCrypto-compatible, and a second implementation in OR would be a drift
  risk for every future Doriath consumer. Then upsert the application-owned
  secret: read-by-name (secret **name = credential UUID**, **root folder**
  (`folderId` null), owner = OR's Doriath application) → absent ⇒
  `SecretService::createByApplication(['name' => $uuid, 'key' => $ciphertext],
  $applicationId)`; present ⇒ `updateByApplication($secretId, ['key' =>
  $ciphertext], $applicationId)` (rotation keeps the row, bumping Doriath's
  `keyUpdatedAt` audit metadata).
- **`get(uuid)`** — read the ciphertext in-process from the application-owned
  vault by name (= credential UUID, root folder), decrypt via Doriath
  `DecryptService::rsaDecrypt($ciphertext, $privateKeyPem)` with OR's private
  key fetched from system-scoped `ICredentialsManager`. Miss → D-A lazy
  migration path. Plaintext exists only in memory; never logged.
- **`delete(uuid)`** — call Doriath's NEW `SecretService::deleteByApplication`
  (**cross-repo dependency**: Doriath change `application-secret-delete`),
  idempotent per the `CredentialStore` contract (absent = no-op). Also clears
  any residual vault row (D-A).
- **Read seam status (verified gap):** `SecretService` has no application-scoped
  read today; only `SecretMapper::findByName('application', …)` exists, used by
  Doriath's machine HTTP controller. This change REQUIRES a service-level
  in-process read-by-name (e.g. alongside `deleteByApplication` in
  `application-secret-delete`); OR MUST NOT reach into Doriath's `Db` mapper
  layer. Until both methods exist, the resolver's `method_exists` probe keeps OR
  on the vault leaf (D-A), so the repos can merge in either order.
- **Ambiguity policy:** name-lookup returning >1 row (Doriath does not enforce
  name uniqueness) is treated as corruption: fail closed on `get`, log
  secret-free, never guess.

### D-F — Custody trade-off: application-owned vault, broker-layer user isolation

Before: per-user `ICredentialsManager` rows — storage itself was user-partitioned.
After (Doriath path): ONE application-owned vault (OR's Doriath application) holds
every brokered secret; **per-user isolation is enforced at the broker/metadata
layer** — the owner IDOR guard on the `credential` OR object (guard 1, unchanged)
is the boundary that decides whose secret may be read. Documented consequences:

- The storage layer no longer provides a second, independent user partition: a
  bug that bypassed the owner guard would expose other users' *ciphertext*
  handles to the broker code path (never to the caller directly — the broker
  still only injects the secret into the host-locked upstream call, and never
  returns it).
- What is gained: real envelope encryption at rest (secrets unreadable without
  OR's private key, which lives in `ICredentialsManager` system scope), Doriath
  audit events on every put/rotate/delete, rotation metadata, admin visibility,
  and D-K background reads (the per-user vault structurally could not serve a
  sessionless job — `currentUserId()` is `''` without a session).
- Compensating controls: the owner guard is already covered by unit tests and
  the `hydra-gate-no-admin-idor` mechanical gate; this change adds tests pinning
  "user B can never broker user A's credential" on the Doriath path
  specifically.

Accepted: the broker/metadata layer was ALWAYS the authorization boundary (the
vault partition never checked `allowedApps` or provider rules); this
consolidates enforcement where it already lived, in exchange for materially
better at-rest and audit properties.

### D-G — Manifest-driven no-code onboarding + trusted in-process calls

- **Auto-registration:** when an AppHost leaf initialises
  (`lib/AppHost/Repair/GenericInitializeSettings::run()`) or a virtual-app
  manifest declaring `credentials[]` is registered, OR calls
  `CredentialAppTokenService::registerApp($appId)` for that app —
  **guarded by `isRegistered($appId)`**: `registerApp` currently ROTATES the
  signing secret on every call (verified), and an unguarded auto-run on each
  upgrade would invalidate the app's held copy. Auto-onboarding therefore
  registers only when absent; explicit rotation stays admin-only via the
  existing `POST /api/credentials/apps/{appId}/register`.
- **Trusted in-process path:** in-process server-side consumers (e.g.
  openconnector) call `CredentialBrokerService::request(credentialId, appId,
  …)` directly, passing `appId` WITHOUT an HMAC token — the service method
  already takes `appId` as a parameter, and token minting/verification was only
  ever the *controller's* mechanism for proving app identity across the HTTP
  boundary. Same-instance PHP is trusted: a malicious in-process caller could
  equally forge a token, so the token adds no security there (it authenticates
  claims across a trust boundary that in-process calls do not cross). Signed
  tokens REMAIN required for every cross-runtime/HTTP caller; the HTTP
  controller path is unchanged (`X-Credential-Token` verified, app id taken
  ONLY from the verified claims, never a body field).

### D-K — Background/system consumption: `actingUserId` for in-process callers only

`CredentialBrokerService::request` gains an optional `?string $actingUserId =
null` parameter with strict semantics:

- Honored ONLY when **no user session exists** (`IUserSession::getUser() ===
  null`): the owner guard (guard 1) then evaluates ownership against
  `$actingUserId`. When a session exists, the **session wins unconditionally**
  — a session-context caller cannot impersonate another user by passing
  `actingUserId`.
- The **HTTP controller NEVER forwards an acting user** — `brokerRequest()` does
  not read any such parameter; on the HTTP path identity is session-only,
  exactly as today. Because the controller never forwards it, any call carrying
  `actingUserId` is by construction an in-process PHP caller.
- **Trust boundary (explicit):** `actingUserId` is an *assertion by trusted
  same-instance code* (background jobs, cron) that it acts for that user — the
  broker cannot verify it and does not pretend to; it applies the same owner +
  allowedApps + rules + host-lock guards against the asserted user, failing
  closed. Consumers MUST derive `actingUserId` from durable job context (e.g.
  the user who configured the sync), never from request input. Cross-runtime
  callers get no such parameter — they authenticate per app token and act as
  the session user only.
- All four guards, their order, and the fail-closed static-403 behaviour are
  unchanged; the only delta is *which* user identity guard 1 compares against
  in the sessionless in-process case.

## Declarative-vs-imperative note (ADR-031)

The broker/store remains an **external-integration exception — imperative
justified**: reaching an external HTTP host, enforcing an ordered security-guard
chain, RSA envelope crypto, and cross-app service invocation are exactly the
"what apps SHOULD still write in PHP" category (ADR-031; ADR-003), and no
`x-openregister-*` declarative extension expresses any of them. This change adds
no new declarative surface and removes none: credential *metadata* stays plain
declarative OR objects; the provider catalogue stays the runtime-immutable
declarative `lib/` JSON (credential-broker D2); custody, resolution, migration,
registration, and the trust-boundary logic are imperative services/repair steps
by design. (No seed-data section: this change makes no OR schema changes.)

## Reuse Analysis (ADR-011)

- **`CredentialStore` interface** — reused as-is; `DoriathCredentialStore` is the
  second leaf the interface was built for. No signature changes.
- **`AnonymisationBackendService`** — the resolution pattern (availability
  detection via `IAppManager` + lazy `class_exists`/`Server::get`, no
  compile-time dependency on the optional app) is mirrored, not imported.
- **`DeckLinkService`** — the Deck-leaf availability idiom
  (`isEnabledForUser('deck')`, degrade gracefully when absent) is the template
  for Doriath eligibility.
- **`ICredentialsManager` system scope** — OR's Doriath private key uses the
  exact idiom `CredentialAppTokenService` already uses (`store('', key, value)`).
- **Doriath `EncryptService`/`DecryptService`** — invoked cross-app precisely
  BECAUSE they are stateless; no OR-side reimplementation of
  `rsa-oaep-sha256-chunked-v1` (drift risk).
- **Repair conventions** — `ImportCredentialBrokerRegister` (never-throw,
  info.xml install + post-migration) and `GenericInitializeSettings`
  (degrade-don't-throw when a peer app is absent) are the templates for the
  self-registration step and the D-G hook respectively.
- **NOT reused:** Doriath's machine HTTP API (`ApplicationSecretsController`) —
  same-instance OR calls services in-process; HTTP-looping to localhost would
  add auth surface for nothing. Doriath's `SecretMapper` is explicitly OFF
  LIMITS (service seam only).

## Risks / Trade-offs

- [Cross-repo ordering] Doriath's `deleteByApplication` + application-scoped
  read may land after this change → the resolver's `method_exists` probe keeps
  OR on the vault leaf until the full seam exists; merge order is free.
- [Doriath disabled after adoption] Secrets already migrated to Doriath become
  unreachable while it is disabled (resolver falls back to the vault, which no
  longer holds them) → broker fails closed (static 403); re-enabling Doriath
  restores access. Documented as operator guidance; no plaintext is ever
  stranded outside a vault. (Fleet note: disabling openregister itself is
  already a known instance-wide hazard.)
- [Private-key loss] `ICredentialsManager` row lost ⇒ ciphertext undecryptable
  → fail closed per credential; the user re-enters the secret (put() re-encrypts
  against the current keypair). No rotation tooling in this change.
- [Auto-registration rotating app secrets] → `isRegistered` guard (D-G);
  rotation stays an explicit admin action.
- [actingUserId spoofing] → honored only sessionless + in-process (the
  controller never forwards it); trust boundary documented in D-K; tests pin
  "session wins" and "HTTP never forwards".
- [Migration races] Two concurrent reads may both migrate → `put()` upsert is
  by-name idempotent, vault `delete()` is idempotent; worst case is a redundant
  rotate audit event.

## Migration Plan

1. Land this change: resolver + Doriath leaf + repair step + D-G/D-K seams; all
   inert on instances without an eligible Doriath (vault leaf keeps serving).
2. Doriath lands `application-secret-delete` (deleteByApplication + the
   application-scoped read); the resolver's probe flips instances to the Doriath
   leaf on their next request.
3. Existing vault secrets migrate lazily on first session read (D-A); no bulk
   job. Rollback = revert the DI factory to the static vault alias — un-migrated
   secrets were never moved, and migrated ones require re-entry (documented).
