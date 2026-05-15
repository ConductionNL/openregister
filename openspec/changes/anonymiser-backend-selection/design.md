# Design — `anonymiser-backend-selection`

## Context

OpenRegister has five recognition methods registered in `EntityRecognitionHandler` (`regex`, `presidio`, `openanonymiser`, `llm`, `hybrid`) and persists the operator's choice plus endpoint URLs inside the `fileManagement` `IAppConfig` blob via `FileSettingsHandler`. The dispatch layer (`EntityRecognitionHandler::detect*`) works, but everything **around** the dispatch is missing: there is no consolidated state-query API, no admin selector UI, no "is this backend actually reachable right now?" indicator, and no canonical rule for what should happen when the selected method is not actually usable. Consumer apps that need to surface backend state to operators (DocuDesk's `anonymiser-backend-warning`) currently have no contract to consume.

This change closes those gaps with a typed service + OCS endpoint + admin UI, **without** disturbing `EntityRecognitionHandler` or the `fileManagement` storage shape.

## Decisions

### D1: PHP service + OCS endpoint — both, not either-or

Both surfaces are needed:

- **PHP service** (`AnonymisationBackendService::getState()`) for in-process consumers: cron jobs, other OR controllers, future capabilities that want to gate behaviour on the active method.
- **OCS endpoint** (`GET /api/admin/anonymisation/backend-state`) for cross-app consumers (DocuDesk's warning banner runs in a different PHP process and must reach OR over HTTP).

The endpoint is a thin wrapper over the service. There is exactly one place that resolves state; the OCS handler reads it and serialises it. No duplicated precedence logic.

### D2: `BackendState` value-object shape

```
BackendState {
  entityRecognitionEnabled: bool
  activeMethod: 'regex' | 'presidio' | 'openanonymiser' | 'llm' | 'hybrid'
  effectiveMethod: 'regex' | 'presidio' | 'openanonymiser' | 'llm' | 'hybrid'
  backends: [
    {
      name: <method enum>,
      available: bool,    // can be reached right now (ExApp installed+enabled, or endpoint reachable, or "always" for regex)
      configured: bool,   // admin has set it up (endpoint URL present, or ExApp registered)
      lastProbedAt: ISO-8601 | null,
      latencyMs: int | null
    }
  ]
}
```

Two flags per backend, not one. `configured` tracks operator intent (the endpoint URL was set, or the ExApp was installed). `available` tracks runtime reachability (the probe succeeded recently). Both matter independently: a configured-but-unreachable backend is a different incident than an unconfigured one, and the admin UI surfaces each differently.

### D3: Canonical "no backend" determination rule

`effectiveMethod` is computed as:

1. If `entityRecognitionEnabled === false` → `effectiveMethod := 'regex'`.
2. Else if `activeMethod === 'regex'` → `effectiveMethod := 'regex'`.
3. Else if `backends[activeMethod].available === true AND backends[activeMethod].configured === true` → `effectiveMethod := activeMethod`.
4. Else → `effectiveMethod := 'regex'`.

The rule lives in `AnonymisationBackendService::getState()`. Callers MUST consume `effectiveMethod`, never recompute it themselves. This is what DocuDesk's warning banner reads: "if `effectiveMethod !== activeMethod`, the selected backend is not in use right now; if `effectiveMethod === 'regex'` and the operator did not explicitly choose regex, render the warning."

For the `hybrid` method specifically: `available` is the AND of the methods it composes (`regex`, `presidio`, and `openanonymiser` under current `EntityRecognitionHandler::detectEntitiesHybrid` semantics). If any composed backend is unavailable, `hybrid` itself is unavailable and falls through to `regex`. This is conservative — a future change can introduce partial-degradation semantics if there is a use case.

### D4: Admin UI placement — extend the existing `OpenRegisterAdmin` settings page

OR already has one admin settings panel registered via `lib/Settings/OpenRegisterAdmin.php`. Rather than introduce a separate admin page (clutters Nextcloud's settings sidebar), add an **"Anonimiseren"** section *within* that existing panel. The section is a Vue component rendered into the same admin SPA, reusing the OR admin Vue router.

Layout (behaviour, not pixels):

- A method selector — radio group or `<select>` over the five supported methods.
- Per-method configuration sub-section visible when that method is selected:
  - For `presidio`, `llm`: a URL input bound to the relevant `*ApiEndpoint` IAppConfig field.
  - For `openanonymiser`: a read-only ExApp-availability indicator showing whether `openanonymiser_light` and/or `openanonymiser` ExApps are installed and enabled via AppAPI, with a deep link to `/settings/apps/discover/{appid}` when an ExApp is missing.
  - For `hybrid`: a summary of which composed backends are available, with their own configuration links.
  - For `regex`: a single explanatory paragraph (no config needed).
- A **Test connection** button per applicable backend (everything except `regex`), wired to the `POST /api/admin/anonymisation/test-connection` endpoint.
- A live availability indicator per backend (dot + label: "available" / "unreachable" / "not configured" / "not detectable"), rendered from `BackendState.backends[].available` and re-fetched on configuration save.

### D5: AppAPI / ExApp availability detection

Use Nextcloud's `IAppManager` to check whether the OpenAnonymiser ExApps are installed and enabled. AppAPI itself is required to install ExApps in the first place; if `IAppManager` does not expose AppAPI-derived state, fall back to checking standard app enablement and surface a "not detectable" state with a clear admin-side message.

The detection happens at probe time, not at startup. The state-query response is computed from a per-process cache that respects the 60s TTL (see D7).

### D6: Test-connection probe

Behaviour, per backend:

- `presidio`, `llm`: HTTP `GET` (or `HEAD`) against the configured endpoint's health path (Presidio exposes `/health`; LLM endpoint health path is operator-configurable, defaulting to the bare endpoint root). Timeout: 2s default, configurable via `anonymisation.probe_timeout`. Successful 2xx → reachable. Anything else (timeout, 4xx, 5xx, DNS failure) → unreachable, with a structured error code (`timeout`, `dns_error`, `http_4xx`, `http_5xx`, `connect_refused`).
- `openanonymiser`: AppAPI registry lookup. The probe queries `IAppManager` to confirm the ExApp is enabled and reports back as `{reachable: true|false, error: 'exapp_not_installed' | 'exapp_disabled' | 'appapi_missing' | null, latencyMs: 0}`. There is no HTTP probe — AppAPI mediates ExApp invocation, so its availability is the right signal.
- `regex`: trivial probe — always returns `{reachable: true, latencyMs: 0}`. The button is hidden in the UI for `regex` since there is nothing to probe.
- `hybrid`: composite probe — runs the underlying backends' probes and aggregates. Reachable iff each composed backend is reachable.

The probe endpoint accepts a `method` parameter and returns the probe result. The button in the UI calls this endpoint and updates the indicator without a full state-query refetch.

### D7: Probe-result caching

To keep the state-query endpoint cheap, probe results are cached:

- **Cache key:** `anonymisation.probe_cache.<method>` in `IAppConfig`.
- **Value:** JSON `{reachable, latencyMs, error?, probedAt: ISO-8601}`.
- **TTL:** 60 seconds, configurable via `anonymisation.probe_cache_ttl` (range 10–600 seconds).
- **Read path:** `getState()` reads the cache; if a cached result is younger than the TTL, it is used. Otherwise a fresh probe runs synchronously and the result is written back. This bounds the worst-case latency of the state-query endpoint to one probe round-trip.
- **Bypass path:** the `POST .../test-connection` endpoint always issues a fresh probe (cache bypass) and writes the result to the cache. Press-the-button = re-probe-now.

The cache is **transient state**, not persisted configuration. Storing it in `IAppConfig` (rather than memory) is pragmatic for multi-process deployments; the spec calls the cache storage location implementation-internal and reserves the right to move it to a shared cache (Redis) later without re-speccing.

### D8: Authorisation

The two new OCS endpoints require admin group membership, matching the existing OR admin endpoint convention. Non-admin callers receive HTTP 403 with the OR-standard error body shape. The admin Vue panel uses the same admin-only routes — no separate authorisation path.

Service-layer callers (other OR services using `AnonymisationBackendService::getState()` directly) are trusted; the service does not enforce authorisation. Authorisation is a controller-layer concern in OR.

### D9: Naming

- Service: `OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService`
- Value object: `OCA\OpenRegister\Service\Anonymisation\BackendState`
- Probe: `OCA\OpenRegister\Service\Anonymisation\BackendProbe`
- Controller: extend an existing admin controller, **or** `OCA\OpenRegister\Controller\AnonymisationAdminController` — leave to implementation; both are acceptable.
- OCS routes: under `/api/admin/anonymisation/...` to align with the broader OR admin route family.

## Risks

**[R1 — Synchronous probes make state-query slow when remotes are slow]**
The 60s cache keeps the steady-state response fast, but a cold cache means the first call after install pays one probe round-trip per method. With 2s default timeout and four probe-able methods (presidio, openanonymiser, llm, hybrid), worst case is ~6s on cold cache (presidio + llm probed; openanonymiser is AppAPI-local; hybrid reuses prior probes within the same call).
→ Mitigation: parallel probes inside `getState()` when populating cold cache (PHP `ReactPHP`/`Guzzle async` is available in OR — use whichever is already in vendor). Worst case drops to ~2s. Document the cold-cache cost in admin docs and add a startup warm-up job (post-install hook calls `getState()` once, populates the cache before any user-facing call).

**[R2 — AppAPI / IAppManager API surface differs across Nextcloud versions]**
ExApp availability detection depends on capabilities of `IAppManager` that may not be present in older NC versions, or on AppAPI ExApp-specific methods that vary.
→ Mitigation: feature-detect at runtime. Wrap the lookup in a `try/catch` + capability probe; on failure, return `{available: false, error: 'appapi_missing', ...}` rather than blowing up the state-query. The admin UI surfaces "not detectable" with a hint to install AppAPI.

**[R3 — `fileManagement` blob storage may shift under a future change]**
This change reads `entityRecognitionMethod`, `*ApiEndpoint`, and `entityRecognitionEnabled` out of the blob. If the storage shape changes, the service needs adapting.
→ Mitigation: read through `FileSettingsHandler` (the existing accessor), not by parsing the blob directly. If `FileSettingsHandler` changes signature, the dependency is explicit and easy to follow. The spec declares storage shape as implementation-internal; consumers MUST go through the service.

**[R4 — Probe-driven side effects on remote endpoints]**
Some Presidio / LLM deployments may log every probe; high-frequency probes (e.g. an admin who reloads the panel repeatedly) could spam ops logs at the remote.
→ Mitigation: the 60s cache TTL means the state-query path probes at most once per minute per backend. The "Test connection" button bypasses cache but is operator-triggered, not automatic. Documented as expected behaviour.

**[R5 — Naming collision with hypothetical future OR-level anonymisation orchestration]**
The names `AnonymisationBackendService` / `BackendState` are scoped to *backend selection* and *reachability*. If a future change introduces a full anonymisation-orchestration service, those names may need disambiguation.
→ Mitigation: place this change's classes under the `Service\Anonymisation\` namespace; the future orchestrator can live alongside without colliding (e.g. `Service\Anonymisation\AnonymisationOrchestratorService`). Recorded here so the namespace decision is intentional.

## Open Questions

None for v1 scope. The candidate follow-ups (multi-tier fallback, normalised storage, backend abstraction) are tracked in the proposal's *Out of scope* list rather than as in-spec open questions, since they do not need to resolve before this change ships.