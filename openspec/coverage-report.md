# Coverage Report — openregister

Generated: 2026-04-23T10:51:00Z
Branch: feature/reverse-spec
Scanner: opsx-coverage-scan v1

## Scope

| Input | Count |
|---|---|
| Spec capabilities | 31 |
| Spec REQs (positional, derived from `### Requirement:` headings) | 384 |
| In-flight change-delta REQs | 483 |
| PHP files scanned (`lib/`, excl. Migration/Db) | 436 |
| PHP methods scanned | 3703 |
| Frontend files scanned (`src/`, excl. tests/bootstrap) | 267 |
| Frontend units scanned (functions/methods/components) | 2244 |

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 0 | — (already tagged) |
| plumbing | 782 | — (never tagged) |
| 1 — REQ matched | 649 | `/opsx-annotate openregister` |
| 2a — existing capability, no REQ | 1333 (27 clusters) | `/opsx-reverse-spec openregister --extend <cap>` |
| 2b — no capability owner | 3249 (26 clusters) | `/opsx-reverse-spec openregister --cluster <name>` |
| 3a — REQ possibly broken (history reference) | 219 | Manual triage — many are false positives |
| 3b — REQ never implemented | 39 | Mark deferred or remove |
| 4 — ADR conformance | 752 findings across 3 rules | Follow-up issue |

**REQ coverage**: 126/384 REQs have at least one Bucket 1 code hit (32%).

## Bucket 1 — Ready to annotate (via ghost change `retrofit-annotate-openregister-2026-04-23`)

### capability: archival-destruction-workflow (23 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/BackgroundJob/DestructionCheckJob.php` | `sendReviewNotification` | archival-destruction-workflow#REQ-002 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/BackgroundJob/DestructionExecutionJob.php` | `run` | archival-destruction-workflow#REQ-002 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/BackgroundJob/DestructionExecutionJob.php` | `notifySkippedHolds` | archival-destruction-workflow#REQ-006 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/ArchivalController.php` | `listDestructionLists` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/ArchivalController.php` | `getDestructionList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/ArchivalController.php` | `approveDestructionList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/ArchivalController.php` | `rejectDestructionList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/ArchivalController.php` | `listLegalHolds` | archival-destruction-workflow#REQ-006 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/ArchivalController.php` | `listCertificates` | archival-destruction-workflow#REQ-002 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Archival/ArchiefactiedatumCalculator.php` | `calculate` | archival-destruction-workflow#REQ-007 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Archival/DestructionService.php` | `findEligibleObjects` | archival-destruction-workflow#REQ-001 | 0.97 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Archival/DestructionService.php` | `createDestructionList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Archival/DestructionService.php` | `approveList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Archival/DestructionService.php` | `handlePartialApproval` | archival-destruction-workflow#REQ-003 | 0.99 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Archival/DestructionService.php` | `rejectList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Archival/DestructionService.php` | `executeDestruction` | archival-destruction-workflow#REQ-002 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Archival/DestructionService.php` | `generateCertificate` | archival-destruction-workflow#REQ-005 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Archival/DestructionService.php` | `validateDestructionList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/ArchivalService.php` | `generateDestructionList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/ArchivalService.php` | `approveDestructionList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/ArchivalService.php` | `rejectFromDestructionList` | archival-destruction-workflow#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/ArchivalController.php` | `checkArchivistRole` | archival-destruction-workflow#REQ-002 | 0.85 | no | pass-b-inherit(path-capability-match+name-keyword+doc-keywords) |
| `lib/Service/Archival/DestructionService.php` | `getCurrentUserId` | archival-destruction-workflow#REQ-002 | 1.0 | no | pass-b-inherit(path-capability-match+multi-name-keyword+doc-keywords) |

### capability: audit-trail-immutable (117 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/AuditTrailController.php` | `extractRequestParameters` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `lib/Controller/AuditTrailController.php` | `index` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/AuditTrailController.php` | `show` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/AuditTrailController.php` | `update` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/AuditTrailController.php` | `objects` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `lib/Controller/AuditTrailController.php` | `export` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/AuditTrailController.php` | `destroy` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/AuditTrailController.php` | `destroyMultiple` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/AuditTrailController.php` | `clearAll` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/AuditTrailController.php` | `verify` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/AuditTrailController.php` | `verwerkingsregister` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `lib/Controller/AuditTrailController.php` | `inzageverzoek` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `lib/Service/AuditHashService.php` | `getGenesisHash` | audit-trail-immutable#REQ-003 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Service/AuditHashService.php` | `getCanonicalJson` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/AuditHashService.php` | `computeHash` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/AuditHashService.php` | `getLastHash` | audit-trail-immutable#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/AuditHashService.php` | `verifyChain` | audit-trail-immutable#REQ-003 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Service/AuditHashService.php` | `getHashBefore` | audit-trail-immutable#REQ-003 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Service/AuditHashService.php` | `mapRowToEntity` | audit-trail-immutable#REQ-002 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Object/AuditHandler.php` | `prepareFilters` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `src/entities/auditTrail/auditTrail.mock.ts` | `mockAuditTrailData` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/entities/auditTrail/auditTrail.mock.ts` | `mockAuditTrail` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `hasChanges` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `changes` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `isTableChanges` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `closeDialog` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `formatDate` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `formatChanges` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `formatValue` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `isObject` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `getChangeType` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `getChangeTypeLabel` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `copyChanges` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailChanges.vue` | `viewFullDetails` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailDetails.vue` | `hasChanges` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailDetails.vue` | `additionalFields` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailDetails.vue` | `closeDialog` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailDetails.vue` | `formatDate` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailDetails.vue` | `formatChanges` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| `src/modals/logs/AuditTrailDetails.vue` | `formatJson` | audit-trail-immutable#REQ-003 | 0.85 | no | path-capability-match+multi-name-keyword |
| _... 77 more entries in JSON sidecar_ | | | | | |

### capability: data-import-export (79 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/BulkController.php` | `validateSchema` | data-import-export#REQ-003 | 0.97 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Configuration/ExportHandler.php` | `exportConfig` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Configuration/ExportHandler.php` | `exportRegister` | data-import-export#REQ-016 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Configuration/ImportHandler.php` | `__construct` | data-import-export#REQ-016 | 0.71 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Configuration/ImportHandler.php` | `importRegister` | data-import-export#REQ-016 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Configuration/ImportHandler.php` | `importMapping` | data-import-export#REQ-011 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Configuration/ImportHandler.php` | `handleDuplicateRegisterError` | data-import-export#REQ-016 | 0.86 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Configuration/ImportHandler.php` | `getDuplicateRegisterInfo` | data-import-export#REQ-005 | 0.72 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Configuration/ImportHandler.php` | `handleDuplicateSchemaError` | data-import-export#REQ-013 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Configuration/ImportHandler.php` | `getDuplicateSchemaInfo` | data-import-export#REQ-013 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Configuration/ImportHandler.php` | `importSchema` | data-import-export#REQ-013 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Configuration/ImportHandler.php` | `importFromJson` | data-import-export#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Configuration/ImportHandler.php` | `importFromApp` | data-import-export#REQ-001 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Configuration/ImportHandler.php` | `importFromFilePath` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Configuration/ImportHandler.php` | `importSeedData` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/ExportService.php` | `exportToExcel` | data-import-export#REQ-007 | 0.94 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/ExportService.php` | `fetchObjectsForExport` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/ExportService.php` | `resolveUuidNameMap` | data-import-export#REQ-009 | 1.0 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/ExportService.php` | `getHeaders` | data-import-export#REQ-015 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/ExportService.php` | `resolveUuidsToNames` | data-import-export#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/ImportService.php` | `importFromExcel` | data-import-export#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/ImportService.php` | `processMultiSchemaSpreadsheetAsync` | data-import-export#REQ-013 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/ImportService.php` | `transformExcelRowToObject` | data-import-export#REQ-001 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/ImportService.php` | `buildColumnMapping` | data-import-export#REQ-011 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/ImportService.php` | `getSchemaBySlug` | data-import-export#REQ-013 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/ImportService.php` | `transformObjectBySchema` | data-import-export#REQ-003 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/ImportService.php` | `validateObjectProperties` | data-import-export#REQ-003 | 0.72 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Object/ExportHandler.php` | `import` | data-import-export#REQ-014 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Service/ImportService.php` | `isUserAdmin` | data-import-export#REQ-001 | 0.75 | yes | pass-b-inherit(path-capability-match+name-keyword+NEEDS-REVIEW) |
| `src/modals/configuration/ExportConfiguration.vue` | `closeModal` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ExportConfiguration.vue` | `exportConfiguration` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ExportConfiguration.vue` | `configTitle` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ExportConfiguration.vue` | `isValid` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ExportConfiguration.vue` | `errorMessage` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ImportConfiguration.vue` | `closeModal` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ImportConfiguration.vue` | `resetForm` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ImportConfiguration.vue` | `searchConfigurations` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ImportConfiguration.vue` | `importDiscoveredConfiguration` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ImportConfiguration.vue` | `fetchBranches` | data-import-export#REQ-016 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/configuration/ImportConfiguration.vue` | `fetchConfigurationFiles` | data-import-export#REQ-004 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| _... 39 more entries in JSON sidecar_ | | | | | |

### capability: deep-link-registry (8 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Dto/DeepLinkRegistration.php` | `resolveUrl` | deep-link-registry#REQ-002 | 0.89 | no | path-capability-match+multi-name-keyword |
| `lib/Service/DeepLinkRegistryService.php` | `register` | deep-link-registry#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/DeepLinkRegistryService.php` | `resolve` | deep-link-registry#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/DeepLinkRegistryService.php` | `resolveUrl` | deep-link-registry#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/DeepLinkRegistryService.php` | `resolveIcon` | deep-link-registry#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/DeepLinkRegistryService.php` | `ensureIdMaps` | deep-link-registry#REQ-009 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Service/DeepLinkRegistryService.php` | `hasRegistrations` | deep-link-registry#REQ-009 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Service/DeepLinkRegistryService.php` | `reset` | deep-link-registry#REQ-009 | 0.9 | no | path-capability-match+multi-name-keyword |

### capability: edepot-transfer (28 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/DashboardController.php` | `getAuditTrailActionChart` | edepot-transfer#REQ-008 | 0.83 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/Settings/EdepotSettingsController.php` | `getEdepotSettings` | edepot-transfer#REQ-004 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Controller/Settings/EdepotSettingsController.php` | `updateEdepotSettings` | edepot-transfer#REQ-004 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Controller/Settings/EdepotSettingsController.php` | `testEdepotConnection` | edepot-transfer#REQ-004 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Controller/TransferController.php` | `index` | edepot-transfer#REQ-003 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Controller/TransferController.php` | `show` | edepot-transfer#REQ-003 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Controller/TransferController.php` | `create` | edepot-transfer#REQ-003 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Cron/TransferCheckJob.php` | `isEdepotConfigured` | edepot-transfer#REQ-002 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Cron/TransferCheckJob.php` | `findEligibleObjects` | edepot-transfer#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/DashboardService.php` | `getAuditTrailActionChartData` | edepot-transfer#REQ-008 | 0.83 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Edepot/EdepotTransferService.php` | `executeTransfer` | edepot-transfer#REQ-003 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Edepot/EdepotTransferService.php` | `logTransferInitiated` | edepot-transfer#REQ-008 | 1.0 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Edepot/EdepotTransferService.php` | `logObjectTransferred` | edepot-transfer#REQ-008 | 1.0 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Edepot/EdepotTransferService.php` | `logTransferFailed` | edepot-transfer#REQ-008 | 1.0 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Edepot/MdtoXmlGenerator.php` | `generate` | edepot-transfer#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Edepot/SipPackageBuilder.php` | `generateMetsXml` | edepot-transfer#REQ-001 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Edepot/SipPackageBuilder.php` | `generatePremisXml` | edepot-transfer#REQ-001 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Edepot/TransferListService.php` | `createTransferList` | edepot-transfer#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Edepot/TransferListService.php` | `approveTransferList` | edepot-transfer#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Edepot/TransferListService.php` | `rejectTransferList` | edepot-transfer#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Edepot/TransferListService.php` | `excludeObjects` | edepot-transfer#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Edepot/TransferListService.php` | `getObjectsOnActiveTransferLists` | edepot-transfer#REQ-003 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Edepot/TransferListService.php` | `notifyArchivists` | edepot-transfer#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Edepot/Transport/TransportInterface.php` | `testConnection` | edepot-transfer#REQ-004 | 0.7 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/TmloService.php` | `generateMdtoXml` | edepot-transfer#REQ-001 | 0.83 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/TmloService.php` | `generateBatchMdtoXml` | edepot-transfer#REQ-001 | 0.83 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/Settings/EdepotSettingsController.php` | `resolveTransport` | edepot-transfer#REQ-004 | 0.75 | yes | pass-b-inherit(path-capability-match+name-keyword+NEEDS-REVIEW) |
| `lib/Service/Edepot/MdtoXmlGenerator.php` | `validateRequiredFields` | edepot-transfer#REQ-001 | 1.0 | no | pass-b-inherit(path-capability-match+multi-name-keyword+doc-keywords) |

### capability: event-driven-architecture (19 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/AppInfo/Application.php` | `registerEventListeners` | event-driven-architecture#REQ-003 | 0.75 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Event/ToolRegistrationEvent.php` | `registerTool` | event-driven-architecture#REQ-015 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/EventListener/AbstractNodesFolderEventListener.php` | `handle` | event-driven-architecture#REQ-010 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/EventListener/AbstractNodesFolderEventListener.php` | `handleNodeCopied` | event-driven-architecture#REQ-010 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/EventListener/AbstractNodesFolderEventListener.php` | `handleNodeRenamed` | event-driven-architecture#REQ-010 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/EventListener/SolrEventListener.php` | `handle` | event-driven-architecture#REQ-010 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/EventListener/SolrEventListener.php` | `handleObjectCreated` | event-driven-architecture#REQ-010 | 0.87 | no | path-capability-match+multi-name-keyword |
| `lib/EventListener/SolrEventListener.php` | `handleObjectUpdated` | event-driven-architecture#REQ-010 | 0.87 | no | path-capability-match+multi-name-keyword |
| `lib/EventListener/SolrEventListener.php` | `handleObjectDeleted` | event-driven-architecture#REQ-010 | 0.87 | no | path-capability-match+multi-name-keyword |
| `lib/EventListener/SolrEventListener.php` | `handleSchemaCreated` | event-driven-architecture#REQ-015 | 0.92 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/EventListener/SolrEventListener.php` | `handleSchemaUpdated` | event-driven-architecture#REQ-015 | 0.92 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/EventListener/SolrEventListener.php` | `handleSchemaDeleted` | event-driven-architecture#REQ-015 | 0.92 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/EventListener/SolrEventListener.php` | `schemaFieldsChanged` | event-driven-architecture#REQ-015 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/EventListener/SolrEventListener.php` | `triggerSchemaReindex` | event-driven-architecture#REQ-015 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/Listener/ActivityEventListener.php` | `handle` | event-driven-architecture#REQ-010 | 0.87 | no | path-capability-match+multi-name-keyword |
| `lib/Service/CalendarEventService.php` | `createEvent` | event-driven-architecture#REQ-015 | 0.78 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/CalendarEventService.php` | `extractOpenRegisterProperties` | event-driven-architecture#REQ-004 | 0.87 | no | path-capability-match+multi-name-keyword |
| `lib/Service/CalendarEventService.php` | `findUserCalendar` | event-driven-architecture#REQ-015 | 0.78 | yes | pass-b-inherit(path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/CalendarEventService.php` | `escapeIcalText` | event-driven-architecture#REQ-015 | 0.78 | yes | pass-b-inherit(path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW) |

