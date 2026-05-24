# Tasks: Integration — Flow

## Backend

- [x] `FlowProvider` — id='flow', label='Automation', icon='RobotOutline', group='workflow', requiredApp='workflowengine', storage='link-table'
- [x] Replace MarkerLookupTrait stub body with real `OCA\WorkflowEngine\Manager` integration
- [x] Lazy Manager resolution via `\OCP\Server::get(FlowManager::class)` (keeps `(db, appManager, l10n)` constructor signature so the greenfield-providers registration block in `Application.php` stays untouched)
- [x] Admin-scoped operation discovery via `getAllOperations(new ScopeContext(IManager::SCOPE_ADMIN))`
- [x] Marker-based per-object filtering on the operation `name` (`[or:{objectUuid}]`)
- [x] `_search` filter on operation name (case-insensitive substring)
- [x] `health()` returns `'unavailable'` (app missing) / `'degraded'` (Manager unresolvable) / `'ok'`; never throws
- [x] `requiresPermission()` returns `'admin'` per spec
- [x] SPDX headers + `@spec` annotation inside main docblock (ADR-008, ADR-014)
- [ ] `FlowService` — full read/write CRUD for link rows (deferred: this change ships the provider + Manager-backed read path; a dedicated link-table follow-up will host the schema/object → rule-id link rows once the upstream NC Flow event-log surface is stable)
- [ ] `FlowLink` entity + mapper + migration (schema/object → flow rule id) — deferred with FlowService
- [ ] `FlowController` sub-resource endpoints — deferred; registry's existing `/api/integrations/flow` route covers list/get via the provider

## Quality

- [x] PHPUnit `tests/Unit/Service/Integration/Providers/FlowProviderTest.php` — 12 test methods covering metadata, isEnabled, list (4 paths), search filter, three health shapes
- [x] Test class marked `@group requires-app-workflowengine` so CI can skip when the upstream app isn't installed
- [x] LeafProvidersMetadataTest's `flow` data set still green (metadata didn't change)
- [x] PHPCS clean (0 errors on FlowProvider.php)
- [x] PHPMD: residual `StaticAccess` warning on `\OCP\Server::get` matches the codebase pattern (FormsProvider, FileVersioningHandler) — accepted noise, not a blocker
- [x] Psalm clean (added `OCA\WorkflowEngine\Manager` + `OCA\WorkflowEngine\Helper\ScopeContext` to the late-bound-classes suppression block in `psalm.xml`)
- [x] PHPStan clean (added `OCA\WorkflowEngine\` ignore patterns + `FlowProvider::$db` "never read" ignore in `phpstan.neon`; the `$db` property is kept for forward-compat with the future fire-events panel)

## Frontend

- [ ] `CnFlowTab.vue` — two sections (NC Flow + OR workflow rules), recent-events panel, link/unlink, "Open in NC settings" link-out — **OUT of this change's scope per proposal AC line 38**; tracked for a separate `@conduction/nextcloud-vue` follow-up
- [ ] `CnFlowCard.vue` widget surfaces (user-dashboard / app-dashboard / detail-page / single-entity) — **OUT of scope** (same reason)
- [ ] `src/integrations/builtin/flow.js` — registry registration with `referenceType: 'flow'` — **OUT of scope**; the generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` already covers the registry surface

## Acceptance verification

- [x] Provider implementation no longer references `MarkerLookupTrait` and imports `OCA\WorkflowEngine\Manager` + `OCP\WorkflowEngine\IManager`
- [x] `health()` returns `'unavailable'` when `workflowengine` is absent; tested
- [x] `list()` returns `[]` instead of throwing in all degenerate paths; tested
- [x] All metadata getters match the leaf spec (`flow` / `Automation` / `RobotOutline` / `workflow` / `workflowengine` / `link-table`); tested
