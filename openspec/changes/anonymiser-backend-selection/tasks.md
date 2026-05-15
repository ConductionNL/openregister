## 1. Service & value object

- [ ] 1.1 Create `lib/Service/Anonymisation/BackendState.php` — value object with fields `entityRecognitionEnabled` (bool), `activeMethod` (enum string), `effectiveMethod` (enum string), `backends` (array of `BackendInfo` value objects with `name`, `available`, `configured`, `lastProbedAt`, `latencyMs`). Constructor + readonly properties (or `final` class with `public readonly`); `toArray()` method for OCS serialisation.
- [ ] 1.2 Create `lib/Service/Anonymisation/BackendInfo.php` — per-backend value object (same readonly pattern).
- [ ] 1.3 Create `lib/Service/Anonymisation/AnonymisationBackendService.php` with `getState(): BackendState`. Injects `FileSettingsHandler`, `BackendProbe`, `IAppManager`, `LoggerInterface`. Implements the precedence rule from design D3.
- [ ] 1.4 Unit-test the precedence rule for all four branches of D3 (recognition disabled; active=regex; active≠regex with backend available+configured; active≠regex with backend unavailable or unconfigured).
- [ ] 1.5 Unit-test `hybrid`-method handling: when any composed backend is unavailable, `hybrid` is unavailable and `effectiveMethod` falls back to `regex`.

## 2. Probe

- [ ] 2.1 Create `lib/Service/Anonymisation/BackendProbe.php` with `probe(string $method): ProbeResult`. `ProbeResult` is a value object with `reachable` (bool), `latencyMs` (int|null), `error` (string|null, one of the codes from design D6), `probedAt` (DateTimeInterface).
- [ ] 2.2 Implement the per-method probe behaviour per design D6 — HTTP for `presidio`/`llm`, AppAPI lookup for `openanonymiser`, trivial for `regex`, composite for `hybrid`.
- [ ] 2.3 Implement caching per design D7 — read/write via `IAppConfig` under `anonymisation.probe_cache.<method>`; respect `anonymisation.probe_cache_ttl` (10–600s, default 60s).
- [ ] 2.4 Implement cache-bypass path: `probe($method, bypassCache: true)` always issues a fresh probe.
- [ ] 2.5 Run probes in parallel inside `getState()` when populating cold cache (R1 mitigation). Use whichever async HTTP primitive is already in `vendor/` (likely Guzzle promises).
- [ ] 2.6 Unit-test cache TTL behaviour (cached result within TTL → no probe; cached result expired → fresh probe; bypass flag → fresh probe regardless of cache state).
- [ ] 2.7 Unit-test error mapping (timeout → `error: 'timeout'`; 4xx → `http_4xx`; DNS failure → `dns_error`; etc.).

## 3. Controller & OCS endpoints

- [ ] 3.1 Add `lib/Controller/AnonymisationAdminController.php` (or extend an existing admin controller — pick whichever is closer to the current OR convention; document the choice in the PR description).
- [ ] 3.2 Add `GET /api/admin/anonymisation/backend-state` route — admin-only, returns `BackendState->toArray()` as JSON with HTTP 200.
- [ ] 3.3 Add `POST /api/admin/anonymisation/test-connection` route — admin-only, accepts `{method}` in body, calls `BackendProbe::probe($method, bypassCache: true)`, returns `ProbeResult` as JSON.
- [ ] 3.4 Authorisation: require admin group via OR's existing `@AdminRequired` / `IGroupManager::isAdmin` pattern. Confirm by grep against an existing admin endpoint and replicate.
- [ ] 3.5 Integration test: as admin, `GET /backend-state` returns the expected shape; as non-admin, returns 403.
- [ ] 3.6 Integration test: as admin, `POST /test-connection` with `{method: "presidio"}` against a known-good local Presidio returns `reachable: true`; against a non-existent endpoint returns `reachable: false, error: "dns_error"` (or `connect_refused`).

## 4. Admin UI

