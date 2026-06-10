# Tasks: Pluggable Integration Registry

> The umbrella covers the contract, the registry, the migration of existing types, and the parity-gap fills. Individual new integrations are leaf changes (see proposal.md "Leaf plan").
>
> **ADR-028 task-cap waiver**: This umbrella exceeds the 15-task cap by design. The waiver is documented in proposal.md ("ADR-028 waiver") and the rationale is that splitting the contract, registry, built-ins, and migration across multiple changes would force interleaved depends_on chains that are harder to review and ship than one cohesive umbrella. Hydra builders SHOULD batch this umbrella across multiple turns; the leaf changes (which each stay within the cap) carry the bulk of the per-integration work.

## Backend — Provider Contract & Registry

- [x] Create `lib/Service/Integration/IntegrationProvider.php` interface (15 methods per design.md normative contract — includes `getStorageStrategy()` allowing `'magic-column' | 'link-table' | 'external' | 'query-time'` and `getOpenConnectorSource(): ?string`)
- [x] Create `lib/Service/Integration/AbstractIntegrationProvider.php` base class with sensible defaults (group=null, requiresPermission=null, authRequirements=['type'=>'none'], `getOpenConnectorSource()` returns null, get() throws NotImplemented for list-only providers)
- [x] Create `lib/Service/Integration/IntegrationRegistry.php` — explicit `addProvider()` registration with collision detection (AD-13) + external-source rejection (AD-4); `list()`, `listIds()`, `get($id)`, `getEnabled()`. _Note: spec said "DI-tag-based discovery" but modern Nextcloud doesn't expose `IAppContainer::queryAll(<tag>)` as a public API. Switched to explicit `addProvider()` at app-bootstrap — same semantics, NC-compatible. RequestScopedCache replaced by the instance-level `$providers` array since the service is request-scoped via DI._
- [x] Create `lib/Service/Integration/ExternalIntegrationRouter.php` — routes `storage: external` providers through OpenConnector + surfaces auth status; raises `ProviderUnavailableException` with `details.cause` of `'openconnector-down' | 'openconnector-source-missing' | 'upstream-service-down'` per AD-23
- [x] Update `lib/AppInfo/Application.php` to register `IntegrationRegistry` + `ExternalIntegrationRouter` as services (new `registerIntegrationRegistry()` phase). _Built-in providers' DI registration moves to the per-built-in tasks (12-17) — each `*Provider` self-registers via `addProvider()` at bootstrap._
- [x] Define `query-time` storage-strategy contract in code: documented in `IntegrationProvider` interface docblocks; `AbstractIntegrationProvider` defaults mutation methods (get / create / update / delete) to `NotImplementedException`; new `QueryTimeContract` helper carries the 2 s render timeout + HTTP 501 envelope builder for `ObjectsController` to consume in tasks 7-11.

## Backend — Schema validator refactor

- [x] Modify `lib/Db/Schema.php::validateLinkedTypesValue()` — now consults BOTH the deprecated `VALID_LINKED_TYPES` fallback AND `IntegrationRegistry::listIds()`. Registry resolved lazily via `\OC::$server->get()` since Schema is an Entity (not a service) and DI doesn't reach it; falls back to fallback-only when container isn't booted (unit tests). AD-5 backwards-compat preserved — existing schemas with values like `'mail'` / `'calendar'` still validate until their leaves land.
- [x] Mark `Schema::VALID_LINKED_TYPES` constant `@deprecated` with pointer to the registry + the `cleanup-linked-entity-type-map` follow-up.
- [x] Mark `LinkedEntityService::TYPE_COLUMN_MAP` constant `@deprecated` (removal scheduled in follow-up cleanup change).
- [x] Add `referenceType` validation to schema property type system — new `PropertyReferenceTypeValidator` service consults `IntegrationRegistry::isValidIntegrationId()`. Kept as a standalone validator (rather than refactoring `PropertyValidatorHandler`) so existing schema validation paths stay untouched; opt-in callers wire it where they need the marker enforced (CnFormDialog / CnDetailGrid landings in tasks 25-46).
- [x] Add migration that logs (does not reject) any existing schema with `linkedTypes` referencing an id not currently registered — `LogDanglingLinkedTypes` repair step registered under `<install>` and `<post-migration>` in info.xml. Strictly informational; never throws, never modifies data.

## Backend — Built-in Providers (5)

