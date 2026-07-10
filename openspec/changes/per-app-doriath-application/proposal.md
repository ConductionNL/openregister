---
kind: code
depends_on:
  - credential-doriath-leaf
---

## Why

Today every brokered credential in the fleet rides under OpenRegister's single
self-registered Doriath application (`credential-doriath-leaf` D-B/D-C:
`RegisterOpenRegisterWithDoriath` registers ONE `openregister` Doriath
`Application`, and `DoriathCredentialStore` stores every app's brokered secret
as an application-owned secret under THAT one vault). The consequence is visible
in Doriath's "My applications" list: an operator sees only `openregister`, never
the consuming apps (openbuild/manifest apps such as `openbuild-spectr`,
`pipelinq`, …) that actually use the broker. Each consuming app should register
its **own** Doriath `Application` — manifest-driven, no code, no admin step —
so it appears with its own identity and approval state, exactly as a first-party
integration should.

This change adds **per-app Doriath `Application` registration** and is
deliberately scoped **identity-only**: each app gets its own Doriath
`Application` record (name, description, approval state), but brokered SECRET
custody is left untouched under OpenRegister's existing application vault
(`credential-doriath-leaf` D-C/D-F). Moving custody to per-app vaults is a
heavier, reversible-only-with-migration follow-up (see Design, Scope fork).

## What Changes

- **Per-app Doriath `Application` registration (identity-only).** When an
  AppHost leaf initialises (`GenericInitializeSettings::run()`) or a virtual-app
  manifest declaring `credentials[]` is registered, OpenRegister — in addition
  to the existing `CredentialAppTokenService::registerApp($appId)` app-key
  onboarding (`credential-doriath-leaf` D-G) — registers a Doriath
  `Application` **named after the consuming appId** (e.g. `openbuild-spectr`),
  description drawn from the manifest, reusing Doriath's
  `ApplicationService::register` exactly the way `RegisterOpenRegisterWithDoriath`
  registers OR's own application.
- **Idempotent.** Registration fires at most once per app: a persisted
  Doriath application UUID (in `IAppConfig`, per-app key) plus a live Doriath row
  make the step a no-op, mirroring `RegisterOpenRegisterWithDoriath`'s
  skip-fast/`isRegistrationLive` guard. It never re-registers and never rotates.
- **Approval state.** A per-app registration is created **pending** (Doriath's
  non-admin registration path → admin must approve), matching Doriath's existing
  security model; first-party auto-approve is the documented alternative (see
  Design, Approval fork). Provisional decision — RAISED for the user.
- **Degrade, never throw.** When Doriath is absent/disabled/ineligible the hook
  warns and completes — the broker keeps operating unchanged, exactly like the
  existing D-G and `RegisterOpenRegisterWithDoriath` posture.
- **No custody move.** `DoriathCredentialStore` and OR's `openregister`
  application vault are untouched: brokered secrets still live under OR's single
  application (`credential-doriath-leaf` D-C/D-F). This change adds identity, not
  custody.

## Capabilities

### New Capabilities

<!-- none — this evolves the existing credential-broker capability -->

### Modified Capabilities

- `credential-broker`: ADDS a "Per-app Doriath application registration"
  requirement (each consuming app registers its own Doriath `Application`,
  manifest-driven, idempotent, pending-by-default). The four ordered broker
  guards, the credential metadata schema, the provider catalogue, secret
  custody (still OR's single application vault), and the HTTP contract are all
  unchanged. NOTE (as in `credential-doriath-leaf`): the base `credential-broker`
  spec still lives in its active head change
  (`openspec/changes/credential-broker/specs/credential-broker/spec.md`);
  `openspec/specs/credential-broker/` does not exist yet, and the
  self-registration + D-G onboarding requirements this change builds on live in
  the `credential-doriath-leaf` delta.

## Impact

- **OpenRegister (this repo):**
  - `lib/AppHost/Repair/GenericInitializeSettings.php` — extend the existing
    `registerCredentialConsumer()` D-G hook to also register a per-app Doriath
    `Application` (idempotent), alongside the current app-key `registerApp`.
  - A per-app registration service/seam reusing the keypair-less
    `ApplicationService::register` name/description path (identity-only needs no
    CSR/EncryptionSuite — see Design), and a per-app `IAppConfig` UUID key
    (namespaced by appId, distinct from OR's own `doriath_application_id`).
  - Cross-app resolution of Doriath's `ApplicationService` reuses the
    `class_exists` + `OCP\Server::get` idiom already in
    `RegisterOpenRegisterWithDoriath` (no compile-time dependency on Doriath).
- **openbuild (possible, RAISED):** virtual apps do not run an AppHost leaf init
  and `ApplicationsController::getManifest` has no onboarding hook today; whether
  the per-app registration trigger for virtual apps lives in openbuild's
  manifest-serve path or in OR's manifest-driven-onboarding path is an open
  question (Design, Trigger fork).
- **Doriath:** no code change — reuses `ApplicationService::register` (verified
  contract: non-admin/anonymous callers create a `pending` row; admin callers
  auto-approve). No CSR is supplied on the identity-only path, so no
  EncryptionSuite is provisioned.
- **No OR schema/seed changes**; no new HTTP endpoints; no declarative surface
  (ADR-031 — registration plumbing is imperative, see Design).
- **Cross-repo dependency:** none new beyond `credential-doriath-leaf` (which
  owns `RegisterOpenRegisterWithDoriath` + the D-G hook this extends).
