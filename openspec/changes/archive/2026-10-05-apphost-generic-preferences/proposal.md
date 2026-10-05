---
kind: code
---

# Proposal: AppHost — ship the missing GenericPreferencesController

## Problem

The AppHost boilerplate engine (`apphost-boilerplate-controllers`, merged via PR #161) shipped `Bootstrap.php` + `Routes.php` plus the generic controllers, services, repair steps, settings and listener — but **`GenericPreferencesController` was never added to `lib/AppHost/Controller/`**. `Bootstrap::register()` still aliases the leaf's `Controller\PreferencesController` to it (constant `GENERIC_PREFERENCES_CONTROLLER`), and `Routes::standard()` still emits the `preferences#getPreference` / `preferences#setPreference` routes.

Result: a leaf app that fully adopts AppHost — deletes its bespoke `PreferencesController` and relies on the alias — would 500 on the first `/api/preferences/{key}` request (class not found), because the aliased target does not exist. The shipped `BootstrapFactoryChainTest::testPreferencesFactoryResolves` even references the class, so the engine's own test suite was red against a missing symbol. The engine is therefore incomplete: it cannot be fully adopted for preferences.

## Proposed Change

1. Add `lib/AppHost/Controller/GenericPreferencesController.php` — a parameterised, per-user preferences controller behaviourally identical to the ~15 bespoke leaf copies (pipelinq, decidesk, procest, opencatalogi, …): `getPreference($key)` and `setPreference($key, $value='')`, `#[NoAdminRequired]`, user-scoped via `IUserSession`, keys sanitised to `[a-z0-9-]{0,64}` and stored under the `pref_` namespace, scoped to the leaf appId injected by the alias closure.
2. Extend `BootstrapFactoryChainTest` with a full-options resolve test asserting **every** aliased factory produces a real generic instance (no dangling reference), plus a behavioural round-trip test for the preferences controller (write → read → clear, leaf-namespace scoping, anonymous rejection).
3. Update `docs/Technical/building-an-app-on-apphost.md` with the complete generic-class inventory.

### Scope

**In scope**: the one missing generic controller, tests, docs.
**Out of scope**: any change to `Bootstrap`/`Routes` (they were already correct), leaf-app adoption.

## Impact

- **New files**: `lib/AppHost/Controller/GenericPreferencesController.php`.
- **Modified**: `tests/Unit/AppHost/BootstrapFactoryChainTest.php`, `docs/Technical/building-an-app-on-apphost.md`.
- **Backward-compatible/additive**: completes existing wiring; no public OR signature changes; apps that don't call `Bootstrap::register` are unaffected.
- **Security (ADR-005)**: the controller is `#[NoAdminRequired]` but strictly user-scoped — userId always from the session, no userId/object-id input, so no IDOR surface.

## Dependencies

- `apphost-boilerplate-controllers` (the engine whose `Bootstrap`/`Routes` already reference this controller).
- ADR-040 (hydra) records the AppHost decision.
