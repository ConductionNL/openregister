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

## Frontend — Registry & Composable (`@conduction/nextcloud-vue`)

- [ ] Create `src/integrations/registry.js` — `window.OCA.OpenRegister.integrations` (register, unregister, list, get, onChange, listByGroup); collision policy per AD-11; queue stub for late-loaded apps
- [ ] Create `src/composables/useIntegrationRegistry.js` — reactive registry consumer
- [ ] Add `integrations` export to `src/index.js`
- [ ] Document the API in `CLAUDE.md`

## Frontend — Built-in registrations

- [ ] `src/integrations/builtin/files.js` — register `files` integration with tab + widget components
- [ ] `src/integrations/builtin/notes.js` — register `notes`
- [ ] `src/integrations/builtin/tasks.js` — register `tasks`
- [ ] `src/integrations/builtin/tags.js` — register `tags`
- [ ] `src/integrations/builtin/audit-trail.js` — register `audit-trail`
- [ ] Each declares `referenceType: <id>` for reference-property crossover

## Frontend — Fill the parity gaps (3 missing widgets)

- [ ] Create `src/components/CnFilesCard/CnFilesCard.vue` — supports surfaces `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`
- [ ] Create `src/components/CnTagsCard/CnTagsCard.vue` — same four surfaces
- [ ] Create `src/components/CnAuditTrailCard/CnAuditTrailCard.vue` — same four surfaces
- [ ] Add to `src/components/index.js` and `src/index.js` barrels

## Frontend — Surface support in existing components

- [ ] Refactor `src/components/CnObjectSidebar/CnObjectSidebar.vue` — render tabs from registry via three-stage filter; preserve all existing props + slots; add `excludeIntegrations` prop
- [ ] Update `src/components/CnDashboardPage/CnDashboardPage.vue` — pass `surface='user-dashboard'` or `'app-dashboard'` (configurable prop) to widgets resolved from registry
- [ ] Update `src/components/CnDetailPage/CnDetailPage.vue` — pass `surface='detail-page'` to widgets
- [ ] Update `src/components/CnFormDialog/CnFormDialog.vue` — detect `referenceType` on schema properties; render integration's `single-entity` widget inline
- [ ] Update `src/components/CnDetailGrid/CnDetailGrid.vue` — same `referenceType` handling for read-only display
- [ ] Implement graceful surface fallback per AD-18 — unknown surface → main `widget` with `surface` prop passed

## Frontend — Tests

- [ ] Unit tests `tests/integrations/registry.test.js` — register, list, get, collision, late-load queue, onChange reactivity
- [ ] Snapshot tests for `CnObjectSidebar` covering 5 existing tabs (backwards-compat assertion)
- [ ] Component tests for the 3 new widgets across all 4 surfaces
- [ ] Test the three-stage filter end-to-end (registry → schema → component)

## Backend — Tests

- [ ] `tests/Unit/Service/Integration/IntegrationRegistryTest.php` — registration, lookup, isEnabled filtering, collision detection
- [ ] `tests/Unit/Service/Integration/CapabilitiesIntegrationTest.php` — OCS block matches registry state
- [ ] `tests/Unit/Db/SchemaLinkedTypesTest.php` — registry-driven validation, deprecated path still works for one cycle
- [ ] `tests/Unit/Service/Integration/BuiltinProviders/*Test.php` — one test class per built-in provider

## Quality gates & CI

- [ ] Create `scripts/check-integration-parity.sh` — walks `src/integrations/` registrations, asserts each has `tab` AND `widget`, fails non-zero on missing
- [ ] Wire parity check into `.github/workflows/integration-parity.yml`
- [ ] Add parity check to hydra quality gate (extend `scripts/run-hydra-gates.sh` in hydra repo — separate small PR)
- [ ] Add parity check to local pre-commit hook

## Scaffold script

- [ ] Create `scripts/scaffold-integration.sh <id>` — generates a new leaf-change skeleton (proposal.md, tasks.md, provider stub, tab stub, widget stub, registration call, hydra.json with `depends_on: ["pluggable-integration-registry"]`)
- [ ] Document scaffold usage in developer guide

## ADR & docs

- [ ] Author `hydra/openspec/architecture/adr-019-integration-registry.md` (separate hydra-repo PR)
- [ ] Create `docs/integrations/README.md` — "How to add an integration" — full walkthrough using one of the built-in providers as the worked example
- [ ] Update OpenRegister main `README.md` with a one-paragraph mention of the integration registry pointing to the developer guide
- [ ] Update `@conduction/nextcloud-vue` `CLAUDE.md` with the integration registry contract

## Translations

- [ ] All new user-facing strings in nl + en — admin section labels, integration group names, parity error messages
- [ ] Verify `l10n/nl.json` and `l10n/en.json` updated for both repos (openregister + nextcloud-vue)

## Acceptance verification

- [ ] An end-to-end test creates a dummy `IntegrationProvider`, registers it backend + frontend, asserts: it appears in `/api/integrations`, in the OCS capabilities response, in `CnObjectSidebar` (when schema allows), in `CnDashboardPage` (across all 4 surfaces), and gets removed cleanly when unregistered
- [ ] Backwards-compat test: an existing app upgrading nextcloud-vue with zero code changes sees identical sidebar behaviour for the 5 migrated types
- [ ] Schema-validator backwards-compat: schemas with existing `linkedTypes: ["files", "notes"]` continue to validate without modification
- [ ] Reference-property test: a schema with `assignedHandler: { type: 'string', referenceType: 'contacts' }` renders the contact's `single-entity` widget in `CnFormDialog`