### capability: faceting-configuration (51 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/Settings/SolrSettingsController.php` | `getSolrFacetConfiguration` | faceting-configuration#REQ-011 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/Settings/SolrSettingsController.php` | `updateSolrFacetConfiguration` | faceting-configuration#REQ-011 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/Settings/SolrSettingsController.php` | `getSolrFacetConfigWithDiscovery` | faceting-configuration#REQ-010 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/Settings/SolrSettingsController.php` | `updateSolrFacetConfigWithDiscovery` | faceting-configuration#REQ-010 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Index/Backends/Solr/SolrFacetProcessor.php` | `getRawSolrFieldsForFacetConfiguration` | faceting-configuration#REQ-011 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Index/FacetBuilder.php` | `getRawSolrFieldsForFacetConfiguration` | faceting-configuration#REQ-011 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Object/FacetHandler.php` | `getFacetsForObjects` | faceting-configuration#REQ-011 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Object/FacetHandler.php` | `getFacetableFields` | faceting-configuration#REQ-010 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `getMetadataFacetableFields` | faceting-configuration#REQ-010 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Object/FacetHandler.php` | `getFacetCount` | faceting-configuration#REQ-016 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `calculateFacetsWithFallback` | faceting-configuration#REQ-011 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Object/FacetHandler.php` | `generateNonAggregatedFacetKey` | faceting-configuration#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `transformFacetsToStandardFormat` | faceting-configuration#REQ-012 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `getMetadataDefinitions` | faceting-configuration#REQ-002 | 0.97 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `transformMetadataFacets` | faceting-configuration#REQ-007 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `transformNonAggregatedFacet` | faceting-configuration#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `transformAggregatedFacet` | faceting-configuration#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `buildFacetEntry` | faceting-configuration#REQ-012 | 1.0 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `formatFieldTitle` | faceting-configuration#REQ-012 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Object/FacetHandler.php` | `getSchemasForQuery` | faceting-configuration#REQ-016 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Object/FacetHandler.php` | `normalizeFacetConfig` | faceting-configuration#REQ-010 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Object/FacetHandler.php` | `getFacetableFieldsFromSchemas` | faceting-configuration#REQ-010 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Object/FacetHandler.php` | `getDefaultMetadataFacets` | faceting-configuration#REQ-007 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/FacetHandler.php` | `determineFacetTypeFromProperty` | faceting-configuration#REQ-002 | 0.97 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/ObjectServiceFacetExample.php` | `legacyFacetingApproach` | faceting-configuration#REQ-001 | 0.7 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/ObjectServiceFacetExample.php` | `paginatedSearchWithFacets` | faceting-configuration#REQ-011 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Object/ObjectServiceFacetExample.php` | `analyticsDashboardFacets` | faceting-configuration#REQ-011 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Object/ObjectServiceFacetExample.php` | `performanceComparison` | faceting-configuration#REQ-016 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Object/ObjectServiceFacetExample.php` | `transformFacetsForFrontend` | faceting-configuration#REQ-015 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/ObjectServiceFacetExample.php` | `calculatePerformanceImprovement` | faceting-configuration#REQ-016 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Schemas/FacetCacheHandler.php` | `cacheFacetableFields` | faceting-configuration#REQ-010 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Schemas/FacetCacheHandler.php` | `invalidateForSchemaChange` | faceting-configuration#REQ-004 | 0.88 | no | path-capability-match+name-keyword |
| `lib/Service/Settings/SolrSettingsHandler.php` | `getSolrFacetConfiguration` | faceting-configuration#REQ-011 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Settings/SolrSettingsHandler.php` | `updateSolrFacetConfiguration` | faceting-configuration#REQ-011 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Settings/SolrSettingsHandler.php` | `validateFacetConfiguration` | faceting-configuration#REQ-011 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/FacetHandler.php` | `countFacetResults` | faceting-configuration#REQ-011 | 0.95 | no | pass-b-inherit(path-capability-match+multi-name-keyword) |
| `lib/Service/Schemas/FacetCacheHandler.php` | `setCachedFacetData` | faceting-configuration#REQ-010 | 0.8 | yes | pass-b-inherit(path-capability-match+name-keyword+NEEDS-REVIEW) |
| `src/components/FacetComponent.vue` | `formatDate` | faceting-configuration#REQ-012 | 0.98 | no | path-capability-match+multi-name-keyword |
| `src/components/FacetComponent.vue` | `termsFacetableFields` | faceting-configuration#REQ-010 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/components/FacetComponent.vue` | `nonTermsObjectFieldFacets` | faceting-configuration#REQ-011 | 0.9 | no | path-capability-match+multi-name-keyword |
| _... 11 more entries in JSON sidecar_ | | | | | |

### capability: graphql-api (33 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/GraphQLController.php` | `explorer` | graphql-api#REQ-016 | 0.82 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/GraphQL/GraphQLResolver.php` | `resolveAuditTrail` | graphql-api#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/GraphQL/GraphQLResolver.php` | `checkSchemaPermission` | graphql-api#REQ-007 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/GraphQL/GraphQLResolver.php` | `filterProperties` | graphql-api#REQ-008 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/GraphQL/GraphQLResolver.php` | `findRegisterForSchema` | graphql-api#REQ-013 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/GraphQL/GraphQLResolver.php` | `reset` | graphql-api#REQ-018 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/GraphQL/GraphQLService.php` | `getSchema` | graphql-api#REQ-013 | 0.94 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/GraphQL/QueryComplexityAnalyzer.php` | `analyze` | graphql-api#REQ-010 | 0.87 | no | path-capability-match+multi-name-keyword |
| `lib/Service/GraphQL/QueryComplexityAnalyzer.php` | `analyzeSelectionSet` | graphql-api#REQ-010 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/Service/GraphQL/QueryComplexityAnalyzer.php` | `getListMultiplier` | graphql-api#REQ-010 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/Service/GraphQL/QueryComplexityAnalyzer.php` | `getResolverCost` | graphql-api#REQ-010 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator/CompositionHandler.php` | `applyComposition` | graphql-api#REQ-012 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator/CompositionHandler.php` | `resolveCompositionRefs` | graphql-api#REQ-012 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` | `mapPropertyToGraphQLType` | graphql-api#REQ-013 | 0.79 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` | `mapPropertyToInputType` | graphql-api#REQ-012 | 0.7 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` | `getAuditTrailType` | graphql-api#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` | `getListArgs` | graphql-api#REQ-010 | 0.72 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` | `getPropertyAuthDescriptions` | graphql-api#REQ-008 | 0.82 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator.php` | `generate` | graphql-api#REQ-013 | 0.71 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator.php` | `buildSchemaFields` | graphql-api#REQ-010 | 0.72 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/GraphQL/SchemaGenerator.php` | `buildQueryFields` | graphql-api#REQ-010 | 0.87 | no | path-capability-match+multi-name-keyword |
| `lib/Service/GraphQL/SchemaGenerator.php` | `getObjectType` | graphql-api#REQ-013 | 0.71 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/GraphQL/SubscriptionService.php` | `filterEventStream` | graphql-api#REQ-013 | 0.71 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Index/SchemaHandler.php` | `mirrorSchemas` | graphql-api#REQ-001 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/GraphQLController.php` | `getGraphiQLHtml` | graphql-api#REQ-016 | 0.82 | yes | pass-b-inherit(path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/GraphQL/SchemaGenerator/CompositionHandler.php` | `applyAllOf` | graphql-api#REQ-012 | 0.75 | yes | pass-b-inherit(path-capability-match+name-keyword+NEEDS-REVIEW) |
| `lib/Service/GraphQL/SchemaGenerator/CompositionHandler.php` | `applyOneOf` | graphql-api#REQ-012 | 0.75 | yes | pass-b-inherit(path-capability-match+name-keyword+NEEDS-REVIEW) |
| `lib/Service/GraphQL/SchemaGenerator/CompositionHandler.php` | `applyAnyOf` | graphql-api#REQ-012 | 0.75 | yes | pass-b-inherit(path-capability-match+name-keyword+NEEDS-REVIEW) |
| `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` | `getSortInputType` | graphql-api#REQ-010 | 0.72 | yes | pass-b-inherit(path-capability-match+name-keyword+NEEDS-REVIEW) |
| `lib/Service/GraphQL/SchemaGenerator.php` | `initScalars` | graphql-api#REQ-013 | 0.71 | yes | pass-b-inherit(path-capability-match+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/GraphQL/SchemaGenerator.php` | `initHandlers` | graphql-api#REQ-013 | 0.71 | yes | pass-b-inherit(path-capability-match+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/GraphQL/SchemaGenerator.php` | `resolveRef` | graphql-api#REQ-013 | 0.71 | yes | pass-b-inherit(path-capability-match+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/GraphQL/SchemaGenerator.php` | `toFieldName` | graphql-api#REQ-010 | 0.72 | yes | pass-b-inherit(path-capability-match+name-keyword+NEEDS-REVIEW) |

### capability: linked-entity-types (16 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/LinkedEntityController.php` | `removeObjectLink` | linked-entity-types#REQ-013 | 0.9 | no | path-capability-match+multi-name-keyword |
| `lib/Controller/LinkedEntityController.php` | `reverseLookup` | linked-entity-types#REQ-008 | 0.98 | no | path-capability-match+multi-name-keyword |
| `lib/Service/LinkedEntityService.php` | `addLink` | linked-entity-types#REQ-004 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/LinkedEntityService.php` | `removeLink` | linked-entity-types#REQ-013 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/LinkedEntityService.php` | `addLinkToRegister` | linked-entity-types#REQ-004 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/LinkedEntityService.php` | `addLinkToSchema` | linked-entity-types#REQ-004 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/LinkedEntityService.php` | `reverseLookup` | linked-entity-types#REQ-008 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/LinkedEntityService.php` | `scanMagicTables` | linked-entity-types#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/LinkedEntityService.php` | `scanEntityTables` | linked-entity-types#REQ-004 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/CacheHandler.php` | `loadNamesFromMagicTables` | linked-entity-types#REQ-003 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/CacheHandler.php` | `batchLoadNamesFromMagicTables` | linked-entity-types#REQ-003 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/SaveObject/LinkedEntityPropertyHandler.php` | `extractAndPopulate` | linked-entity-types#REQ-004 | 1.0 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Object/SaveObject/LinkedEntityPropertyHandler.php` | `mergeIntoMetadataColumn` | linked-entity-types#REQ-004 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Schemas/SchemaCacheHandler.php` | `cacheSchemaConfiguration` | linked-entity-types#REQ-001 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/LinkedEntityService.php` | `validateType` | linked-entity-types#REQ-004 | 0.9 | no | pass-b-inherit(path-capability-match+name-keyword+doc-keywords) |
| `lib/Service/Schemas/SchemaCacheHandler.php` | `setCachedData` | linked-entity-types#REQ-001 | 0.78 | yes | pass-b-inherit(multi-name-keyword+doc-keywords+NEEDS-REVIEW) |

### capability: mail-sidebar (37 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Listener/MailAppScriptListener.php` | `handle` | mail-sidebar#REQ-004 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/Listener/MailAppScriptListener.php` | `userHasRegisterAccess` | mail-sidebar#REQ-004 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/EmailService.php` | `unlinkEmail` | mail-sidebar#REQ-006 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/EmailService.php` | `fetchMailMessage` | mail-sidebar#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `src/mail-sidebar/MailSidebar.vue` | `toggleCollapsed` | mail-sidebar#REQ-008 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/MailSidebar.vue` | `String` | mail-sidebar#REQ-008 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/MailSidebar.vue` | `onLinked` | mail-sidebar#REQ-005 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/mail-sidebar/api/emailLinks.js` | `fetchLinkedObjects` | mail-sidebar#REQ-005 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/api/emailLinks.js` | `createQuickLink` | mail-sidebar#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `src/mail-sidebar/api/emailLinks.js` | `deleteEmailLink` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ActionsTab.vue` | `objectName` | mail-sidebar#REQ-006 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ActionsTab.vue` | `loadSchemas` | mail-sidebar#REQ-006 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ActionsTab.vue` | `loadInitialResults` | mail-sidebar#REQ-006 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ActionsTab.vue` | `showResults` | mail-sidebar#REQ-006 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ActionsTab.vue` | `debounceSearch` | mail-sidebar#REQ-006 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ActionsTab.vue` | `searchObjects` | mail-sidebar#REQ-006 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ActionsTab.vue` | `linkObject` | mail-sidebar#REQ-006 | 1.0 | no | path-capability-match+multi-name-keyword |
| `src/mail-sidebar/components/EntitiesTab.vue` | `messageId` | mail-sidebar#REQ-001 | 0.7 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `onSearchInput` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `doSearch` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `isAlreadyLinked` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `selectResult` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `resultAriaLabel` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `confirmLink` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `close` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `reset` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | `visible` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ObjectCard.vue` | `deepLink` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ObjectsTab.vue` | `unlinkObject` | mail-sidebar#REQ-006 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/components/ObjectsTab.vue` | `messageId` | mail-sidebar#REQ-001 | 0.7 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/composables/useEmailLinks.js` | `loadForMessage` | mail-sidebar#REQ-001 | 0.7 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/composables/useEmailLinks.js` | `linkObject` | mail-sidebar#REQ-003 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar/composables/useEmailLinks.js` | `unlinkObject` | mail-sidebar#REQ-006 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar.js` | `findMountPoint` | mail-sidebar#REQ-008 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/mail-sidebar.js` | `createContainer` | mail-sidebar#REQ-008 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar.js` | `mountSidebar` | mail-sidebar#REQ-008 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/mail-sidebar.js` | `tryMount` | mail-sidebar#REQ-008 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |

### capability: mariadb-ci-matrix (1 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Service/MigrationService.php` | `migrateToBlobStorage` | mariadb-ci-matrix#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |

### capability: mcp-discovery (17 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/McpController.php` | `discover` | mcp-discovery#REQ-001 | 1.0 | no | path-capability-match+doc-keywords |
| `lib/Controller/McpController.php` | `discoverCapability` | mcp-discovery#REQ-002 | 1.0 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `getCapabilityHref` | mcp-discovery#REQ-001 | 0.98 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `getCatalog` | mcp-discovery#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `getCapabilityDetail` | mcp-discovery#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildRegistersCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildSchemasCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildObjectsCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildSearchCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildFilesCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildAuditCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildBulkCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildWebhooksCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildChatCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/McpDiscoveryService.php` | `buildViewsCapability` | mcp-discovery#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/ToolRegistry.php` | `registerTool` | mcp-discovery#REQ-014 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Tool/AbstractTool.php` | `formatError` | mcp-discovery#REQ-015 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |

### capability: mock-registers (10 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Service/File/FolderManagementHandler.php` | `getOpenRegisterUserFolder` | mock-registers#REQ-005 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Index/ObjectHandler.php` | `convertToOpenRegisterFormat` | mock-registers#REQ-005 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/ValidateObject.php` | `transformOpenRegisterObjectConfigurations` | mock-registers#REQ-005 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/ValidateObject.php` | `transformPropertyForOpenRegister` | mock-registers#REQ-005 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/ValidateObject.php` | `transformObjectPropertyForOpenRegister` | mock-registers#REQ-005 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/File/FolderManagementHandler.php` | `getUser` | mock-registers#REQ-005 | 0.7 | yes | pass-b-inherit(multi-name-keyword+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/Object/ValidateObject.php` | `extractObjectConfigurationHandling` | mock-registers#REQ-005 | 0.7 | yes | pass-b-inherit(multi-name-keyword+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/Object/ValidateObject.php` | `getMixedValue` | mock-registers#REQ-005 | 0.7 | yes | pass-b-inherit(multi-name-keyword+doc-keywords+NEEDS-REVIEW) |
| `src/entities/register/register.mock.ts` | `mockRegisterData` | mock-registers#REQ-001 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/entities/register/register.mock.ts` | `mockRegister` | mock-registers#REQ-001 | 0.9 | no | path-capability-match+multi-name-keyword |

### capability: rbac-scopes (25 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/AppInfo/Application.php` | `registerCacheAndFileHandlers` | rbac-scopes#REQ-005 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/AuthorizationAuditService.php` | `logRegisterAuthorizationChange` | rbac-scopes#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/AuthorizationAuditService.php` | `logRoleDefinitionChange` | rbac-scopes#REQ-005 | 0.88 | no | path-capability-match+name-keyword |
| `lib/Service/DashboardService.php` | `getRegistersWithSchemas` | rbac-scopes#REQ-016 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/PermissionHandler.php` | `filterObjectsForPermissions` | rbac-scopes#REQ-014 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/PermissionHandler.php` | `filterUuidsForPermissions` | rbac-scopes#REQ-014 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/PermissionHandler.php` | `getRegisterForSchema` | rbac-scopes#REQ-001 | 0.97 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/PermissionHandler.php` | `getRegisterAuthorization` | rbac-scopes#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/PermissionHandler.php` | `expandRoles` | rbac-scopes#REQ-002 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/PermissionHandler.php` | `getRoleDefinitionsForSchema` | rbac-scopes#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Object/SaveObjects.php` | `loadRegisterWithCache` | rbac-scopes#REQ-005 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/RequestScopedCache.php` | `get` | rbac-scopes#REQ-005 | 0.98 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Settings/ConfigurationSettingsHandler.php` | `isMultiTenancyEnabled` | rbac-scopes#REQ-014 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/PermissionHandler.php` | `getRegisterConfiguration` | rbac-scopes#REQ-003 | 1.0 | no | pass-b-inherit(path-capability-match+multi-name-keyword+doc-keywords) |
| `src/components/RbacTable.vue` | `updatePermission` | rbac-scopes#REQ-006 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/PermissionMatrix.vue` | `getRegisterSchemas` | rbac-scopes#REQ-008 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RbacConfiguration.vue` | `showRebaseDialog` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RbacConfiguration.vue` | `saveSettings` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RbacConfiguration.vue` | `get` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RbacConfiguration.vue` | `set` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RbacConfiguration.vue` | `groupOptions` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RbacConfiguration.vue` | `userOptions` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RbacConfiguration.vue` | `loading` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RbacConfiguration.vue` | `saving` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RbacConfiguration.vue` | `rebasing` | rbac-scopes#REQ-012 | 0.9 | no | path-capability-match+multi-name-keyword |

