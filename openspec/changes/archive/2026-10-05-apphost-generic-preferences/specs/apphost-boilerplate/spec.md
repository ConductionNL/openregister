# Spec delta: AppHost Boilerplate — Generic Preferences Controller

## ADDED Requirements

### Requirement: Generic Preferences Controller

`AppHost\Controller\GenericPreferencesController` SHALL provide per-user key/value preference get/set endpoints behaviourally identical to the bespoke leaf `PreferencesController`, scoped to the calling leaf app id, so a leaf app aliasing its `Controller\PreferencesController` to it via `Bootstrap::register()` resolves to a real class and serves the `preferences#getPreference` / `preferences#setPreference` routes.

#### Scenario: Preferences round-trip per user

- **WHEN** an authenticated user calls `setPreference('support-dialog-seen', '1')` then `getPreference('support-dialog-seen')` on a leaf app that adopted AppHost
- **THEN** the value `'1'` is stored under the leaf app's `IConfig` user namespace with key `pref_support-dialog-seen` and returned as `{value: '1'}`
- **AND** a subsequent `setPreference('support-dialog-seen', '')` clears it and returns `{value: null}`

#### Scenario: User-scoped, no cross-user access

- **WHEN** an anonymous request hits `getPreference`/`setPreference`
- **THEN** it is rejected with HTTP 401, and the controller never reads a userId or object id from request input — the userId is always the active session user, so a user can only read/write their OWN preferences (no IDOR)

#### Scenario: Bootstrap full-options resolution

- **WHEN** `Bootstrap::register($context, $appId, $options)` is called with all standard options enabled and every aliased factory is resolved through a container
- **THEN** each factory produces a real generic instance — including `Controller\PreferencesController` → `GenericPreferencesController` — with no dangling reference to a non-existent class
