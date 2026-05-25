# Retrofit — audit-trail-immutable

Describes observed behavior of methods clustered under `audit-trail-immutable` and adds two new REQs:

1. File-attachment audit events (`logBulkDownload`, `logFileAction`) MUST flow through the same hash-chained `AuditTrail` entity.
2. An admin-only `clearAll` surface is exposed at `DELETE /api/audit-trails/clear-all` that wipes the entire audit table. This is a documented operational escape hatch and a known drift from the spec's "Audit trail entries MUST NOT be deletable or modifiable" requirement; the retrofit captures observed behavior and flags it as drift.

Also retroactively annotates the `ClearAuditTrails.vue` admin UI methods.

## Affected code units (in-scope, retained)

- `src/modals/logs/ClearAuditTrails.vue::closeDialog` — admin UI to close the clear-all confirmation dialog.
- `src/modals/logs/ClearAuditTrails.vue::clearAuditTrails` — admin UI submitting `DELETE /api/audit-trails` with active filters; surfaces the immutability drift to operators.
- `lib/Service/File/FileAuditHandler.php::logBulkDownload` — persists a single `AuditTrail` row for ZIP bulk downloads, inheriting hash-chain integrity from `AuditTrailMapper::insert`.
- `lib/Service/File/FileAuditHandler.php::logFileAction` — persists `AuditTrail` rows for namespaced file events (`file.renamed`, `file.locked`, `file.version_restored`, …), inheriting hash-chain integrity from `AuditTrailMapper::insert`.

## DROPs (mis-clustered — owning capability noted)

- `lib/Service/Integration/BuiltinProviders/AuditTrailProvider.php::{getId,getLabel,getIcon,getRequiredApp,getStorageStrategy,getGroup,isEnabled,list,normalize}` — owned by **pluggable-integration-registry** (already annotated `@spec openspec/changes/pluggable-integration-registry/tasks.md#task-16`); IntegrationProvider contract surface, not the immutable-trail engine.
- `src/modals/logs/AuditTrailChanges.vue::closeDialog`, `src/modals/logs/AuditTrailDetails.vue::closeDialog`, `src/modals/logs/DeleteAuditTrail.vue::closeDialog`, `src/modals/objectAuditTrail/ViewObjectAuditTrail.vue::closeDialog`, `src/views/logs/AuditTrailIndex.vue::loadAuditTrails` — already annotated under `retrofit-2026-04-23-annotate-openregister`; generic UI dialog plumbing, not the immutable-trail engine.
- `lib/Service/AuthorizationAuditService.php::logSchemaAuthorizationChange` — writes to NC `LoggerInterface`, NOT the `AuditTrail` entity / hash chain; owned by **auth-system** (authorization-config audit, separate surface).
- `src/sidebars/logs/AuditTrailSideBar.vue::loadAuditTrailData` — UI consumer of the trail API; owned by **audit-trail-immutable** UI surface but is generic data-fetch plumbing (no new behavior to spec).
- `src/entities/auditTrail/auditTrail.mock.ts::{mockAuditTrailData,mockAuditTrail}` — test fixtures; no production behavior.
- `src/store/modules/auditTrail.js::useAuditTrailStore` — Pinia store factory; UI state, not the trail engine.
- `lib/Service/File/FileAuditHandler.php::__construct`, `getCurrentUserId` — constructor / generic user-id getter helpers, no spec-relevant behavior.
- `lib/Controller/AuditTrailController.php::requireAdmin` — generic admin gate helper already annotated under `retrofit-2026-04-23-annotate-openregister/task-8`.

## Drift (flagged, not fixed)

- **D-1**: `AuditTrailMapper::clearAllLogs()` (called from `AuditTrailController::clearAll` → `DELETE /api/audit-trails/clear-all`) wipes the entire audit table. The mapper's docblock explicitly says "not just expired ones." This contradicts the spec's REQ "Audit trail entries MUST NOT be deletable or modifiable." It is admin-only and defense-in-depth-gated by `requireAdmin()`, but a privileged user can still destroy the chain of trust required for AVG/GDPR Art 30 reviews. Captured here as observed behavior (REQ-IMMU-002); fixing is a future spec change, not a retrofit.

## Approach

Source: `/tmp/or-scan/rspec-cluster-audit-trail-immutable.json` (26 methods, 13 files).

The retrofit follows the playbook for `--extend` mode: two new REQs (REQ-IMMU-001 for file-attachment audit events, REQ-IMMU-002 to acknowledge the admin clear-all drift), plus `@spec` annotations on the four in-scope methods listed above. The drift on `clearAll` is intentionally captured rather than ignored — the spec must reflect what the code does, even when that's a known compliance gap.
