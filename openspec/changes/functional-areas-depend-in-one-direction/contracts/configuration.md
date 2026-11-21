# Contract design: the Configuration area

Wave 5 design package D09 of the change `functional-areas-depend-in-one-direction` (hydra ADR-119,
Decisions 8, 9, 13 and 14). This file is a design. It changes nothing in `lib/`.

Measured on `origin/main` `911c2702` with the module graph of the maintainability checker, the reading
`modularity_check.py` uses. Lines are code lines as that tool counts them (no
comments, no blank lines). The fleet was read with `fleet_checkout.sh` (development branches of
the 19 apps, 2026-11-19).

## Summary

| | Today | After this design |
|---|---|---|
| Exposed lines of Configuration | 6,895 | 121 |
| Exposed files of Configuration | 89 | 11 |
| References against the order into Configuration | 146 | 0 |
| References against the order out of Configuration (all to Platform) | 19 | 2 (flagged, section 4) |
| References against the order, whole app | 727 | 548 |
| Exposed lines, whole app | 155,651 | 148,650 |
| New port lines in the lower areas (their budgets) | – | 151 (Core 54, Integrations 46, Search 22, Compliance 12, Notifications 7, Processes 7, Kernel +3) |

The "after" column is a simulation, not an estimate. A script applied every move listed below to the graph
measured on `911c2702`: csv rows reassigned, caller edges rewired, bindings moved, new ports and new classes
added with their line counts, one interface removed. It then reran the exposure and order arithmetic of
`modularity_check.py`. A port's line count is estimated from the existing ports in
`lib/Kernel/Contracts/Core/`, which take 6 to 9 lines for 1 to 3 one-line methods. The simulation found no
new reference against the order anywhere in the app.

Configuration is rank 8 of 10. Only Platform may use it. Its exposure is therefore almost all wrong-way
traffic: 146 references from eight lower areas, 34 Core files among them. Three causes account for most of
it:

1. **Work filed under Configuration that another area does.** The translation store and language
   negotiation (Core's save and render path read them), identifier sequences (Core's generated-identifier
   listener), task sequences (Processes' approval chains), the redaction-policy engine (Compliance) and a
   dozen small values that the lower caller builds. 2,050 exposed lines move by csv row, with no new code.
2. **Settings and services lower areas read.** File, retention, archival and search-backend settings, tenant
   keys, MCP agent scope, federated configuration, machine translation, register-bundle import, job-run
   recording. Each becomes one port owned by the lowest reader, which Configuration implements.
3. **One endpoint in the wrong controller.** `objects#export` lives in Core's `ObjectsController` and names
   ten Configuration export classes. The decision on `ObjectsController` endpoints moves it to a
   Configuration controller.

## 1. The exposed files of Configuration today

89 files, 6,895 lines. "Callers" are the other areas' files that reference the file.

Distinct caller files per area: Core 34, Platform 25, Compliance 24, Processes 20, Integrations 8, Search 8,
Files 4, Notifications 3, Kernel 2. Lines exposed per calling area (a file counts once for every area that
names it): Core 3,189, Platform 1,725, Notifications 1,289, Processes 987, Integrations 611, Compliance 562,
Search 373, Files 304, Kernel 55.

### Export (14 files, 1,512 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/ExportService.php` | 755 | Notifications `Service/ScheduledReportExporters` (a bag whose only user is Configuration's `ScheduledReportService`) |
| `Service/Export/ExportRunRecorder.php` | 209 | Core `Controller/ObjectsController`; Notifications `ScheduledReportExporters` |
| `Service/Export/ExportProfileService.php` | 188 | Notifications `ScheduledReportExporters` |
| `Service/Export/ExportRightService.php` | 137 | Core `ObjectsController` (container lookup, `ACTION`); Platform `Repair/GrantExportWhereReadIsGranted` (`ACTION`, `FALLBACK_ACTION`) |
| `Service/Export/PublicExportService.php` | 77 | Core `ObjectsController` |
| `Service/Export/ExportRequest.php`, `ExportPage.php`, `ExportRunContent.php`, `ExportRunFile.php`, `ExportRunOrigin.php`, `ExportRefusedException.php`, `ExportAuditRecorderInterface.php` | 43+23+12+12+12+9+22 | Core `ObjectsController` (the `export` endpoint and its 20 private helpers) |
| `Service/Export/ExportGateInterface.php` | 6 | Core `Controller/Support/ObjectRelationsGraph`, `TmloGuards`; Compliance `Controller/TmloController` (`refusalFor`) |
| `Service/Export/Odt/OdtWriterInterface.php` | 7 | Search `Service/Reporting/PdfReportWriter` (`fromHtml`) |

### Translation and language (13 files, 811 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/TranslationProjectionService.php` | 178 | Core `Object/SaveObject`, `SaveObject/OutdatedTranslations`, `SaveObject/SaveObjectValueHandlers`; Platform `Service/PlatformServiceBindings` |
| `Service/TranslationStatusService.php` | 120 | Core `RenderTranslations`, `SaveObject`, `OutdatedTranslations`, `SaveObjectValueHandlers`; Platform bindings |
| `Service/LanguageService.php` | 113 | Core `AggregationRunner`, `RenderTranslations`, `TranslationHandler`, `Schemas/PropertyTitles`, `ServiceRegistrar`; Platform bindings |
| `Db/TranslationMapper.php` | 230 | Platform `Db/PlatformMapperBindings` |
| `Db/Translation.php` | 51 | Core `RenderObject`; Processes `TranslationController`, `BulkTranslationService`; Platform `TranslationEdit`, `TranslationFilter`, `TranslationMapperInterface`, `TranslationStatusServiceInterface` |
| `Service/TranslationSearch.php`, `SourceLanguageLookup.php`, `Db/Translation/LanguageCoverageCheck.php` | 13+14+11 | Processes `TranslationController`; Core `OutdatedTranslations`; Platform interfaces |
| `Service/Translation/TranslationProviderInterface.php`, `TranslationProviderResolver.php`, `DetailedTranslationProviderInterface.php`, `TranslationOutcome.php`, `LanguagePair.php` | 7+39+6+19+10 | Processes `BulkTranslationService`, `PropertyTranslation`; Core `ServiceRegistrar` (binds the provider) |

The csv puts the translation interfaces and values (`TranslationMapperInterface`, `TranslationSlot`,
`TranslationEdit`, `TranslationFilter`, `TranslationBackfillScope`, `TranslationSourceLanguageBackfillInterface`,
`LanguageServiceInterface`, `TranslationStatusServiceInterface`, `TranslationProjectionServiceInterface`,
`TranslationStores`) in Platform. Their implementations are Configuration's, so 17 of Configuration's 19
references against the order outward are this split.

### Settings (6 files, 1,013 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/SettingsService.php` | 544 | Core `ObjectService` (an unused `use`; the property is typed `SettingsServiceInterface`) |
| `Service/Settings/FileSettingsHandler.php` | 277 | Core `Service/File/ArchiveMemberEnumerator`; Compliance `AnonymisationBackendService`, `AnonymisationBackendCollaborators`; Files `DocumentRedactionServices`, `EntityRecognitionHandler`; Processes `Decision/ReviewPolicyResolver` (`getFileSettingsOnly`, `confidenceThreshold`, `DEFAULT_CONFIDENCE_THRESHOLD`) |
| `Service/Settings/SearchBackendHandler.php` | 59 | Search `Controller/SettingsController` (`setAccentInsensitive`, `getSearchBackendConfig`) |
| `Service/Settings/ArchivalSettingsReaderInterface.php` | 6 | Compliance, 12 files (`getArchivalSettingsOnly`) |
| `Service/Settings/RetentionSettingsReaderInterface.php` | 6 | Core `BackgroundJob/LogCleanUpTask`, `Deletion/DeletionWindowService` |
| `Service/TenantKeyService.php` | 121 | Compliance `AuditChainKey` (`getCurrentTenantKey`) |

### Wiring, seeding, job runs and the operations console (14 files, 1,128 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/Configuration/ConfigurationServiceRegistrar.php` | 390 | Platform `AppInfo/Application` (`register`) |
| `Db/ConfigurationMapperRegistrar.php`, `Service/ContractBindingsRegistrar.php` | 10+9 | Platform `Application` |
| `Service/Configuration/SeedReport.php`, `SeedDirectoryImporter.php` | 166+164 | Platform `Repair/SeedFromDirectory` (`import`, `CONFIG_KEY`, `status`, `summary`, `failures`, `STATUS_SUCCESS`) |
| `Service/Configuration/DescriptorConfigIdsInterface.php` | 30 | Platform, 10 register-import repair steps (constants) |
| `Service/MigrationPackServiceInterface.php` | 24 | Platform `Repair/SeedZgwZakenMigrationPack`; Core `Controller/Support/RegisterServices` (`findByPackSlug`) |
| `Controller/OperationsConsoleController.php`, `OperationsMaintenanceController.php`, `OperationsConsistencyController.php` | 146+47+73 | Core `Controller/Support/MaintenanceReachableControllersInterface` (a constant list only Platform's `MaintenanceModeMiddleware` reads) |
| `Service/Operations/MaintenanceModeHoldInterface.php`, `MovedJobStateInterface.php` | 7+6 | Platform `MaintenanceModeMiddleware`, `Repair/RepointMovedBackgroundJobs` |
| `BackgroundJob/RecordedQueuedJob.php`, `RecordedJobRuntime.php` | 44+12 | Compliance `AuditTrailExportJob`, Search `SearchIndexRebuildJob` (`extends`) |

### Configurations, applications and federation (13 files, 668 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/ConfigurationDeployment/ConfigurationDraftService.php`, `ConfigurationDraftsInterface.php` | 211+16 | Integrations `Service/Bindings/SchemasBindings` (binds them; every user is Configuration) |
| `Db/Application.php` | 159 | Integrations `Db/ApplicationMapperInterface` (every user but one is Configuration; the other is Integrations' MCP `ApplicationTool`) |
| `Db/Configuration.php` | 105 | Search `Db/View`, `ViewFormatting`, `ViewMapper` (managed-by); Notifications `Service/NotificationServiceInterface` |
| `Service/ConfigurationServiceInterface.php` | 26 | Core `RegistersController`, `Support/RegisterIntegrations` (`getUploadedJson`, `importFromJson`); Integrations `AppHost/Service/AppHostSettingsService` (`importFromApp`, by class-string); Compliance `ConfigurationCheckServices` (a bag of Configuration's `ConfigurationCheckJob`) |
| `Service/Configuration/ConfigurationCollaborators.php` | 13 | Core `RegistersController` (`registerBundleBinding()->refusalFor`, `appImportJobsFrom()->appForJob`) |
| `Db/ConfigurationMapperInterface.php` | 21 | Compliance `ConfigurationCheckServices` |
| `Service/Config/FederatedConfigServiceInterface.php`, `ShareableConfigTypeRegistryInterface.php`, `IShareableConfigType.php` | 18+6+10 | Integrations `AppHost/Store/FederatedStoreCatalog` (`discover`, `fetchBundle`, `install`, `isSourceAllowed`, `registry->get`), `DeclaredShareableType` (`getTopic`, `getDisplayName`) |
| `Service/Config/RegisterShareableConfigTypesEvent.php`, `Listener/ShareableConfigTypeRegistrationListener.php` | 13+30 | Processes `Listener/Registrar/ConfigurationListenerRegistrar` (registers them) |
| `Listener/RedactionPolicyCacheListener.php` | 40 | Processes `Listener/ListenerRegistrar` (three registrations) |

### Previews, MCP, notifications, import, vocabulary, example sets (16 files, 1,420 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/Notification/NotificationTemplateRegistry.php` | 383 | Processes `Service/OperationsConsoleNotifications` (a bag whose only user is Configuration's `OperationsConsoleService`) |
| `Service/Reference/ObjectPreviewFormatter.php` | 365 | Core `Object/ObjectCacheInvalidator` (container id), `Search/ObjectSearchResultFormatter` (`resolveRegisterName`, `resolveSchemaName`, `resolveMdiIconUrl`) |
| `Service/Reference/ObjectPreviewFormatterInterface.php` | 11 | Integrations `AppHost/Reference/AbstractSchemaReferenceProvider` (all five methods); Platform `Reference/ObjectReferenceProvider`, `Collaboration/ObjectResourceProvider` |
| `Service/VocabularyImportService.php` | 192 | Core `Vocabulary/ConceptDeleteGuard`, `ConceptRepository`, `ConceptShapeGuard` (`REGISTER`, `SCHEMA_SCHEME`, `SCHEMA_CONCEPT`) |
| `Service/Mcp/McpAgentScope.php`, `McpSessionCaller.php`, `ToolCall.php` | 134+10+10 | Integrations `Mcp/Controller/McpServerController`, `Support/McpServerDomain` (`assertUsable`, `bind`, `filterListing`, `assertMayCall`) |
| `Service/Import/MatchResolver.php`, `MatchQuery.php`, `MatchLookupFailedException.php` | 120+12+6 | Core `Object/UpsertOnKeyHandler` |
| `Service/Notification/ReferencedAddressNotifierInterface.php`, `ReferenceOrigin.php`, `MailingRequest.php` | 7+13+12 | Notifications `AnnotationNotificationDispatcher` (`KIND`, `mail`, by container id) |
| `Service/ExampleSet/ExampleSetEngineRows.php`, `ExampleSetRow.php` | 118+11 | Search `ExampleSet/ExampleSetViews` (`extends`), `ExampleSetViewRowsInterface` |
| `Service/ExampleSet/ExampleSetDateValue.php` | 16 | Processes `RulesController` (`moment`) |

### Entities, Db toolkit and small values (13 files, 343 lines)

| File | Lines | Callers |
|---|---|---|
| `Db/RecordTemplate.php`, `Db/JobRun.php` | 62+56 | Core `RecordTemplateMapperInterface`, `JobRunMapperInterface` (all their users are Configuration) |
| `Db/TaskSequence.php` | 55 | Processes (9 files: approval chains, task sequence service, its mapper interface, migration); Core `Event/AbstractTaskSequenceEvent`; Kernel `Event/TaskSequenceCompletedEvent`, `TaskSequenceUndecidedEvent`; Platform `Repair/Support/SequenceMigrationRun` |
| `Db/Sequence.php`, `Service/SequenceScope.php` | 18+14 | Processes `SequenceMapperInterface`, `SequenceServiceInterface`, `Calculation/SequenceContext`; Core `Listener/GeneratedIdentifierListener` |
| `Db/StoredEntity.php` | 27 | Core `AccessLinkController`; Files `NoteService`, `Sharing/AccessLinkReader` (`StoredEntity::object`) |
| `Db/NewRowStamps.php` | 24 | Compliance `ContentReportMapper`, `ProcessingPurposeMapper`, `VerwerkingsactiviteitMapper` |
| `Db/IsoTimestampsTrait.php` | 17 | Core `Db/Organisation`; Search `Db/View` |
| `Service/RecordUpdate.php` | 13 | Core `SaveObject` (builds it); Compliance `RetentionService(Interface)` (reads it) |
| `Service/SchemaCascade.php` | 12 | Compliance `SchemaDeletionService` |
| `Command/Support/PurgeSwitches.php` | 10 | Core `PurgeObjectCommand`, `PurgeRun` |
| `Command/Support/RematerialiseStorage.php`, `RematerialiseEvaluation.php` | 21+14 | Processes `RematerialiseCalculationsCommand` |

## 2. What outside callers actually need

| Capability | Who needs it | What they call |
|---|---|---|
| Export a register/schema listing | Core `ObjectsController::export` | the whole export flow: right check, public path, run recording, audit, formats |
| Export guard on graph and TMLO exports | Core relations graph, TMLO guards; Compliance TMLO | `refusalFor(schema, profile, registerId)`, already on the Core port `ExportGuardInterface` |
| Render a report as ODT | Search `PdfReportWriter` | `fromHtml(html, landscape)` |
| Track translations of object values | Core save and render path; Processes translation workflow | mark derived translations outdated, resolve source language, completeness, status, the row store |
| Negotiate the request language | Core MagicMapper, render, translation handler, aggregation | `shouldReturnAllTranslations`, `getPreferredLanguage`, `resolveLanguageForRegister`, `setFallbackUsed`, `getTargetLanguage`, `parseAcceptLanguageHeader` |
| Machine translation | Processes `BulkTranslationService` | `translate`, `translateDetailed` when supported, `getIdentifier` |
| Reserve the next identifier | Core generated-identifier listener; Processes calculations | `SequenceScope`, `reserveNext`, `advanceTo` |
| Run an approval chain as a task sequence | Processes, the Kernel events | the `TaskSequence` entity and its mapper |
| Read file, retention, archival and search-backend settings | Core, Compliance, Files, Processes, Search | `getFileSettingsOnly`, `confidenceThreshold`, `getRetentionSettingsOnly`, `getArchivalSettingsOnly`, `getSearchBackendConfig`, `setAccentInsensitive` |
| Sign the audit chain | Compliance `AuditChainKey` | `getCurrentTenantKey(tenantId)` |
| Record a background job's run | Compliance and Search queued jobs | the `RecordedQueuedJob` base class: `around(JobRunStart, work)` |
| Import a bundle into a register | Core `RegistersController` | upload parse, bundle-binding refusal, `importFromJson`, migration pack lookup, app-for-import-job |
| Import an app's configuration | Integrations `AppHostSettingsService` | `importFromApp`, already on the Core interface `AppConfigurationImporterInterface` |
| Agent scope for MCP sessions | Integrations MCP server | `assertUsable`, `bind`, `filterListing`, `assertMayCall` |
| Store applications from an MCP tool | Integrations `ApplicationTool` | `findAllBy`, `countAll`, `findByUuid`, `createFromArray`, `update`, `delete` |
| Federated configuration store | Integrations `FederatedStoreCatalog` | `discover`, `fetchBundle`, `install`, `isSourceAllowed`, a type's topic and display name |
| Object reference previews | Integrations app-host references; Platform reference and collaboration providers | `parseReference`, `buildReference`, `resolveCachePrefix`, `resolveMdiIconUrl`, `getAppIconUrl` |
| Object hit labels and preview cache | Core search-result formatter, cache invalidator | `resolveRegisterName`, `resolveSchemaName`, `resolveMdiIconUrl`; `buildCanonicalUrls`, `resolveCachePrefix` (the Core port `ObjectPreviewCacheInterface` exists) |
| Mail a referenced address | Notifications annotation dispatcher | `KIND`, `mail(ReferenceOrigin, MailingRequest)` |
| Vocabulary slugs | Core concept guards and repository | three constants |
| Managed-by on saved views | Search `View`, `ViewFormatting`, `ViewMapper` | the configuration's id, title and listing, which the Kernel `ConfigurationInterface` carries |
| Upsert on key | Core `UpsertOnKeyHandler` | `MatchResolver`, `MatchQuery`, `MatchLookupFailedException` |
| Seed the instance, grant export, enter maintenance | Platform repairs and middleware | `import()`, a report's status, summary and failures; `ACTION`, `FALLBACK_ACTION`; the controllers reachable in maintenance; `holds`, `message`; `carry` |
| Register the area | Platform `Application` | three `register()` calls |

## 3. The proposed contract set

### 3a. Classes that count to the area whose work they do (move 3, csv rows only, no file moves)

Each row change rests on what the class does and who uses it, never on hiding a reference. The bindings move
with the rows (rule 5), in the same PR, or the class becomes newly exposed through Configuration's maps.

| Id | Files (lines) | From → to | Why it is that area's work |
|---|---|---|---|
| A1 | The translation store and language negotiation: `Db/Translation`, `TranslationMapper`, `Translation/LanguageCoverageCheck`, `Service/TranslationStatusService`, `TranslationProjectionService`, `TranslationSearch`, `SourceLanguageLookup`, `LanguageService`, `LanguageGapScan` (Configuration, 740) and `Db/TranslationBackfillScope`, `TranslationEdit`, `TranslationFilter`, `TranslationMapperInterface`, `TranslationSlot`, `TranslationSourceLanguageBackfillInterface`, `Service/LanguageServiceInterface`, `TranslationProjectionServiceInterface`, `TranslationStatusServiceInterface`, `TranslationStores` (Platform, 148) | → Core | They are the object store's record of its translated values. `SaveObject` marks derived translations outdated on every save, `RenderObject` reports completeness and row status, and `MagicMapper` and `TranslationHandler` negotiate the language on every read. The lowest consumer is Core, so every caller then points down. The Platform rows are wrong today: an interface cannot count above the class that implements it. Translation providers, the glossary and the settings controller stay in Configuration. |
| A2 | `Db/TaskSequence`, `TaskSequenceMapper`, `Db/TaskSequence/TaskSequenceFieldTypes` (Configuration and Integrations, 122) | → Processes | The approval-chain sequence. Its mapper interface, service, decision guard, closure and listeners are all Processes. |
| A3 | `Db/Sequence`, `SequenceMapper`, `Service/SequenceScope`, `SequenceService` (Configuration) and `Db/SequenceMapperInterface`, `Service/SequenceServiceInterface` (Processes) (171) | → Core | Identifier sequences: the next number for a generated identifier, reserved by Core's `GeneratedIdentifierListener` during the save. Processes' calculations use them too, which then points down. |
| A4 | `Service/RedactionPolicy/*` (8 files) and `Listener/RedactionPolicyCacheListener` (421) | → Compliance | The term rules the redaction engine applies (openspec `redaction-policy-as-data`), next to Compliance's anonymisation backend. The listener only clears that engine's index cache. It removes the Processes `ListenerRegistrar` → Configuration references; the registration keeps its three positions. |
| A5 | `Db/StoredEntity`, `Db/IsoTimestampsTrait` (44) | → Core | Db toolkit used by Core entities and controllers first. `StoredEntity` depends only on Kernel. |
| A5 | `Db/NewRowStamps` (24) | → Compliance | Its only users are three Compliance mappers. |
| A6 | `Service/RecordUpdate`, `Command/Support/PurgeSwitches`, `Service/Import/MatchResolver`, `MatchQuery`, `MatchLookupFailedException` (161) | → Core | Values and the key lookup the Core caller builds or runs: `SaveObject` builds `RecordUpdate`, the purge commands build `PurgeSwitches`, and `UpsertOnKeyHandler` resolves match keys (Configuration's import preview then calls down). `MatchResolver` depends only on Kernel. |
| A6 | `Service/SchemaCascade` (12) | → Compliance | Built and read only by `SchemaDeletionService`. |
| A6 | `Command/Support/RematerialiseStorage`, `RematerialiseEvaluation` (35) | → Processes | Bags whose only user is `RematerialiseCalculationsCommand`. This is the decided bag rule. |
| A7 | `BackgroundJob/Support/ConfigurationCheckServices` (Compliance), `Service/ScheduledReportExporters`, `Service/NotificationServiceInterface` (Notifications), `Service/OperationsConsoleNotifications` (Processes), `Db/JobRunMapperInterface`, `Db/RecordTemplateMapperInterface` (Core), `Db/ApplicationMapperInterface` (Integrations), `Listener/Registrar/ConfigurationListenerRegistrar` (Processes) | → Configuration | Bags and interfaces whose users are Configuration's. Most are already in D02 and D03 (the decided bag rule). `ApplicationMapperInterface` is the Configuration mapper's own interface. `ConfigurationListenerRegistrar` registers Configuration's listener (rule 5). Whichever design's package lands first takes each row. |
| A8 | `Search/Service/ExampleSet/ExampleSetViews`, `ExampleSetViewRowsInterface` (Search, 216) | → Configuration | An example-set row kind, like `ExampleSetWatchers` and the timeline rows D03 already moved. It `extends ExampleSetEngineRows`, and its only caller is Configuration's `ExampleSetOwnRowKinds`. It then writes views through the Search port `ViewSeedInterface` (3b). This is the "row collection" D04 waits for. |
| A9 | `Controller/Support/ObjectExport`, `ServedExport` (Core) | → Configuration | They move with the export endpoint (E1). |

Values that become the argument of a lower area's port count to that area, with their port in 3b:
`Service/Mcp/McpSessionCaller`, `Service/Mcp/ToolCall` → Integrations; `Service/Notification/ReferenceOrigin`,
`MailingRequest` → Notifications; `Service/Translation/LanguagePair`, `TranslationOutcome` → Processes. The
lower caller builds each one today.

### 3b. Ports owned by the lower areas, implemented by Configuration

Each port is one capability. Its methods are what the callers call today. Configuration implements it and
binds it in its own map (`Service/ContractBindings`, or whatever Configuration map D02's split leaves). Where
an existing Configuration interface has the same capability, it `extends` the port and keeps its name, so
nothing that types it breaks.

**Core (`lib/Kernel/Contracts/Core/`, 54 lines)**

| Port | Methods (one line each) | Implemented by | Callers | Lines |
|---|---|---|---|---|
| `FileSettingsReaderInterface` | `public const DEFAULT_CONFIDENCE_THRESHOLD = 0.5;` · `public function getFileSettingsOnly(): array;` · `public function confidenceThreshold(): float;` | `Settings/FileSettingsHandler` (the constant moves to the port, the class inherits it) | Core `ArchiveMemberEnumerator`; Compliance anonymisation (2); Files redaction and entity recognition (2); Processes `ReviewPolicyResolver` | 8 |
| `RetentionSettingsReaderInterface` | `public function getRetentionSettingsOnly(): array;` | the Configuration interface of the same name `extends` it | `LogCleanUpTask`, `DeletionWindowService` | 6 |
| `ObjectHitLabelsInterface` | `public function resolveRegisterName(int $registerId): string;` · `public function resolveSchemaName(int $schemaId): string;` · `public function resolveMdiIconUrl(int $schemaId): ?string;` | `Reference/ObjectPreviewFormatter` | `ObjectSearchResultFormatter` | 8 |
| `JobRunRecordingInterface` | `public function record(string $jobClass, mixed $argument, callable $work): void;` | `Operations/JobRunRecorder` (builds `JobRunStart` with the cause/actor rule that sits in `RecordedQueuedJob` today; `RecordedQueuedJob` then calls the port too, so both paths run the same code) | Compliance `AuditTrailExportJob`, Search `SearchIndexRebuildJob` (extend `QueuedJob`, call `record` in `run`) | 6 |
| `RecordsItsRunsInterface` | marker | Configuration's `RecordsItsRuns` `extends` it; the two lower jobs implement it | `OperationsConsoleService` checks `is_subclass_of(..., RecordsItsRunsInterface::class)`: the same set of classes | 4 |
| `RegisterBundleImportInterface` | `public function uploadedBundle(array $params, array $file): array\|JSONResponse;` · `public function refusalFor(Register $register, array $bundle): ?string;` · `public function import(array $bundle, BundleImportOptions $options): array;` · `public function packDefinition(string $packSlug): array;` · `public function appForJob(string $importJobId): ?string;` | new `Service/Configuration/RegisterBundleImport` over `ConfigurationService`, `ConfigurationCollaborators` and `MigrationPackService` | `RegistersController`, `Support/RegisterIntegrations`, `Support/RegisterServices` | 10 |
| `BundleImportOptions` (value) | `owner`, `appId`, `version`, `force` (readonly) | built by `RegistersController` | | 12 |

Existing lower contracts that replace a Configuration name, with no new file:
- `Kernel/Contracts/Core/ExportGuardInterface`, which `ExportGateInterface` already extends and `ContractBindings` already binds: `ObjectRelationsGraph`, `TmloGuards`, `TmloController`.
- `Kernel/Contracts/Core/ObjectPreviewCacheInterface`, which `ObjectPreviewFormatter` already implements: `ObjectCacheInvalidator` resolves it instead of the class.
- `Service/AppConfigurationImporterInterface` (Core): `AppHostSettingsService` resolves it instead of the `ConfigurationServiceInterface` class-string. Same binding target, `ConfigurationService`.
- `Kernel/Contracts/VocabularyImporterInterface`, which `VocabularyImportService` already implements, gets `REGISTER`, `SCHEMA_SCHEME`, `SCHEMA_CONCEPT` (+3 Kernel lines). The class inherits them, so `VocabularyImportService::REGISTER` keeps working. The three Core vocabulary files read the interface.
- `Kernel/Contracts/ConfigurationInterface` (#10689): Search's `View`, `ViewFormatting` and `ViewMapper` type the managing configuration with it.
- `Kernel/Contracts/NotificationTemplatesInterface`: `OperationsConsoleNotifications` (which becomes Configuration's in A7 anyway).

**Compliance (`lib/Kernel/Contracts/Compliance/`, 12 lines)**

| Port | Methods | Implemented by | Callers | Lines |
|---|---|---|---|---|
| `ArchivalSettingsReaderInterface` | `public function getArchivalSettingsOnly(): array;` | the Configuration interface of the same name `extends` it | 12 Compliance files | 6 |
| `TenantKeysInterface` | `public function getCurrentTenantKey(string $tenantId): string;` | `TenantKeyService` | `AuditChainKey` | 6 |

**Search (`lib/Kernel/Contracts/Search/`, 22 lines)**

| Port | Methods | Implemented by | Callers | Lines |
|---|---|---|---|---|
| `SearchBackendSettingsInterface` | `public function getSearchBackendConfig(): array;` · `public function setAccentInsensitive(bool $enabled): array;` | `Settings/SearchBackendHandler` | `Controller/SettingsController` | 7 |
| `OdtDocumentWriterInterface` | `public function fromHtml(string $html, bool $landscape): string;` | `OdtWriterInterface` `extends` it (its third, optional house-style parameter stays on the subinterface) | `PdfReportWriter` | 6 |
| `ViewSeedInterface` | `public function seed(array $data): string;` · `public function reseed(string $uuid, array $data): void;` · `public function exists(string $uuid): bool;` · `public function remove(string $uuid): void;` | new `Search/Service/ViewSeeding`, which takes over the `ViewEdit`/`ViewService`/`ViewMapper` half of `ExampleSetViews` | `ExampleSetViews` (A8) | 9 |

This one runs the other way. Search implements it, and Configuration calls it, which points down.

**Notifications (`lib/Kernel/Contracts/Notifications/`, 7 lines)**

| Port | Methods | Implemented by | Callers | Lines |
|---|---|---|---|---|
| `ReferencedAddressMailInterface` | `extends ReferencedAddressLimits` (the existing Notifications contract that carries `KIND`) · `public function mail(ReferenceOrigin $origin, MailingRequest $request): array;` | `ReferencedAddressNotifierInterface` `extends` it; `ReferencedAddressNotifier` unchanged | `AnnotationNotificationDispatcher` (same container lookup, by the port id) | 7 |

This closes the "annotation dispatcher → Configuration's referenced-address notifier" item that D03 left to
a higher design.

**Integrations (`lib/Kernel/Contracts/Integrations/`, 46 lines)**

| Port | Methods | Implemented by | Callers | Lines |
|---|---|---|---|---|
| `McpAgentScopeInterface` | `public function assertUsable(string $agentUuid, string $userId): void;` · `public function bind(string $sessionId, string $agentUuid): void;` · `public function filterListing(McpSessionCaller $caller, array $listing): array;` · `public function assertMayCall(McpSessionCaller $caller, ToolCall $call): void;` | `Mcp/McpAgentScope` (stays: it composes `ToolGrantResolver`, which fleet apps use) | `McpServerController`, `McpServerDomain` | 9 |
| `ApplicationRecordsInterface` | `public function findAllBy(ListQuery $query): array;` · `public function countAll(): int;` · `public function findByUuid(string $uuid): ApplicationInterface;` · `public function createFromArray(array $data): ApplicationInterface;` · `public function update(Entity $entity): Entity;` · `public function delete(Entity $entity): Entity;` | `ApplicationMapperInterface` `extends` it (6 of its methods; return types narrow covariantly) | MCP `ApplicationTool` | 11 |
| `ObjectReferencePreviewInterface` | `public function parseReference(string $referenceText): ?array;` · `public function buildReference(string $referenceText): ?IReference;` · `public function resolveCachePrefix(string $referenceText): string;` · `public function resolveMdiIconUrl(int $schemaId): ?string;` · `public function getAppIconUrl(): string;` | `ObjectPreviewFormatterInterface` `extends` it and keeps its name | `AbstractSchemaReferenceProvider`; Platform's `ObjectReferenceProvider`, `ObjectResourceProvider` | 11 |
| `FederatedConfigStoreInterface` | `public function discover(string $topic, ?string $credentialId = null): array;` · `public function fetchBundle(string $repo, string $path, ?string $credentialId = null): array;` · `public function install(string $typeId, array $bundle, string $source = ''): array;` · `public function isSourceAllowed(string $source): bool;` · `public function type(string $id): ?ShareableTypeInterface;` | new `Service/Config/FederatedConfigStore` over `FederatedConfigService` and the type registry | `FederatedStoreCatalog` | 9 |
| `ShareableTypeInterface` | `public function getDisplayName(): string;` · `public function getTopic(): string;` | `IShareableConfigType` `extends` it | `FederatedStoreCatalog`, `DeclaredShareableType` | 6 |

**Processes (`lib/Kernel/Contracts/Processes/`, 7 lines)**

| Port | Methods | Implemented by | Callers | Lines |
|---|---|---|---|---|
| `MachineTranslationInterface` | `public function identifier(): string;` · `public function translate(string $text, LanguagePair $pair, ?string $register = null): ?TranslationOutcome;` | new `Service/Translation/ResolvedMachineTranslation` over `TranslationProviderResolver`. It moves the `instanceof DetailedTranslationProviderInterface` branch out of `BulkTranslationService` unchanged. | `BulkTranslationService` | 7 |

Ports deliberately not added:
- One port for all settings. File, retention, archival and search-backend settings have different readers in different areas. A single settings port would be owned by Core and name every area's settings.
- A Core port that copies `ConfigurationServiceInterface` (16 methods). Core needs five register-import calls, so `RegisterBundleImportInterface` carries exactly those.
- A port for `MigrationPackServiceInterface`. Core needs one lookup, `packDefinition` on the bundle-import port. Platform's seeding repair may name the interface (section 4).

### 3c. Configuration-owned contracts for Platform (`lib/Kernel/Contracts/Configuration/`, 23 lines)

Platform is the only area above Configuration. These narrow contracts replace big classes it names today.

| Contract | Methods | Implemented by | Lines |
|---|---|---|---|
| `MaintenanceReachableInterface` | marker | `OperationsConsoleController`, `OperationsMaintenanceController`, `OperationsConsistencyController` | 4 |
| `SeedDirectoryInterface` | `public const CONFIG_KEY = 'openregister.seed_directory';` · `public function import(): ?SeedOutcomeInterface;` | `SeedDirectoryImporter` (the constant moves up and is inherited) | 6 |
| `SeedOutcomeInterface` | `public const STATUS_SUCCESS = 'success';` · `public function status(): string;` · `public function summary(): string;` · `public function failures(): array;` | `SeedReport` | 8 |
| `ExportRightInterface` | `public const ACTION = 'export';` · `public const FALLBACK_ACTION = 'read';` | `ExportRightService` (inherits both) | 5 |

`MaintenanceModeMiddleware` replaces `in_array($class, ALWAYS_REACHABLE, true)` with
`is_subclass_of($class, MaintenanceReachableInterface::class)`. The same three controllers implement the
marker, so the set of reachable controllers is identical. Core's
`Controller/Support/MaintenanceReachableControllersInterface` is then unused and removed: it is internal, and
no fleet app names it. This follows the Core marker pattern (D02, M).

### 3d. Wiring (rule 5)

- **W1.** `ConfigurationServiceRegistrar` (390 lines, named by `Application`) becomes a thin `register()` of
  about 12 lines over one hidden factory map, the new `Service/Configuration/ConfigurationServiceFactories`
  (the 24 `build*` factories and their helpers, moved unchanged). Registration order and the factories' behaviour are
  identical.
- **W2.** Bindings follow their rows: translation (`PlatformMapperBindings`, `PlatformServiceBindings` →
  Core's `Db/MapperWiring` and `Service/ServiceRegistrar`), sequences (`ConfigurationMapperBindings`,
  `ContractBindings` → Core), task sequences (`ConfigurationMapperBindings` → `ProcessesMapperBindings`),
  term rules (`ContractBindings` → `ComplianceServiceBindings`), the configuration drafts
  (`Integrations/Service/Bindings/SchemasBindings` → Configuration's map), the translation provider
  (`Core/ServiceRegistrar` → Configuration's map), and the example-set view rows (`SearchWiring` →
  Configuration's map, with `ViewSeedInterface` bound in `SearchWiring`).
- **W3.** `Kernel/Contracts/Processes/TranslationProjectionInterface` loses its implementer to Core (A1).
  Processes' `TranslationProjectionListener` types the Core `TranslationProjectionServiceInterface`, which stops
  extending the Processes port, and the port is removed. It is internal, has one user and no fleet use. This
  avoids a Core class implementing a Processes port.

### 3e. The export endpoint (E1)

`ObjectsController::export` and its 20 private helpers (about 600 lines: format dispatch, public path, refusal
and completion recording, run recording, row counters) move to a new Configuration controller
`lib/Controller/ObjectExportController.php`. This applies the decision on `ObjectsController` endpoints:
`appinfo/routes.php` keeps `/api/objects/{register}/{schema}/export` and `GET`, and the route name becomes
`objectExport#export` (nothing reads `objects#export`). The controller keeps the same attributes, the same
order of checks (public path first, then the export-right refusal, then format dispatch) and the same
responses. `ObjectExport` and `ServedExport` count with it (A9).

### 3f. Smaller changes, no contract

- `Service/ObjectService.php`: drop the unused `use ...\Service\SettingsService;`. Only the interface is used.
- `Controller/RulesController.php`: parse `since`/`until` with a private two-line helper instead of
  `ExampleSetDateValue::moment` (same `date_create` and blank guard). It is a request-parameter parse, not
  example-set work.

### 3g. Entities that cross the boundary

| Entity | Today crosses to | After |
|---|---|---|
| `Db\Configuration` | Search views, Notifications' interface | The Kernel `ConfigurationInterface` for Search. The interface is Configuration's (A7). |
| `Db\Application` | Integrations' mapper interface | `ApplicationInterface` through `ApplicationRecordsInterface`. The mapper interface is Configuration's (A7). |
| `Db\TaskSequence` | Processes, Core, Kernel, Platform | Processes' (A2). The two Kernel events keep naming it; see section 4. |
| `Db\Translation` | Core, Processes, Platform | Core's (A1). |
| `Db\JobRun`, `Db\RecordTemplate` | Core mapper interfaces | Internal: the interfaces are Configuration's (A7). |
| `Db\Sequence` | Processes | Core's (A3). |
| Core entities (`Register`, `Schema`, `ObjectEntity`) named by Configuration | | Stay. They point down, which is allowed. |

## 4. The budget

| Exposed file after | Lines | Named by |
|---|---|---|
| `Service/Configuration/DescriptorConfigIdsInterface` | 30 | Platform (10 repair steps) |
| `Service/MigrationPackServiceInterface` | 24 | Platform `SeedZgwZakenMigrationPack` |
| `Service/Configuration/ConfigurationServiceRegistrar` (thin) | 12 | Platform `Application` |
| `Db/ConfigurationMapperRegistrar` | 10 | Platform `Application` |
| `Service/ContractBindingsRegistrar` | 9 | Platform `Application` |
| `Kernel/Contracts/Configuration/SeedOutcomeInterface` | 8 | Platform `SeedFromDirectory` |
| `Service/Operations/MaintenanceModeHoldInterface` | 7 | Platform `MaintenanceModeMiddleware` |
| `Service/Operations/MovedJobStateInterface` | 6 | Platform `RepointMovedBackgroundJobs` |
| `Kernel/Contracts/Configuration/SeedDirectoryInterface` | 6 | Platform `SeedFromDirectory` |
| `Kernel/Contracts/Configuration/ExportRightInterface` | 5 | Platform `GrantExportWhereReadIsGranted` |
| `Kernel/Contracts/Configuration/MaintenanceReachableInterface` | 4 | Platform `MaintenanceModeMiddleware` |
| **Total** | **121** (11 files) | |

Before: 6,895 lines in 89 files. After: 121 lines in 11 files, all named by Platform only.

Configuration's share of the target. Kernel takes about 9,700 lines, which leaves about 9,200 for the other
nine areas at 4.0 and about 2,900 at 4.5. Configuration is 57,066 of the 449,016 non-Kernel lines (12.7%),
so its proportional share is about 1,170 lines at 4.0 and about 370 at 4.5.
- **4.0:** 121 lines fits, with about 1,050 to spare.
- **4.5:** 121 lines fits too. Two further cuts, if the app needs the room: the 30 lines of
  `DescriptorConfigIdsInterface` (each repair step could carry its config id as its own constant), and
  `MigrationPackServiceInterface` (−18) narrowed to a `seedBuiltin` port. Neither is needed for either target.

The cost in other areas, from the same simulation (exposed lines before → after):

| Area | Before | After | What changes |
|---|---|---|---|
| Core | 65,057 | 64,844 | +54 port lines, +the rows A1/A3/A5/A6 that are still named by Processes, Integrations and Platform (the translation interfaces and values, `SequenceServiceInterface`), −600 lines out of `ObjectsController` (E1) |
| Compliance | 15,739 | 15,777 | +12 port lines, the redaction-policy rows |
| Processes | 32,009 | 32,059 | +7 port lines, `TaskSequence` (named by Core's and Kernel's events and Platform's migration support) |
| Integrations | 18,785 | 18,804 | +46 port lines, minus `TaskSequenceFieldTypes` |
| Search | 2,072 | 2,087 | +22 port lines (net of `ExampleSetViews` leaving) |
| Notifications | 1,914 | 1,923 | +7 port lines, the two mail values |
| Platform | 976 | 828 | the translation interfaces leave |
| Kernel | 8,515 | 8,518 | +3 constants on `VocabularyImporterInterface` |
| Files | 3,689 | 3,689 | – |

Nothing here only fits by breaking a rule. Four things are flagged:

- **Two references out of Configuration to Platform stay.** `RegisterDescriptorController` and
  `SetupController` carry `#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]`. Nextcloud needs
  the admin-settings class there. Writing it as a string would only hide the reference, and moving
  `OpenRegisterAdmin` (an `ISettings` registration, Platform's work) would be a row change to hide it. They
  remain: a finding, not a failure.
- **The listener registrar call the graph does not see.** Processes' `Listener/ListenerRegistrar` calls
  `Registrar\ConfigurationListenerRegistrar::register()` through a relative name that the module graph does
  not resolve. After A7 this is a real Processes → Configuration call that `modularity_check.py` does not
  count. Moving the call out of `ListenerRegistrar` would reorder the registration sequence, which is not
  allowed. The clean fix belongs to whoever owns the registration sequence: either `ListenerRegistrar`
  counts to Platform (it is the app's registration sequence and `Application` reaches it through
  `ListenerBootstrap`), or that call moves to `Application` at the same position. That decision is the
  coordinator's (D08/D10), so this design does not take it.
- **Kernel events name `TaskSequence`.** `Kernel/Event/TaskSequenceCompletedEvent` and
  `TaskSequenceUndecidedEvent` carry the entity. After A2 they point Kernel → Processes, where today they
  point Kernel → Configuration: the count is the same, 2. buildiq and filinq read
  `TaskSequenceCompletedEvent::getSequence()->getChainKey()` and `getAnchorObjectUuid()`. Under the decision
  on events other apps read, the fix is a Kernel `TaskSequenceInterface` the entity implements, with those
  getters, as the payload type. That is the Processes design's (D08) to size.
- **Ordering against D02.** `ContractBindings.php` is one Configuration file that binds every area. D02's
  C5-01 takes the Core entries out. The Configuration packages below add or remove only Configuration-owned
  lines in it, so they stack on each other and rebase cleanly on C5-01. CF9 touches
  `ConfigurationServiceRegistrar`, which C5-01 also edits (its Core entries), so CF9 goes after C5-01.

## 5. The build packages

Dependency order. Every package that touches `scripts/component-map.csv` regenerates
the generated components file and the deptrac `areas.yaml`. Every package that adds a binding edits
Configuration's map, `lib/Service/ContractBindings.php`. Those three files are shared, so packages that touch
the map are stacked in the order below, never run in parallel. They are listed once here, not per package.
New ports under `lib/Kernel/Contracts/<Area>/` get their area automatically. New classes elsewhere get a csv
row. Every package lists its newly exposed contracts in its PR body.

**CF1: rows for work done elsewhere (A2–A7), with their bindings.** No code changes.
Files:
- `scripts/component-map.csv`
- `lib/Db/ConfigurationMapperBindings.php`, `lib/Db/ProcessesMapperBindings.php`, `lib/Db/MapperWiring.php`
- `lib/Service/ContractBindings.php`, `lib/Service/ServiceRegistrar.php`, `lib/Service/ComplianceServiceBindings.php`
- the binding tests of those maps (`tests/Unit/Architecture/*Bindings*Test`)

Simulated: references against the order into Configuration 146 → 99; Configuration exposed 6,895 → 4,984.
Rows D02/D03 already own (`ConfigurationCheckServices`, `ScheduledReportExporters`, `OperationsConsoleNotifications`,
`NotificationServiceInterface`, `JobRunMapperInterface`, `RecordTemplateMapperInterface`) are skipped if those
packages land first.

**CF2: the translation store counts to Core (A1, W3).**
Files:
- `scripts/component-map.csv`
- `lib/Db/PlatformMapperBindings.php`, `lib/Service/PlatformServiceBindings.php` (drop)
- `lib/Db/MapperWiring.php`, `lib/Service/ServiceRegistrar.php` (add; stacked after CF1)
- `lib/Service/ContractBindings.php` (drop the `TranslationProjectionInterface` binding)
- `lib/Service/TranslationProjectionServiceInterface.php`, `lib/Listener/TranslationProjectionListener.php`
- `lib/Kernel/Contracts/Processes/TranslationProjectionInterface.php` (removed)
- tests: binding tests, `TranslationProjectionListenerTest`

Simulated: 99 → 82 into Configuration, and out of Configuration 19 → 2. Platform's exposure falls by 148.

**CF3a: Core ports Configuration implements (settings readers, hit labels, job runs).**
Files:
- new `lib/Kernel/Contracts/Core/FileSettingsReaderInterface.php`, `RetentionSettingsReaderInterface.php`, `ObjectHitLabelsInterface.php`, `JobRunRecordingInterface.php`, `RecordsItsRunsInterface.php`
- `lib/Service/Settings/FileSettingsHandler.php`, `RetentionSettingsReaderInterface.php`
- `lib/Service/Reference/ObjectPreviewFormatter.php`
- `lib/Service/Operations/JobRunRecorder.php`, `lib/BackgroundJob/RecordsItsRuns.php`, `RecordedQueuedJob.php`
- `lib/Service/OperationsConsoleService.php` (`is_subclass_of` on the Core marker)
- `lib/Service/ContractBindings.php`
- tests for the implementations, and a golden test of the job-run row a recorded job writes (cause, actor), written first

Nothing outside Configuration names the ports yet, so nothing is newly exposed.

**CF3b: callers of the settings readers.**
Files:
- `lib/Service/File/ArchiveMemberEnumerator.php`
- `lib/Service/Anonymisation/AnonymisationBackendService.php`, `AnonymisationBackendCollaborators.php`
- `lib/Files/Service/File/DocumentRedactionServices.php`, `lib/Files/Service/TextExtraction/EntityRecognitionHandler.php`
- `lib/Service/Decision/ReviewPolicyResolver.php`
- `lib/BackgroundJob/LogCleanUpTask.php`, `lib/Service/Deletion/DeletionWindowService.php`
- their tests (fake containers retargeted to the ports)

**CF3c: callers of hit labels, preview cache and job runs.**
Files:
- `lib/Service/Search/ObjectSearchResultFormatter.php`, `lib/Service/Object/ObjectCacheInvalidator.php`
- `lib/BackgroundJob/AuditTrailExportJob.php`, `lib/Search/BackgroundJob/SearchIndexRebuildJob.php`
- their tests

Simulated after CF3: 68 into Configuration.

**CF4a: register-bundle import through a Core port.**
Files:
- new `lib/Kernel/Contracts/Core/RegisterBundleImportInterface.php`, `BundleImportOptions.php`
- new `lib/Service/Configuration/RegisterBundleImport.php`
- `lib/Controller/RegistersController.php`, `Support/RegisterIntegrations.php`, `Support/RegisterServices.php`
- `lib/Service/ContractBindings.php`, `scripts/component-map.csv`
- tests: `RegisterBundleImportTest` (new); the `RegistersController` import tests, with the 403 bundle refusal for a non-admin pinned first

The admin short-circuit and the refusal stay in `RegistersController`, in the same order.

**CF4b: existing lower contracts instead of Configuration names.**
Files:
- `lib/Controller/Support/ObjectRelationsGraph.php`, `TmloGuards.php`, `lib/Controller/TmloController.php`
- `lib/AppHost/Service/AppHostSettingsService.php`
- `lib/Kernel/Contracts/VocabularyImporterInterface.php`, `lib/Service/VocabularyImportService.php`
- `lib/Service/Vocabulary/ConceptDeleteGuard.php`, `ConceptRepository.php`, `ConceptShapeGuard.php`
- `lib/Search/Db/View.php`, `ViewFormatting.php`, `ViewMapper.php`
- `lib/Service/ObjectService.php` (import), `lib/Controller/RulesController.php`
- their tests

Simulated after CF4: 52 into Configuration.

**CF5a: Compliance's archival settings reader.**
Files:
- new `lib/Kernel/Contracts/Compliance/ArchivalSettingsReaderInterface.php`
- `lib/Service/Settings/ArchivalSettingsReaderInterface.php`
- the 12 Compliance callers (`DestructionCheckJob`, `DestructionListRun`, `ReviewReminderJob`, `RetentionController`, `SelectielijstController`, `Support/RetentionDomain`, `Support/SelectielijstControllerServices`, `Archival/ArchivalOverviewService`, `DestructionListCreator`, `DestructionListRepository`, `SelectielijstStorage`, `RetentionRules`)
- `lib/Service/ContractBindings.php`; tests

This is 15 files, mechanical. If it runs over, the jobs and the services become two PRs.

**CF5b: ports owned by Compliance, Search and Notifications.**
Files:
- new `lib/Kernel/Contracts/Compliance/TenantKeysInterface.php`, `Search/SearchBackendSettingsInterface.php`, `Search/OdtDocumentWriterInterface.php`, `Notifications/ReferencedAddressMailInterface.php`
- `lib/Service/TenantKeyService.php`, `lib/Service/AuditChainKey.php`
- `lib/Service/Settings/SearchBackendHandler.php`, `lib/Controller/SettingsController.php`
- `lib/Service/Export/Odt/OdtWriterInterface.php`, `lib/Search/Service/Reporting/PdfReportWriter.php`
- `lib/Service/Notification/ReferencedAddressNotifierInterface.php`, `lib/Notifications/Service/Notification/AnnotationNotificationDispatcher.php`
- `lib/Service/ContractBindings.php`, `scripts/component-map.csv` (the two mail values); tests

Simulated after CF5: 34 into Configuration.

**CF6a: Integrations ports (MCP agent scope, application records, reference previews).**
Files:
- new `lib/Kernel/Contracts/Integrations/McpAgentScopeInterface.php`, `ApplicationRecordsInterface.php`, `ObjectReferencePreviewInterface.php`
- `lib/Service/Mcp/McpAgentScope.php`, `lib/Mcp/Controller/McpServerController.php`, `Support/McpServerDomain.php`
- `lib/Db/ApplicationMapperInterface.php`, `lib/Mcp/Tool/ApplicationTool.php`
- `lib/Service/Reference/ObjectPreviewFormatterInterface.php`, `lib/AppHost/Reference/AbstractSchemaReferenceProvider.php`, `lib/Reference/ObjectReferenceProvider.php`, `lib/Collaboration/ObjectResourceProvider.php`
- `lib/Service/ContractBindings.php`, `scripts/component-map.csv` (two MCP values, the mapper interface); tests

**CF6b: federated store, machine translation, drafts binding.**
Files:
- new `lib/Kernel/Contracts/Integrations/FederatedConfigStoreInterface.php`, `ShareableTypeInterface.php`, `Processes/MachineTranslationInterface.php`
- new `lib/Service/Config/FederatedConfigStore.php`, `lib/Service/Translation/ResolvedMachineTranslation.php`
- `lib/Service/Config/IShareableConfigType.php`, `lib/AppHost/Store/FederatedStoreCatalog.php`, `DeclaredShareableType.php`
- `lib/Service/BulkTranslationService.php`, `lib/Service/ServiceRegistrar.php` (drop the provider binding)
- `lib/Service/Bindings/SchemasBindings.php` (drop the drafts binding)
- `lib/Service/ContractBindings.php`, `scripts/component-map.csv`; tests (the provider log line names the same identifier)

Simulated after CF6: 16 into Configuration.

**CF7: example-set views as Configuration's row kind (A8).** Stacked after D04's packages that edit
`SearchWiring`.
Files:
- new `lib/Kernel/Contracts/Search/ViewSeedInterface.php`, `lib/Search/Service/ViewSeeding.php`
- `lib/Search/Service/ExampleSet/ExampleSetViews.php`, `ExampleSetViewRowsInterface.php`
- `lib/Search/SearchWiring.php`, `lib/Service/ContractBindings.php`, `scripts/component-map.csv`
- tests: `ExampleSetViewsTest` retargeted; the example-set install golden test unchanged

**CF8: Configuration's contracts for Platform (3c).**
Files:
- new `lib/Kernel/Contracts/Configuration/MaintenanceReachableInterface.php`, `SeedDirectoryInterface.php`, `SeedOutcomeInterface.php`, `ExportRightInterface.php`
- `lib/Controller/OperationsConsoleController.php`, `OperationsMaintenanceController.php`, `OperationsConsistencyController.php`
- `lib/Middleware/MaintenanceModeMiddleware.php`, `lib/Controller/Support/MaintenanceReachableControllersInterface.php` (removed)
- `lib/Service/Configuration/SeedDirectoryImporter.php`, `SeedReport.php`, `lib/Repair/SeedFromDirectory.php`
- `lib/Service/Export/ExportRightService.php`, `lib/Repair/GrantExportWhereReadIsGranted.php`
- tests: the middleware's reachable-in-maintenance cases pinned first

Simulated after CF8: 10 into Configuration (the export endpoint's).

**CF9: a thin service registrar (W1).** After D02 C5-01.
Files:
- `lib/Service/Configuration/ConfigurationServiceRegistrar.php`
- new `lib/Service/Configuration/ConfigurationServiceFactories.php`
- `scripts/component-map.csv`
- tests: the registrar's binding test, plus one asserting that the registered ids and their order are unchanged

**CF10: the export endpoint moves to Configuration (E1).** After D04's S4c, which also edits
`ObjectsController` and `appinfo/routes.php`.
Files:
- new `lib/Controller/ObjectExportController.php`
- `lib/Controller/ObjectsController.php`, `appinfo/routes.php` (one route name; URL and verb unchanged)
- `scripts/component-map.csv` (the new controller, `ObjectExport`, `ServedExport`)
- tests: `ObjectExportControllerTest` (the export cases of the ObjectsController tests, moved and retargeted), and a route test that the URL and verb still resolve

Simulated after CF10: 0 into Configuration, 121 exposed lines.

Not in a Configuration package:
- The listener-registration ownership and the `TaskSequence` event payload (section 4), for the coordinator, D08 and D10.
- Core's own references to Configuration that are no reference in the graph (container class-strings) are covered by the ports above where they were found. A text search for `OCA\\OpenRegister\\Service\\` class-strings in Core is part of each package's check.

## 6. Fleet impact

Measured with `fleet_checkout.sh` on the development branches of the 19 fleet apps (2026-11-19),
and a scan of each app's `lib/` for every Configuration class name. The fleet names these Configuration
classes:

| Class | Apps | This design |
|---|---|---|
| `Service\LanguageService` | filinq, opencatalogi (3 files) | Counts to Core (A1). Name, namespace and methods unchanged. |
| `Service\SequenceService` | dossiq, pipelinq | Counts to Core (A3). Unchanged. |
| `Db\TaskSequenceMapper` | buildiq | Counts to Processes (A2). Unchanged. |
| `Event\TaskSequenceCompletedEvent` (Kernel, payload `TaskSequence`) | buildiq, filinq | Unchanged here. See section 4 for its payload. |
| `Service\ConfigurationService` | integriq | Unchanged. It keeps implementing `ConfigurationServiceInterface` and `AppConfigurationImporterInterface`. |
| `Service\ExportService` | portaliq | Unchanged. |
| `Service\Export\ExportGate`, `ExportAuditRecorder` | planninq | Unchanged. |
| `Service\Notification\NotificationTemplateRegistry`, `Service\SettingsService`, `Service\ShippedBaseline\ShippedBaselineStore` | dossiq | Unchanged. |
| `Service\ExampleSet\ExampleSetRecord` | portaliq | Unchanged. |

Nothing other apps use is renamed, moved or removed. The two removed interfaces
(`Kernel/Contracts/Processes/TranslationProjectionInterface` and
`Controller/Support/MaintenanceReachableControllersInterface`) are named by no fleet app. No event other apps
read changes in this design.

Candidates for promotion to `lib/Contract` (ADR-119 Decision 14) are named here, not done:
- `FileSettingsReaderInterface`, if an app that redacts documents (filinq, dossiq) wants the redaction settings without `SettingsService`.
- `ObjectReferencePreviewInterface`, which is what an app's own reference provider needs from OpenRegister (today through `AbstractSchemaReferenceProvider`).
- A published `ExportInterface` over `ExportService`, for portaliq.

## Notes for the other designs

- **D01 (Kernel):** +3 lines of constants on `VocabularyImporterInterface`. Nothing moves into Kernel.
- **D02 (Core):** Core gets 54 port lines (3b) and the rows A1, A3, A5, A6. After them Core's
  `RenderObject`, `SaveObject`, `TranslationHandler` and `MagicMapper` reach translation and language through
  Core's own classes. `ContractBindings` keeps only Configuration-owned entries after C5-01 and this design.
- **D03 (Notifications):** `ReferencedAddressMailInterface` closes the dispatcher → notifier item. A7 repeats
  D03's rows for the scheduled-report bags, `NotificationServiceInterface` and `OperationsConsoleNotifications`.
- **D04 (Search):** A8 is the row collection D04 waits for. `ExampleSetViewRowsInterface` leaves Search's
  budget (−7) and `ExampleSetViews` with it.
- **D05 (Compliance):** the redaction-policy engine and `NewRowStamps`/`SchemaCascade` arrive by row. Two
  ports (12 lines) are Compliance's.
- **D06 (Files):** the Files callers of the file settings use the Core port. Nothing else.
- **D07 (Integrations):** five ports (46 lines) are Integrations', all implemented by Configuration.
- **D08 (Processes):** `TaskSequence` arrives by row, with the event payload question. `MachineTranslationInterface`
  (7) and its two values are Processes'. The Processes `TranslationProjectionInterface` is removed (W3).
- **D10 (Platform):** the two `OpenRegisterAdmin` attribute references and the owner of the listener
  registration sequence (section 4).
