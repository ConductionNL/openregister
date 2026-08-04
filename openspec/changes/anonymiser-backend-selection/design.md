## Context

Entity recognition in OpenRegister currently has no authoritative runtime model. The active method lives inside a single `fileManagement` JSON blob in `IAppConfig` (`FileSettingsHandler::getFileSettingsOnly()` defaults it to `hybrid`; the Vue default is `regex`), and there is no way to know whether a chosen backend is actually reachable. OpenAnonymiser is currently treated as an HTTP service: `FileSettingsController::testOpenAnonymiserConnection()` does a `GET {endpoint}/api/v1/health`. That contradicts ADR-017, which says a companion ExApp MUST be detected via `IAppManager` and resolved through AppAPI service routing, not a hardcoded host.

Constraints:
- ADR-017 (external AI service packaging): OpenRegister is the **foundation app** that owns backend selection, endpoint resolution, and the HTTP/AppAPI call; DocuDesk owns only the warning banner. Detected healthy ExApp is the recommended/auto-selected backend.
- ADR-005: all admin strings in NL + EN. ADR-007: external API keys stored with the `IAppConfig` sensitive flag.
- ADR-011: reuse existing utilities — the existing `performHealthCheck()` helper in `FileSettingsController` is the basis for HTTP probes; do not reimplement.
- Settings persistence is a JSON blob today, not per-key config. The state service must read/write through that blob (or a dedicated key) without breaking the existing FileConfiguration save path.

Stakeholders: OpenRegister admins (selector UI), DocuDesk (state consumer), OpenAnonymiser ExApp repo (separate tasks per ADR-017).

## Goals / Non-Goals

**Goals:**
- One authoritative `AnonymisationBackendService::getState(): BackendState` that applies the `effectiveMethod` precedence rule — no parallel implementation in controllers or the frontend.
- AppAPI/`IAppManager`-based detection for `openanonymiser` (`openanonymiser_light` OR `openanonymiser`), replacing the HTTP health check for that method only.
- **Auto-select on first run** (per decision): when the admin has never explicitly chosen a method and a healthy OpenAnonymiser ExApp is detected, the recommended method resolves to `openanonymiser`; once an admin saves any concrete method, that choice always wins.
- Admin OCS endpoints for state query and per-backend test-connection, with a 60s probe cache.
- An "Anonimiseren" admin settings section (NL/EN, WCAG AA) with live availability indicators and test buttons.

**Non-Goals:**
- The DocuDesk warning banner itself (consumer side; separate change/app).
- The OpenAnonymiser ExApp implementation or its HTTP contract (separate repo, ADR-017).
- Changing the entity-recognition detection algorithms themselves (`EntityRecognitionHandler` logic) beyond reading hybrid composition for availability.
- Multi-variant "full beats light" auto-selection (deferred — see Open Questions; first-run picks whichever single variant is healthy, full preferred only if both present is trivially handled).

## Decisions

**1. New `AnonymisationBackendService` as single source of truth.**
A new service in `lib/Service/` (constructor-injected `IAppManager`, `IAppConfig`, `ICacheFactory`, `IClientService`, and `FileSettingsHandler`). It owns the `effectiveMethod` precedence rule and is the only caller of `IAppConfig` for backend selection. *Alternative considered:* extending `FileSettingsHandler` — rejected because the handler is a thin blob serializer and mixing probe/cache logic into it violates single responsibility and the ADR-017 "foundation app owns selection logic" boundary.

**2. Value objects: `BackendState`, `BackendInfo`, `ProbeResult`.**
Plain `final readonly` classes with `jsonSerialize()` (no Entity/QBMapper — these are not persisted rows). Enum of methods modelled as class constants validated centrally. *Alternative:* associative arrays — rejected; the spec mandates a typed value object and Psalm strictness benefits from real types.

**3. Storage + first-run sentinel.**
Selection continues to live in the `fileManagement` blob to avoid a migration and keep the existing save path intact. To support auto-select-on-first-run without a schema change, introduce an explicit `'auto'` value as the *default* for `entityRecognitionMethod` (replacing the current `hybrid`/`regex` split). `getState()` resolves `'auto'` → `openanonymiser` if a healthy ExApp is detected, else falls back to `regex`. When an admin saves a concrete method via the selector, the blob stores that literal value and `'auto'` resolution no longer applies. *Alternative:* a separate `entityRecognitionMethodExplicit` boolean — rejected as redundant; `'auto'` is self-describing and serialises cleanly to the UI as the "recommended" state. `anonymisation.probe_cache_ttl` is stored as its own `IAppConfig` key (operational tuning, not user file settings).