### capability: retention-management (30 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/BackgroundJob/DestructionCheckJob.php` | `sendPreDestructionNotifications` | retention-management#REQ-008 | 0.95 | no | multi-name-keyword+doc-keywords |
| `lib/Controller/RetentionController.php` | `checkDualApprovalRequired` | retention-management#REQ-005 | 0.82 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/Settings/ConfigurationSettingsController.php` | `getRetentionSettings` | retention-management#REQ-009 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/Settings/ConfigurationSettingsController.php` | `updateRetentionSettings` | retention-management#REQ-009 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/ArchivalService.php` | `setRetentionMetadata` | retention-management#REQ-001 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/RetentionService.php` | `applyArchivalMetadata` | retention-management#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/RetentionService.php` | `calculateArchiefactiedatum` | retention-management#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/RetentionService.php` | `validateNotImmutable` | retention-management#REQ-001 | 0.72 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/RetentionService.php` | `getObjectsOnPendingDestructionLists` | retention-management#REQ-004 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/RetentionService.php` | `generateDestructionCertificate` | retention-management#REQ-004 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `getObjectSettingsOnly` | retention-management#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `updateObjectSettingsOnly` | retention-management#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `getRetentionSettingsOnly` | retention-management#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `updateRetentionSettingsOnly` | retention-management#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `getArchivalSettingsOnly` | retention-management#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `updateArchivalSettingsOnly` | retention-management#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `getArchivalDefaults` | retention-management#REQ-009 | 0.88 | no | path-capability-match+name-keyword |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `getVersionInfoOnly` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Settings/ObjectRetentionHandler.php` | `convertToBoolean` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `showRebaseDialog` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `saveSettings` | retention-management#REQ-009 | 0.98 | no | path-capability-match+multi-name-keyword |
| `src/views/settings/sections/RetentionConfiguration.vue` | `get` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `set` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `loading` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `saving` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `rebasing` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `retentionStatusClass` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `retentionStatusTextClass` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `retentionStatusMessage` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/settings/sections/RetentionConfiguration.vue` | `formatRetentionPeriod` | retention-management#REQ-009 | 0.83 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |

### capability: schema-hooks (14 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/BackgroundJob/HookRetryJob.php` | `run` | schema-hooks#REQ-011 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/Service/HookExecutor.php` | `executeHooks` | schema-hooks#REQ-001 | 0.88 | no | path-capability-match+name-keyword |
| `lib/Service/HookExecutor.php` | `loadHooks` | schema-hooks#REQ-001 | 0.88 | no | path-capability-match+name-keyword |
| `lib/Service/HookExecutor.php` | `evaluateFilterCondition` | schema-hooks#REQ-007 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/HookExecutor.php` | `buildCloudEventPayload` | schema-hooks#REQ-002 | 0.98 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/HookExecutor.php` | `executeAsyncHook` | schema-hooks#REQ-010 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/HookExecutor.php` | `processWorkflowResult` | schema-hooks#REQ-004 | 0.88 | no | path-capability-match+name-keyword |
| `lib/Service/HookExecutor.php` | `determineFailureMode` | schema-hooks#REQ-006 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/HookExecutor.php` | `applyFailureMode` | schema-hooks#REQ-006 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/HookExecutor.php` | `scheduleRetryJob` | schema-hooks#REQ-011 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/HookExecutor.php` | `logHookExecution` | schema-hooks#REQ-005 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/WorkflowEngineRegistry.php` | `discoverEngines` | schema-hooks#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/HookExecutor.php` | `resolveEventType` | schema-hooks#REQ-001 | 0.88 | no | pass-b-inherit(path-capability-match+name-keyword) |
| `lib/Service/HookExecutor.php` | `stopEvent` | schema-hooks#REQ-006 | 1.0 | no | pass-b-inherit(path-capability-match+multi-name-keyword+doc-keywords) |

### capability: tenant-isolation-audit (2 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/OrganisationController.php` | `isolationMetrics` | tenant-isolation-audit#REQ-004 | 0.75 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Middleware/TenantQuotaMiddleware.php` | `afterException` | tenant-isolation-audit#REQ-002 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |

### capability: tenant-lifecycle (7 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Service/TenantLifecycleService.php` | `getValidTransitions` | tenant-lifecycle#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/TenantLifecycleService.php` | `provision` | tenant-lifecycle#REQ-002 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/TenantLifecycleService.php` | `suspend` | tenant-lifecycle#REQ-004 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/TenantLifecycleService.php` | `reactivate` | tenant-lifecycle#REQ-004 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/TenantLifecycleService.php` | `deprovision` | tenant-lifecycle#REQ-004 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/TenantLifecycleService.php` | `archive` | tenant-lifecycle#REQ-004 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/TenantLifecycleService.php` | `isValidStatus` | tenant-lifecycle#REQ-001 | 0.84 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |

### capability: tenant-quotas (2 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Middleware/TenantQuotaMiddleware.php` | `beforeController` | tenant-quotas#REQ-001 | 0.85 | no | multi-name-keyword+doc-keywords |
| `lib/Middleware/TenantQuotaMiddleware.php` | `afterController` | tenant-quotas#REQ-001 | 0.85 | no | multi-name-keyword+doc-keywords |

### capability: verwerkingsregister-api (2 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/Controller/SearchTrailController.php` | `export` | verwerkingsregister-api#REQ-003 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/SearchTrailController.php` | `extractRequestParameters` | verwerkingsregister-api#REQ-003 | 0.7 | yes | pass-b-inherit(multi-name-keyword+doc-keywords+NEEDS-REVIEW) |

### capability: webhook-payload-mapping (26 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/BackgroundJob/WebhookDeliveryJob.php` | `run` | webhook-payload-mapping#REQ-012 | 0.91 | no | path-capability-match+multi-name-keyword |
| `lib/Listener/WebhookEventListener.php` | `extractPayload` | webhook-payload-mapping#REQ-004 | 0.92 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Webhook/CloudEventFormatter.php` | `formatAsCloudEvent` | webhook-payload-mapping#REQ-006 | 0.78 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/WebhookService.php` | `dispatchEvent` | webhook-payload-mapping#REQ-015 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/WebhookService.php` | `passesFilters` | webhook-payload-mapping#REQ-002 | 0.78 | yes | path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/WebhookService.php` | `buildPayload` | webhook-payload-mapping#REQ-002 | 0.92 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/WebhookService.php` | `applyMappingTransformation` | webhook-payload-mapping#REQ-002 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/WebhookService.php` | `generateSignature` | webhook-payload-mapping#REQ-005 | 0.72 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/WebhookService.php` | `scheduleRetry` | webhook-payload-mapping#REQ-011 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/WebhookService.php` | `findWebhooksForInterception` | webhook-payload-mapping#REQ-015 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/WebhookService.php` | `getNestedValue` | webhook-payload-mapping#REQ-002 | 0.78 | yes | pass-b-inherit(path-capability-match+name-keyword+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/WebhookService.php` | `calculateRetryDelay` | webhook-payload-mapping#REQ-011 | 0.9 | no | pass-b-inherit(path-capability-match+name-keyword+doc-keywords) |
| `src/modals/webhook/EditWebhook.vue` | `updateEventProperty` | webhook-payload-mapping#REQ-006 | 0.77 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/webhook/EditWebhook.vue` | `updateSecret` | webhook-payload-mapping#REQ-001 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/webhook/EditWebhook.vue` | `loadAvailableEvents` | webhook-payload-mapping#REQ-001 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/webhook/EditWebhook.vue` | `searchEvents` | webhook-payload-mapping#REQ-001 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/webhook/EditWebhook.vue` | `loadExistingSelections` | webhook-payload-mapping#REQ-017 | 0.82 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/webhook/EditWebhook.vue` | `eventPropertyOptions` | webhook-payload-mapping#REQ-006 | 0.77 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/modals/webhook/ViewWebhookLog.vue` | `loadWebhooks` | webhook-payload-mapping#REQ-014 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/views/webhooks/WebhookLogsIndex.vue` | `loadWebhooks` | webhook-payload-mapping#REQ-014 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/views/webhooks/WebhookLogsIndex.vue` | `truncateEventClass` | webhook-payload-mapping#REQ-015 | 0.75 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `src/views/webhooks/WebhooksIndex.vue` | `selectedEventProperties` | webhook-payload-mapping#REQ-015 | 0.9 | no | path-capability-match+multi-name-keyword |
| `src/views/webhooks/WebhooksIndex.vue` | `testWebhook` | webhook-payload-mapping#REQ-014 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/views/webhooks/WebhooksIndex.vue` | `toggleWebhook` | webhook-payload-mapping#REQ-014 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/views/webhooks/WebhooksIndex.vue` | `deleteWebhook` | webhook-payload-mapping#REQ-014 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |
| `src/views/webhooks/WebhooksIndex.vue` | `editWebhook` | webhook-payload-mapping#REQ-014 | 0.79 | yes | path-capability-match+multi-name-keyword+NEEDS-REVIEW |

### capability: workflow-engine-abstraction (44 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/BackgroundJob/ScheduledWorkflowJob.php` | `handleError` | workflow-engine-abstraction#REQ-005 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Controller/DashboardController.php` | `getAuditTrailStatistics` | workflow-engine-abstraction#REQ-016 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/UiController.php` | `auditTrail` | workflow-engine-abstraction#REQ-016 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/WorkflowEngineController.php` | `health` | workflow-engine-abstraction#REQ-010 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Controller/WorkflowExecutionController.php` | `index` | workflow-engine-abstraction#REQ-005 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Controller/WorkflowExecutionController.php` | `show` | workflow-engine-abstraction#REQ-005 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Controller/WorkflowExecutionController.php` | `destroy` | workflow-engine-abstraction#REQ-005 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/DashboardService.php` | `getAuditTrailStatistics` | workflow-engine-abstraction#REQ-016 | 0.78 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/WorkflowEngineRegistry.php` | `resolveAdapter` | workflow-engine-abstraction#REQ-008 | 0.98 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/WorkflowEngineRegistry.php` | `healthCheck` | workflow-engine-abstraction#REQ-010 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/WorkflowEngineRegistry.php` | `decryptAuthConfig` | workflow-engine-abstraction#REQ-008 | 0.98 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/WorkflowEngine/N8nAdapter.php` | `getWorkflow` | workflow-engine-abstraction#REQ-001 | 0.73 | yes | path-capability-match+NEEDS-REVIEW |
| `lib/WorkflowEngine/N8nAdapter.php` | `healthCheck` | workflow-engine-abstraction#REQ-010 | 0.98 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/WorkflowEngine/WindmillAdapter.php` | `configure` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `deployWorkflow` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `updateWorkflow` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `getWorkflow` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `deleteWorkflow` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `activateWorkflow` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `deactivateWorkflow` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `executeWorkflow` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `getWebhookUrl` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `listWorkflows` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `healthCheck` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `buildRequestOptions` | workflow-engine-abstraction#REQ-003 | 0.98 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `buildAuthHeaders` | workflow-engine-abstraction#REQ-003 | 0.98 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WindmillAdapter.php` | `parseWorkflowResponse` | workflow-engine-abstraction#REQ-003 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `deployWorkflow` | workflow-engine-abstraction#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `updateWorkflow` | workflow-engine-abstraction#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `getWorkflow` | workflow-engine-abstraction#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `deleteWorkflow` | workflow-engine-abstraction#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `activateWorkflow` | workflow-engine-abstraction#REQ-001 | 0.98 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `deactivateWorkflow` | workflow-engine-abstraction#REQ-001 | 0.98 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `executeWorkflow` | workflow-engine-abstraction#REQ-001 | 0.98 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `getWebhookUrl` | workflow-engine-abstraction#REQ-001 | 0.98 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `listWorkflows` | workflow-engine-abstraction#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `healthCheck` | workflow-engine-abstraction#REQ-001 | 1.0 | no | path-capability-match+multi-name-keyword |
| `lib/Controller/UiController.php` | `makeSpaResponse` | workflow-engine-abstraction#REQ-016 | 0.78 | yes | pass-b-inherit(multi-name-keyword+doc-keywords+NEEDS-REVIEW) |
| `lib/WorkflowEngine/N8nAdapter.php` | `buildRequestOptions` | workflow-engine-abstraction#REQ-001 | 0.73 | yes | pass-b-inherit(path-capability-match+NEEDS-REVIEW) |
| `lib/WorkflowEngine/N8nAdapter.php` | `buildAuthHeaders` | workflow-engine-abstraction#REQ-001 | 0.73 | yes | pass-b-inherit(path-capability-match+NEEDS-REVIEW) |
| _... 4 more entries in JSON sidecar_ | | | | | |

### capability: workflow-in-import (9 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/BackgroundJob/ScheduledWorkflowJob.php` | `run` | workflow-in-import#REQ-019 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/BackgroundJob/ScheduledWorkflowJob.php` | `evaluateSchedule` | workflow-in-import#REQ-019 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/ScheduledWorkflowController.php` | `show` | workflow-in-import#REQ-019 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/ScheduledWorkflowController.php` | `create` | workflow-in-import#REQ-019 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/ScheduledWorkflowController.php` | `update` | workflow-in-import#REQ-019 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/ScheduledWorkflowController.php` | `destroy` | workflow-in-import#REQ-019 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Configuration/ExportHandler.php` | `exportWorkflowsForSchema` | workflow-in-import#REQ-018 | 0.83 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Configuration/ImportHandler.php` | `processWorkflowDeployment` | workflow-in-import#REQ-003 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Configuration/ImportHandler.php` | `processWorkflowHookWiring` | workflow-in-import#REQ-006 | 0.85 | no | multi-name-keyword+doc-keywords |

### capability: zoeken-filteren (49 methods)

| File | Method | REQ | Confidence | Needs review | Signal |
|---|---|---|---|---|---|
| `lib/AppInfo/Application.php` | `registerSearchBackend` | zoeken-filteren#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/FileSearchController.php` | `keywordSearch` | zoeken-filteren#REQ-001 | 1.0 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Controller/SearchController.php` | `processSearchQuery` | zoeken-filteren#REQ-016 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Controller/SearchTrailController.php` | `destroyMultiple` | zoeken-filteren#REQ-014 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Controller/SettingsController.php` | `getSearchBackend` | zoeken-filteren#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Controller/SettingsController.php` | `updateSearchBackend` | zoeken-filteren#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Search/ObjectsProvider.php` | `search` | zoeken-filteren#REQ-014 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Search/ObjectsProvider.php` | `buildDescription` | zoeken-filteren#REQ-001 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Index/Backends/ElasticsearchBackend.php` | `searchObjectsPaginated` | zoeken-filteren#REQ-009 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/Index/Backends/ElasticsearchBackend.php` | `fixMismatchedFields` | zoeken-filteren#REQ-009 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Index/SearchBackendInterface.php` | `isAvailable` | zoeken-filteren#REQ-009 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Index/SearchBackendInterface.php` | `indexObject` | zoeken-filteren#REQ-009 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Index/SearchBackendInterface.php` | `searchObjectsPaginated` | zoeken-filteren#REQ-016 | 0.85 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Index/SearchBackendInterface.php` | `optimize` | zoeken-filteren#REQ-017 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Index/SearchBackendInterface.php` | `getStats` | zoeken-filteren#REQ-009 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Index/SearchBackendInterface.php` | `search` | zoeken-filteren#REQ-009 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Index/SearchBackendInterface.php` | `fixMismatchedFields` | zoeken-filteren#REQ-009 | 0.75 | yes | path-capability-match+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/NewFacetingExample.php` | `paginatedSearchWithFacets` | zoeken-filteren#REQ-008 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/ObjectServiceFacetExample.php` | `ecommerceFacetedSearch` | zoeken-filteren#REQ-008 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Object/QueryHandler.php` | `searchObjectsPaginatedDatabase` | zoeken-filteren#REQ-014 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Object/SearchQueryHandler.php` | `applyViewsToQuery` | zoeken-filteren#REQ-014 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Object/SearchQueryHandler.php` | `isSearchTrailsEnabled` | zoeken-filteren#REQ-011 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/SearchTrailService.php` | `clearExpiredSearchTrails` | zoeken-filteren#REQ-011 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/SearchTrailService.php` | `getSearchTrails` | zoeken-filteren#REQ-011 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/SearchTrailService.php` | `cleanupSearchTrails` | zoeken-filteren#REQ-011 | 0.95 | no | path-capability-match+multi-name-keyword |
| `lib/Service/SearchTrailService.php` | `calculatePerformanceRating` | zoeken-filteren#REQ-017 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/SearchTrailService.php` | `enrichTrailsWithNames` | zoeken-filteren#REQ-011 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Settings/SearchBackendHandler.php` | `getSearchBackendConfig` | zoeken-filteren#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Settings/SearchBackendHandler.php` | `updateSearchBackendConfig` | zoeken-filteren#REQ-009 | 1.0 | no | path-capability-match+multi-name-keyword+doc-keywords |
| `lib/Service/Settings/SolrSettingsHandler.php` | `getSearchBackendConfig` | zoeken-filteren#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Settings/SolrSettingsHandler.php` | `updateSearchBackendConfig` | zoeken-filteren#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/SettingsService.php` | `getSearchBackendConfig` | zoeken-filteren#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/SettingsService.php` | `updateSearchBackendConfig` | zoeken-filteren#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Service/Vectorization/Handlers/VectorSearchHandler.php` | `extractEntityId` | zoeken-filteren#REQ-014 | 0.8 | yes | path-capability-match+name-keyword+NEEDS-REVIEW |
| `lib/Service/Vectorization/Handlers/VectorSearchHandler.php` | `getCollectionsToSearch` | zoeken-filteren#REQ-014 | 0.9 | no | path-capability-match+name-keyword+doc-keywords |
| `lib/Service/Vectorization/VectorEmbeddings.php` | `getVectorSearchBackend` | zoeken-filteren#REQ-009 | 0.7 | yes | multi-name-keyword+doc-keywords+NEEDS-REVIEW |
| `lib/Search/ObjectsProvider.php` | `resolveSchemaName` | zoeken-filteren#REQ-001 | 0.75 | yes | pass-b-inherit(path-capability-match+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/Object/ObjectServiceFacetExample.php` | `isAuditTrailsEnabled` | zoeken-filteren#REQ-008 | 0.7 | yes | pass-b-inherit(multi-name-keyword+doc-keywords+NEEDS-REVIEW) |
| `lib/Service/SearchTrailService.php` | `processConfig` | zoeken-filteren#REQ-011 | 0.95 | no | pass-b-inherit(path-capability-match+multi-name-keyword) |
| `src/store/modules/searchTrail.js` | `fetchSearchTrails` | zoeken-filteren#REQ-011 | 0.9 | no | path-capability-match+multi-name-keyword |
| _... 9 more entries in JSON sidecar_ | | | | | |

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

