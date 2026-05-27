## Why

OpenRegister already supports five entity-recognition methods in `EntityRecognitionHandler` (`regex`, `presidio`, `openanonymiser`, `llm`, `hybrid`) and stores the selected method plus per-backend endpoint URLs inside the `fileManagement` `IAppConfig` blob (`entityRecognitionMethod`, `presidioApiEndpoint`, `openAnonymiserApiEndpoint`, etc.). What it does **not** have:

1. A first-class **state-query API** that consumer apps can read to answer "is a non-regex backend actually configured *and* available right now?". DocuDesk's [`anonymiser-backend-warning` change](https://github.com/ConductionNL/docudesk/pull/135) names exactly this query as a Hard cross-app dependency — the admin warning banner cannot render without it.
2. A **consolidated admin selector UI** for picking the method, configuring per-backend endpoints, observing live availability for ExApp backends (via AppAPI), and probing reachability with a test-connection button. Today these knobs are scattered across the `fileManagement` blob and there is no live availability signal.

Without (1), every consumer would have to read the raw `fileManagement` blob and reimplement the "is this backend actually usable?" logic locally — a duplication that will drift. Without (2), admins have no in-app discovery path: they have to find out from logs or end-user complaints that their selected backend isn't reachable.

This change introduces the typed state-query API, the admin selector UI, and the canonical "no backend" determination rule. It is **specs only** — implementation lands in a follow-up PR.

## What Changes

- **NEW** capability `anonymiser-backend-selection`.
- **NEW** typed service `OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService` (or equivalent location) exposing `getState(): BackendState`. `BackendState` is a value object with `activeMethod`, `entityRecognitionEnabled`, `effectiveMethod` (resolved per the "no backend" rule), and a per-backend list of `{name, available, configured}` flags.
- **NEW** admin-only OCS endpoint `GET /apps/openregister/api/admin/anonymisation/backend-state` returning the same `BackendState` as JSON. Single source of truth: the endpoint calls into the PHP service; no parallel implementation.
- **NEW** admin settings panel (`Anonimiseren` section under the existing `OpenRegisterAdmin` settings page) where admins:
  - pick the active method from the five supported values;
  - configure per-backend endpoint URLs where applicable (`presidioApiEndpoint`, `openAnonymiserApiEndpoint`, future LLM endpoint);
  - see live availability indicators per backend (AppAPI-derived for the two OpenAnonymiser ExApps; reachability-probe-derived for HTTP endpoints);
  - press a per-backend **Test connection** button that issues a synthetic probe and reports latency + reachability.
- **NEW** canonical "no backend" determination rule. `effectiveMethod` resolves to `regex` when (a) `entityRecognitionEnabled` is `false`, OR (b) the active method ≠ `regex` AND that backend's `{available AND configured}` is `false`. The rule lives in the service, never in callers.
- **NEW** authorisation contract: the state-query OCS endpoint is admin-only (requires the admin group). Non-admin callers receive 403 matching OR's existing admin-endpoint convention.
- **UNCHANGED** storage shape. The `fileManagement` `IAppConfig` blob continues to hold persisted state. This change reads it through the new service and adds no new persisted fields; live availability + probe results are computed on read, not persisted.
- **UNCHANGED** dispatch in `EntityRecognitionHandler`. This change adds a layer **above** the existing dispatch; it does not replace or modify per-method execution.

### Out of scope

- A backend abstraction (`BackendInterface` + per-method handlers). Could come later; not needed for the state query + UI.
- Multi-tier fallback policy ("if `openanonymiser` is unavailable, use `presidio`"). Deferred — current effective-method rule is binary: "active OR regex".
- Migrating the `fileManagement` `IAppConfig` blob into normalised storage. Implementation-internal; may evolve under a separate change.
- Audit-trail entries for backend-selection changes. Not needed for v1 — admin settings audit is a cross-cutting OR concern, not specific to this change.

## Capabilities

### New Capabilities

- `anonymiser-backend-selection`: typed state-query API + admin selector UI + canonical "no backend" determination rule.

### Modified Capabilities

(none — `entity-recognition` and `text-extraction` capabilities are not yet covered by OpenSpec, so this change introduces the first capability over the anonymisation surface without modifying any existing one.)

## Cross-app Dependencies

- **Soft** — `docudesk:anonymiser-backend-warning` — consumer. DocuDesk's admin warning banner queries the new state-query OCS endpoint to determine whether to render the "no backend configured" warning. Either change can land first: until OR lands, DocuDesk's banner falls through to "always show / never show" per its own default; until DocuDesk lands, OR's endpoint has one fewer consumer.

Track as a `Depends on` link between this change's GitHub issue (#1497) and DocuDesk PR #135's tracking issue once it exists.

## Impact

- **Code (openregister):**
  - NEW `lib/Service/Anonymisation/AnonymisationBackendService.php` — typed service implementing `getState()`, the precedence rule, and probe orchestration.
  - NEW `lib/Service/Anonymisation/BackendState.php` — value object.
  - NEW `lib/Service/Anonymisation/BackendProbe.php` (or similar) — per-method reachability probe; emits `{reachable, latencyMs, error?}`. Caches results per-endpoint for 60s (configurable via `anonymisation.probe_cache_ttl`).
  - NEW controller method (extend an existing admin controller or add `lib/Controller/AnonymisationAdminController.php`) handling `GET /api/admin/anonymisation/backend-state` plus `POST /api/admin/anonymisation/test-connection`.
  - NEW Vue admin panel — Anonimiseren section in the OR admin settings (`src/views/admin/AnonymisationBackend.vue` or equivalent), wired into the existing admin Vue router.
  - `lib/Settings/OpenRegisterAdmin.php` — register the new admin panel section.
- **API contract:** two new admin-only OCS endpoints (`backend-state` GET; `test-connection` POST). No changes to existing endpoints.
- **Storage:** reads existing `fileManagement` `IAppConfig` keys via `FileSettingsHandler`. No new persisted state. Probe-result cache lives in `IAppConfig` under a dedicated key (`anonymisation.probe_cache`) with TTL eviction; treated as transient state.
- **AppAPI integration:** the service checks for AppAPI presence via Nextcloud's `IAppManager` / OCP container; falls back to "not detectable" when AppAPI is not installed (with a clear admin-side hint).
- **Cross-app:** unblocks DocuDesk's `anonymiser-backend-warning`. No effect on other apps.
- **Tests:**
  - Unit: precedence rules of `effectiveMethod`; probe caching; AppAPI feature-detection fallback.
  - Integration: OCS endpoint returns the expected shape; admin-only authorisation; error responses match the OR convention.
  - Browser (Playwright MCP via `browser-1`): admin can pick a method, configure an endpoint, press Test connection, see the live availability indicator update.
- **Migration:** None. Existing `fileManagement` values continue to be read by `EntityRecognitionHandler`; the new service reads the same keys.
- **Documentation:** add `docs/admin/anonymisation-backends.md` describing the five methods, the discovery flow, and the AppAPI dependency for ExApp methods.