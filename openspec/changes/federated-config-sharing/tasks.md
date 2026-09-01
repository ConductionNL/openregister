# Tasks: federated-config-sharing

## Phase 1 — design + trust
- [x] `IShareableConfigType` contract (select / serialise / deserialise / exclude
      secrets / topic), storage-agnostic. Canonical repo layout. Trust model:
      org allowlist + version pinning + secret exclusion.

## Phase 2 — the engine
- [x] `FederatedConfigService` in OpenRegister (types / bundle / install / publish),
      backed by ConfigurationService; broker-routed publish. (#2134)
- [x] `RegisterShareableConfigTypesEvent` + registry (mirror flow nodes). (#2134)
- [x] GitHub credentials via the credential broker's **Doriath** leaf per user/org
      (NC-vault fallback); no shared-token assumption for user publish. (#2134)
- [x] Org source allowlist enforcement. (#2134)
- [x] **Schema-marker mechanism** — `x-openregister-shareable` on a schema's
      configuration auto-registers a `GenericObjectShareableConfigType`; vocabulary
      entry so it survives `setConfiguration()`. 15 unit + 3 e2e. (#2140)

## Phase 3 — consumers (with Playwright e2e)
- [x] Flows as a shareable type — reframed #2065. e2e: bundle→install→run;
      allowlist denial; unknown-type 404. (#2134)
- [x] Registers & schemas as a shareable type (adapter over ConfigurationService). (#2135)
- [x] **Config set** (`openregister.configset`) — a whole app's multi-entity bundle. (#2148)

## Phase 4 — store foundation
- [x] **Publish endpoint** using the user-selected credential; repo-creation +
      **topic-tagging** on publish. (#2147, #2148)
- [x] **Discovery** endpoint (GitHub topic search) + **fetchBundle** (discover→install bridge). (#2147, #2149)
- [x] **Ed25519 signing / verification** (`BundleSigner`): tamper always refused;
      trusted-keys allowlist enforcement; instance public-key endpoint. (#2147)
- [x] **Per-org RBAC** (`FederatedConfigAccess`): publish/install group allowlists. (#2147)

## Phase 5 — fold in hermiq
- [x] Agent templates → schema marker (pinned topic). Skills → per-app
      `HermiqSkillShareableConfigType` (agentskills.io markdown, quarantine install). (hermiq #126)
- [x] Retire `GitHubTemplatePushService`/`CatalogService` (~1,390 lines); rewire the
      three GitHub controllers onto the shared engine (`FederatedStoreService`).
      Live-verified end-to-end against real GitHub. (hermiq #127)

## Phase 6 — roll out declared types (markers on shipped schemas)
- [x] nldesign: NL Design theme token sets (wraps `ConfigBundleService`). (nldesign #192)
- [x] procest case types (#244) · shillinq tax config (#516) · opencatalogi catalog (#148)
      · softwarecatalog GEMMA element (#98) · larpingapp skill (#70) · docudesk template (#190)
      · decidesk ProcessTemplate (#143) · pipelinq pipeline (#409).

## Phase 7 — installable config-set repos (OpenBuild convergence)
- [x] `ExportService::buildScaffoldMap` + fold the installable scaffold into the
      `openbuild-app` publish; OpenRegister declared a hard dependency; standalone
      nc-vue runtime renders the set's manifest. (openbuild #181)

## Phase 8 — UI + governance
- [x] nc-vue `CnConfigurationStore` — user settings pane: pick which GitHub
      credential the store uses + instance signing key + browse-by-type discovery. (nc-vue #242)
- [ ] Org source-allowlist / trusted-keys **admin** UI (config keys exist; no UI yet).
- [ ] "How to share a config" end-user docs.

## Verified against REAL GitHub (2026-07-26)
- [x] publish → repo created + topic set + Ed25519-signed bundle (201) → fetch (with
      provenance) → install (verified) → discover (topic search found the repo).
- [x] hermiq skill round-trip through the rewired endpoints (publish → discover →
      install → `state: quarantined, source: hub`).

## Known follow-ups (external to the code)
- [ ] Two test repos (`fedtest-store-*`, `hermiq-skill-test-*`) need manual deletion
      — the broker correctly refuses `DELETE /repos/*` (token-custody safety).
- [ ] OpenBuild full generate→install→run e2e (a CI/build step; live push is gated by
      OpenBuild's per-app owners-role RBAC, orthogonal to the convergence).