### cluster: mail-sidebar (197 methods)
- `lib/Controller/EmailsController.php`::index — List all email links for a specific object.
- `lib/Controller/EmailsController.php`::create — Link an email to a specific object.
- `lib/Controller/EmailsController.php`::destroy — Remove an email link from an object.
- `lib/Controller/EmailsController.php`::search — Search email links by sender.
- `lib/Controller/EmailsController.php`::validateObject — Validate that the object exists and return it.
- `lib/Controller/EmailsController.php`::bySender — Find objects linked to emails from a specific sender.
- `lib/Controller/FileSidebarController.php`::getObjectsForFile — Get all OpenRegister objects that reference the given file.
- `lib/Controller/FileSidebarController.php`::getExtractionStatus — Get the extraction status and metadata for the given file.
- `lib/Listener/FilesSidebarListener.php`::handle — OpenRegister Files Sidebar Listener
- `lib/Service/EmailService.php`::getEmailsForObject — Get all email links for an object.
- `lib/Service/EmailService.php`::linkEmail — Link an existing email to an object.
- `lib/Service/EmailService.php`::searchBySender — Search email links by sender.
- `lib/Service/EmailService.php`::buildMailboxSubquery — Build the mailbox subquery string for filtering by account.
- `lib/Service/FileSidebarService.php`::getObjectsForFile — Get all OpenRegister objects that reference a given Nextcloud file ID.
- `lib/Service/FileSidebarService.php`::searchTableForFileId — Search a specific magic table for rows containing a file ID.
- `lib/Service/FileSidebarService.php`::extractTitle — Extract a human-readable title from an object row.
- `lib/Service/FileSidebarService.php`::getExtractionStatus — Get extraction status and metadata for a file.
- `src/components/EntitiesSidebar.vue`::handleSearchInput — vue-method:handleSearchInput
- `src/components/EntitiesSidebar.vue`::updateType — vue-method:updateType
- `src/components/EntitiesSidebar.vue`::updateCategory — vue-method:updateCategory
- _... 177 more entries in JSON sidecar_

### cluster: zoeken-filteren (180 methods)
- `lib/Activity/Filter.php`::getIdentifier — Get the unique identifier of the filter.
- `lib/Activity/Filter.php`::getPriority — Get the priority of the filter.
- `lib/Activity/Filter.php`::getIcon — Get the icon URL for the filter.
- `lib/Activity/Filter.php`::filterTypes — Filter the activity types to show.
- `lib/Activity/Filter.php`::allowedApps — Get the allowed apps for this filter.
- `lib/Controller/FileSearchController.php`::semanticSearch — Semantic search in file contents (vector similarity search)
- `lib/Controller/FileSearchController.php`::hybridSearch — Hybrid search - Combines keyword (SOLR) and semantic (vector) search
- `lib/Controller/SearchController.php`::search — Handles search requests and forwards them to the SOLR search service
- `lib/Controller/SearchTrailController.php`::paginate — Private helper method to handle pagination of results.
- `lib/Controller/SearchTrailController.php`::index — Get all search trail logs
- `lib/Controller/SearchTrailController.php`::show — Get a specific search trail log by ID
- `lib/Controller/SearchTrailController.php`::statistics — Get search statistics for a given period
- `lib/Controller/SearchTrailController.php`::popularTerms — Get popular search terms
- `lib/Controller/SearchTrailController.php`::activity — Get search activity by time period
- `lib/Controller/SearchTrailController.php`::registerSchemaStats — Get search statistics by register and schema
- `lib/Controller/SearchTrailController.php`::userAgentStats — Get user agent statistics
- `lib/Controller/SearchTrailController.php`::cleanup — Clean up old search trail logs
- `lib/Controller/SearchTrailController.php`::destroy — Delete a single search trail log
- `lib/Controller/SearchTrailController.php`::arrayToCsv — Convert array to CSV format
- `lib/Controller/SearchTrailController.php`::clearAll — Clear all search trail logs
- _... 160 more entries in JSON sidecar_

### cluster: webhook-payload-mapping (111 methods)
- `lib/Controller/MappingsController.php`::index — Retrieves a list of all mappings
- `lib/Controller/MappingsController.php`::show — Retrieves a single mapping by ID
- `lib/Controller/MappingsController.php`::create — Creates a new mapping
- `lib/Controller/MappingsController.php`::update — Updates an existing mapping
- `lib/Controller/MappingsController.php`::destroy — Deletes a mapping
- `lib/Controller/MappingsController.php`::test — Tests a mapping with provided input data
- `lib/Controller/WebhooksController.php`::index — index (no docblock)
- `lib/Controller/WebhooksController.php`::show — show (no docblock)
- `lib/Controller/WebhooksController.php`::create — create (no docblock)
- `lib/Controller/WebhooksController.php`::update — update (no docblock)
- `lib/Controller/WebhooksController.php`::destroy — destroy (no docblock)
- `lib/Controller/WebhooksController.php`::test — test (no docblock)
- `lib/Controller/WebhooksController.php`::events — events (no docblock)
- `lib/Controller/WebhooksController.php`::logs — logs (no docblock)
- `lib/Controller/WebhooksController.php`::logStats — logStats (no docblock)
- `lib/Controller/WebhooksController.php`::allLogs — allLogs (no docblock)
- `lib/Controller/WebhooksController.php`::retry — retry (no docblock)
- `lib/Cron/WebhookRetryJob.php`::run — Run the retry job
- `lib/Listener/WebhookEventListener.php`::handle — Handle event
- `lib/Service/MappingService.php`::__construct — OpenRegister Mapping Service
- _... 91 more entries in JSON sidecar_

### cluster: deletion-audit-trail (92 methods)
- `lib/Controller/DeletedController.php`::isCurrentUserAdmin — Check if the current user is an admin
- `lib/Controller/DeletedController.php`::extractRequestParameters — Helper method to extract request parameters for deleted objects
- `lib/Controller/DeletedController.php`::index — Get all soft deleted objects
- `lib/Controller/DeletedController.php`::statistics — Get statistics for deleted objects
- `lib/Controller/DeletedController.php`::topDeleters — Get top deleters statistics
- `lib/Controller/DeletedController.php`::restore — Restore a deleted object
- `lib/Controller/DeletedController.php`::restoreMultiple — Restore multiple deleted objects
- `lib/Controller/DeletedController.php`::destroy — Permanently delete an object
- `lib/Controller/DeletedController.php`::destroyMultiple — Permanently delete multiple objects
- `lib/Controller/DeletedController.php`::formatRestoreMessage — Format restore message.
- `lib/Controller/DeletedController.php`::formatDeleteMessage — Format delete message.
- `src/modals/deleted/PurgeMultiple.vue`::objectsToDelete — class-method:objectsToDelete
- `src/modals/deleted/PurgeMultiple.vue`::initializeSelection — class-method:initializeSelection
- `src/modals/deleted/PurgeMultiple.vue`::removeObject — class-method:removeObject
- `src/modals/deleted/PurgeMultiple.vue`::closeDialog — class-method:closeDialog
- `src/modals/deleted/PurgeMultiple.vue`::permanentlyDeleteMultiple — class-method:permanentlyDeleteMultiple
- `src/modals/deleted/PurgeMultiple.vue`::getObjectTitle — class-method:getObjectTitle
- `src/modals/deleted/RestoreMultiple.vue`::objectsToRestore — class-method:objectsToRestore
- `src/modals/deleted/RestoreMultiple.vue`::initializeSelection — class-method:initializeSelection
- `src/modals/deleted/RestoreMultiple.vue`::removeObject — class-method:removeObject
- _... 72 more entries in JSON sidecar_

### cluster: rbac-scopes (74 methods)
- `lib/Service/AuthorizationAuditService.php`::logSchemaAuthorizationChange — Log a schema authorization change.
- `lib/Service/AuthorizationService.php`::findIssuer — Find the consumer for a given JWT issuer.
- `lib/Service/AuthorizationService.php`::base64urlDecode — Base64url-decode a string per RFC 7515.
- `lib/Service/AuthorizationService.php`::verifyHmac — Verify an HMAC JWT signature using PHP built-in functions.
- `lib/Service/AuthorizationService.php`::validatePayload — Validate data in the JWT payload.
- `lib/Service/AuthorizationService.php`::authorizeJwt — Checks if authorization header contains a valid JWT token.
- `lib/Service/AuthorizationService.php`::authorizeBasic — Authorize user based on HTTP Basic Auth.
- `lib/Service/AuthorizationService.php`::authorizeOAuth — Authorize user based on OAuth2 Bearer token.
- `lib/Service/AuthorizationService.php`::corsAfterController — Add CORS headers to controller result.
- `lib/Service/AuthorizationService.php`::authorizeApiKey — Authorize user based on API key.
- `lib/Service/Index/Backends/SolrBackend.php`::testConnection — Test connection with diagnostics.
- `lib/Service/Index/Backends/SolrBackend.php`::indexObject — Index a single object.
- `lib/Service/Index/Backends/SolrBackend.php`::bulkIndexObjects — Index multiple objects in bulk.
- `lib/Service/Index/Backends/SolrBackend.php`::deleteObject — Delete an object from the index.
- `lib/Service/Index/Backends/SolrBackend.php`::deleteByQuery — Delete objects by query.
- `lib/Service/Index/Backends/SolrBackend.php`::searchObjectsPaginated — Search with pagination.
- `lib/Service/Index/Backends/SolrBackend.php`::warmupIndex — Warm up the index.
- `lib/Service/Index/Backends/SolrBackend.php`::createCollection — Create a collection.
- `lib/Service/Index/Backends/SolrBackend.php`::addFieldType — Add field type.
- `lib/Service/Index/Backends/SolrBackend.php`::addOrUpdateField — Add or update field.
- _... 54 more entries in JSON sidecar_

### cluster: faceting-configuration (68 methods)
- `lib/Service/Index/Backends/Solr/SolrFacetProcessor.php`::buildFacetQuery — Build facet query for search.
- `lib/Service/Index/Backends/Solr/SolrFacetProcessor.php`::processFacetResponse — Process facet response from Solr.
- `lib/Service/Object/FacetHandler.php`::__construct — OpenRegister Facet Handler
- `lib/Service/Object/FacetHandler.php`::sanitizeFieldName — Sanitize a field name for use as an index field.
- `lib/Service/Object/FacetHandler.php`::inferDataType — Infer the data type from facet data.
- `lib/Service/Object/FacetHandler.php`::generateFacetCacheKey — Generate cache key for facet responses.
- `lib/Service/Object/FacetHandler.php`::getCachedFacetResponse — Get cached facet response.
- `lib/Service/Object/FacetHandler.php`::cacheFacetResponse — Cache facet response for future requests.
- `lib/Service/Object/FacetHandler.php`::hasRestrictiveFilters — Check if query has restrictive filters that might eliminate all results.
- `lib/Service/Object/NewFacetingExample.php`::basicTermsFaceting — Example 1: Basic Terms Faceting
- `lib/Service/Object/NewFacetingExample.php`::dateHistogramFaceting — Example 2: Date Histogram Faceting
- `lib/Service/Object/NewFacetingExample.php`::rangeFaceting — Example 3: Range Faceting
- `lib/Service/Object/NewFacetingExample.php`::ecommerceFaceting — Example 4: Complete E-commerce Faceting
- `lib/Service/Object/NewFacetingExample.php`::migrationExample — Example 6: Migration from Legacy getFacets
- `lib/Service/Object/NewFacetingExample.php`::advancedFilteringWithFacets — Example 7: Advanced Filtering with Facets
- `lib/Service/Object/NewFacetingExample.php`::performanceOptimizedFaceting — Example 8: Performance Optimized Faceting
- `lib/Service/Object/ObjectServiceFacetExample.php`::newFacetingApproach — Example 1: Basic New Faceting with getFacetsForObjects
- `lib/Service/Object/ObjectServiceFacetExample.php`::disjunctiveFacetingDemo — Example 6: Disjunctive Faceting Demonstration
- `lib/Service/Object/ObjectServiceFacetExample.php`::frontendIntegrationExample — Example 8: Complete Frontend Integration Example
- `lib/Service/Object/ObjectServiceFacetExample.php`::transformBuckets — Transform facet buckets for frontend
- _... 48 more entries in JSON sidecar_

### cluster: built-in-dashboards (61 methods)
- `lib/Controller/DashboardController.php`::page — Returns the template of the dashboard page
- `lib/Controller/DashboardController.php`::index — Retrieves dashboard data including registers with their schemas
- `lib/Controller/DashboardController.php`::calculate — Calculate sizes for objects and logs
- `lib/Controller/DashboardController.php`::getObjectsByRegisterChart — Get chart data for objects by register
- `lib/Controller/DashboardController.php`::getObjectsBySchemaChart — Get chart data for objects by schema
- `lib/Controller/DashboardController.php`::getObjectsBySizeChart — Get chart data for objects by size distribution
- `lib/Controller/DashboardController.php`::getAuditTrailActionDistribution — Get action distribution data for audit trails
- `lib/Controller/DashboardController.php`::getMostActiveObjects — Get most active objects based on audit trail activity
- `lib/Service/DashboardService.php`::getStats — Get statistics for a register/schema combination
- `lib/Service/DashboardService.php`::getOrphanedStats — Get statistics for orphaned items
- `lib/Service/DashboardService.php`::recalculateSizes — Recalculate sizes for objects in specified registers and/or schemas
- `lib/Service/DashboardService.php`::recalculateLogSizes — Recalculate sizes for audit trail logs in specified registers and/or schemas
- `lib/Service/DashboardService.php`::recalculateAllSizes — Recalculate sizes for both objects and logs in specified registers and/or schemas
- `lib/Service/DashboardService.php`::calculate — Calculate sizes for all entities (objects and logs) in the system
- `lib/Service/DashboardService.php`::fetchRegister — Fetch register by ID with validation
- `lib/Service/DashboardService.php`::fetchSchema — Fetch schema by ID with validation
- `lib/Service/DashboardService.php`::buildResponseScope — Build response scope object from register and schema
- `lib/Service/DashboardService.php`::calculateSuccessRate — Calculate success rate from results
- `lib/Service/DashboardService.php`::getObjectsByRegisterChartData — Get chart data for objects by register
- `lib/Service/DashboardService.php`::getObjectsBySchemaChartData — Get chart data for objects by schema
- _... 41 more entries in JSON sidecar_

### cluster: datetime-input-handling (60 methods)
- `lib/Service/DateTimeNormalizer.php`::normalize — Normalise user-supplied input to `DateTimeImmutable` or `null`.
- `lib/Service/DateTimeNormalizer.php`::formatForDatabase — Format user-supplied input as a database datetime string.
- `lib/Service/DateTimeNormalizer.php`::formatForIso8601 — Format user-supplied input as an ISO 8601 string with timezone offset.
- `lib/Service/File/UpdateFileHandler.php`::updateFile — Update a file's content, metadata, and tags.
- `lib/Service/GraphQL/Scalar/DateTimeType.php`::serialize — DateTime scalar type for GraphQL.
- `lib/Service/GraphQL/Scalar/DateTimeType.php`::parseValue — Parses a value from client input.
- `lib/Service/GraphQL/Scalar/DateTimeType.php`::parseLiteral — Parses a literal AST value.
- `lib/Service/Object/ValidateObject.php`::preprocessSchemaReferences — Pre-processes a schema object to resolve all schema references.
- `lib/Service/Object/ValidateObject.php`::resolveSchemaProperty — Resolves schema references in a property definition.
- `lib/Service/Object/ValidateObject.php`::transformToUuidProperty — Transforms an object property to expect UUID strings for related objects.
- `lib/Service/Object/ValidateObject.php`::transformToNestedObjectProperty — Transforms an object property for nested objects, removing circular references.
- `lib/Service/Object/ValidateObject.php`::extractHandlingFromOneOfItems — Extracts the handling value from a oneOf array of schema items.
- `lib/Service/Object/ValidateObject.php`::transformSchemaForValidation — Transforms schema for validation by handling circular references, OpenRegister configurations, and schema resolution.
- `lib/Service/Object/ValidateObject.php`::cleanSchemaForValidation — Cleans a schema object by removing all Nextcloud-specific metadata properties.
- `lib/Service/Object/ValidateObject.php`::cleanPropertyForValidation — Cleans a property schema by removing metadata and handling special cases.
- `lib/Service/Object/ValidateObject.php`::fixMisplacedArrayConstraints — Fixes misplaced enum and oneOf constraints on array-type properties.
- `lib/Service/Object/ValidateObject.php`::transformCustomTypeToJsonSchemaType — Transforms custom OpenRegister types to valid JSON Schema types.
- `lib/Service/Object/ValidateObject.php`::transformArrayItemsForValidation — Transforms array items for validation by converting object items to appropriate types.
- `lib/Service/Object/ValidateObject.php`::isSelfReference — Checks if a property schema is a self-reference to the given schema slug.
- `lib/Service/Object/ValidateObject.php`::findSchemaBySlug — Finds a schema by slug (case-insensitive).
- _... 40 more entries in JSON sidecar_

