# Proposal: AppHost Settings Plane — Generic Settings, Preferences, and Exception-Translation Consumables

## Why

ADR-066 (fleet drift sweep 2026-07-26): the app scaffold is copy-forked
across the fleet — PreferencesController ×11 apps, ActionAuthService ×4
(~250 ln each), SettingsService clones ×10+ (two grew into 3.3k/7.4k-line
monoliths), 3 AdminSettings styles, 4 settings-endpoint dialects, the
nullable `getObjectService()` fail-mode hazard ×6, and register-import drift
(repair-step vs lazy vs absent). Separately, OR's own
`HandlesExceptionsTrait` is adopted by 5 of 120+ controllers while ~2,700
hand-written catch blocks fleet-wide echo `getMessage()` into response
bodies (ADR-050/051). AppHost already proves the model
(`GenericAdminSettings`, `GenericActionAuthService` — consumed by scholiq in
40 lines vs petstore's 246-line copy).

## What Changes

OpenRegister's AppHost layer (`lib/AppHost/`) gains four consumables with
published contracts:

1. **GenericSettingsService + GenericSettingsController base** — canonical
   `index/update/load(force)` settings surface bound to an app's
   `lib/Settings/{app}_register.json` (+ `register.d/`), ADR-050 envelope,
   explicit ADR-049 fail-mode (foundation-missing ⇒ explicit 503-style
   error, never silent null), no seed/demo methods.
2. **GenericPreferencesController** — per-user key/value preferences
   (getPreference/setPreference), replacing the 11 app copies.
3. **RegisterConfigResolver** — register/schema slug→id resolution contract
   seeded from opencatalogi's `ResolvesRegisterConfiguration` trait
   (empty-config = explicit error).
4. **HandlesApiExceptionsTrait (promoted)** — OR's existing
   `Controller/Trait/HandlesExceptionsTrait` hardened and published as the
   AppHost exception→HTTP translation consumable (typed catch → status map,
   message-leak-safe bodies, one envelope), with OR's own 120+ controllers
   as the first migration wave.

Non-goals here: fleet-wide consumption in leaf apps (each app migrates via
its own change referencing ADR-066), EgressGuard (separate change per
ADR-067).

## Impact

- Affected specs: new `apphost-settings-plane` capability spec; touches
  AppHost bootstrap/routes registration.
- Affected code: `lib/AppHost/Service/`, `lib/AppHost/Controller/`,
  `lib/Controller/Trait/HandlesExceptionsTrait.php`, AppHost docs.
- Consumers (later waves): all 20+ leaf apps; scholiq/planix are the
  reference pattern already.
- Risk: contracts become public API — semver + deprecation discipline
  required from day one.
