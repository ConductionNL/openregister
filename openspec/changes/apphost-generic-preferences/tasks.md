# Tasks: AppHost — ship the missing GenericPreferencesController

## 1. Build the generic controller

- [x] 1.1 Add `lib/AppHost/Controller/GenericPreferencesController.php` with constructor `(string $appName, IRequest $request, IConfig $config, IUserSession $userSession)` matching the `Bootstrap` alias closure.
- [x] 1.2 Implement `getPreference($key)` and `setPreference($key, $value='')` — `#[NoAdminRequired]`, user-scoped, `pref_` namespace, key sanitised to `[a-z0-9-]{0,64}`, leaf-appId-scoped; anonymous → 401, invalid key → 400.

## 2. Prove the engine resolves completely

- [x] 2.1 Extend `BootstrapFactoryChainTest` with `testAllFactoriesResolveToRealClassesWithFullOptions` — register with all options enabled, resolve every aliased factory, assert each is a real generic.
- [x] 2.2 Add `testPreferencesControllerRoundTripsPerUserValue` — write → read → clear round-trip, leaf-namespace scoping, anonymous rejection.

## 3. Document

- [x] 3.1 Add the complete generic-class inventory to `docs/Technical/building-an-app-on-apphost.md`.