### cluster: graphql-api (58 methods)
- `lib/Controller/GraphQLController.php`::execute — Execute a GraphQL query.
- `lib/Controller/GraphQLController.php`::render — Render the HTML body.
- `lib/Controller/GraphQLSubscriptionController.php`::subscribe — SSE subscription endpoint.
- `lib/Listener/GraphQLSubscriptionListener.php`::handle — Handle an event.
- `lib/Service/GraphQL/GraphQLErrorFormatter.php`::format — GraphQL Error Formatter
- `lib/Service/GraphQL/GraphQLErrorFormatter.php`::fieldForbidden — Create a field-level forbidden error.
- `lib/Service/GraphQL/GraphQLErrorFormatter.php`::notFound — Create a not-found error.
- `lib/Service/GraphQL/GraphQLResolver.php`::resolveSingle — Resolve a single object query.
- `lib/Service/GraphQL/GraphQLResolver.php`::resolveList — Resolve a list query with pagination, filtering, and facets.
- `lib/Service/GraphQL/GraphQLResolver.php`::resolveCreate — Resolve a create mutation.
- `lib/Service/GraphQL/GraphQLResolver.php`::resolveUpdate — Resolve an update mutation.
- `lib/Service/GraphQL/GraphQLResolver.php`::resolveDelete — Resolve a delete mutation.
- `lib/Service/GraphQL/GraphQLResolver.php`::resolveRelation — Resolve a relation field using deferred batching (DataLoader pattern).
- `lib/Service/GraphQL/GraphQLResolver.php`::resolveUsedBy — Resolve the _usedBy field for an object.
- `lib/Service/GraphQL/GraphQLResolver.php`::flushRelationBuffer — Flush the DataLoader buffer — batch-load all buffered relation UUIDs.
- `lib/Service/GraphQL/GraphQLResolver.php`::argsToRequestParams — Build a query array from GraphQL arguments for QueryHandler.
- `lib/Service/GraphQL/GraphQLResolver.php`::objectToArray — Convert an ObjectEntity to an array for GraphQL output.
- `lib/Service/GraphQL/GraphQLResolver.php`::encodeCursor — Encode a pagination cursor.
- `lib/Service/GraphQL/GraphQLService.php`::execute — Execute a GraphQL query.
- `lib/Service/GraphQL/GraphQLService.php`::createContext — Create the resolver context passed to all resolvers.
- _... 38 more entries in JSON sidecar_

### cluster: linked-entity-types (54 methods)
- `lib/Controller/LinkedEntityController.php`::addObjectLink — addObjectLink (no docblock)
- `lib/Controller/LinkedEntityController.php`::addRegisterLink — addRegisterLink (no docblock)
- `lib/Controller/LinkedEntityController.php`::addSchemaLink — addSchemaLink (no docblock)
- `lib/Controller/RelationsController.php`::index — Get all relations for an object.
- `lib/Controller/RelationsController.php`::gatherRelations — Gather all relations for an object, optionally filtered by type.
- `lib/Controller/RelationsController.php`::buildTimeline — Build a timeline view from grouped relations.
- `lib/Controller/RelationsController.php`::validateObject — Validate that the object exists.
- `lib/Service/Object/LinkedEntityEnricher.php`::enrich — Enrich linked entity IDs for the requested _extend types.
- `lib/Service/Object/LinkedEntityEnricher.php`::enrichMail — Enrich mail IDs to full mail objects.
- `lib/Service/Object/LinkedEntityEnricher.php`::enrichContacts — Enrich contact UIDs to full contact objects.
- `lib/Service/Object/LinkedEntityEnricher.php`::enrichNotes — Enrich note IDs to full note objects via ICommentsManager.
- `lib/Service/Object/LinkedEntityEnricher.php`::enrichTodos — Enrich todo UIDs to full todo objects.
- `lib/Service/Object/LinkedEntityEnricher.php`::enrichCalendar — Enrich calendar event UIDs to full event objects.
- `lib/Service/Object/LinkedEntityEnricher.php`::enrichTalk — Enrich Talk conversation tokens to full conversation objects.
- `lib/Service/Object/LinkedEntityEnricher.php`::enrichDeck — Enrich Deck card IDs to full card objects.
- `lib/Service/Object/LinkedEntityEnricher.php`::notFoundResult — Create a "not found" fallback result for a missing entity.
- `lib/Service/Object/LinkedEntityEnricher.php`::extractVcardField — Extract a field value from vCard data.
- `lib/Service/Object/LinkedEntityEnricher.php`::extractIcalField — Extract a field value from iCalendar data.
- `lib/Service/Object/RelationHandler.php`::applyInversedByFilter — Apply inversedBy filter to find objects by their inverse relations.
- `lib/Service/Object/RelationHandler.php`::extractRelatedData — Extract related data from results (delegates to PerformanceHandler).
- _... 34 more entries in JSON sidecar_

### cluster: data-import-export (52 methods)
- `lib/BackgroundJob/BulkLegalHoldJob.php`::run — Execute the bulk legal hold operation.
- `lib/Controller/BulkController.php`::resolveRegisterSchemaIds — Resolve register and schema slugs/IDs to numeric IDs.
- `lib/Controller/BulkController.php`::delete — Perform bulk delete operations on objects
- `lib/Controller/BulkController.php`::save — Perform bulk save operations on objects
- `lib/Controller/BulkController.php`::deleteSchema — Delete all objects belonging to a specific schema
- `lib/Controller/BulkController.php`::deleteSchemaObjects — Delete all objects belonging to a specific register and schema combination.
- `lib/Controller/BulkController.php`::deleteRegister — Delete all objects belonging to a specific register
- `lib/Service/Configuration/ExportHandler.php`::exportSchema — Export a schema to OpenAPI format.
- `lib/Service/Configuration/ExportHandler.php`::getLastNumericSegment — Get the last segment of a URL if it is numeric.
- `lib/Service/Configuration/ImportHandler.php`::decode — Decode JSON or YAML string data into PHP array.
- `lib/Service/Configuration/ImportHandler.php`::ensureArrayStructure — Recursively converts stdClass objects to arrays.
- `lib/Service/Configuration/ImportHandler.php`::getJSONfromFile — Get JSON data from uploaded file.
- `lib/Service/Configuration/ImportHandler.php`::getJSONfromURL — Fetch JSON from URL using HTTP GET.
- `lib/Service/Configuration/ImportHandler.php`::getJSONfromBody — Get JSON data from request body.
- `lib/Service/Configuration/ImportHandler.php`::createOrUpdateConfiguration — Create or update a Configuration entity to track imports.
- `lib/Service/Configuration/ImportHandler.php`::ensureDependenciesForSeedData — Ensure Nextcloud app dependencies are met for seedData import.
- `lib/Service/ExportService.php`::isUserAdmin — Check if the given user is in the admin group
- `lib/Service/ExportService.php`::exportToCsv — Export data to CSV format
- `lib/Service/ExportService.php`::populateSheet — Populate a worksheet with data
- `lib/Service/ExportService.php`::identifyNameCompanionColumns — Identify which header columns are name-companion columns (prefixed with _).
- _... 32 more entries in JSON sidecar_

### cluster: workflow-engine-abstraction (52 methods)
- `lib/Controller/ScheduledWorkflowController.php`::index — List all scheduled workflows.
- `lib/Controller/WorkflowEngineController.php`::index — List all registered engines.
- `lib/Controller/WorkflowEngineController.php`::show — Get a single engine.
- `lib/Controller/WorkflowEngineController.php`::create — Register a new engine.
- `lib/Controller/WorkflowEngineController.php`::update — Update an engine.
- `lib/Controller/WorkflowEngineController.php`::destroy — Delete an engine.
- `lib/Controller/WorkflowEngineController.php`::available — List auto-discovered engine types from installed ExApps.
- `lib/Controller/WorkflowEngineController.php`::testHook — Test a hook by executing a workflow with sample data (dry-run).
- `lib/Service/WorkflowEngineRegistry.php`::resolveAdapterById — Resolve an adapter by engine ID.
- `lib/Service/WorkflowEngineRegistry.php`::createEngine — Create a new engine with encrypted credentials.
- `lib/Service/WorkflowEngineRegistry.php`::updateEngine — Update an engine with encrypted credentials.
- `lib/Service/WorkflowEngineRegistry.php`::deleteEngine — Delete an engine.
- `lib/WorkflowEngine/N8nAdapter.php`::configure — Configure the adapter with engine settings.
- `lib/WorkflowEngine/N8nAdapter.php`::deployWorkflow — Deploy a workflow to n8n.
- `lib/WorkflowEngine/N8nAdapter.php`::updateWorkflow — Update an existing workflow in n8n.
- `lib/WorkflowEngine/N8nAdapter.php`::deleteWorkflow — Delete a workflow from n8n.
- `lib/WorkflowEngine/N8nAdapter.php`::activateWorkflow — Activate a workflow in n8n.
- `lib/WorkflowEngine/N8nAdapter.php`::deactivateWorkflow — Deactivate a workflow in n8n.
- `lib/WorkflowEngine/N8nAdapter.php`::executeWorkflow — Execute a workflow in n8n via webhook.
- `lib/WorkflowEngine/N8nAdapter.php`::getWebhookUrl — Get the webhook URL for a workflow.
- _... 32 more entries in JSON sidecar_

### cluster: edepot-transfer (46 methods)
- `lib/BackgroundJob/TransferExecutionJob.php`::run — Execute the transfer job.
- `lib/BackgroundJob/TransferExecutionJob.php`::resolveTransport — Resolve the configured transport implementation.
- `lib/Cron/TransferCheckJob.php`::run — Run the transfer check.
- `lib/Service/Edepot/EdepotTransferService.php`::sendWithRetry — Send a SIP file with retry logic.
- `lib/Service/Edepot/EdepotTransferService.php`::gatherObjectsWithFiles — Gather objects and their file metadata for SIP building.
- `lib/Service/Edepot/EdepotTransferService.php`::getObjectFiles — Get file metadata for an object.
- `lib/Service/Edepot/EdepotTransferService.php`::processResults — Process transport results and update object statuses.
- `lib/Service/Edepot/EdepotTransferService.php`::markObjectTransferred — Mark an object as successfully transferred.
- `lib/Service/Edepot/EdepotTransferService.php`::markObjectTransferFailed — Mark an object's transfer as failed.
- `lib/Service/Edepot/EdepotTransferService.php`::getTransportConfig — Get the transport configuration from app settings.
- `lib/Service/Edepot/EdepotTransferService.php`::getAvailableProfiles — Get available SIP profile names.
- `lib/Service/Edepot/EdepotTransferService.php`::isValidProfile — Validate a SIP profile name.
- `lib/Service/Edepot/EdepotTransferService.php`::notifyTransferCompletion — Send notification on transfer completion.
- `lib/Service/Edepot/MdtoXmlGenerator.php`::addIdentificatie — Add the identificatie element to the XML document.
- `lib/Service/Edepot/MdtoXmlGenerator.php`::addNaam — Add the naam element to the XML document.
- `lib/Service/Edepot/MdtoXmlGenerator.php`::addWaardering — Add the waardering element to the XML document.
- `lib/Service/Edepot/MdtoXmlGenerator.php`::addBewaartermijn — Add the bewaartermijn element to the XML document.
- `lib/Service/Edepot/MdtoXmlGenerator.php`::addInformatiecategorie — Add the informatiecategorie element to the XML document.
- `lib/Service/Edepot/MdtoXmlGenerator.php`::addArchiefvormer — Add the archiefvormer element to the XML document.
- `lib/Service/Edepot/MdtoXmlGenerator.php`::addBestand — Add a bestand (file) element to the XML document.
- _... 26 more entries in JSON sidecar_

### cluster: mcp-discovery (45 methods)
- `lib/Controller/McpServerController.php`::handle — Handle MCP JSON-RPC 2.0 request
- `lib/Controller/McpServerController.php`::handleNotification — Handle a notification (no id, no response expected)
- `lib/Controller/McpServerController.php`::handleInitialize — Handle MCP initialize request
- `lib/Controller/McpServerController.php`::dispatch — Dispatch a JSON-RPC method to the appropriate handler
- `lib/Controller/McpServerController.php`::handleToolCall — Handle tools/call request
- `lib/Controller/McpServerController.php`::handleResourceRead — Handle resources/read request
- `lib/Controller/McpServerController.php`::jsonRpcSuccess — Build a JSON-RPC 2.0 success response
- `lib/Controller/McpServerController.php`::jsonRpcError — Build a JSON-RPC 2.0 error response
- `lib/Service/Mcp/McpProtocolService.php`::initialize — Handle MCP initialize request
- `lib/Service/Mcp/McpProtocolService.php`::ping — Handle MCP ping request
- `lib/Service/Mcp/McpProtocolService.php`::createSession — Create a new MCP session
- `lib/Service/Mcp/McpProtocolService.php`::validateSession — Validate an MCP session ID
- `lib/Service/Mcp/McpProtocolService.php`::destroySession — Destroy an MCP session
- `lib/Service/Mcp/McpResourcesService.php`::listResources — List available MCP resources
- `lib/Service/Mcp/McpResourcesService.php`::listTemplates — List MCP resource URI templates
- `lib/Service/Mcp/McpResourcesService.php`::readResource — Read an MCP resource by URI
- `lib/Service/Mcp/McpResourcesService.php`::parseUri — Parse an openregister:// URI into components
- `lib/Service/Mcp/McpResourcesService.php`::readRegisters — Read register data
- `lib/Service/Mcp/McpResourcesService.php`::readSchemas — Read schema data
- `lib/Service/Mcp/McpResourcesService.php`::readObjects — Read object data
- _... 25 more entries in JSON sidecar_

### cluster: event-driven-architecture (29 methods)
- `lib/Calendar/CalendarEventTransformer.php`::transform — OpenRegister Calendar Event Transformer
- `lib/Calendar/CalendarEventTransformer.php`::determineAllDay — Determine if events should be all-day based on config and schema property format
- `lib/Calendar/CalendarEventTransformer.php`::formatDateValue — Format a date value into iCalendar format
- `lib/Calendar/CalendarEventTransformer.php`::buildDtend — Build DTEND value from configuration
- `lib/Calendar/CalendarEventTransformer.php`::interpolateTemplate — Interpolate a template string with object data
- `lib/Calendar/CalendarEventTransformer.php`::resolveStatus — Resolve the VEVENT STATUS from object data using status mapping
- `lib/Controller/CalendarEventsController.php`::index — List all calendar events for a specific object.
- `lib/Controller/CalendarEventsController.php`::create — Create a new calendar event linked to an object.
- `lib/Controller/CalendarEventsController.php`::link — Link an existing calendar event to an object.
- `lib/Controller/CalendarEventsController.php`::destroy — Unlink a calendar event from an object.
- `lib/Controller/CalendarEventsController.php`::validateObject — Validate that the object exists.
- `lib/Event/ObjectCreatingEvent.php`::isPropagationStopped — Check if propagation has been stopped by a hook
- `lib/Event/ObjectCreatingEvent.php`::stopPropagation — Stop event propagation (used by hooks to reject creation)
- `lib/Event/ObjectDeletingEvent.php`::isPropagationStopped — Check if propagation has been stopped by a hook
- `lib/Event/ObjectDeletingEvent.php`::stopPropagation — Stop event propagation (used by hooks to reject deletion)
- `lib/Event/ObjectUpdatingEvent.php`::isPropagationStopped — Check if propagation has been stopped by a hook
- `lib/Event/ObjectUpdatingEvent.php`::stopPropagation — Stop event propagation (used by hooks to reject update)
- `lib/Event/UserProfileUpdatedEvent.php`::hasChanged — Check if a specific field was changed.
- `lib/Event/UserProfileUpdatedEvent.php`::hasNameChanges — Check if any name fields were changed.
- `lib/EventListener/AbstractNodeFolderEventListener.php`::handle — Handle event dispatched by the event dispatcher.
- _... 9 more entries in JSON sidecar_