- [x] `lib/Service/Integration/BuiltinProviders/FilesProvider.php` — wraps `FileService` (magic-column). `list()` delegates to `FileService::getFilesForEntity()` after resolving the ObjectEntity via the container; mutation throws `NotImplementedException` until the controller refactor consolidates writes (tasks 18-22).
- [x] `lib/Service/Integration/BuiltinProviders/NotesProvider.php` — wraps `NoteService` (link-table). Full CRUD: `list` / `create` / `update` / `delete` delegate to the wrapped service.
- [x] `lib/Service/Integration/BuiltinProviders/TasksProvider.php` — wraps `TaskService` (link-table, CalDAV). Composite `{calendarId}/{taskUri}` entity ids; full CRUD delegation.
- [x] `lib/Service/Integration/BuiltinProviders/TagsProvider.php` — wraps NC system tag manager (link-table). `list()` via `ISystemTagObjectMapper::getTagIdsForObjects`; mutation throws (write path stays at TagsController routes for now).
- [x] `lib/Service/Integration/BuiltinProviders/AuditTrailProvider.php` — wraps `AuditTrailMapper` (query-time, AD-22). Read-only by design; mutation methods inherit `NotImplementedException` from `AbstractIntegrationProvider`.
- [x] All five register through `addProvider()` at `Application::boot()` time (new `bootBuiltinIntegrationProviders()` helper). Frontend-side `referenceType: <id>` declarations land in tasks 25-30 when each provider gets its JS registry counterpart.

## Backend — Routes, Controller, Capabilities

- [x] Create `lib/Controller/IntegrationsController.php` — `GET /api/integrations` (with `group` + `enabled` filter params) + `GET /api/integrations/{id}`. Role-redacted descriptor: every authed user sees public fields; admins additionally get `requiresPermission`, `openConnectorSource`, `authStatus`.
- [x] Sub-resource dispatch via the registry — new dedicated `lib/Controller/ObjectIntegrationsController.php` owns `/api/objects/{register}/{schema}/{id}/integrations/{integrationId}[/{entityId}]` (GET / POST / PUT / DELETE). Additive — `ObjectsController` (2400+ lines) stays untouched. The legacy `/api/objects/{...}/files`, `/api/objects/{...}/notes` etc. routes continue working. Error translation: `NotImplementedException` → 501 with `QueryTimeContract::buildHttpBody()` envelope; `ProviderUnavailableException` → 503 with `details.cause` payload (AD-23); unknown id → 404.
- [x] Add `/api/integrations` + `/api/objects/.../integrations/...` routes to `appinfo/routes.php`.
- [x] Add `lib/Capabilities/IntegrationsCapability.php` — surfaces the registry through `/ocs/v2.php/cloud/capabilities`, role-redacted per AD-17 (public surface for everyone; admin-only fields omitted, not null-stubbed). _Spec said "Update lib/Service/CapabilitiesService.php"; OR's capability pattern uses one ICapability class per concern (see `UrnCapability`), so the new file lives in `lib/Capabilities/` and is registered via `$context->registerCapability()`. Same end shape, idiomatic structure._
- [x] Register the new capability via `$context->registerCapability(IntegrationsCapability::class)` in `Application::register()`. _info.xml doesn't carry capability declarations in OR — registration happens through `IRegistrationContext::registerCapability()` at runtime, mirroring the existing `UrnCapability` pattern. No `appinfo/info.xml` change needed._

## Backend — Admin UI for auth

- [x] Create admin settings page — `lib/Settings/IntegrationsAdminSettings.php` + server-rendered template at `templates/settings/integrations-admin.php`. Lists every IntegrationProvider with id / label / group / storage / requiredApp / status / authStatus / OpenConnectorSource. _Spec called for an "AdminSection" but OR's pattern is one `ISettings` page per `IIconSection`; the existing `Sections\OpenRegisterAdmin` already provides the parent section, so this lands as a second `<admin>` entry under it. Same end shape, idiomatic structure._
- [x] Wire admin section to OpenConnector credential management — `buildOpenConnectorConfigureUrl()` produces a deep-link to OpenConnector's source-edit screen (with graceful fallback to the install page when OpenConnector isn't enabled). External-provider rows render a "Configure" button pointing there.
- [x] Per-integration "Test connection" action — external providers' rows include a "Test connection" link pointing at the OCS route `/ocs/v2.php/apps/openregister/api/integrations/{id}` which returns the role-redacted descriptor (including `authStatus`).

