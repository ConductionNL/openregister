## 1. Value Objects

- [x] 1.1 Create `lib/Service/Anonymisation/ProbeResult.php` — `final readonly` class with `reachable` (bool), `latencyMs` (?int), `error` (?string from the fixed set `timeout`/`dns_error`/`http_4xx`/`http_5xx`/`connect_refused`/`exapp_not_installed`/`exapp_disabled`/`appapi_missing`/`not_configured`), `probedAt` (ISO-8601 string); add `jsonSerialize()`.
- [x] 1.2 Create `lib/Service/Anonymisation/BackendInfo.php` — `final readonly` class with `name` (method enum string), `available` (bool), `configured` (bool), `lastProbedAt` (?string), `latencyMs` (?int); `jsonSerialize()`.
- [x] 1.3 Create `lib/Service/Anonymisation/BackendState.php` — `final readonly` class with `entityRecognitionEnabled` (bool), `activeMethod` (string), `effectiveMethod` (string), `backends` (array<string,BackendInfo>); `jsonSerialize()`.
- [x] 1.4 Define the method enum (`regex`/`presidio`/`openanonymiser`/`llm`/`hybrid`) as validated constants in a single place (`BackendState::METHODS`) reused by service + controller.

## 2. AnonymisationBackendService (single source of truth)

- [x] 2.1 Create `lib/Service/Anonymisation/AnonymisationBackendService.php` with DI: `IAppManager`, `IAppConfig`, `ICacheFactory`, `IClientService`, `FileSettingsHandler`, `LoggerInterface`.
- [x] 2.2 Implement private `detectOpenAnonymiser(): ProbeResult` — via `IAppManager`, prefer full `openanonymiser` over `openanonymiser_light`; `appapi_missing` / `exapp_not_installed` / `exapp_disabled` mapping. No external HTTP.
- [x] 2.3 Implement `probePresidio()` via `IClientService` (mockable; produces latency + error-enum); controller test endpoints delegate here (task 4.4) for one code path.
- [x] 2.4 Implement `probe(string $method): ProbeResult` dispatcher (`regex`/`openanonymiser`/`presidio`; `llm` → `not_configured`; `hybrid` composes via logical AND).
- [x] 2.5 Implement probe caching: distributed `ICache` (`openregister_anon_probe`), TTL from `anonymisation.probe_cache_ttl` (default 60, clamp 10–600).
- [x] 2.6 Implement `getState(): BackendState` — resolve `'auto'` default and apply the `effectiveMethod` precedence rule.
- [x] 2.7 Implement `testConnection(string $method): ProbeResult` — always bypass cache, issue fresh probe, write result back.

## 3. First-run auto-select wiring

- [x] 3.1 Change `FileSettingsHandler::getFileSettingsOnly()` default `entityRecognitionMethod` from `hybrid` to `'auto'`.
- [x] 3.2 Ensure `updateFileSettingsOnly()` persists a concrete admin-chosen method literally; preserve legacy stored values.
- [x] 3.3 Add `anonymisation.probe_cache_ttl` as its own `IAppConfig` key with default 60 and clamp on read (in `AnonymisationBackendService::resolveTtl`).

## 4. Admin OCS endpoints

- [x] 4.1 Create `lib/Controller/AnonymisationBackendController.php` (admin-gated): `GET /api/admin/anonymisation/backend-state`; non-admin 403, unauthenticated 401.
- [x] 4.2 Add `POST /api/admin/anonymisation/test-connection` accepting `{method}` → serialised `ProbeResult`; admin-gated; cache-bypassing.
- [x] 4.3 Register both routes in `appinfo/routes.php`.
- [x] 4.4 Repurpose/deprecate `FileSettingsController::testOpenAnonymiserConnection()` — now resolves via AppAPI (no HTTP); route kept functional with a deprecation note.

## 5. Admin UI (Anonimiseren section)

- [x] 5.1 In `FileConfiguration.vue`, replace the static method default (`auto`) and wire a `loadBackendState()` to `GET backend-state`; add store actions `getAnonymisationBackendState` / `testAnonymisationBackend`.
- [x] 5.2 Show per-backend availability indicators (available/configured/latency) and a "recommended" badge on the detected OpenAnonymiser ExApp.
- [x] 5.3 Add per-backend "Test connection" buttons calling `POST test-connection`, updating indicators without page reload.
- [x] 5.4 Render an ExApp hint (appapi_missing / not-installed / disabled) and a deep link to `/settings/apps/discover/openanonymiser_light`.
- [x] 5.5 Add NL + EN strings for new admin-visible text (ADR-005) in `l10n/nl.js` + `l10n/nl.json`. ADR-007 sensitive flag: N/A — no new external credentials (OpenAnonymiser uses AppAPI; Presidio endpoint is a URL).
- [x] 5.6 WCAG AA: `aria-label` on test buttons, `role="status"` on the hint, CSS via design-system variables, keyboard-operable controls (standard NC components).

## 6. Tests & quality gates

- [x] 6.1 Unit tests for `AnonymisationBackendService`: precedence (disabled, regex, available pass-through, unreachable fall-through), hybrid degradation, `'auto'` first-run resolution, legacy stored-method preservation.
- [x] 6.2 Unit tests for ExApp detection: enabled, not-installed, disabled, appapi_missing, full-beats-light.
- [x] 6.3 Unit tests for probe caching: within-TTL reuse, expired refresh + write-back, test-connection bypass.
- [x] 6.4 Controller tests: admin 200, non-admin 403, no-session 403 (framework returns 401 pre-dispatch), invalid method 400, openanonymiser delegated (no HTTP).
- [ ] 6.5 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan, PHPUnit). BLOCKED in this dev container: dev-deps are intentionally not installed (`--no-dev`; installing them 500s the live app). `php -l` (lint stage) passes on all new files; the static-analysis + PHPUnit stages must run in CI / a dev-deps environment.

## 8. Redesign: internal/external source + AppAPI execution (post-review)

- [x] 8.1 Drop the user-facing `auto` option; keep `auto` only as an invisible "not-yet-configured" marker resolved to the concrete recommended method in the UI (persist on save).
- [x] 8.2 Add `openAnonymiserSource` ('internal' default | 'external') to `FileSettingsHandler` defaults + persistence; all readers default to `internal` for back-compat with pre-existing blobs.
- [x] 8.3 `AnonymisationBackendService::requestOpenAnonymiser()` — call the ExApp via AppAPI `PublicFunctions::exAppRequest` (signed), resolved lazily; `resolveActiveExAppId()` prefers full over light. Verified end-to-end (PERSON/LOCATION/BSN returned).
- [x] 8.4 Fix the execution path: `EntityRecognitionHandler::resolveMethod()` maps `auto`/unknown → `effectiveMethod` (no more `match` throw); `detectWithOpenAnonymiser()` routes internal→AppAPI, external→HTTP, with fallback.
- [x] 8.5 Admin UI: replace the OpenAnonymiser URL field with an internal/external **radio** (internal = built-in ExApp via AppAPI + test button + install hint; external = URL field + test). NL/EN strings added.
- [x] 8.6 Update `spec.md`: reframe `auto` as an invisible marker; add the internal/external source + AppAPI-execution requirement and scenarios.

## 7. Spec sync

- [x] 7.1 Add the auto-select-on-first-run requirement (and full-beats-light tie-break) to the change's `spec.md`.
- [ ] 7.2 Run `/opsx:verify` then sync the delta into `openspec/specs/anonymiser-backend-selection/` at archive time.