- [ ] 4.1 Add the `Anonimiseren` section to the existing OR admin Vue SPA. Component path: `src/views/admin/AnonymisationBackend.vue` (or equivalent matching the existing admin component layout).
- [ ] 4.2 Render the method selector (radio group or `<select>` over the five methods) bound to the existing `entityRecognitionMethod` `IAppConfig` field via the existing settings update mechanism.
- [ ] 4.3 Render per-method configuration sub-section per design D4. For `presidio` / `llm`, a URL input bound to the relevant `*ApiEndpoint` field. For `openanonymiser`, a read-only ExApp-availability indicator with `/settings/apps/discover/{appid}` deep links. For `regex`, an explanatory paragraph. For `hybrid`, a summary listing composed backends with their own configuration links.
- [ ] 4.4 Render the live availability indicator per backend (status dot + label), driven by `BackendState.backends[].available`. Re-fetch state on configuration save.
- [ ] 4.5 Wire the per-backend **Test connection** button to `POST /api/admin/anonymisation/test-connection`. Display the result (reachable / unreachable + latency) inline and update the indicator without a full refetch.
- [ ] 4.6 NL + EN translations per ADR-005 — all admin-visible strings.
- [ ] 4.7 Accessibility: WCAG AA — focus order, contrast, keyboard operability of the selector + buttons.

## 5. Wiring

- [ ] 5.1 Update `lib/Settings/OpenRegisterAdmin.php` to register the new Anonimiseren section. Confirm it appears in the admin settings sidebar under OpenRegister.
- [ ] 5.2 DI registration in `lib/AppInfo/Application.php` (or wherever OR registers services) for `AnonymisationBackendService` and `BackendProbe`.
- [ ] 5.3 Add the new routes to `appinfo/routes.php` (or the OR-specific routing config) under the admin route group.

## 6. Browser verification

- [ ] 6.1 Reset the local env (`bash clean-env.sh` or `/clean-env`).
- [ ] 6.2 Using `browser-1` (Playwright MCP), navigate to Nextcloud admin settings → OpenRegister → Anonimiseren. Confirm the section renders with the five method options and the live indicators.
- [ ] 6.3 Pick `presidio`, enter an obviously-bad URL, press Test connection. Confirm the result is "unreachable" with a meaningful error code.
- [ ] 6.4 Pick `regex`, save. Reload. Confirm `entityRecognitionMethod` persists.
- [ ] 6.5 With `openanonymiser_light` ExApp uninstalled, pick `openanonymiser`. Confirm the indicator shows "not configured" / "exapp_not_installed" and the deep link to `/settings/apps/discover/openanonymiser_light` is present.
- [ ] 6.6 Capture screenshots (NL + EN) → `docs/screenshots/anonymisation-backend-*.png`.

## 7. Cross-app verification

- [ ] 7.1 Confirm the `GET /api/admin/anonymisation/backend-state` response shape matches the DocuDesk-side consumer expectation in `anonymiser-backend-warning` (DocuDesk PR #135). Coordinate with DocuDesk apply phase.
- [ ] 7.2 Run DocuDesk's `anonymiser-backend-warning` integration test (when it lands) against a local OR with this change applied; confirm the warning banner renders on regex-only / unreachable-backend conditions and is suppressed when a backend is `available + configured`.

## 8. Quality gates

- [ ] 8.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- [ ] 8.2 Fix any pre-existing PHPCS/PHPMD/PHPStan warnings encountered in touched files per CLAUDE.md.
- [ ] 8.3 Unit-test coverage for new code ≥ 75% (ADR-009).
- [ ] 8.4 No regressions in existing OR test suites (PHPUnit, eslint, stylelint).

## 9. Documentation

- [ ] 9.1 Add `docs/admin/anonymisation-backends.md` — describe the five methods, when to pick each, the discovery flow (AppAPI for ExApps; URL config for HTTP backends), and the "no backend" determination rule operators can rely on.
- [ ] 9.2 Reference the screenshots from 6.6.
- [ ] 9.3 Cross-link to DocuDesk's admin docs once `anonymiser-backend-warning` is documented there.

## 10. Follow-up hand-off

- [ ] 10.1 Open (or reference) the DocuDesk-side issue for `anonymiser-backend-warning` and add a `Depends on` link from this change's tracking issue (#1497).
- [ ] 10.2 Note in `docs/admin/anonymisation-backends.md` that multi-tier fallback ("if openanonymiser unavailable, try presidio") is intentionally out of scope for v1 and may follow under a separate change if a use case emerges.