## Frontend — Registry & Composable (`@conduction/nextcloud-vue`) — [ConductionNL/nextcloud-vue#202]

- [x] Create `src/integrations/registry.js` — `createIntegrationRegistry()` factory + default `integrations` singleton + `installIntegrationRegistry(window)` (register, unregister, list, get, has, resolveWidget, onChange); collision policy per AD-13 (dev throws, prod warns + keeps first); queue stub replay for late-loaded apps
- [x] Create `src/composables/useIntegrationRegistry.js` — Vue 2.7 composable wrapping the singleton in a `shallowRef` snapshot, returns `{ integrations, getById, resolveWidget, registry }`; cleans up on unmount
- [x] Add `integrations` / `createIntegrationRegistry` / `installIntegrationRegistry` / `VALID_SURFACES` / `useIntegrationRegistry` exports to `src/index.js` + `src/integrations/index.js` + `src/composables/index.js`
- [x] Document the API in `CLAUDE.md` — "Pluggable Integration Registry" section

> Spec deviation: `listByGroup` → inline filter on the snapshot; `resolveWidget(id, surface)` added to encapsulate AD-19 fallback.

## Frontend — Built-in registrations — [ConductionNL/nextcloud-vue#210]

- [x] `src/integrations/builtin/files.js` — register `files` (tab `CnFilesTab`, widget `CnFilesCard`)
- [x] `src/integrations/builtin/notes.js` — register `notes` (tab `CnNotesTab`, widget = adapter around `CnNotesCard`)
- [x] `src/integrations/builtin/tasks.js` — register `tasks` (tab `CnTasksTab`, widget = adapter around `CnTasksCard`)
- [x] `src/integrations/builtin/tags.js` — register `tags` (tab `CnTagsTab`, widget `CnTagsCard`)
- [x] `src/integrations/builtin/audit-trail.js` — register `audit-trail` (tab `CnAuditTrailTab`, widget `CnAuditTrailCard`)
- [x] Each declares `referenceType: <id>` for reference-property crossover; `registerBuiltinIntegrations()` + `builtinIntegrations` exported; id/order/icon/group match the PHP providers

## Frontend — Fill the parity gaps (3 missing widgets) — [ConductionNL/nextcloud-vue#204]

- [x] Create `src/components/CnFilesCard/CnFilesCard.vue` — surface-aware compact files widget (all four surfaces via AD-19 fallback)
- [x] Create `src/components/CnTagsCard/CnTagsCard.vue` — same four surfaces
- [x] Create `src/components/CnAuditTrailCard/CnAuditTrailCard.vue` — same four surfaces
- [x] Add to `src/components/index.js` and `src/index.js` barrels; +docs files + unit tests

## Frontend — Surface support in existing components — [ConductionNL/nextcloud-vue#209]

- [x] Refactor `src/components/CnObjectSidebar/CnObjectSidebar.vue` — opt-in `useRegistry` prop renders one tab per registered provider; `excludeIntegrations` + `hiddenTabs` filter; reactive late registration; mutually exclusive with `tabs` (warns); `#extra-tabs` slot preserved
- [x] Update `src/components/CnDashboardPage/CnDashboardPage.vue` — new `integration` widget type; `surface` prop (default `'app-dashboard'`) + `integrationContext` prop; resolves via `resolveWidget(integrationId, surface)`
- [x] Update `src/components/CnDetailPage/CnDetailPage.vue` — same `integration` widget type; `surface` prop (default `'detail-page'`); derives `integrationContext` from `sidebarProps` + `objectId`
- [x] Update `src/components/CnFormDialog/CnFormDialog.vue` — detect `referenceType` on schema properties (`fieldsFromSchema` now passes it through); render integration's `single-entity` widget inline; `referenceContext` prop
- [x] Update `src/components/CnDetailGrid/CnDetailGrid.vue` — same `referenceType` handling for read-only display
- [x] Implement graceful surface fallback per AD-19 — unknown surface → main `widget` with `surface` prop passed (in `registry.resolveWidget`)

## Frontend — Tests — [ConductionNL/nextcloud-vue#202/#204/#209/#210]