### cluster: document-zaakdossier (27 methods)
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchDocumentIndexer.php`::indexObject — Index a single object.
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchDocumentIndexer.php`::bulkIndexObjects — Index multiple objects in bulk.
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchDocumentIndexer.php`::deleteObject — Delete an object from the index.
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchDocumentIndexer.php`::clearIndex — Clear all documents from index.
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::indexObject — Index a single object.
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::bulkIndexObjects — Index multiple objects in bulk.
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::indexDocuments — Index raw documents (not ObjectEntity).
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::deleteObject — Delete an object from the index.
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::deleteByQuery — Delete documents by query.
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::commit — Commit changes to Solr.
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::clearIndex — Clear all documents from the index.
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::optimize — Optimize the Solr index.
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php`::getDocumentCount — Get document count in the index.
- `lib/Service/Index/DocumentBuilder.php`::createDocument — Create a Solr document from an ObjectEntity
- `lib/Service/Index/DocumentBuilder.php`::flattenRelationsForSolr — Flatten relations array for SOLR - extract all values from relations key-value pairs
- `lib/Service/Index/DocumentBuilder.php`::flattenFilesForSolr — Flatten files array for SOLR to prevent document multiplication
- `lib/Service/Index/DocumentBuilder.php`::extractIdFromObject — Extract ID/UUID from an object/array
- `lib/Service/Index/DocumentBuilder.php`::extractArraysFromRelations — Extract array fields from dot-notation relations
- `lib/Service/Index/DocumentBuilder.php`::extractIndexableArrayValues — Extract indexable values from an array for SOLR indexing
- `lib/Service/Index/DocumentBuilder.php`::mapFieldToSolrType — Map field name and type to appropriate SOLR field name
- _... 7 more entries in JSON sidecar_

### cluster: archival-destruction-workflow (20 methods)
- `lib/BackgroundJob/DestructionCheckJob.php`::__construct — OpenRegister Destruction Check Background Job
- `lib/BackgroundJob/DestructionCheckJob.php`::run — Execute the destruction check job.
- `lib/BackgroundJob/DestructionCheckJob.php`::sendObjectNotification — Send a notification about a specific object.
- `lib/Controller/ArchivalController.php`::createLegalHold — Place a legal hold on one or more objects.
- `lib/Controller/ArchivalController.php`::releaseLegalHold — Release a legal hold on an object.
- `lib/Service/Archival/ArchiefactiedatumCalculator.php`::determineBrondatum — Determine the base date (brondatum) for the calculation.
- `lib/Service/Archival/ArchiefactiedatumCalculator.php`::brondatumFromClosure — Get brondatum from case closure date (afgehandeld method).
- `lib/Service/Archival/ArchiefactiedatumCalculator.php`::brondatumFromProperty — Get brondatum from a named property on the object (eigenschap method).
- `lib/Service/Archival/ArchiefactiedatumCalculator.php`::brondatumFromTermijn — Get brondatum from closure date plus process term (termijn method).
- `lib/Service/Archival/DestructionService.php`::extendArchiefactiedatum — Extend the archiefactiedatum for an object by the configured period.
- `lib/Service/Archival/LegalHoldService.php`::placeHold — Place a legal hold on an object.
- `lib/Service/Archival/LegalHoldService.php`::releaseHold — Release a legal hold on an object.
- `lib/Service/Archival/LegalHoldService.php`::hasActiveHold — Check if an object has an active legal hold.
- `lib/Service/Archival/LegalHoldService.php`::hasActiveHoldFromRetention — Check if an object has an active legal hold using its retention array directly.
- `lib/Service/Archival/LegalHoldService.php`::bulkPlaceHold — Schedule a bulk legal hold operation on all objects in a schema.
- `lib/Service/Archival/LegalHoldService.php`::getCurrentUserId — Get the current authenticated user ID.
- `lib/Service/ArchivalService.php`::calculateArchivalDate — Calculate the archival action date from a selection list and close date.
- `lib/Service/ArchivalService.php`::findObjectsDueForDestruction — Find objects that are due for destruction.
- `lib/Service/ArchivalService.php`::destroyObject — Destroy a single object and create audit trail.
- `lib/Service/ArchivalService.php`::extendRetentionForObject — Extend the retention period for a specific object.

### cluster: deprecate-published-metadata (19 methods)
- `lib/Repair/RegisterRiskLevelMetadata.php`::getName — Get the name of this repair step.
- `lib/Repair/RegisterRiskLevelMetadata.php`::run — Run the repair step.
- `lib/Service/Object/MetadataHandler.php`::getValueFromPath — MetadataHandler
- `lib/Service/Object/MetadataHandler.php`::generateSlugFromValue — Generate a slug from a given value.
- `lib/Service/Object/MetadataHandler.php`::createSlugHelper — Creates a URL-friendly slug from a string.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::hydrateObjectMetadata — Hydrates simple object metadata from schema configuration.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::tryCommonFields — Try to extract a value from common field names.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::getValueFromPath — Gets a value from an object using dot notation path.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::extractMetadataValue — Extracts metadata value from object data with support for twig-like concatenation and fallbacks.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::processFieldWithFallbacks — Processes a pipe-separated fallback chain and returns the first non-empty value.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::processTwigLikeTemplate — Processes twig-like templates by extracting field values and concatenating them.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::processMapFilter — Processes a map filter expression by looking up a field value in a key-value map.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::processIfFilledFilter — Processes an ifFilled filter expression that returns one value when a field is filled
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::resolveRelationValue — Resolve a relation field value (UUID) to an object name.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::isRelationProperty — Check if a schema property is a relation (references another object).
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::extractUuidFromValue — Extract a UUID string from a value that may be a string, array, or object.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::createSlugFromValue — Creates a URL-friendly slug from a metadata value.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::generateSlug — Generates a slug for an object based on schema configuration.
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`::createSlug — Creates a URL-friendly slug from text.

### cluster: mock-registers (18 methods)
- `src/entities/agent/agent.mock.ts`::mockAgentData — arrow:mockAgentData
- `src/entities/agent/agent.mock.ts`::mockAgent — arrow:mockAgent
- `src/entities/application/application.mock.ts`::mockApplicationData — arrow:mockApplicationData
- `src/entities/application/application.mock.ts`::mockApplication — arrow:mockApplication
- `src/entities/conversation/conversation.mock.ts`::mockConversationData — arrow:mockConversationData
- `src/entities/conversation/conversation.mock.ts`::mockConversation — arrow:mockConversation
- `src/entities/database/database.mock.ts`::mockDatabaseData — arrow:mockDatabaseData
- `src/entities/database/database.mock.ts`::mockDatabase — arrow:mockDatabase
- `src/entities/message/message.mock.ts`::mockMessageData — arrow:mockMessageData
- `src/entities/message/message.mock.ts`::mockMessage — arrow:mockMessage
- `src/entities/object/object.mock.ts`::mockObjectData — arrow:mockObjectData
- `src/entities/object/object.mock.ts`::mockObject — arrow:mockObject
- `src/entities/organisation/organisation.mock.ts`::mockOrganisationData — arrow:mockOrganisationData
- `src/entities/organisation/organisation.mock.ts`::mockOrganisation — arrow:mockOrganisation
- `src/entities/schema/schema.mock.ts`::mockSchemaData — arrow:mockSchemaData
- `src/entities/schema/schema.mock.ts`::mockSchema — arrow:mockSchema
- `src/entities/source/source.mock.ts`::mockSourceData — arrow:mockSourceData
- `src/entities/source/source.mock.ts`::mockSource — arrow:mockSource

### cluster: retention-management (15 methods)
- `lib/Controller/RetentionController.php`::approveDestructionList — Approve a destruction list (full or partial).
- `lib/Controller/RetentionController.php`::rejectDestructionList — Reject a destruction list.
- `lib/Controller/RetentionController.php`::placeLegalHold — Place a legal hold on a single object.
- `lib/Controller/RetentionController.php`::releaseLegalHold — Release a legal hold on an object.
- `lib/Controller/RetentionController.php`::placeBulkLegalHold — Place a bulk legal hold on all objects in a schema.
- `lib/Service/RetentionService.php`::determineBrondatum — Determine the brondatum (source date) based on afleidingswijze.
- `lib/Service/RetentionService.php`::recalculateArchiefactiedatum — Recalculate archiefactiedatum when a source property changes.
- `lib/Service/RetentionService.php`::lookupSelectielijstEntry — Look up a selectielijst entry by categorie code.
- `lib/Service/RetentionService.php`::placeLegalHold — Place a legal hold on an object.
- `lib/Service/RetentionService.php`::releaseLegalHold — Release a legal hold on an object.
- `lib/Service/RetentionService.php`::hasActiveLegalHold — Check if an object has an active legal hold.
- `lib/Service/RetentionService.php`::extendArchiefactiedatum — Extend archiefactiedatum by a period for excluded/rejected objects.
- `lib/Service/RetentionService.php`::findEligibleForDestruction — Find objects eligible for destruction.
- `lib/Service/RetentionService.php`::createDestructionList — Create a destruction list as a register object.
- `lib/Service/RetentionService.php`::extractSelectielijstBron — Extract selectielijst bron references from a destruction list.

### cluster: audit-trail-immutable (13 methods)
- `lib/Service/File/FileAuditHandler.php`::logDownload — Log a file download event.
- `lib/Service/File/FileAuditHandler.php`::logBulkDownload — Log a bulk download event (ZIP archive).
- `lib/Service/File/FileAuditHandler.php`::getCurrentUserId — Get the current user ID.
- `lib/Service/Object/AuditHandler.php`::getLogs — Get audit logs for an object
- `lib/Service/Object/AuditHandler.php`::validateObjectOwnership — Validate object ownership
- `lib/Service/Object/AuditHandler.php`::extractSchemaId — Extract schema ID from schema data
- `lib/Service/Object/AuditHandler.php`::extractSchemaSlug — Extract schema slug from schema data
- `src/modals/logs/ClearAuditTrails.vue`::hasActiveFilters — class-method:hasActiveFilters
- `src/modals/logs/ClearAuditTrails.vue`::displayFilters — class-method:displayFilters
- `src/modals/logs/ClearAuditTrails.vue`::closeDialog — class-method:closeDialog
- `src/modals/logs/ClearAuditTrails.vue`::clearAuditTrails — class-method:clearAuditTrails
- `src/modals/logs/ClearAuditTrails.vue`::formatFilterKey — class-method:formatFilterKey
- `src/modals/logs/ClearAuditTrails.vue`::formatFilterValue — class-method:formatFilterValue

### cluster: verwerkingsregister-api (11 methods)
- `lib/Controller/GdprEntitiesController.php`::index — Get all entities with optional filtering and pagination
- `lib/Controller/GdprEntitiesController.php`::show — Get a single entity by ID
- `lib/Controller/GdprEntitiesController.php`::getTypes — Get entity types for filtering
- `lib/Controller/GdprEntitiesController.php`::getCategories — Get entity categories for filtering
- `lib/Controller/GdprEntitiesController.php`::getStats — Get entity statistics
- `lib/Controller/GdprEntitiesController.php`::destroy — Delete an entity
- `lib/Service/File/DocumentProcessingHandler.php`::replaceWords — Replace words in a document.
- `lib/Service/File/DocumentProcessingHandler.php`::anonymizeDocument — Anonymize a document by replacing entity values.
- `lib/Service/File/DocumentProcessingHandler.php`::replaceWordsInWordDocument — Replace words in a Word document.
- `lib/Service/File/DocumentProcessingHandler.php`::replaceWordsInTextDocument — Replace words in a text-based document.
- `lib/Service/Object/SaveObjects/ChunkProcessingHandler.php`::processObjectsChunk — Process a chunk of objects for bulk save operations.

### cluster: content-versioning (10 methods)
- `lib/BackgroundJob/ExecutionHistoryCleanupJob.php`::run — Execute the cleanup job.
- `lib/Service/Chat/MessageHistoryHandler.php`::buildMessageHistory — Build message history array for LLM
- `lib/Service/Chat/MessageHistoryHandler.php`::storeMessage — Store a message in the database
- `lib/Service/File/FileVersioningHandler.php`::listVersions — List versions for a file.
- `lib/Service/File/FileVersioningHandler.php`::restoreVersion — Restore a specific version of a file.
- `lib/Service/File/FileVersioningHandler.php`::getCurrentUserId — Get the current user ID.
- `src/components/shared/VersionInfoCard.vue`::updateButtonType — class-method:updateButtonType
- `src/components/shared/VersionInfoCard.vue`::updateButtonDisabled — class-method:updateButtonDisabled
- `src/components/shared/VersionInfoCard.vue`::updateButtonText — class-method:updateButtonText
- `src/components/shared/VersionInfoCard.vue`::handleUpdateClick — class-method:handleUpdateClick

### cluster: schema-hooks (7 methods)
- `lib/Listener/HookListener.php`::handle — Handle event by delegating to HookExecutor
- `lib/Listener/HookListener.php`::getObjectFromEvent — Extract the ObjectEntity from the event
- `lib/Service/HookExecutor.php`::getObjectFromEvent — Extract the ObjectEntity from the event.
- `lib/Service/HookExecutor.php`::isEventStopped — Check if the event has had its propagation stopped.
- `lib/Service/HookExecutor.php`::executeSingleHook — Execute a single hook against the event and object.
- `lib/Service/HookExecutor.php`::setModifiedDataOnEvent — Set modified data on the event.
- `lib/Service/HookExecutor.php`::setValidationMetadata — Set validation metadata on the object entity.

### cluster: notificatie-engine (7 methods)
- `lib/Notification/Notifier.php`::getID — Identifier of the notifier.
- `lib/Notification/Notifier.php`::prepare — Prepare notification for display.
- `lib/Notification/Notifier.php`::prepareConfigurationUpdate — Prepare configuration update notification.
- `lib/Service/NotificationService.php`::notifyConfigurationUpdate — OpenRegister Notification Service
- `lib/Service/NotificationService.php`::sendUpdateNotification — Send update notification to a specific user
- `lib/Service/NotificationService.php`::markConfigurationUpdated — Mark configuration update notification as processed.
- `src/views/account/sections/NotificationsSection.vue`::save — vue-method:save

### cluster: tenant-lifecycle (4 methods)
- `lib/BackgroundJob/TenantDeprovisionJob.php`::run — Execute the background job.
- `lib/Service/TenantLifecycleService.php`::validateTransition — Validate that a state transition is allowed.
- `lib/Service/TenantLifecycleService.php`::isValidEnvironment — Validate an environment value.
- `lib/Service/TenantLifecycleService.php`::isValidPromotionOrder — Validate OTAP promotion order (source must be lower than target).

### cluster: tenant-isolation-audit (3 methods)
- `lib/BackgroundJob/TenantPurgeJob.php`::run — Execute the background job.
- `lib/BackgroundJob/TenantUsageSyncJob.php`::run — Execute the background job: flush APCu counters to database.
- `lib/Middleware/TenantQuotaMiddleware.php`::checkRequestQuota — Check request quota using APCu counters.

## Bucket 2b — No capability owner (reverse-spec --cluster)

Note: cluster labels are directory-derived ('service', 'modals', 'views', 'controller', 'store', ...). Before invoking `/opsx-reverse-spec --cluster <name>`, review the entries within a cluster and split them into purpose-coherent groups — many clusters here are far too broad to become a single REQ.

### cluster: service (1133 methods)
- `lib/Service/ActionExecutor.php`::executeActions — Execute a list of matching actions for an event
- `lib/Service/ActionExecutor.php`::executeSingleAction — Execute a single action
- `lib/Service/ActionExecutor.php`::buildCloudEventPayload — Build CloudEvent payload for an action execution
- `lib/Service/ActionExecutor.php`::processWorkflowResult — Process a workflow result from sync execution
- `lib/Service/ActionExecutor.php`::handleFailure — Handle action execution failure based on failure mode
- `lib/Service/ActionExecutor.php`::createLogEntry — Create an ActionLog entry for an execution
- `lib/Service/ActionService.php`::createAction — Create a new action
- `lib/Service/ActionService.php`::updateAction — Update an existing action
- `lib/Service/ActionService.php`::deleteAction — Soft-delete an action
- `lib/Service/ActionService.php`::testAction — Test an action with a dry-run simulation
- _... 1123 more entries in JSON sidecar_

### cluster: modals (584 methods)
- `src/modals/agent/DeleteAgent.vue`::confirmDelete — vue-method:confirmDelete
- `src/modals/agent/DeleteAgent.vue`::closeDialog — vue-method:closeDialog
- `src/modals/agent/EditAgent.vue`::initializeAgent — vue-method:initializeAgent
- `src/modals/agent/EditAgent.vue`::updateType — vue-method:updateType
- `src/modals/agent/EditAgent.vue`::updateRagSearchMode — vue-method:updateRagSearchMode
- `src/modals/agent/EditAgent.vue`::updateGroups — vue-method:updateGroups
- `src/modals/agent/EditAgent.vue`::removeGroup — vue-method:removeGroup
- `src/modals/agent/EditAgent.vue`::updateViews — vue-method:updateViews
- `src/modals/agent/EditAgent.vue`::addInvitedUser — vue-method:addInvitedUser
- `src/modals/agent/EditAgent.vue`::removeInvitedUser — vue-method:removeInvitedUser
- _... 574 more entries in JSON sidecar_

### cluster: controller (490 methods)
- `lib/Controller/ActionsController.php`::index — index (no docblock)
- `lib/Controller/ActionsController.php`::show — show (no docblock)
- `lib/Controller/ActionsController.php`::create — create (no docblock)
- `lib/Controller/ActionsController.php`::update — update (no docblock)
- `lib/Controller/ActionsController.php`::patch — patch (no docblock)
- `lib/Controller/ActionsController.php`::destroy — destroy (no docblock)
- `lib/Controller/ActionsController.php`::test — test (no docblock)
- `lib/Controller/ActionsController.php`::logs — logs (no docblock)
- `lib/Controller/ActionsController.php`::migrateFromHooks — migrateFromHooks (no docblock)
- `lib/Controller/AgentsController.php`::page — Render the Agents page
- _... 480 more entries in JSON sidecar_

### cluster: views (476 methods)
- `src/views/Endpoint/EndpointDetails.vue`::testEndpoint — vue-method:testEndpoint
- `src/views/account/sections/AccountSection.vue`::requestDeactivation — vue-method:requestDeactivation
- `src/views/account/sections/AccountSection.vue`::cancelDeactivation — vue-method:cancelDeactivation
- `src/views/account/sections/AccountSection.vue`::formatDate — vue-method:formatDate
- `src/views/account/sections/ActivitySection.vue`::loadActivity — vue-method:loadActivity
- `src/views/account/sections/ActivitySection.vue`::loadMore — vue-method:loadMore
- `src/views/account/sections/ActivitySection.vue`::fetchActivity — vue-method:fetchActivity
- `src/views/account/sections/ActivitySection.vue`::formatTime — vue-method:formatTime
- `src/views/account/sections/ActivitySection.vue`::hasMore — class-method:hasMore
- `src/views/account/sections/AvatarSection.vue`::triggerUpload — vue-method:triggerUpload
- _... 466 more entries in JSON sidecar_

### cluster: store (236 methods)
- `src/store/modules/agent.js`::setViewMode — class-method:setViewMode
- `src/store/modules/agent.js`::setAgentItem — class-method:setAgentItem
- `src/store/modules/agent.js`::setAgentList — class-method:setAgentList
- `src/store/modules/agent.js`::setPagination — class-method:setPagination
- `src/store/modules/agent.js`::setFilters — class-method:setFilters
- `src/store/modules/agent.js`::refreshAgentList — class-method:refreshAgentList
- `src/store/modules/agent.js`::getAgent — class-method:getAgent
- `src/store/modules/agent.js`::deleteAgent — class-method:deleteAgent
- `src/store/modules/agent.js`::saveAgent — class-method:saveAgent
- `src/store/modules/agent.js`::getStats — class-method:getStats
- _... 226 more entries in JSON sidecar_

