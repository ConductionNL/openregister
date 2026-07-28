---
kind: code
depends_on: []
---

# Proposal: App-declared credential providers

## Why

`lib/Settings/credential-providers.json` is a **runtime-immutable** catalogue
(ADR-004 Rule 3): the host-lock, the auth-header scheme, and the `allowRules[]`
that bound what a brokered secret can ever do are read from disk and are not
writable through any API. That immutability is the security control, and it is
also the bottleneck — *"apps cannot self-serve new hosts"* is already listed as
a known consequence in ADR-004. Two concrete costs were paid while building the
hydra-console app:

1. **No `codeberg`/`forgejo` provider exists** (only `github`/`gitlab`). A
   Codeberg-hosted forge had to fall back to a `generic-bearer` `inject_only`
   credential — which means the raw token leaves OpenRegister into the calling
   app and there is *no* host-lock and *no* per-path allow-rule at all. The
   fallback is strictly weaker than the first-class provider we could not have.
2. **The `github` provider's `allowRules` permitted no issue-label write**, so
   the broker refused label-driven forge automation even with a valid PAT
   (ConductionNL/openregister#2165). It was only fixable because we own
   OpenRegister. An app author outside this repo had no route at all: their
   options were "open a PR against OpenRegister and wait for a release" or
   "keep custody of the secret yourself" — and the second is what actually
   happens, which is exactly what ADR-004 exists to prevent.

The immutability must survive; the bottleneck must not. An app should be able to
**declare** the credentials and the bounded egress it needs, and an
administrator — not the app — decides whether that declaration becomes usable.

## What Changes

- **A declaration file, shipped by the consuming app.** An app may ship
  `lib/Settings/credential-providers.json` in **its own** app directory, in the
  same shape as OpenRegister's catalogue. OpenRegister discovers declarations by
  walking `IAppManager::getInstalledApps()` + `getAppPath($appId)` — the same
  read-only, declarative-config pattern as `AppHost\Scheduling\ScheduleManifestLoader`
  (ADR-031: declare it, do not code it).
- **Declared identifiers are namespaced and app-scoped.** A declared provider is
  addressed as `<appId>:<localId>`. Base-catalogue identifiers contain no colon,
  so a declaration can **never shadow or override** a reviewed provider, and the
  base catalogue always wins on any collision. A credential minted against a
  declared provider is forced to `allowedApps == [<declaringApp>]` — it cannot
  be borrowed by a second app.
- **Two admission lanes, one boundary.**
  - **Narrowing lane (no approval needed).** A declaration carrying
    `extends: <base provider id>` may only *intersect* the base: identical host,
    and an `allowRules[]` subset of the base's rules. It grants nothing the
    reviewed catalogue does not already permit, so it is admitted on discovery.
    Anything that would widen host or rules is rejected outright, not escalated.
  - **Approval lane (explicit human step).** A declaration that names a host or
    a path the reviewed catalogue does not already permit — a Codeberg or
    self-hosted Forgejo base URL, GitHub issue-label rules the base lacks — is
    **`pending` and unusable** until an administrator approves it. The broker
    fails closed on a pending, rejected, or revoked declaration.
- **Approval is bound to a digest of the exact declaration.** The approval
  record stores a content digest; if the app later edits its declaration, the
  digest no longer matches, the approval is invalidated automatically, and the
  provider returns to `pending`. An app cannot ship a benign declaration, obtain
  approval, and then widen it in an update.
- **The approval record is an auditable object, not a config flag.** A
  `credentialProviderApproval` schema is added to the existing credential-broker
  register, so every approve / reject / revoke is carried by OpenRegister's
  immutable hash-chained audit trail (ADR-003) and records **who** decided,
  **when**, and **what digest** they saw.
- **`inject_only` declarations are rejected.** An app-declared provider MUST be
  a host-locked proxy declaration (`baseUrl` + `allowRules` required). The
  reviewed `generic-apikey`/`generic-bearer`/`generic-basic`/`generic-oauth2`/
  `generic-jwt` entries already cover the app-injected case and are unchanged;
  a *declared* `inject_only` entry would add a secret-egress path with no bound
  at all, which is the one thing this change must not create. The existing
  `inject_only` vs proxied distinction, and `resolveInjectable()`'s refusal to
  serve proxy credentials, are preserved exactly.
- **Lifecycle is fail-closed.** Disabling or uninstalling the declaring app
  makes its declared providers unresolvable and any credential minted against
  them denied — never silently proxied.
- **Admin surface.** OpenRegister's credential settings gain a "Declared
  providers" view: pending declarations with the full host + rule set rendered
  for review, and approve / reject / revoke actions. `GET /api/credentials/providers`
  keeps exposing only `identifier` + `title` (never allow-rules), plus the
  declaration's `origin` and `status` so a picker can hide unusable providers.

Not breaking: every existing catalogue provider, every existing credential, and
both broker paths behave identically. The change is additive.

## Capabilities

### New Capabilities
- `app-declared-credential-providers`: how an app declares a credential provider,
  how OpenRegister discovers and validates that declaration, the narrowing lane
  vs the admin-approval lane, digest-bound approval and automatic invalidation,
  app-scoping of declared providers and their credentials, and the fail-closed
  lifecycle on app disable/uninstall.

### Modified Capabilities
- `credential-broker`: provider resolution is no longer "the shipped catalogue
  only" — it becomes "the shipped catalogue, then an *admitted* declaration",
  with the base catalogue winning every collision and every non-admitted
  declaration denying. Credential mint validation accepts a namespaced declared
  identifier and forces `allowedApps` for it. ADR-004 Rule 3 is amended: the
  shipped catalogue stays runtime-immutable, and the only runtime addition is an
  administrator-approved, digest-pinned, app-scoped declaration.

## Impact

- **Code**: `lib/Service/Credential/ProviderCatalogue.php` (base loader, unchanged
  semantics) gains a sibling declaration loader and a resolver that layers the
  two; `CredentialBrokerService::resolveProvider()` consults the resolver;
  `assertRuleAllowed()`, `resolveAndLockUrl()`, `injectAuth()`, `isInjectOnly()`
  and both guard chains are untouched — a declared provider produces the same
  entry shape, so it is guarded by exactly the same four guards.
  `CredentialController::create()`/`providers()` gain declared-provider handling.
- **Config/registers**: `lib/Settings/credential_broker_register.json` gains the
  `credentialProviderApproval` schema; the shipped
  `lib/Settings/credential-providers.json` is unchanged by this proposal.
- **ADRs**: ADR-004 (credential-broker custody) Rule 3 amended; ADR-003 (audit
  trail) consumed as-is; ADR-031 (declarative over imperative) is the reason the
  declaration is a shipped JSON file rather than a registration API call;
  ADR-001 seed data applies to the new schema.
- **Dependent apps**: additive for opencatalogi/softwarecatalog/openconnector —
  no existing provider or credential changes. hydra-console is the first
  consumer (a `codeberg` declaration replacing its `generic-bearer` fallback).
- **Security review**: this change touches the credential custody boundary and
  MUST carry tests for every deny path (unapproved, digest-drift, widening
  attempt, cross-app borrow, shadowing attempt, disabled declaring app).