**4. ExApp detection + endpoint resolution via AppAPI.**
`available` for `openanonymiser` = `IAppManager::isEnabledForUser()`/`isInstalled()` true for `openanonymiser_light` OR `openanonymiser`. If AppAPI is absent (capability lookup throws / app not present), the probe returns `error: appapi_missing` and the UI surfaces an "install AppAPI" hint. Endpoint resolution uses AppAPI service routing by app id (not host:port). The existing HTTP `testOpenAnonymiserConnection()` is **repurposed**: `openanonymiser` test-connection becomes an AppAPI presence/health check (returns `latencyMs: 0`, no external HTTP); HTTP probing stays for `presidio` and `llm` via the existing `performHealthCheck()` helper.

**5. Probe caching.**
Per-method `ProbeResult` cached in a distributed `ICache` (`ICacheFactory::createDistributed('openregister_anon_probe')`), TTL = `anonymisation.probe_cache_ttl` (default 60, clamp 10–600). `getState()` consumes cache within TTL; `test-connection` always bypasses cache and writes back. *Alternative:* in-memory/local cache — rejected; admin actions and consumer reads can hit different PHP workers.

**6. Dedicated admin OCS controller.**
New `AnonymisationBackendController` (or methods on an existing admin controller) registered as OCS routes in `appinfo/routes.php`, both admin-gated (group check → 403; unauthenticated → 401). Thin wrappers over the service. *Alternative:* add to `FileSettingsController` — acceptable but the new endpoints are conceptually separate (state/probe, not file settings CRUD); a dedicated controller keeps routing legible.

**7. `effectiveMethod` precedence (from spec, restated):** disabled → `regex`; active `regex` → `regex`; active available+configured → active; else → `regex`. Hybrid availability = logical AND of its composed methods (`regex`, `presidio`, `openanonymiser`) per `EntityRecognitionHandler::detectEntitiesHybrid`.

## Risks / Trade-offs

- **AppAPI detection semantics vary by Nextcloud/AppAPI version** → isolate all AppAPI/`IAppManager` calls behind one private method in the service so the detection rule is testable and swappable; cover the `appapi_missing` path with a unit test.
- **`'auto'` default changes stored behaviour for existing installs** → installs that never opened settings have an empty blob (no stored method), so they pick up `'auto'` cleanly; installs with a saved `hybrid`/`regex`/etc. keep their literal value. Add an explicit note + test for the empty-blob and legacy-`hybrid` cases. No data migration required.
- **Repurposing `testOpenAnonymiserConnection` could break the existing FileConfiguration "test" button** → keep the old route working during transition; the new `test-connection` endpoint supersedes it and the Vue section is rewired in the same change. Flag the old method as deprecated.
- **Synchronous probe on state query adds latency** → bounded by the 60s cache and a short HTTP timeout on `performHealthCheck`; AppAPI checks are local (no network).
- **Stored choice vs. recommended drift** → operator intent always wins after first save (per decision); the UI shows "recommended" next to the detected ExApp even when another method is active, so the admin understands why their choice differs.

## Migration Plan

1. Ship the service + value objects + endpoints behind the existing settings (no behavioural change until the blob default flips to `'auto'`).
2. Flip the `FileSettingsHandler` default method from `hybrid`/`regex` to `'auto'`; `getState()` resolves it. Empty-blob installs auto-select a detected ExApp.
3. Rewire the Vue Anonimiseren section to the new endpoints; deprecate the old per-endpoint OpenAnonymiser test.
4. Rollback: revert the default to `hybrid` and unregister the new routes; value objects/service are inert if unused. No DB migration to reverse.

## Open Questions

- **Full-beats-light multi-variant rule:** ADR-017 wants the higher-capability variant auto-selected when both are installed. The chosen scope auto-selects "an OpenAnonymiser ExApp" — if both are present, do we hard-prefer `openanonymiser` (full) over `openanonymiser_light`, and surface light as "also detected"? Proposed: yes, prefer full; confirm before implementing the tie-break.
- **`llm` backend probe shape:** the method enum includes `llm`, but there is no `llmApiEndpoint` in the current blob. Does this change add LLM endpoint config, or is `llm` availability deferred (always `configured: false`) until a later change? Proposed: defer — report `llm` as not-configured and out of scope here.