- [x] Unit tests `tests/integrations/registry.spec.js` + `tests/composables/useIntegrationRegistry.spec.js` — register, list, get, has, resolveWidget, collision, install/replay queue, onChange reactivity (21 tests)
- [x] Tests for `CnObjectSidebar` registry mode + backwards-compat assertion on the 5 legacy tabs (23 tests total in `CnObjectSidebar.spec.js`)
- [x] Component tests for the 3 new widgets (`CnFilesCard.spec.js`, `CnTagsCard.spec.js`, `CnAuditTrailCard.spec.js` — 12 tests) + `CnDashboardPage` / `CnDetailPageIntegrationWidget` / `CnFormDialog` / `CnDetailGrid` integration tests
- [x] End-to-end registry → schema → component path covered by the surface-component + builtin tests (`tests/integrations/builtin.spec.js` — 9 tests)

## Backend — Tests

- [ ] `tests/Unit/Service/Integration/IntegrationRegistryTest.php` — registration, lookup, isEnabled filtering, collision detection
- [ ] `tests/Unit/Service/Integration/CapabilitiesIntegrationTest.php` — OCS block matches registry state
- [ ] `tests/Unit/Db/SchemaLinkedTypesTest.php` — registry-driven validation, deprecated path still works for one cycle
- [ ] `tests/Unit/Service/Integration/BuiltinProviders/*Test.php` — one test class per built-in provider

## Quality gates & CI

- [x] Create `scripts/check-integration-parity.js` (matches the repo's Node-script convention rather than `.sh`) — imports `builtinIntegrations`, asserts each descriptor has `id` + `label` + `tab` + `widget`, fails non-zero listing offenders; source-scan fallback — [ConductionNL/nextcloud-vue#211]
- [x] Wire parity check into CI — added as the "Integration parity gate" step in `.github/workflows/code-quality.yml` (single workflow per the repo convention, not a separate `integration-parity.yml`) — [ConductionNL/nextcloud-vue#211]
- [ ] Add parity check to hydra quality gate (extend `scripts/run-hydra-gates.sh` in hydra repo — separate small PR — **deferred to a follow-up hydra PR**)
- [x] Add parity check to local pre-commit hook (`scripts/precommit-regenerate-partials.sh`, runs on `src/integrations/` changes) — [ConductionNL/nextcloud-vue#211]

## Scaffold script

- [x] Create `scripts/scaffold-integration.sh <id>` — generates a new leaf-change skeleton (proposal.md, tasks.md, PHP provider stub, JS registration stub, hydra.json with `depends_on: ["pluggable-integration-registry"]`)
- [x] Document scaffold usage in developer guide (`docs/Integrations/pluggable-integration-registry.md`)

## ADR & docs

- [ ] Author `hydra/openspec/architecture/adr-019-integration-registry.md` (separate hydra-repo PR — **deferred to a follow-up hydra PR**)
- [x] Create `docs/Integrations/pluggable-integration-registry.md` — "How to add an integration" — full walkthrough using the built-in `files` provider as the worked example, plus the scaffold-script quickstart
- [x] Update OpenRegister main `README.md` with a one-paragraph mention of the integration registry pointing to the developer guide
- [x] Update `@conduction/nextcloud-vue` `CLAUDE.md` with the integration registry contract — [ConductionNL/nextcloud-vue#202/#209]

## Translations

- [x] New user-facing strings wrapped for translation — the admin section labels emitted by `IntegrationsAdminSettings` / `templates/settings/integrations-admin.php` go through `$l->t()`, and the `@conduction/nextcloud-vue` widget/tab strings go through `t('nextcloud-vue', …)`. `l10n/{en,nl}.json` are produced by the repo's translation-extraction build step (no PR in this umbrella hand-edits them, matching the existing convention — cf. PR 5 which added the admin UI without touching `l10n/`). Parity-gate error messages are CLI-only English (never localised, matches `check-docs.js` / `check-jsdoc.js`). Integration group names (`core` / `comms` / `docs` / `workflow` / `external`) are machine ids, not user-facing labels.

## Acceptance verification

- [ ] An end-to-end test creates a dummy `IntegrationProvider`, registers it backend + frontend, asserts: it appears in `/api/integrations`, in the OCS capabilities response, in `CnObjectSidebar` (when schema allows), in `CnDashboardPage` (across all 4 surfaces), and gets removed cleanly when unregistered
- [ ] Backwards-compat test: an existing app upgrading nextcloud-vue with zero code changes sees identical sidebar behaviour for the 5 migrated types
- [ ] Schema-validator backwards-compat: schemas with existing `linkedTypes: ["files", "notes"]` continue to validate without modification
- [ ] Reference-property test: a schema with `assignedHandler: { type: 'string', referenceType: 'contacts' }` renders the contact's `single-entity` widget in `CnFormDialog`
