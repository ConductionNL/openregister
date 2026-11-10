## Why

OpenRegister can recognise PII entities via several backends (regex, Presidio, the OpenAnonymiser ExApp, an LLM, or a hybrid), but there is no single source of truth for *which* backend is active, whether it is actually reachable, and no admin UI to choose between them. Today the method is a static default (`hybrid` in the backend handler, `regex` in the frontend) and OpenAnonymiser's HTTP-style config offers no way to wire to the installed ExApp. ADR-017 requires the foundation app (OpenRegister) to own backend selection and endpoint resolution; consumer apps (DocuDesk's admin warning banner) need to read this state rather than reimplement the precedence rule locally.

## What Changes

- Add a typed `BackendState` value object encoding `entityRecognitionEnabled`, `activeMethod`, the computed `effectiveMethod`, and per-backend `available` / `configured` / probe metadata.
- Add `AnonymisationBackendService::getState()` as the **single source of truth** that applies the `effectiveMethod` precedence rule (disabled or unusable backend falls through to `regex`).
- Derive `openanonymiser` availability from AppAPI / `IAppManager` (`openanonymiser_light` OR `openanonymiser`, installed + enabled), with a documented `appapi_missing` fallback — selecting the method routes to the ExApp via AppAPI, no manual endpoint.
- Add admin-only OCS endpoints: `GET /api/admin/anonymisation/backend-state` and `POST /api/admin/anonymisation/test-connection` (per-backend probe, cache-bypassing).
- Cache probe results with a 60s default TTL (`anonymisation.probe_cache_ttl`, range 10–600s).
- Add an "Anonimiseren" admin settings panel: method selector, per-backend endpoint inputs (Presidio/LLM), live availability indicators, AppAPI-derived ExApp status, Test-connection buttons, and a deep link to install a missing OpenAnonymiser ExApp. NL + EN strings (ADR-005), WCAG AA.
- **Open decision for design.md:** ADR-017 calls for the detected ExApp to be *auto-selected* ("recommended" backend when a companion is present). The current spec preserves operator intent (an installed ExApp is reported `available` but does not change `activeMethod`). design.md must reconcile these — likely an explicit "recommended / auto-select on first run" default that operator choice then overrides.

## Capabilities

### New Capabilities
- `anonymiser-backend-selection`: Typed state-query API, `effectiveMethod` precedence rule, per-backend availability probes (HTTP for Presidio/LLM, AppAPI for OpenAnonymiser), admin OCS endpoints, and the admin selector UI. Consumed in-process and by DocuDesk's warning banner.

### Modified Capabilities
<!-- None. `text-extraction` / entity recognition handlers are touched at implementation level only; their spec-level requirements do not change. -->

## Impact

- **Backend:** new `AnonymisationBackendService` + `BackendState` / `BackendInfo` / `ProbeResult` value objects (`lib/Service/...`); new admin OCS controller methods (alongside existing `FileSettingsController::testOpenAnonymiserConnection`); `FileSettingsHandler` default-method resolution; `EntityRecognitionHandler::detectEntitiesHybrid` referenced for hybrid availability composition.
- **Frontend:** `src/views/settings/sections/FileConfiguration.vue` (or a new Anonimiseren section) — method selector wired to the new endpoints, availability indicators, test-connection.
- **Config:** `IAppConfig` keys — `entityRecognitionMethod`, `entityRecognitionEnabled`, `presidioApiEndpoint`, `anonymisation.probe_cache_ttl`; sensitive flag for any external API keys (ADR-007).
- **Dependencies:** AppAPI (optional, runtime-detected); OpenAnonymiser ExApp tracked in its own repo (ADR-017 responsibility split).
- **Consumers:** DocuDesk admin warning banner reads `GET /api/admin/anonymisation/backend-state` instead of local precedence logic.
- **Routes:** `appinfo/routes.php` — two new admin OCS routes.
