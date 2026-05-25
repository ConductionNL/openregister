# Tasks

Retroactive annotation only — every task documents existing behavior in
`lib/Service/*.php`. No runtime changes. 112 scanned methods: 76
reverse-spec'd (5 new REQs in this change + annotated against existing
capabilities/changes), 36 tagged `@spec exclude`.

## New requirements (this change)

- [x] task-1: activity-provider "Tier-2 Object Activity Read Surface" — Tier-2 filtered + cursor-paginated activity read surface (`ActivityFilterService::getActivityEntries`)
- [x] task-2: generic-integrations "Tier-2 Remote-Entity Link Service Contract" — link/create-and-link/unlink/list/picker across 5 link services, 26 methods
- [x] task-3: search-index "Adaptive Post-Import Search-Index Warmup Scheduling" — `ImportService::scheduleSolrWarmup`, `scheduleSmartSolrWarmup`, `getRecommendedWarmupMode`
- [x] task-4: audit-trail-immutable "Audit-Trail Access, Export, and Administrative Deletion Surface" — `LogService::getLogs`, `count`, `exportLogs`, `deleteLog`, `deleteLogs`
- [x] task-5: text-extraction "File and Object Chunk-Extraction Lifecycle" — `TextExtractionService::extractFile`, `extractObject`, `chunkDocument`, `discoverUntrackedFiles`, `extractPendingFiles`, `retryFailedExtractions`, `getStats`

## Annotated against existing capabilities (no new REQ)

- [x] task-6: register-i18n — `LanguageService::parseAcceptLanguageHeader`, `resolveLanguageForRegister`
- [x] task-7: row-field-level-security — `OperatorEvaluator::valueMatchesOperator`; `PropertyRbacHandler::canReadProperty`, `canUpdateProperty`, `filterReadableProperties`, `getUnauthorizedProperties`
- [x] task-8: production-observability — `MetricsService::recordMetric`, `cleanOldMetrics`
- [x] task-9: webhook-payload-mapping — `WebhookService::interceptRequest`
- [x] task-10: data-import-export — `ExportService::buildTemplateCsv`, `buildTemplateSpreadsheet`; `ImportService::softDeleteByImportJobId`
- [x] task-11: avg-verwerkingsregister — `AvgComplianceService::runAllChecks`
- [x] task-12: built-in-dashboards (b-svc-compute-profile-org) — `DashboardService::recalculateLogSizes`, `recalculateAllSizes`; `RegisterService::getSchemaObjectCounts`
- [x] task-13: organisation multi-tenancy (b-svc-compute-profile-org) — `OrganisationService::getOrganisationForNewEntity`, `joinOrganisation`, `getUserOrganisationStats`, `getOrganisationSettingsOnly`, `getDefaultOrganisationUuid`
- [x] task-14: file-actions REQ-004 — `RegisterService::createFromArray`, `updateFromArray`
- [x] task-15: file-actions REQ-001/004/005 — `FileService::getFiles`, `getFilesForEntity`, `getFileById`, `saveFile`, `updateFile`, `deleteFile`, `createEntityFolder`, `createFolder`, `createObjectFolderWithoutUpdate`, `attachTagsToFile`

## Tag counts

| Disposition | Methods |
|---|---|
| Reverse-spec'd (new REQ + existing-cap/change annotation) | 76 |
| `@spec exclude` (boilerplate / delegation / debug / util) | 36 |
| **Total scanned** | **112** |

## Excluded (boilerplate — `@spec exclude`)

- RequestScopedCache: `set`, `has`, `getMultiple`, `clear` — trivial in-memory cache plumbing (class already annotated).
- ContactService: `unlinkContactByUid` — thin (objectUuid,contactUid)→id overload delegating to already-specced `unlinkContact`.
- DashboardService: `getObjectsByRegisterChartData`, `getObjectsBySchemaChartData`, `getObjectsBySizeChartData`, `getAuditTrailStatistics`, `getAuditTrailActionDistribution`, `getMostActiveObjects` — thin try/catch mapper delegations with degraded-empty fallback.
- LanguageService: `shouldReturnAllTranslations` — trivial getter.
- LogService: `getAllLogs`, `countAllLogs` — thin mapper findAll delegations.
- PropertyRbacHandler: `isAdmin` — trivial admin-group check helper.
- ImportService: `clearCaches` — one-line internal cache reset.
- OrganisationService: `getDefaultOrganisationId`, `setDefaultOrganisationId`, `clearCache`, `clearDefaultOrganisationCache` — config getter/setter + cache-invalidation helpers.
- RegisterService: `find`, `findAll`, `delete` — pure mapper delegations.
- FileService: `checkOwnership` (guard delegation), `formatFile`, `formatFiles` (deferred FileFormattingHandler group), `findShares`, `createShareLink` (deferred FileSharingHandler group), `publishFile`, `unpublishFile`, `createObjectFilesZip` (deferred FilePublishingHandler group), `debugFindFileById`, `debugListObjectFiles` (debug helpers), `extractFileNameFromPath` (string util), `replaceWords`, `anonymizeDocument` (one-line delegations to DocumentProcessingHandler) — file-actions follow-up-pass deferrals + plumbing.

(All `@spec exclude` reasons carry a required rationale in the docblock.)
