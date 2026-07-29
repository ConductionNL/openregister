# Tasks: apphost-settings-plane

## 1. Contracts
- [x] 1.1 Define `IAppSettingsProvider` contract (app id, register JSON path, fragment dir) + envelope shapes — realised as the `GenericSettingsService` constructor contract (app id + `lib/Settings/{app}_register.json` + `register.d/` convention) with the ADR-050 flat-payload/`{message, error}` envelope; no separate interface needed since the service IS the published contract
- [x] 1.2 Harden `HandlesExceptionsTrait`: typed exception→status map (NotFound→404, Forbidden→403, Validation→400/422, Conflict→409, default→500 with generic body + logged detail), ADR-050 envelope — new `handleApiException()`; legacy `errorResponse()` contract untouched for its 5 existing consumers

## 2. Implementation
- [x] 2.1 `AppHost/Service/GenericSettingsService` (index/update/load(bool $force)) with explicit ADR-049 fail-mode — throws typed `FoundationUnavailableException` / `ConfigurationMissingException` (new `lib/AppHost/Exception/`), never null
- [x] 2.2 `AppHost/Controller/GenericSettingsController` base + route registration via AppHost Routes helper — `GenericSettingsControllerBase` (abstract index/update/load + legacy `create` alias) + `settings#update` PUT route appended to `Routes::standard()`
- [x] 2.3 `AppHost/Controller/GenericPreferencesController` (getPreference/setPreference, IConfig-backed) — pre-existing generic controller verified against the contract (session-user-scoped, `pref_` namespace, leak-safe errors) and covered with unit tests
- [x] 2.4 `AppHost/Service/RegisterConfigResolver` (seed: opencatalogi ResolvesRegisterConfiguration) — delegates to OR `RegisterResolverService`, empty config ⇒ `ConfigurationMissingException`, unavailable foundation ⇒ `FoundationUnavailableException` (no nullable-resolver fallback)
- [x] 2.5 Wire all four into AppHost Bootstrap (load-order-safe per the AppHost bootstrap incident) — registrations APPENDED after all pre-existing ones (order asserted in BootstrapTest), lazy closures only

## 3. Adoption proof
- [ ] 3.1 Migrate OR's own SettingsController canonical surface onto the generic service (kitchen-sink extras stay in their own controllers)
- [ ] 3.2 Adopt HandlesApiExceptionsTrait in OR controllers (wave 1: the 20 highest-traffic)
- [ ] 3.3 Reference consumer PR in one leaf app (petstore — scaffold origin) deleting its SettingsService/PreferencesController copies

## 4. Verification
- [x] 4.1 PHPUnit for all four consumables incl. fail-mode paths — `tests/Unit/AppHost/{GenericSettingsServiceTest,GenericSettingsControllerBaseTest,GenericPreferencesControllerTest,RegisterConfigResolverTest}.php` + `tests/Unit/Controller/HandlesExceptionsTraitTest.php`; 165 tests green in nextcloud:34 container
- [ ] 4.2 `composer check:strict` green
- [ ] 4.3 Playwright: settings surface e2e on the reference consumer
- [ ] 4.4 Update ADR-066 status Proposed→Accepted + ADR-022 abstraction table row
