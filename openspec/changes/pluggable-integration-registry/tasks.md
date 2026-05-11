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

- [ ] Modify `lib/Db/Schema.php::validateLinkedTypesValue()` — replace hardcoded `VALID_LINKED_TYPES` lookup with `IntegrationRegistry::listIds()` injection
- [ ] Mark `Schema::VALID_LINKED_TYPES` constant `@deprecated` with pointer to the registry
- [ ] Mark `LinkedEntityService::TYPE_COLUMN_MAP` constant `@deprecated` (removal scheduled in follow-up cleanup change)
- [ ] Add `referenceType` validation to schema property type system — accepts any registered integration id
- [ ] Add migration that logs (does not reject) any existing schema with `linkedTypes` referencing an id not currently registered

## Backend — Built-in Providers (5)

- [ ] `lib/Service/Integration/BuiltinProviders/FilesProvider.php` — wraps existing `FileService` integration, storage='magic-column'
- [ ] `lib/Service/Integration/BuiltinProviders/NotesProvider.php` — wraps existing `NoteService` integration
- [ ] `lib/Service/Integration/BuiltinProviders/TasksProvider.php` — wraps existing `TaskService` (todos)
- [ ] `lib/Service/Integration/BuiltinProviders/TagsProvider.php` — wraps existing tags integration; requiredApp=null (always available)
- [ ] `lib/Service/Integration/BuiltinProviders/AuditTrailProvider.php` — wraps existing `AuditTrailController`; requiredApp=null
- [ ] All five register `referenceType: <id>` so schema reference properties can target them

## Backend — Routes, Controller, Capabilities

- [ ] Create `lib/Controller/IntegrationsController.php` — `GET /api/integrations` (list + filter by group/enabled), `GET /api/integrations/{id}` (single + health + auth status)
- [ ] Update `lib/Controller/ObjectsController.php` — sub-resource calls (`{integrationId}` segment) route through `IntegrationRegistry::get($id)`
- [ ] Add `/api/integrations` routes to `appinfo/routes.php`
- [ ] Update `lib/Service/CapabilitiesService.php` — add `integrations` block to OCS capabilities response
- [ ] Add `integrations` capability declaration to `appinfo/info.xml`

## Backend — Admin UI for auth

- [ ] Create `lib/Settings/IntegrationsAdminSection.php` — admin section listing integrations + auth status + Configure buttons
- [ ] Wire admin section to OpenConnector credential management for `storage: external` providers
- [ ] Per-integration "Test connection" action in admin UI

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