### cluster: components (73 methods)
- `src/components/AgentSelector.vue`::t — vue-method:t
- `src/components/AgentSelector.vue`::data — class-method:data
- `src/components/AgentSelector.vue`::hasCapabilities — class-method:hasCapabilities
- `src/components/AgentSelector.vue`::getViewName — class-method:getViewName
- `src/components/AgentSelector.vue`::getToolName — class-method:getToolName
- `src/components/AgentSelector.vue`::handleStartConversation — class-method:handleStartConversation
- `src/components/AgentSelector.vue`::toggleExpand — class-method:toggleExpand
- `src/components/AgentSelector.vue`::isExpanded — class-method:isExpanded
- `src/components/AgentSelector.vue`::getVisibleViews — class-method:getVisibleViews
- `src/components/AgentSelector.vue`::getVisibleTools — class-method:getVisibleTools
- _... 63 more entries in JSON sidecar_

### cluster: tool (56 methods)
- `lib/Tool/AbstractTool.php`::getUserId — Get the current user ID
- `lib/Tool/AbstractTool.php`::applyViewFilters — Apply view filters to query parameters
- `lib/Tool/AbstractTool.php`::formatSuccess — Format a success result
- `lib/Tool/AbstractTool.php`::log — Log tool execution
- `lib/Tool/AbstractTool.php`::validateParameters — Validate required parameters
- `lib/Tool/AbstractTool.php`::__call — Magic method to support snake_case method calls for LLPhant compatibility
- `lib/Tool/AgentTool.php`::getName — Get the tool name
- `lib/Tool/AgentTool.php`::getDescription — Get the tool description
- `lib/Tool/AgentTool.php`::getFunctions — Get function definitions for LLM function calling
- `lib/Tool/AgentTool.php`::listAgents — List agents
- _... 46 more entries in JSON sidecar_

### cluster: backgroundjob (28 methods)
- `lib/BackgroundJob/ActionRetryJob.php`::run — Run the retry job
- `lib/BackgroundJob/ActionRetryJob.php`::calculateDelay — Calculate retry delay in seconds based on retry policy
- `lib/BackgroundJob/ActionScheduleJob.php`::run — Run the schedule evaluation
- `lib/BackgroundJob/BlobMigrationJob.php`::run — Execute the blob migration job
- `lib/BackgroundJob/BlobMigrationJob.php`::blobTableExists — Check if the blob table exists in the database.
- `lib/BackgroundJob/BlobMigrationJob.php`::fetchBlobObjects — Fetch a batch of objects from the blob table.
- `lib/BackgroundJob/BlobMigrationJob.php`::countBlobRows — Count remaining rows in the blob table.
- `lib/BackgroundJob/BlobMigrationJob.php`::groupByRegisterSchema — Group blob rows by their register+schema combination.
- `lib/BackgroundJob/BlobMigrationJob.php`::blobRowToObjectArray — Convert a blob table row to an object array suitable for MagicMapper.
- `lib/BackgroundJob/BlobMigrationJob.php`::deleteBlobRows — Delete migrated rows from the blob table.
- _... 18 more entries in JSON sidecar_

### cluster: activity (25 methods)
- `lib/Activity/Provider.php`::parse — Parse an activity event into a human-readable format.
- `lib/Activity/ProviderSubjectHandler.php`::applySubjectText — OpenRegister ProviderSubjectHandler.
- `lib/Activity/ProviderSubjectHandler.php`::buildRichParams — Build rich parameters for an event.
- `lib/Activity/ProviderSubjectHandler.php`::applySimpleSubject — Apply a simple parsed and rich subject to the event.
- `lib/Activity/Setting/ObjectSetting.php`::getIdentifier — Get the identifier for this setting.
- `lib/Activity/Setting/ObjectSetting.php`::getGroupIdentifier — Get the group identifier for this setting.
- `lib/Activity/Setting/ObjectSetting.php`::getPriority — Get the priority for this setting.
- `lib/Activity/Setting/ObjectSetting.php`::canChangeStream — Whether the user can change the stream setting.
- `lib/Activity/Setting/ObjectSetting.php`::isDefaultEnabledStream — Whether the stream is enabled by default.
- `lib/Activity/Setting/ObjectSetting.php`::canChangeMail — Whether the user can change the mail setting.
- _... 15 more entries in JSON sidecar_

### cluster: listener (22 methods)
- `lib/Listener/ActionListener.php`::handle — Handle event by finding and executing matching actions
- `lib/Listener/ActionListener.php`::getEventTypeName — Get the short event type name from an event class
- `lib/Listener/ActionListener.php`::extractPayload — Extract payload data from an event
- `lib/Listener/ActionListener.php`::applyFilterConditions — Apply filter_condition matching against the payload
- `lib/Listener/ActionListener.php`::getNestedValue — Get a nested value from an array using dot notation
- `lib/Listener/CommentsEntityListener.php`::handle — Handle the CommentsEntityEvent.
- `lib/Listener/FileChangeListener.php`::handle — Handle file events
- `lib/Listener/ObjectChangeListener.php`::handle — Handle object events
- `lib/Listener/ObjectChangeListener.php`::processExtractionMode — Process extraction based on configured mode
- `lib/Listener/ObjectChangeListener.php`::processImmediateExtraction — Process immediate synchronous extraction
- _... 12 more entries in JSON sidecar_

### cluster: services (22 methods)
- `src/services/AppInitializationService.js`::initializeAppData — function:initializeAppData
- `src/services/AppInitializationService.js`::reloadAppData — function:reloadAppData
- `src/services/AppInitializationService.js`::loadRegisters — function:loadRegisters
- `src/services/AppInitializationService.js`::forceLoadRegisters — function:forceLoadRegisters
- `src/services/AppInitializationService.js`::loadSchemas — function:loadSchemas
- `src/services/AppInitializationService.js`::forceLoadSchemas — function:forceLoadSchemas
- `src/services/AppInitializationService.js`::loadOrganisations — function:loadOrganisations
- `src/services/AppInitializationService.js`::forceLoadOrganisations — function:forceLoadOrganisations
- `src/services/AppInitializationService.js`::loadApplications — function:loadApplications
- `src/services/AppInitializationService.js`::forceLoadApplications — function:forceLoadApplications
- _... 12 more entries in JSON sidecar_

### cluster: reference (21 methods)
- `lib/Reference/ObjectReferenceProvider.php`::getId — Returns the unique identifier for this reference provider.
- `lib/Reference/ObjectReferenceProvider.php`::getOrder — Returns the order/priority for Smart Picker sorting.
- `lib/Reference/ObjectReferenceProvider.php`::getSupportedSearchProviderIds — Returns the supported search provider IDs for the Smart Picker.
- `lib/Reference/ObjectReferenceProvider.php`::matchReference — Check if a URL matches an OpenRegister object reference.
- `lib/Reference/ObjectReferenceProvider.php`::resolveReference — Resolve a matched URL into a rich reference object.
- `lib/Reference/ObjectReferenceProvider.php`::getCachePrefix — Returns the cache prefix for a reference URL.
- `lib/Reference/ObjectReferenceProvider.php`::getCacheKey — Returns the cache key for a reference URL.
- `lib/Reference/ObjectReferenceProvider.php`::parseReference — Parse a reference URL into its component parts.
- `lib/Reference/ObjectReferenceProvider.php`::extractTitle — Extract the display title from object data.
- `lib/Reference/ObjectReferenceProvider.php`::extractDescription — Extract a description from object data.
- _... 11 more entries in JSON sidecar_

### cluster: command (19 methods)
- `lib/Command/MigrateStorageCommand.php`::configure — Configure the command.
- `lib/Command/MigrateStorageCommand.php`::execute — Execute the migration command.
- `lib/Command/SolrDebugCommand.php`::configure — HTTP client service (unused but required by dependency injection).
- `lib/Command/SolrDebugCommand.php`::execute — Execute the command
- `lib/Command/SolrDebugCommand.php`::showTenantInfo — Show tenant information
- `lib/Command/SolrDebugCommand.php`::testSetup — Test SOLR setup
- `lib/Command/SolrDebugCommand.php`::testConnection — Test SOLR connection
- `lib/Command/SolrDebugCommand.php`::checkCores — Check existing cores/collections
- `lib/Command/SolrDebugCommand.php`::testSolrAdminAPI — Test SOLR Admin API directly
- `lib/Command/SolrManagementCommand.php`::configure — Configure the command
- _... 9 more entries in JSON sidecar_

### cluster: cron (16 methods)
- `lib/Cron/ConfigurationCheckJob.php`::__construct — OpenRegister Configuration Check Job
- `lib/Cron/ConfigurationCheckJob.php`::run — Run the background job
- `lib/Cron/ConfigurationCheckJob.php`::isJobDisabled — Check if the job is currently disabled via configuration
- `lib/Cron/ConfigurationCheckJob.php`::checkSingleConfiguration — Check a single configuration for updates
- `lib/Cron/ConfigurationCheckJob.php`::handleAutoUpdate — Handle automatic update of a configuration
- `lib/Cron/ConfigurationCheckJob.php`::sendUpdateNotification — Send update notification for a configuration
- `lib/Cron/LogCleanUpTask.php`::__construct — OpenRegister Log Cleanup Task
- `lib/Cron/LogCleanUpTask.php`::run — Execute the log cleanup task
- `lib/Cron/SyncConfigurationsJob.php`::__construct — OpenRegister Configuration Sync Job
- `lib/Cron/SyncConfigurationsJob.php`::run — Run the background job
- _... 6 more entries in JSON sidecar_

### cluster: calendar (13 methods)
- `lib/Calendar/RegisterCalendar.php`::getKey — Get the unique key for this calendar
- `lib/Calendar/RegisterCalendar.php`::getUri — Get the URI for this calendar
- `lib/Calendar/RegisterCalendar.php`::getDisplayName — Get the display name for this calendar
- `lib/Calendar/RegisterCalendar.php`::getDisplayColor — Get the display color for this calendar
- `lib/Calendar/RegisterCalendar.php`::getPermissions — Get the permissions for this calendar (read-only)
- `lib/Calendar/RegisterCalendar.php`::isDeleted — Check if this calendar is deleted
- `lib/Calendar/RegisterCalendar.php`::search — Search for events in this virtual calendar
- `lib/Calendar/RegisterCalendar.php`::extractUserId — Extract user ID from a principal URI
- `lib/Calendar/RegisterCalendar.php`::buildTimerangeFilters — Build MagicMapper query filters from calendar search timerange options
- `lib/Calendar/RegisterCalendar.php`::findRegistersForSchema — Find all registers that contain the given schema
- _... 3 more entries in JSON sidecar_

### cluster: appinfo (6 methods)
- `lib/AppInfo/Application.php`::register — Register application components
- `lib/AppInfo/Application.php`::registerMappersWithCircularDependencies — Register mappers with circular dependencies.
- `lib/AppInfo/Application.php`::registerConfigurationServices — Register configuration-related services.
- `lib/AppInfo/Application.php`::registerSettingsServices — Register settings-related services including handlers.
- `lib/AppInfo/Application.php`::registerVectorizationService — Register vectorization service with strategies.
- `lib/AppInfo/Application.php`::registerObjectInteractionServices — Register task and note services for object interactions.

### cluster: navigation (6 methods)
- `src/navigation/Configuration.vue`::saveConfig — vue-method:saveConfig
- `src/navigation/Configuration.vue`::debounceNotification — arrow:debounceNotification
- `src/navigation/Configuration.vue`::fetchData — class-method:fetchData
- `src/navigation/MainMenu.vue`::handleNavigate — vue-method:handleNavigate
- `src/navigation/MainMenu.vue`::openLink — vue-method:openLink
- `src/navigation/MainMenu.vue`::activeOrganisationName — class-method:activeOrganisationName

### cluster: contacts (4 methods)
- `lib/Contacts/ContactsMenuProvider.php`::process — Process a contact entry and inject OpenRegister actions.
- `lib/Contacts/ContactsMenuProvider.php`::doProcess — Internal processing logic (separated for testability).
- `lib/Contacts/ContactsMenuProvider.php`::injectCountBadge — Inject a count badge summary action.
- `lib/Contacts/ContactsMenuProvider.php`::injectEntityActions — Inject individual entity actions.

### cluster: composables (4 methods)
- `src/composables/UseFileSelection.js`::useFileSelection — function:useFileSelection
- `src/composables/UseFileSelection.js`::setTags — arrow:setTags
- `src/composables/UseFileSelection.js`::reset — arrow:reset
- `src/composables/UseFileSelection.js`::setFiles — arrow:setFiles

### cluster: exception (3 methods)
- `lib/Exception/DatabaseConstraintException.php`::fromDatabaseException — Create a DatabaseConstraintException from a database exception
- `lib/Exception/DatabaseConstraintException.php`::parseConstraintError — Parse database constraint error messages and return user-friendly messages
- `lib/Exception/ReferentialIntegrityException.php`::toResponseBody — Get a structured error response body suitable for JSON API responses.

### cluster: settings (3 methods)
- `lib/Settings/OpenRegisterAdmin.php`::getForm — Get the admin settings form
- `lib/Settings/OpenRegisterAdmin.php`::getSection — Get the section identifier
- `lib/Settings/OpenRegisterAdmin.php`::getPriority — Get the priority of this settings form

### cluster: formats (2 methods)
- `lib/Formats/BsnFormat.php`::validate — OpenRegister BsnFormat
- `lib/Formats/SemVerFormat.php`::validate — Semantic Version Format Validator

### cluster: middleware (2 methods)
- `lib/Middleware/LanguageMiddleware.php`::beforeController — Called before the controller method is invoked.
- `lib/Middleware/LanguageMiddleware.php`::afterController — Called after the controller method returns a response.

### cluster: sections (2 methods)
- `lib/Sections/OpenRegisterAdmin.php`::getID — Get the ID of this admin section.
- `lib/Sections/OpenRegisterAdmin.php`::getPriority — Get the priority of this admin section.

### cluster: dialogs (2 methods)
- `src/dialogs/Dialogs.vue`::onConfigSetCreated — vue-method:onConfigSetCreated
- `src/dialogs/Dialogs.vue`::onConfigSetDeleted — vue-method:onConfigSetDeleted

### cluster: twig (1 methods)
- `lib/Twig/AuthenticationExtension.php`::getFunctions — Twig extension for authentication token functions.

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (REQ has multi-keyword co-occurrence in git history)

Heuristic flagged 219 REQs. **Many are likely false positives** — Dutch/English keyword overlap (e.g. 'version', 'workflow', 'support') makes git-log-S unreliable. Treat as 'history hints at prior work' not 'fix these first'. Verify manually.

- **archivering-vernietiging#REQ-001** — Objects MUST support archival metadata (MDTO)
  'support' and 'archival' both removed in commit 0c5eaecc7 (heuristic — verify manually)
- **archivering-vernietiging#REQ-002** — The system MUST support configurable selection lists (selectielijsten)
  'support' and 'configurable' both removed in commit ac7f1177a (heuristic — verify manually)
- **archivering-vernietiging#REQ-003** — The system MUST support automated destruction workflows
  'support' and 'automated' both removed in commit 03fc9b7ae (heuristic — verify manually)
- **archivering-vernietiging#REQ-004** — The system MUST support e-Depot export (transfer/overbrenging)
  'support' and 'depot' both removed in commit 26689e6cb (heuristic — verify manually)
- **audit-hash-chain#REQ-001** — Every audit trail entry MUST include a SHA-256 hash chained to the previous entry
  'audit' and 'trail' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **audit-hash-chain#REQ-002** — The system MUST provide a hash chain verification endpoint
  'provide' and 'chain' both removed in commit 58982b5f6 (heuristic — verify manually)
- **audit-hash-chain#REQ-003** — Hash chain writes MUST be serialized to prevent race conditions
  'chain' and 'writes' both removed in commit 1384f120b (heuristic — verify manually)
- **audit-hash-chain#REQ-004** — A database migration MUST add hash columns
  'database' and 'migration' both removed in commit 7931a37ca (heuristic — verify manually)
- **audit-trail-immutable#REQ-001** — Every mutation MUST produce an immutable audit trail entry
  'mutation' and 'produce' both removed in commit 03fc9b7ae (heuristic — verify manually)
- **audit-trail-immutable#REQ-004** — Audit trail entries MUST NOT be deletable or modifiable
  'audit' and 'trail' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **audit-trail-immutable#REQ-005** — The audit trail MUST support minimum 10-year retention
  'audit' and 'trail' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **audit-trail-immutable#REQ-006** — The audit trail MUST be exportable for compliance audits
  'audit' and 'trail' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **audit-trail-immutable#REQ-007** — Sensitive data reads MUST be audited
  'sensitive' and 'reads' both removed in commit f0c956f1e (heuristic — verify manually)
- **content-versioning#REQ-001** — Every save operation MUST produce a new version
  'operation' and 'produce' both removed in commit bf7d45035 (heuristic — verify manually)
- **content-versioning#REQ-002** — Objects MUST support a draft/published lifecycle
  'support' and 'draft' both removed in commit e3c466999 (heuristic — verify manually)
- **content-versioning#REQ-004** — The system MUST support version comparison with visual diffs
  'support' and 'version' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **content-versioning#REQ-005** — The system MUST support version rollback
  'support' and 'version' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **content-versioning#REQ-006** — Version history MUST be queryable via API
  'version' and 'history' both removed in commit ac7f1177a (heuristic — verify manually)
- **content-versioning#REQ-007** — Version metadata MUST capture comprehensive context
  'version' and 'metadata' both removed in commit 864c3bcb9 (heuristic — verify manually)
