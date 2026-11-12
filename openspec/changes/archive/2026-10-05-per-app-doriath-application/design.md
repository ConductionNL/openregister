## Context

`credential-doriath-leaf` established the fleet's Doriath custody model
(verified against the OR worktree + the live Doriath container
`/var/www/html/custom_apps/doriath`):

- **OR self-registers ONE Doriath `Application`.**
  `lib/Repair/RegisterOpenRegisterWithDoriath.php` (D-B) generates an RSA-4096
  keypair, self-generates a PKCS#10 CSR, and calls Doriath's
  `ApplicationService::register('openregister', …, type: 'internal', csr,
  userId: null, isAdmin: true)` in-process. Admin registration auto-approves the
  row (`status = active`) and provisions an EncryptionSuite from the CSR. Doriath
  assigns the row UUID; OR persists it + its public key PEM in `IAppConfig`
  (`DoriathCredentialStore::APP_CONFIG_APPLICATION_ID = 'doriath_application_id'`,
  `APP_CONFIG_PUBLIC_KEY_PEM = 'doriath_public_key_pem'`).
- **All brokered secrets live under THAT one application** (D-C/D-F):
  `DoriathCredentialStore` stores each credential as an application-owned Doriath
  secret (name = credential UUID) under OR's single `openregister` application.
- **Consuming apps get an OR HMAC signing key, not a Doriath identity.**
  `CredentialAppTokenService::registerApp($appId)` (idempotent behind
  `isRegistered($appId)`) is auto-called by the D-G hook
  `GenericInitializeSettings::registerCredentialConsumer()` when a leaf's
  `src/manifest.json` declares a non-empty `credentials[]`. This is an OR
  app-key, NOT a Doriath `Application`.
- **openbuild virtual apps do not onboard at all** — they run no AppHost leaf
  init, and `ApplicationsController::getManifest` has no credentials onboarding
  hook.

Verified Doriath contract (`ApplicationService::register`, live container):
`register(string $name, ?string $description, string $type, ?string $csr,
?string $userId, bool $isAdmin): Application`. **Admin callers auto-approve
(`status = active`); non-admin/anonymous callers create a `pending` row that an
admin must approve** (`app_pending` notification dispatched to the NC admin
group). A CSR is validated (PKCS#10, ≥4096-bit) and drives EncryptionSuite
provisioning **only when supplied**; with `csr = null` the row is created with
no suite. Doriath generates the row UUID (`Uuid::uuid4()`) — the caller supplies
a *name*, not an id.

The user's ask: in Doriath's "My applications" list they see only
`openregister`; each consuming/manifest/openbuild app should register its OWN
Doriath `Application`.

## Goals / Non-Goals

**Goals:**

- Each consuming app that onboards to the credential broker registers its OWN
  Doriath `Application` (its own identity + approval state in the Applications
  list), manifest-driven, no admin step to trigger it.
- Reuse Doriath's `ApplicationService::register` exactly as
  `RegisterOpenRegisterWithDoriath` does (same cross-app `class_exists` +
  `OCP\Server::get` resolution, same never-throw/degrade posture).
- Idempotent: register at most once per app; never re-register, never rotate.
- Fully backward-compatible: OR's own `openregister` Doriath application and all
  existing brokered secrets under it are untouched; apps without `credentials[]`
  are unaffected; instances without an eligible Doriath behave byte-for-byte as
  today.

**Non-Goals:**

- **No custody move** (see D-1 scope fork): brokered secrets stay under OR's
  single application vault; per-app secret vaults are a follow-up.
- No new HTTP endpoints, no HTTP-contract change, no broker guard changes.
- No CSR / EncryptionSuite for per-app applications on the identity-only path
  (custody stays with OR's suite; a per-app identity that never holds ciphertext
  needs no suite).
- No OR schema or seed-data changes; no new declarative surface.
- No Doriath-side code change (reuses the existing `register` contract).

## Decisions

### D-1 — Scope: identity-only (PROVISIONAL — RAISED as deferred question 1)

Two options were weighed:

- **(a) IDENTITY-ONLY (chosen).** Each app registers its own Doriath
  `Application` record — it appears in "My applications" with its own name +
  approval state — but brokered SECRETS still live in OR's existing single
  application vault (`credential-doriath-leaf` D-C/D-F). Lowest risk, fully
  reversible (drop the per-app rows; custody never moved), and it satisfies the
  user's literal ask ("register their own applications"). No CSR, no
  EncryptionSuite, no secret migration.
- **(b) CUSTODY-MOVE (rejected for this change).** Brokered secrets actually
  move to per-app Doriath applications (each app's secrets stored under ITS
  application, encrypted to ITS suite). This reverses D-C/D-F, requires a CSR +
  EncryptionSuite per app, a secret-migration path, and per-app private-key
  custody in OR. Materially bigger and only reversible with a reverse migration.

**Decision: (a) identity-only** — smallest change that satisfies the ask;
custody-move is a heavier follow-up that should be its own change if the user
wants it. This is architecturally load-bearing — RAISED for the user to choose.

### D-2 — Registration flow: reuse `ApplicationService::register`, no CSR

The per-app registration mirrors `RegisterOpenRegisterWithDoriath::register()`
but WITHOUT the keypair/CSR steps (identity-only holds no ciphertext, so it needs
no EncryptionSuite):

1. Resolve Doriath's `ApplicationService` cross-app via `class_exists(
   'OCA\\Doriath\\Service\\ApplicationService')` + `OCP\Server::get` (the exact
   idiom already in `RegisterOpenRegisterWithDoriath::resolveApplicationService()`
   — no compile-time dependency on the optional app). Absent → warn, skip.
2. Read the consuming leaf's `src/manifest.json` (reusing the existing
   `manifestDeclaresCredentials()` read) for a display description.
3. Call `register(name: <appId>, description: <manifest description>, type:
   'internal', csr: null, userId: <initiating user or null>, isAdmin: <false —
   see D-4>)`.
4. Persist the Doriath-assigned application UUID in `IAppConfig` under a
   **per-app key** namespaced by appId (e.g. app `openregister`, key
   `doriath_application_id/<appId>`), kept DISTINCT from OR's own
   `doriath_application_id` (which identifies OR's custody vault). No secret
   material is stored — identity-only needs no private key.

This adds a small per-app registration seam (a method/service) invoked from the
D-G hook; it reuses the Doriath resolution and never-throw posture wholesale.

### D-3 — Idempotency guard (mirrors `isRegistrationLive`)

Register at most once per app. Before calling `register`, check the per-app
`IAppConfig` UUID: if set AND Doriath still has that row (probe
`ApplicationService::get($uuid, '', true)`, exactly as
`RegisterOpenRegisterWithDoriath::isRegistrationLive()` does), the hook is a
no-op. A stale/removed row (Doriath reinstalled) re-registers. This runs on
`<install>` + `<post-migration>`, so it must be safe on every `occ upgrade`.
Registration NEVER rotates or mutates an existing row — parallel to the D-G
`isRegistered()` guard that prevents `registerApp` from rotating an app's HMAC
secret.

### D-4 — Approval flow: pending by default (PROVISIONAL — RAISED as deferred question 2)

A per-app application is registered with `isAdmin: false`, so Doriath creates it
**`pending`** and dispatches `app_pending` to the NC admin group — an admin then
approves it (matching the "CI Pipeline Bot — Pending approval" security model in
Doriath's existing UI). Rationale: a consuming app auto-registering itself should
NOT silently become an active, trusted identity without a human gate.

- **Alternative (documented):** first-party auto-approve. First-party
  OpenBuild-published apps could register `isAdmin: true` (auto-`active`), the
  same way OR self-registers. That trades the human gate for zero-touch
  onboarding and would need a trust signal distinguishing first-party from
  arbitrary apps (e.g. a manifest publisher field). Left as the alternative.

Because identity-only per-app applications carry NO CSR, `pending` vs `active`
here only affects list visibility/approval state — neither state provisions an
EncryptionSuite or changes custody. Low blast radius either way. RAISED for the
user.

### D-5 — Onboarding trigger + openbuild ownership (PROVISIONAL — RAISED as deferred question 3)

**Decision: reuse the `credential-doriath-leaf` D-G hook.** The per-app Doriath
registration fires from the SAME place the app-key onboarding already fires —
`GenericInitializeSettings::registerCredentialConsumer()` (AppHost leaf init,
manifest declares `credentials[]`) — extended to ALSO register a Doriath
`Application` after `CredentialAppTokenService::registerApp`. Both are idempotent;
both degrade the same way. This keeps a single onboarding path and reuses the
manifest read.

- **openbuild fork (RAISED).** openbuild *virtual* apps do not run an AppHost
  leaf init, and `ApplicationsController::getManifest` has no onboarding hook
  today. For those, the trigger must live either (a) in openbuild's
  manifest-serve/publish path (openbuild owns it), or (b) in OR's
  manifest-driven-onboarding path (the requirement `credential-doriath-leaf`
  already names — OR registers when a virtual-app manifest declaring
  `credentials[]` is seen). **Provisional: OR owns it** (single onboarding
  authority; openbuild has no Doriath dependency). Whether openbuild should own
  the virtual-app trigger instead is RAISED for the user.

### D-6 — App identity/naming (PROVISIONAL — RAISED as deferred question 4)

The per-app Doriath `Application` **name = the consuming appId** (e.g.
`openbuild-spectr`, `pipelinq`); **description = the manifest's description**
(fallback to a generic "credential-broker consumer" string when absent).
`type: 'internal'` (same as OR's own registration — these are same-fleet apps).
Confirm the naming/type choice.

## Relationship to `credential-doriath-leaf` (D-C / D-F)

This change **ADDS per-app identity; it does NOT move custody.**

- D-C/D-F (custody under OR's single `openregister` application vault) stay
  exactly as specced and implemented. `DoriathCredentialStore` is not touched;
  no secret is re-keyed, re-encrypted, or moved.
- The D-G onboarding hook is EXTENDED (per-app Doriath `Application` registration
  added alongside the existing `CredentialAppTokenService::registerApp`), not
  replaced. The app-key path and the Doriath-identity path are independent and
  both idempotent.
- Whether custody should later move to per-app application vaults (option (b) in
  D-1, reversing D-C/D-F) is the explicit scope fork left to the user.

## ADR-031 (declarative-vs-imperative)

**N/A — imperative registration plumbing, no declarative surface.** Like the
credential broker/store (`credential-doriath-leaf`'s ADR-031 note), per-app
Doriath registration is cross-app service invocation + Repair-step onboarding —
exactly the "what apps SHOULD still write in PHP" category; no `x-openregister-*`
declarative extension expresses application registration. This change adds no new
declarative surface and removes none.

## Seed data

**None.** This change introduces no OR schema and no register/schema JSON —
therefore no seed objects (confirmed). Per-app application UUIDs live in
`IAppConfig`, not in OR objects.

## Risks / Trade-offs

- [Cross-repo / Doriath eligibility] Doriath absent, disabled, or its
  `ApplicationService` unloadable → the hook warns and skips (never throws), and
  the app still onboards its OR app-key as today. → Mirror
  `RegisterOpenRegisterWithDoriath`'s degrade-don't-throw posture; the resolver
  probe is `class_exists` + `Server::get`.
- [Duplicate applications] A stale per-app `IAppConfig` UUID whose Doriath row
  was removed would re-register on next `occ upgrade`, and Doriath does not
  enforce name uniqueness → at most one extra row per reinstall. → The
  `isRegistrationLive`-style probe (`get($uuid)`) collapses the common case to a
  no-op; a re-register only happens when the prior row genuinely no longer
  exists.
- [Pending backlog] Every consuming app landing `pending` creates an admin
  approval queue entry (D-4). → Acceptable under the chosen security model;
  first-party auto-approve is the documented alternative if the queue is a
  burden.
- [openbuild virtual apps unreached] With OR owning the trigger (D-5), a virtual
  app with no AppHost leaf init still needs OR's manifest-driven-onboarding path
  wired to fire per-app registration → covered only when that path exists;
  otherwise virtual apps remain unregistered (no regression vs today, where they
  do not onboard at all). RAISED (D-5).
- [Scope creep to custody-move] If the user actually wants custody per app (D-1
  option b), this change is the wrong shape → RAISED up front; identity-only is
  explicitly reversible and does not foreclose a later custody-move change.

## Migration Plan

1. Land this change: extend the D-G hook with idempotent per-app Doriath
   `Application` registration + per-app `IAppConfig` UUID key. Inert on instances
   without an eligible Doriath (warn-and-skip); OR's own vault + existing secrets
   untouched.
2. On the next `occ upgrade` / leaf init, each `credentials[]`-declaring app
   registers its Doriath `Application` once (pending). Admin approves in Doriath.
3. Rollback = drop the per-app registration call. Custody never moved, so nothing
   to reverse-migrate; leftover per-app application rows are inert identity
   records an admin can reject/delete in Doriath.

## Open Questions

1. **Scope** (D-1): identity-only (chosen) vs custody-move? The user should
   confirm identity-only is what they want, or request the heavier custody-move.
2. **Approval** (D-4): pending/admin-approved (chosen) vs first-party
   auto-approve?
3. **Trigger ownership** (D-5): should openbuild own the virtual-app trigger, or
   OR's manifest-driven-onboarding path?
4. **Naming/type** (D-6): confirm name = appId, description = manifest
   description, type `internal`.
5. **Kind** (proposal): single `code` spec delta to `credential-broker` (chosen,
   small + coupled) vs a chain — confirm.