- **content-versioning#REQ-008** — Version storage MUST use a delta strategy for drafts and full snapshots for published versions
  'version' and 'storage' both removed in commit 3709e8878 (heuristic — verify manually)
- **content-versioning#REQ-009** — Version retention MUST be configurable per register
  'version' and 'retention' both removed in commit da42723cf (heuristic — verify manually)
- **content-versioning#REQ-010** — Version operations MUST respect RBAC permissions
  'version' and 'operations' both removed in commit 819aa0bf8 (heuristic — verify manually)
- **content-versioning#REQ-011** — Search MUST be configurable to include or exclude draft versions
  'search' and 'configurable' both removed in commit 7d2419dc1 (heuristic — verify manually)
- **content-versioning#REQ-012** — Bulk version operations MUST be supported
  'version' and 'operations' both removed in commit 819aa0bf8 (heuristic — verify manually)
- **content-versioning#REQ-013** — Version operations MUST perform efficiently at scale
  'version' and 'operations' both removed in commit 819aa0bf8 (heuristic — verify manually)
- **content-versioning#REQ-014** — Version events MUST be dispatched for integration
  'version' and 'events' both removed in commit 7d40602a4 (heuristic — verify manually)
- **content-versioning#REQ-015** — Versions MUST support WOO and archiving compliance
  'versions' and 'support' both removed in commit d52db336f (heuristic — verify manually)
- **content-versioning#REQ-016** — The version key "main" MUST be reserved for the published version
  'version' and 'reserved' both removed in commit 0641388d0 (heuristic — verify manually)
- **data-import-export#REQ-002** — The system MUST support bulk import via API
  'support' and 'import' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **data-import-export#REQ-006** — Import MUST support progress tracking for large datasets
  'import' and 'support' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **data-import-export#REQ-008** — Export MUST support filtering and column selection
  'export' and 'support' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **data-import-export#REQ-010** — Export MUST support streaming for large datasets
  'export' and 'support' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **data-import-export#REQ-012** — Import MUST support rollback on critical failure
  'import' and 'support' both removed in commit f4fe4ae1e (heuristic — verify manually)
- **data-import-export#REQ-017** — The system MUST support scheduled and automated imports
  'support' and 'scheduled' both removed in commit bf7d45035 (heuristic — verify manually)
- **deep-link-registry#REQ-001** — Apps SHALL register deep link patterns via boot-time events
  'shall' and 'register' both removed in commit 37c4a3bb2 (heuristic — verify manually)
- **deep-link-registry#REQ-006** — Registry MUST maintain backward compatibility
  'registry' and 'maintain' both removed in commit bf7d45035 (heuristic — verify manually)
- **deep-link-registry#REQ-008** — Cross-app deep linking SHALL work with hash-based and history-mode routing
  'cross' and 'linking' both removed in commit ff9aa99ba (heuristic — verify manually)
- **deep-link-registry#REQ-014** — Link preview metadata SHALL be available for shared deep links
  'preview' and 'metadata' both removed in commit ad836d0a2 (heuristic — verify manually)
- **edepot-transfer#REQ-005** — The system MUST support multiple transport protocols for SIP delivery
  'support' and 'transport' both removed in commit 03fc9b7ae (heuristic — verify manually)
- **edepot-transfer#REQ-006** — The system MUST track transfer status per object
  'track' and 'transfer' both removed in commit 3709e8878 (heuristic — verify manually)
- **environment-otap#REQ-001** — Organisation entities MUST have an environment type field
  'organisation' and 'entities' both removed in commit 756235b90 (heuristic — verify manually)
- **environment-otap#REQ-002** — Environment-aware behavior MUST differ between OTAP stages
  'environment' and 'aware' both removed in commit 22ed0126c (heuristic — verify manually)
- **environment-otap#REQ-003** — Configuration promotion MUST transfer settings between OTAP environments
  'configuration' and 'promotion' both removed in commit 26689e6cb (heuristic — verify manually)
- **environment-otap#REQ-004** — Database migration MUST add environment field to Organisation entity
  'database' and 'migration' both removed in commit 7931a37ca (heuristic — verify manually)
- **event-driven-architecture#REQ-001** — All entity mutations MUST dispatch typed PHP events via IEventDispatcher
  'entity' and 'mutations' both removed in commit 03fc9b7ae (heuristic — verify manually)
- **event-driven-architecture#REQ-002** — Pre-mutation events MUST support rejection and data modification via StoppableEventInterface
  'mutation' and 'events' both removed in commit 03fc9b7ae (heuristic — verify manually)
- **event-driven-architecture#REQ-005** — Webhook delivery MUST support CloudEvents v1.0 format with configurable payload strategies
  'webhook' and 'delivery' both removed in commit 8add353ec (heuristic — verify manually)
- **event-driven-architecture#REQ-006** — Webhook delivery MUST support filtering by event payload attributes
  'webhook' and 'delivery' both removed in commit 8add353ec (heuristic — verify manually)
- **event-driven-architecture#REQ-007** — Webhook delivery MUST implement retry with configurable backoff strategies
  'webhook' and 'delivery' both removed in commit 8add353ec (heuristic — verify manually)
- **event-driven-architecture#REQ-008** — Cross-app event consumption MUST work via standard Nextcloud IEventListener registration
  'cross' and 'event' both removed in commit 3709e8878 (heuristic — verify manually)
- **event-driven-architecture#REQ-009** — GraphQL subscription listeners MUST push events for real-time SSE delivery
  'graphql' and 'subscription' both removed in commit de5d7311c (heuristic — verify manually)
- **event-driven-architecture#REQ-011** — Webhook entities MUST support event subscription configuration with wildcard matching
  'webhook' and 'entities' both removed in commit 8add353ec (heuristic — verify manually)
- **event-driven-architecture#REQ-012** — Schema hooks MUST be executed via HookListener and HookExecutor on object lifecycle events
  'schema' and 'hooks' both removed in commit 8add353ec (heuristic — verify manually)
- **event-driven-architecture#REQ-014** — Event dispatch MUST be suppressible for bulk operations
  'event' and 'dispatch' both removed in commit 6789789c2 (heuristic — verify manually)
- **event-driven-architecture#REQ-016** — Request interception MUST support pre-mutation webhook notifications
  'interception' and 'support' both removed in commit d925fc088 (heuristic — verify manually)
- **faceting-configuration#REQ-005** — Custom facet title, description, and order in response
  'custom' and 'facet' both removed in commit 756235b90 (heuristic — verify manually)
- **faceting-configuration#REQ-006** — Facet counts computed independently of pagination
  'facet' and 'counts' both removed in commit 864c3bcb9 (heuristic — verify manually)
- **faceting-configuration#REQ-008** — Backend-agnostic faceting across PostgreSQL and Solr
  'backend' and 'agnostic' both removed in commit 03fc9b7ae (heuristic — verify manually)
- **faceting-configuration#REQ-009** — Multi-layered facet caching
  'multi' and 'layered' both removed in commit fb32ab948 (heuristic — verify manually)
- **faceting-configuration#REQ-013** — Faceting MUST be available through GraphQL connection types
  'faceting' and 'available' both removed in commit 864c3bcb9 (heuristic — verify manually)
- _... 159 more entries in JSON sidecar_

### 3b — never implemented

No git history reference found for 39 REQs. Candidates for `Mark deferred` or `remove` — many are roadmap/future-work items that the spec authors added proactively.

**archival-destruction-workflow** (2):
- archival-destruction-workflow#REQ-004: The DestructionExecutionJob MUST permanently delete approved objects in batches (single keyword 'permanently' seen in removed code)
- archival-destruction-workflow#REQ-008: WOO-published objects MUST be flagged on destruction lists (single keyword 'published' seen in removed code)

**archivering-vernietiging** (1):
- archivering-vernietiging#REQ-005: NEN 2082 compliance MUST be verifiable (single keyword 'compliance' seen in removed code)

**content-versioning** (1):
- content-versioning#REQ-003: Drafts MUST be promotable to published version

**deep-link-registry** (8):
- deep-link-registry#REQ-003: Registration SHALL use slugs not database IDs (single keyword 'registration' seen in removed code)
- deep-link-registry#REQ-004: URL templates SHALL support placeholder-based URL generation (single keyword 'templates' seen in removed code)
- deep-link-registry#REQ-005: Registry SHALL be in-memory only without database persistence (single keyword 'registry' seen in removed code)
- deep-link-registry#REQ-007: Canonical object URLs SHALL follow a predictable format (single keyword 'canonical' seen in removed code)
- deep-link-registry#REQ-010: API responses SHALL include self-referencing links (single keyword 'responses' seen in removed code)
- deep-link-registry#REQ-011: Deep link registry SHALL be discoverable via ICapability (single keyword 'registry' seen in removed code)
- deep-link-registry#REQ-012: Deep link resolution SHALL handle circular DI gracefully (single keyword 'resolution' seen in removed code)
- deep-link-registry#REQ-013: Deep link context SHALL support pre-selected views via query parameters (single keyword 'context' seen in removed code)

**edepot-transfer** (1):
- edepot-transfer#REQ-007: Transferred objects MUST be read-only

**event-driven-architecture** (1):
- event-driven-architecture#REQ-013: HookRetryJob MUST re-execute failed hooks with exponential backoff and CloudEvents payload (single keyword 'execute' seen in removed code)

**graphql-api** (1):
- graphql-api#REQ-011: Introspection MUST be controllable per environment (single keyword 'introspection' seen in removed code)

**linked-entity-types** (1):
- linked-entity-types#REQ-005: SaveObject Pipeline Extraction (single keyword 'pipeline' seen in removed code)

**mail-sidebar** (2):
- mail-sidebar#REQ-007: Email URL observation for automatic context switching (single keyword 'email' seen in removed code)
- mail-sidebar#REQ-009: i18n support for Dutch and English (single keyword 'support' seen in removed code)

**mariadb-ci-matrix** (3):
- mariadb-ci-matrix#REQ-001: 2-Line CI Matrix Covering Both Databases and Nextcloud Versions (single keyword 'matrix' seen in removed code)
- mariadb-ci-matrix#REQ-002: PHPUnit Tests Use the Same Database Matrix (single keyword 'tests' seen in removed code)
- mariadb-ci-matrix#REQ-003: Matrix Strategy Configuration in quality.yml (single keyword 'matrix' seen in removed code)

**mcp-discovery** (2):
- mcp-discovery#REQ-007: MCP Tool Definitions
- mcp-discovery#REQ-013: Versioned URL Paths (single keyword 'versioned' seen in removed code)

**mock-registers** (4):
- mock-registers#REQ-004: DSO Mock Register (Digitaal Stelsel Omgevingswet) (single keyword 'register' seen in removed code)
- mock-registers#REQ-007: Idempotent Import via ConfigurationService Pipeline (single keyword 'idempotent' seen in removed code)
- mock-registers#REQ-009: Data Realism and Quality (single keyword 'quality' seen in removed code)
- mock-registers#REQ-013: Mock Data Distinguishability

**object-interactions** (3):
- object-interactions#REQ-001: Notes on Objects via ICommentsManager (single keyword 'notes' seen in removed code)
- object-interactions#REQ-006: File Attachments on Objects
- object-interactions#REQ-007: Tags for Object Categorization

**retention-management** (1):
- retention-management#REQ-010: Cascading destruction MUST respect referential integrity (single keyword 'cascading' seen in removed code)

**schema-hooks** (4):
- schema-hooks#REQ-008: Stoppable Events for Hook-Based Rejection (single keyword 'events' seen in removed code)
- schema-hooks#REQ-012: Hook Logging
- schema-hooks#REQ-013: HookListener Registration and Event Delegation (single keyword 'registration' seen in removed code)
- schema-hooks#REQ-016: HookStoppedException Carries Validation Errors (single keyword 'carries' seen in removed code)

**workflow-in-import** (3):
- workflow-in-import#REQ-004: Hash-Based Idempotent Versioning (single keyword 'based' seen in removed code)
- workflow-in-import#REQ-005: DeployedWorkflow Entity Tracking (single keyword 'entity' seen in removed code)
- workflow-in-import#REQ-016: Import Pause and Resume with Workflow State (single keyword 'import' seen in removed code)

**workflow-integration** (1):
- workflow-integration#REQ-001: n8n SHALL be the primary workflow engine (single keyword 'shall' seen in removed code)

## Bucket 4 — ADR conformance findings

### missing-spec-in-file-docblock (703 files)
- `lib/Repair/RegisterRiskLevelMetadata.php`
- `lib/Service/ActivityService.php`
- `lib/Service/AuthenticationService.php`
- `lib/Service/UserService.php`
- `lib/Service/DeepLinkRegistryService.php`
- `lib/Service/SettingsService.php`
- `lib/Service/MigrationService.php`
- `lib/Service/OperatorEvaluator.php`
- `lib/Service/DeckCardService.php`
- `lib/Service/TextExtractionService.php`
- `lib/Service/ArchivalService.php`
- `lib/Service/ActionService.php`
- `lib/Service/RequestScopedCache.php`
- `lib/Service/HookExecutor.php`
- `lib/Service/FileSidebarService.php`
- `lib/Service/AuthorizationAuditService.php`
- `lib/Service/EndpointService.php`
- `lib/Service/UploadService.php`
- `lib/Service/LinkedEntityService.php`
- `lib/Service/ActionExecutor.php`
- `lib/Service/ViewService.php`
- `lib/Service/DashboardService.php`
- `lib/Service/SearchTrailService.php`
- `lib/Service/ImportService.php`
- `lib/Service/WebhookService.php`
- _... 678 more in JSON sidecar_

### missing-copyright-in-file-docblock (48 files)
- `lib/Service/AuthenticationService.php`
- `lib/Service/LinkedEntityService.php`
- `lib/Service/AuditHashService.php`
- `lib/Service/AuthorizationService.php`
- `lib/Controller/ConsumersController.php`
- `lib/Controller/LinkedEntityController.php`
- `lib/Exception/AuthenticationException.php`
- `lib/Twig/AuthenticationRuntime.php`
- `lib/Twig/AuthenticationExtension.php`
- `lib/Service/File/FileSharingHandler.php`
- `lib/Service/File/FileValidationHandler.php`
- `lib/Service/File/FileAuditHandler.php`
- `lib/Service/File/FileFormattingHandler.php`
- `lib/Service/File/TaggingHandler.php`
- `lib/Service/File/ReadFileHandler.php`
- `lib/Service/File/FileVersioningHandler.php`
- `lib/Service/File/FileBatchHandler.php`
- `lib/Service/File/FilePreviewHandler.php`
- `lib/Service/File/DocumentProcessingHandler.php`
- `lib/Service/File/FileCrudHandler.php`
- `lib/Service/File/CreateFileHandler.php`
- `lib/Service/File/DeleteFileHandler.php`
- `lib/Service/File/FileLockHandler.php`
- `lib/Service/File/FolderManagementHandler.php`
- `lib/Service/File/UpdateFileHandler.php`
- _... 23 more in JSON sidecar_

### direct-sql-outside-openregister (1 files)
- `lib/Controller/SettingsController.php`

## Notes for the human reviewer

- No .opsx-ignore file present in the app.
- This is the first coverage scan on this branch. Prior reports: none.
- REQ inventory derives REQ IDs from positional order ('### Requirement:' headings) as capability#REQ-NNN. Spec files do not carry intrinsic REQ IDs — downstream tools (opsx-annotate / opsx-reverse-spec) must use the same positional scheme or re-derive IDs from heading text.
- Redirect-status specs (status: redirect) are excluded from REQ inventory (e.g. built-in-dashboards).
- Confidence scoring is heuristic. Bucket 1 requires either >=2 keyword matches or 1 keyword match on a path that matches the capability directory. Matches at 0.70-0.84 are flagged needs_review. A human MUST spot-check Bucket 1 before /opsx-annotate runs — especially entries tagged with 'single-keyword-match' or 'NEEDS-REVIEW'.
- Pass B resolves private helpers by inheriting the REQ of their callers. Iteration depth capped at 3. Inherited bucket_1 entries carry signal='pass-b-inherit(...)'.
- Bucket 3a/3b distinction is weak. The tight heuristic requires both REQ keywords to co-occur in at least one historical commit diff — even then, many 3a entries are likely false positives from Dutch/English word overlap (e.g. 'version' or 'workflow' appears in unrelated commits). Treat 3a as 'REQ has some faint git history' not 'implementation was removed'. Verify manually before treating any 3a entry as a repair target.
- Large Bucket 1 warning: >400 methods across hundreds of files. Per skill guidance, annotate one capability at a time when the --capability flag lands. For now, review the by-capability grouping in the .md before running /opsx-annotate.
- Top 2b clusters are generic directory labels (service/modals/views/controller/store). These reflect that openregister has many methods in service/controller code that do not map cleanly to a single capability — likely cross-cutting plumbing (SaveObjectHandler, ObjectHandler, UuidHandler, etc.) or features genuinely without a spec. Reverse-spec clusters should inspect these labels and split them into purpose-coherent groups before writing new REQs.
- ADR-014 licensing: 48 PHP files lack @copyright; 703 files (PHP + Vue/TS/JS) lack any @spec tag in their file docblock — expected for a first scan on legacy code. /opsx-annotate will add @spec tags for Bucket 1 matches; the remaining files need reverse-spec or deferred.
- One direct-SQL finding (ADR-001 deviation). Review to confirm it's not a legitimate low-level DB helper.

## REQs flagged as ambiguous / low-confidence

REQs whose top-3 keywords are all <=4 chars (common verbs/short words) — matches involving them are likely weak:
