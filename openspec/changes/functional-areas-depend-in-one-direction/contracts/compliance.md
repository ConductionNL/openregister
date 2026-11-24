# Contract design: the Compliance area

Wave 5 design package D05 of the change `functional-areas-depend-in-one-direction` (hydra ADR-119,
Decisions 8, 9, 13 and 14). This file is a design. It changes nothing in `lib/`.

Measured on `origin/main` `911c2702` with the module graph of the maintainability checker,
the same reading that `modularity_check.py` uses. Lines are code lines as that tool counts
them (no comments, no blank lines). Fleet use was measured with `fleet_checkout.sh` and
`fleet_surface.py` on the development branches of the 19 fleet apps (2026-11-19).

## Summary

| | Today | After this design |
|---|---|---|
| Exposed lines of Compliance | 15,739 | 474 (578 until the job-handle package P46 lands) |
| Exposed files of Compliance | 230 | 29 (40 until P46) |
| References against the order into Compliance (Core 108, Search 5) | 113 | 3 (all owned by D02, see section 4) |
| References against the order out of Compliance | 73 | 30 (the rest belong to the higher areas' designs) |
| References against the order, whole app | 727 | 567 |
| Exposed lines, whole app | 155,651 | 138,858 |

The "after" column is a simulation, not an estimate. A script applied every move listed below to the
graph measured on `911c2702`: wiring moved, csv rows reassigned, caller edges rewired, new ports added
with their line counts. It then ran the exposure and order arithmetic of `modularity_check.py` again. It
also applies the moves of D02 (Core) and D04 (Search) that touch Compliance files, so the three designs
add up: D02's rows into Compliance, D02's `AuditRecorderInterface`, and D04's A1 (search trail) and A3
(alert sweep). A port's line count is estimated as its methods plus 6, as the existing ports in
`lib/Kernel/Contracts/Core/` measure.

The simulation makes no newly exposed file apart from the new ports and one listener registrar, and no
new reference against the order.

Compliance is a large exposer for one reason. `lib/Service/ContractBindings.php` (Configuration) binds
75 Compliance interfaces to their Compliance implementations. That single wiring map is the only outside
caller of 109 Compliance files, 9,335 lines, which is 59% of the total. Moving those bindings into Compliance's own map
(BRIEF way 5) is the first and largest step. The second largest item goes the other way. Core names
Compliance's audit trail 108 times against the order: the `AuditTrail` entity, the value objects of
`AuditTrailMapperInterface`, and the archival, purpose, search trail and authorization audit services
that Core's save, read and delete paths call.

## 1. The exposed files of Compliance today

230 files, 15,739 lines. Distinct outside caller files per area: Core 62, Processes 27, Configuration 20,
Integrations 15, Files 15, Search 4, Platform 3.

### Named only by Configuration's `ContractBindings` (109 files, 9,335 lines)

These files have no other outside caller. The binding map names an interface and its implementation,
and nothing else.

| File or group | Files | Lines |
|---|---|---|
| `Service/Archival/*` (destruction, destruction review and lists, selectielijst import and resolver, action date calculator, annotation and element mapping validators, nomination, review outcome, legal hold, dependant retention index, and their interfaces) | 29 | 2,387 |
| `Service/Gdpr/*` (DSAR requests, erasure preview, runner and store, export bundle and subject export, retention sweep, DPIA patterns, policy pack, case access and accessor, evidence, identity and regulator registries, redaction, and their interfaces) | 29 | 1,582 |
| `Service/Audit/*` (content report, sink and sink status, readable lister, purpose registry, attribution, aggregation, page service, entry writer, and their interfaces) | 21 | 1,026 |
| `Service/Edepot/*` (transfer list, transfer, transfer record, three transports) | 7 | 777 |
| `Service/Hardening/*` (report, statement, policy, settings, elevation, and their interfaces) | 10 | 739 |
| `Service/AuditHashService.php` | 1 | 691 |
| `Service/RetentionService.php` | 1 | 516 |
| `Service/DsarService.php` | 1 | 356 |
| `Service/Anonymisation/AnonymisationBackendService.php` | 1 | 355 |
| `Service/AvgRetentionService.php`, `AuditQueryService.php`, `AuditRetentionResolver.php`, `AvgComplianceService.php` | 4 | 860 |
| `Service/SearchTrailServiceInterface.php`, `AuditRetentionResolverInterface.php`, `AuditHashServiceInterface.php`, `AuditQueryServiceInterface.php`, `AuditSealInterface.php` | 5 | 46 |

The 75 pairs are listed in the appendix.

### Audit trail (35 files, 2,609 lines)

| File | Lines | Callers |
|---|---|---|
| `Db/AuditTrailMapper.php` | 1,442 | Core `ObjectSlugBackfillLedger`; Processes `AppHost/Scheduling/ScheduleAuditTrail`; Platform `AppInfo/Application` (unused import) |
| `Db/AuditTrail.php` | 79 | Core 19 (`AuditTrailMapperInterface`, `DeletedController`, `ConflictTrail`, `ConflictReport(Interface)`, `CorrectionService(Interface)`, `DeletedObjectAuthorizer(Interface)`, `EntityRelationMapper`, `FolderAccessDenial`, `PublishedDigestService`, `ReferentialIntegrityService`, `SaveObject`, `AuditRowWriter`, `ObjectAccessHistory`, `RevealFlusher`, `SettingsChangeAuditor`, `ApiTokenAuditRow`); Processes 7; Files 3; Configuration 2; Search `DashboardSizes` |
| `Db/AuditTrail/*` value objects: `AuditStatisticsScope` 30, `AuditedObject` 19, `RegisterAuditEvent` 19, `AuditedChange` 14, `RevertOptions` 14, `ActorTrailQuery` 12, `ExportOutcome` 12, `HardeningChange` 12, `PartyQueryRefusal` 12, `SharedMasterDataRefusal` 12, `ToolInvocation` 12, `ActionWindow` 11, `PurposeRefusal` 11, `SharedMasterDataRefusalOptions` 11, `SlugBackfill` 11, `ActionLookupOptions` 10, `ActionScope` 10 | 243 | Core (the interface's signatures; `SaveObject`, `UserService`, `RevertHandler`, `UrnService`, `MovedObjectLookup`, `ObjectAccessReport`, `StateHistoryRebuildJob`, `DeletedController`, `DestructionScopeService`, `RegistersController`, `SchemasController`); Search dashboard; Integrations, Processes and Configuration for their own typed records |
| `Service/Audit/ObjectHistoryForReaders.php` (+ interface 7) | 159 | Integrations 3 (activity feed, audit trail provider); Core `ObjectsController` (interface) |
| `Service/AuthorizationAuditService.php`, `Service/AuthorizationChange.php` | 92 | Core 5 (register and schema controllers, register access audit, reach revocation); Integrations MCP tool providers |
| `Service/Audit/TokenIdentity.php`, `TokenContext.php`, `NamedIdentifier.php` | 109 | Integrations `Service/AuthorizationService` |
| `Service/Audit/SecuritySettingAnnouncer.php`, `SecuritySettingRegistry.php`, `ReadEventVisibility.php` | 313 | Configuration settings handlers |
| `Service/Audit/PurposeGuard.php`, `PurposeGuardInterface.php`, `PurposeRefusedException.php` | 156 | Core `MagicMapper`, `RelationHandler`, `ObjectsController` and three controller support bags |
| `Db/AuditFlowAttributionInterface.php`, `Service/AuditRechainInterface.php`, `Service/Audit/AuditAggregationServiceInterface.php` | 27 | Processes (implements it), Platform repair, Configuration audit settings |

### Retention and archival in Core's save, render and delete paths (9 files, 979 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/TmloService.php` | 238 | Core `SaveObject`, `SaveObjectRecords` (`populateDefaults`, `isTmloEnabled`, `validateFieldValues`, `validateStatusTransition`, `ARCHIEFSTATUS_ACTIEF`) |
| `Service/Archival/ArchivalDecisionResolver.php`, `RetentionEvaluator.php` | 372 | Core `Service/Object/RecordAnnotations` (`new …()->resolve`, `->evaluate`) |
| `Service/Archival/ArchivalRetentionGuard.php` | 123 | Core `IntegrityCascadeControls`, `ReferentialIntegrityService` (`cascadeRefusal`) |
| `Service/Archival/InheritedAppraisal.php` | 113 | Core `Db/SchemaMapper/RecordAnnotationRefusals` (`ANNOTATION`) |
| `Service/Archival/RecordState.php` | 42 | Core `DeleteRowRules`, `OrphanDeletionGuard` (`TRANSFERRED_ALIASES`) |
| `Service/RetentionServiceInterface.php` | 29 | Core `SaveObject` (`applyClassification`, `guardClassificationOverride`, `applyArchivalMetadata`) |
| `Db/Verwerkingsactiviteit.php` | 50 | Core `RetentionClockService`, `VerwerkingsactiviteitMapperInterface` |
| `Service/Edepot/AuditRecording.php` | 12 | Core `DestructionScopeService` |

### Search trail (4 files, 265 lines)

`Service/SearchTrailService.php` 162 and `SearchMeasurement` 12 (Core `ObjectService`, `SearchQueryHandler`,
`ObjectStorageServices`: `createSearchTrail`), `Db/SearchTrail.php` 79 and `SearchTrailOutcome` 12 (Search's
`SearchTrailMapperInterface`). `Service/SearchTrailServiceInterface.php` is named only by the binding map.

### Background job support bags and run values used by another area's job (32 files, 472 lines)

| Bags | Lines | The job that uses them |
|---|---|---|
| `AbandonedFlowClaim`, `FlowReapRun` | 44 | Processes `FlowRunWorker` |
| `AutoTransitionServices`, `BulkJobServices`, `FlowRunRetentionServices` | 39 | Processes `AutoTransitionJob`, `BulkJobRunner`, `FlowRunRetentionJob` |
| `SyncDataServices`, `OAuth2TokenRefreshServices`, `WebhookDeliveryStores` | 42 | Integrations `SyncDataJob`, `OAuth2TokenRefreshJob`, `WebhookRetryJob` |
| `ConfigurationCheckServices`, `BackfillCalendarLinksSources`, `BackfillCalendarLinksPlatform`, `BlobMigrationRun`, `BlobGroup` | 94 | Configuration `ConfigurationCheckJob`, `BackfillCalendarLinksJob`, `BlobMigrationJob` |
| `ViewAlertCheck`, `ViewAlertSweepPlatform`, `ViewAlertSweepSources` | 45 | Core `ViewAlertSweepJob` (Search after D04 A3) |
| `TransferExecutionPlatform`, `TransferExecutionRecords`, `TransferExecutionTransports` + 8 e-Depot interfaces | 104 | Core `BackgroundJob/TransferExecutionJob` (11 references against the order); the 4 transport interfaces also Configuration's e-Depot settings |
| `LogCleanUpTrails` | 16 | Core `BackgroundJob/LogCleanUpTask` |
| `BackgroundJob/AbstractTextExtractionJob.php` | 55 | Processes `FileTextExtractionJob`, `ObjectTextExtractionJob` (they extend it) |
| `Service/CalculationRun.php`, `DescriptorReimportRun.php`, `AuthFailureRun.php` | 33 | Configuration `ManifestService`, `RegisterDescriptorService`; Core `SecurityService` |

### Listeners registered by Processes (8 files, 539 lines)

`QualityScoreOnSaveListener` 132, `RecordTypeAuditListener` 124, `SchemaAuthorizationAuditListener` 68,
`RelatedRetentionRecalculationListener` 55, `ArchivalNominationListener` 52, `ContentReportRemovalListener` 46,
`ArchivalDeclarationChecksListener` 33, `EntityEditAuditListener` 29. Processes' `Listener/ListenerRegistrar`
names them at seven positions in the registration sequence.

### Anonymisation and personal data for Files (12 files, 354 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/Anonymisation/PipelineSteps.php` | 88 | Files `DocumentProcessingHandler` (`ANONYMISER`) |
| `Db/GdprEntity.php`, `Db/GdprEntityMapperInterface.php` | 65 | Files entity recognition and sidebar (7), Processes `GdprEntitiesController`, Core `ImageRedactionService` |
| `Service/Anonymisation/TextDisclosureRecorder.php`, `TextDisclosureSource.php`, `Exception/TextDisclosureNotRecordedException.php` | 70 | Files entity recognition (the recorder writes Files' `TextDisclosure` table) |
| `Service/Anonymisation/AnonymiserCall.php`, `BackendMethodsInterface.php`, `AnonymiserRequestInterface.php`, `AnonymisationBackendServiceInterface.php` | 44 | Files settings controller and entity recognition |
| `Service/Anonymisation/CapacityPolicy.php`, `OfficialsSource.php` | 87 | Configuration `FileSettingsHandler` |

### Remaining items (21 files, 1,186 lines)

| File | Lines | Callers |
|---|---|---|
| `Service/SchemaDeletionService.php` | 307 | Core `ObjectService` (`deleteObjectsBySchema`, `deleteObjectsByRegister`), `SchemaTeardownGuard` (`ACTION_FORCED_TEARDOWN`) |
| `Service/Archival/SelectionListSeeder.php` | 238 | Configuration `ConfigurationServiceRegistrar`, `Configuration/ImportHandler` (`seed`) |
| `Service/ProcessingLogService.php`, `ProcessingRead.php` | 248 | Processes `Interaction/ReadHistoryService` (`recordRead`, `recordReadList`) |
| `Service/Gdpr/GdprRegistrar.php` | 57 | Core `ServiceBootstrap` (against the order) |
| `Db/TaskAudit.php`, `Db/TaskAuditMapper.php` | 83 | Processes (task service, write-back, repair steps, `ProcessesMapperBindings`) |
| `Db/ContentReport.php`, `ErasurePreview.php`, `ProcessingPurpose.php`, `ProcessingLog/ProcessingLogSubject.php` | 149 | Core's `…MapperInterface` for each (Compliance implements them) |
| `Service/DsarSubjectQuery.php` | 14 | Core `DsarServiceInterface` |
| `Service/Hardening/ThrottledSurfacesInterface.php` | 25 | Core, Integrations, Processes controllers (`THROTTLE_ACTION` constants) |
| `Service/Hardening/AllowedOriginsInterface.php` | 6 | Platform `PublicApiCorsMiddleware` |
| `Service/Gdpr/Identity/*`, `Service/Gdpr/Regulator/*` (providers and registry interfaces) | 30 | Integrations `ConnectionSeamReportJob` |
| `Service/Edepot/EdepotTransferServiceInterface.php` | 8 | Configuration `EdepotSettingsController`, `EdepotSettingsDomain` (with the 4 transport interfaces above) |
| `Service/ComplianceServiceRegistrar.php`, `Db/ComplianceMapperRegistrar.php` | 21 | Platform `Application` |

## 2. What outside callers actually need

| Capability | Who needs it | What they call |
|---|---|---|
| Binding Compliance's services | Configuration `ContractBindings` (wiring only) | 75 `interface => implementation` pairs |
| Recording an object's audit entry | Core save, merge, delete, RBAC and file paths | `createAuditTrail`, `recordChange(AuditedChange)`, `insertAuditTrails`, `recordSharedMasterDataRefusal`, `recordSlugBackfill`, raw `insert(new AuditTrail())` |
| Reading an object's history | Core deleted-object, conflict, correction, move lookup, access report, URN, revert, user export, register and schema statistics; Search dashboard | `findObjectActions(ActionLookupOptions)`, `countObjectTrail(ActionScope)`, `findActorTrail(ActorTrailQuery)`, `revertObjectWith(RevertOptions)`, `statisticsTotals`/`actionChartData`/… `(AuditStatisticsScope)`, `findTrail(ListQuery)`, the `AuditTrail` getters |
| Typed audit records of higher areas | Integrations MCP, Configuration export, Processes party search, flows, schedules, read history; Files file audit | `recordToolInvocation`, `recordExport`, `recordPartyQueryRefusal`, `createAuditTrail(null, $object, 'read')`, `insert(AuditTrail)` |
| Object history for a reader | Integrations activity feed; Core `ObjectsController::logs` | `ObjectHistoryForReadersInterface::page()` |
| Processing log of reads | Processes `ReadHistoryService` | `recordRead`, `recordReadList` with `ProcessingRead` |
| Retention classification on save | Core `SaveObject` | `applyClassification`, `guardClassificationOverride`, `applyArchivalMetadata` |
| Archival metadata on save | Core `SaveObject` | `populateDefaults`, `isTmloEnabled`, `validateFieldValues`, `validateStatusTransition`, `ARCHIEFSTATUS_ACTIEF` |
| Archival annotations on render | Core `RecordAnnotations` | `RetentionEvaluator::evaluate`, `ArchivalDecisionResolver::resolve` |
| Archival hold on delete | Core integrity and delete rules | `cascadeRefusal`, `RecordState::TRANSFERRED_ALIASES` |
| The processing activity that retains a record | Core `RetentionClockService` | `VerwerkingsactiviteitMapperInterface` → `Verwerkingsactiviteit` |
| Recording a search | Core search path | `createSearchTrail(array, SearchMeasurement)` |
| Read purpose binding | Core `MagicMapper`, `RelationHandler`, object controllers | `enforce`, `allows`, catching the refusal |
| Authorization change log | Core register/schema controllers and RBAC reach revocation; Integrations MCP | `recordSchemaChange`, `recordRegisterChange`, `recordRoleChange`, `EVENT_TYPE` |
| Deleting a schema's or register's objects | Core `ObjectService` | `deleteObjectsBySchema`, `deleteObjectsByRegister`, `TRIGGER_BULK_DELETE` |
| Throttled surface names | 7 controllers in Core, Integrations, Processes | string constants on `ThrottledSurfacesInterface` |
| API token attribution | Integrations `AuthorizationService` | `TokenContext::claim(TokenIdentity)` |
| Identity and regulator connector report | Integrations `ConnectionSeamReportJob` | `listIds()`, `NULL_PROVIDER_ID` of two registries |
| Anonymiser for detected entities | Files entity recognition, file settings | anonymise request, backend methods, `AnonymiserCall` |
| Personal data entities | Files entity recognition and sidebar; Processes `GdprEntitiesController` | `GdprEntity`, `GdprEntityMapperInterface` |
| Audit-relevant settings | Configuration settings handlers | announcer `snapshot`/`announce`, registry `isSecret`/`label`, `ReadEventVisibility::groupsFrom`, `CONFIG_KEY` |
| Anonymisation settings | Configuration `FileSettingsHandler` | `CapacityPolicy::DEFAULT`/`fromStored`, `OfficialsSource::normalise` |
| Archive transfer connection test | Configuration e-Depot settings | the transfer service and transport interfaces |
| Seeding selection lists on import | Configuration import | `SelectionListSeeder::seed` |
| CORS origins, audit rechain, audit aggregation | Platform middleware and repair; Configuration audit settings | existing narrow interfaces |
| Flow attribution of audit rows | Compliance's mapper; Processes implements it | `AuditFlowAttributionInterface` (a Compliance-owned port already) |

## 3. The proposed contract set

### 3a. Wiring (BRIEF way 5)

| Id | Change | Lines unexposed |
|---|---|---|
| W1 | The 75 Compliance pairs leave `Service/ContractBindings.php` (Configuration) and join `Service/ComplianceServiceBindings.php`, which `ComplianceServiceRegistrar` already binds. Six of them bind a Core interface to a Compliance class (`AuditEntryWriterInterface`, `AvgComplianceServiceInterface`, `DsarServiceInterface`, `TmloServiceInterface`, `SchemaTeardownInterface`, `AvgRetentionPassInterface`). Compliance binding its own class to a lower contract points down, so they move too. `ArchivalSettingsReaderInterface` stays in `ContractBindings`: its implementation is Configuration's. Each id is still bound exactly once. A test asserts that the union of bound ids over all maps is unchanged. | 9,335 |
| W2 | `Service/ServiceBootstrap` (Core) stops calling `GdprRegistrar::register`. `ComplianceServiceRegistrar::register` calls it instead, at the end of its own binding. `GdprRegistrar` registers only Compliance services (the PAdES signer, the evidence registry and the identity and regulator seams), so the bound set is unchanged. | 57 |
| W3 | The unused `use …\Db\AuditTrailMapper;` in `AppInfo/Application.php` is removed. Only a comment mentions the class. | (part of 1,442) |
| W4 | A new `lib/Listener/Registrar/ComplianceListenerRegistrar.php` (about 40 lines) gets one static method per position: `authorizationAudit()` (today lines 121 to 135 of Processes' `ListenerRegistrar`), `declarationChecks()` (174), `retentionRecalculation()` (466), `qualityScore()` (540 to 541), `contentReportRemoval()` (714) and `archivalNomination()` (824). `ListenerRegistrar` calls each method at exactly that position. Where a block also registers other areas' listeners, only the Compliance lines move, and the call keeps their place. The registration sequence is identical, so `listener-order.json` and the hash in `ListenerRegistrationOrderGoldenTest` do not change. This follows `McpListenerRegistrar`, `NotificationsListenerRegistrar` and D04's `SearchListenerRegistrar`. | 499 (exposes the 40-line registrar) |

### 3b. Classes that count to the area whose work they do (BRIEF way 3, csv rows only, no file moves)

Each row rests on what the class does and who uses it. None of these is a change made only to hide a reference.

| Id | Files (lines) | From → to | Why it is that area's work |
|---|---|---|---|
| R1 | 16 of the job support bags and run values of section 1, all but the alert sweep, e-Depot, log cleanup and text extraction ones and `AuthFailureRun` (`AbandonedFlowClaim`, `FlowReapRun`, `AutoTransitionServices`, `BulkJobServices`, `FlowRunRetentionServices`, `FlowRunRetentionStores` → Processes; `SyncDataServices`, `OAuth2TokenRefreshServices`, `WebhookDeliveryStores` → Integrations; `ConfigurationCheckServices`, `BackfillCalendarLinksSources`, `BackfillCalendarLinksPlatform`, `BlobMigrationRun`, `BlobGroup`, `CalculationRun`, `DescriptorReimportRun` → Configuration) (255) | Compliance → the job's area | Each bag or run value carries the collaborators or state of one job or service of that area, which is its only user. This is the decided bag rule, applied to `BackgroundJob/Support`. 18 of Compliance's 73 references against the order are these bags naming the job's own area. |
| R2 | `BackgroundJob/AbstractTextExtractionJob.php` (55) | Compliance → Processes | The base class of the two Processes text-extraction jobs, its only users. No Compliance class extends or names it. |
| R3 | `Db/TaskAudit.php`, `Db/TaskAuditMapper.php` (83) | Compliance → Processes | The audit of Processes' tasks. The mapper implements Processes' `TaskAuditMapperInterface`, is bound in `ProcessesMapperBindings`, and every other reader is a Processes task class or Configuration's `ExampleSetTaskClaim`, which then points down. |
| R4 | `Service/Anonymisation/TextDisclosureRecorder.php`, `TextDisclosureSource.php`, `Exception/TextDisclosureNotRecordedException.php` (70) | Compliance → Files | The recorder writes Files' `TextDisclosure` table through `TextDisclosureMapper`. Its callers are Files' entity recognition. The source and the exception belong to the recorder alone. This removes 2 Compliance → Files references. |
| R5 | `Service/AuthFailureRun.php` (11) | Compliance → Core | The run value of Core's `SecurityService` auth-failure handling, its only user. |
| R6 | the audit query and record values that Core builds: `ActionLookupOptions`, `ActionScope`, `ActorTrailQuery`, `ActionWindow`, `AuditStatisticsScope`, `RevertOptions`, `AuditedChange`, `AuditedObject`, `SharedMasterDataRefusal`, `SharedMasterDataRefusalOptions`, `SlugBackfill` (`Db/AuditTrail/*`), `Service/SearchMeasurement.php`, `Service/AuthorizationChange.php` (178) | Compliance → Core | They become the arguments of the Core-owned ports in 3c. Core's callers build them, which is the same reasoning as D04's A10 (`CreateCandidate`). `AuditedObject::stampOnto(AuditTrail)` moves into `AuditTrailMapper` first, so the value no longer names the entity. |
| R7 | `Service/RecordUpdate.php` (Configuration, 13), `Service/TableDeletionOptions.php`, `Service/TableObjectRef.php` (Integrations, 21) | → Core | Value objects of Core's save and delete paths, whose lowest consumer is Core. Compliance's retention and schema deletion services take them as arguments. Today these are 4 Compliance → higher references. |
| R8 | `Service/Settings/ArchivalSettingsReaderInterface.php` (Configuration, 6) | Configuration → Compliance | A port of Compliance's archival settings, read by 12 Compliance classes and implemented by Configuration's `ObjectRetentionHandler`. The lower area owns the port. This removes 12 Compliance → Configuration references. |
| R9 | `Db/NewRowStamps.php` (Configuration, 24), `Service/SchemaCascade.php` (Configuration, 12), `Db/ContentReport/ContentReportFieldTypes.php` (Integrations, 24) | → Compliance | Their only users are Compliance mappers (content report, processing purpose, processing activity), `SchemaDeletionService` and the `ContentReport` entity. |
| R10 | `BackgroundJob/LogCleanUpTask.php` (Core, 122) | Core → Compliance | It cuts the audit trail, search trail, state history and metrics to their retention, using Compliance's `LogCleanUpTrails` bag. Log retention is Compliance's work. P01 owns this reference today; this row is the proposal for P01. |
| R11 | `Controller/Support/TmloRecords.php`, `TmloGuards.php` (28), `Service/WholeTableRows.php` (78), `Db/VerwerkingsactiviteitMapperInterface.php` (13) | Core → Compliance | The fixed point of D02's rows into Compliance. `TmloControllerServices` (D02) is the bags' only user, `SchemaTeardownGuard` (D02) is the only user of `WholeTableRows`, and after P7 the mapper interface is used only by Compliance's controller bag and mapper. D02's list missed these four. |
| R12 | `BackgroundJob/TransferExecutionJob.php`, `TransferAttemptRun.php` (Core, 198) | Core → Compliance | It executes one e-Depot transfer attempt on Compliance's transfer services. It names 11 Compliance files and is named by nothing but Core's job-handle lists (`JobHandles`, `QueuedJobClassesInterface`). The row waits for P46 (each area registers its own job handles). Before P46 it would turn into two Core → Compliance references. |

These rows are other designs' work and are already in the numbers above:
- D02's 38 Core rows into Compliance (controller support bags, mapper and service interfaces, retention job base, log export formatter, schema teardown guard, quality audit evaluator).
- D04's A1 (the 16 search trail files to Compliance) and A3 (`ViewAlertSweepJob` with Compliance's three alert bags to Search).

### 3c. Ports owned by Core that Compliance implements: `lib/Kernel/Contracts/Core/`

This applies the decision that Core services calling a higher area get a Core-owned port per capability.
These ports count to Core's budget (D02), not to Compliance's. Compliance implements them and binds them
in `ComplianceServiceBindings`. Constants on ports are literals equal to today's value, and a unit test
asserts the two are equal. They never read `= ComplianceClass::X`.

| Id | Port | Methods (one line each) | Implemented by | Lines |
|---|---|---|---|---|
| P1 | `AuditRecorderInterface` (D02 §3.2, same file) | D02's `record(AuditEntry $entry): void`, `recordChange(?ObjectEntity $old, ?ObjectEntity $new): void`, `recordBatch(array $entries): void`, `trailOf(ListQuery $query): array`, plus `public function recordSharedMasterDataRefusal(SharedMasterDataRefusal $refusal): void;` and `public function recordSlugBackfill(SlugBackfill $backfill): void;` for the two typed writes of `SaveObject` and `ObjectSlugBackfillLedger` | `Db/AuditTrailMapper` | 14 |
| P2 | `AuditHistoryInterface` | `public function findObjectActions(string $objectUuid, ActionLookupOptions $options): array;` · `public function countObjectTrail(string $objectUuid, ActionScope $scope): int;` · `public function tombstoneObjectTrail(string $objectUuid, ActionScope $scope): int;` · `public function findActorTrail(ActorTrailQuery $query): array;` · `public function revertObjectWith(mixed $identifier, RevertOptions $options): ObjectEntity;` · `public function statistics(AuditStatisticsScope $scope): array;` (totals, chart, distribution and most active as today's four methods return them, keyed) | `Db/AuditTrailMapper` | 12 |
| P3 | `RetentionClassificationInterface` | `public function applyClassification(RecordUpdate $update): ObjectEntity;` · `public function guardClassificationOverride(Schema $schema, array $data): void;` · `public function applyArchivalMetadata(ObjectEntity $object, Schema $schema): ObjectEntity;` | `Service/RetentionService` (`RetentionServiceInterface extends` it) | 9 |
| P4 | `ArchivalMetadataOnSaveInterface` | `public const STATUS_ACTIVE = …;` (the literal of `TmloService::ARCHIEFSTATUS_ACTIEF`) · `public function populateDefaults(ObjectEntity $object, RegisterSchema $table): ObjectEntity;` · `public function isTmloEnabled(Register $register): bool;` · `public function validateFieldValues(array $tmlo): array;` · `public function validateStatusTransition(array $tmlo, string $oldStatus): array;` | `Service/TmloService` (`TmloServiceInterface extends` it) | 11 |
| P5 | `ArchivalAnnotationsInterface` | `public function evaluateRetention(ObjectEntity $entity, array $annotation): array;` · `public function resolveDecision(ObjectEntity $entity): array;` | new `Service/Archival/RecordArchivalAnnotations`. It makes exactly the `new RetentionEvaluator(logger)->evaluate()` and `new ArchivalDecisionResolver()->resolve()` calls `RecordAnnotations` makes today. | 8 |
| P6 | `ArchivalHoldInterface` | `public const TRANSFERRED_ALIASES = [...];` (literal of `RecordState::TRANSFERRED_ALIASES`) · `public function cascadeRefusal(string $uuid, string\|int\|null $schemaIdentifier): ?array;` | `Service/Archival/ArchivalRetentionGuard` | 9 |
| P7 | `ProcessingActivityLookupInterface` | `public function retainingActivity(string $reference): ?array;` (the fields `RetentionClockService` reads from the entity today) | `Db/VerwerkingsactiviteitMapper` | 7 |
| P8 | `SearchRecordingInterface` | `public function record(array $query, SearchMeasurement $measured): void;` | `Service/SearchTrailService` (delegates to `createSearchTrail`, same try/catch at the caller) | 7 |
| P9 | `AuthorizationChangeLogInterface` | `public const EVENT_TYPE = 'openregister_authorization';` · `public function recordSchemaChange(AuthorizationChange $change): void;` · `public function recordRegisterChange(AuthorizationChange $change): void;` · `public function recordRoleChange(AuthorizationChange $change): void;` | `Service/AuthorizationAuditService` | 10 |

These need no new port:
- **Read purpose binding.** `Kernel/Contracts/Core/ReadPurposeGuardInterface` and `ReadPurposeRefusalInterface` already exist on main. `PurposeGuardInterface extends` the first and `PurposeRefusedException implements` the second. Core's `MagicMapper`, `RelationHandler`, `ObjectGovernanceServices`, `ObjectRelationsGuards`, `ObjectRelationsPurposeScope` and `ObjectsController` switch to them. The catch site catches `ReadPurposeRefusalInterface` and still reads `getStatusCode()`/`toResponseBody()`, so the answer is identical.
- **Schema teardown.** `Service/SchemaTeardownInterface` (Core, already bound to `SchemaDeletionService`) gains `deleteObjectsBySchema(RegisterSchemaRef $table, TableDeletionOptions $options = new TableDeletionOptions()): array` and `deleteObjectsByRegister(Register $register, TableDeletionOptions $options = new TableDeletionOptions()): array`, as `SchemaDeletionService` declares them, plus the literals `TRIGGER_BULK_DELETE` and `ACTION_FORCED_TEARDOWN`. `ObjectService` resolves the interface instead of the class. D02 folds this interface into `SchemaEvolutionInterface::teardown`. Either way the capability stays one Core port.
- **Inherited appraisal check.** The `InheritedAppraisal::ANNOTATION` check in `Db/SchemaMapper/RecordAnnotationRefusals` moves into Compliance's existing `ArchivalDeclarationChecksListener`, contributed through `SchemaDeclarationChecksEvent` in the slot where it runs today (#10685), never reordered. No listener is added, so the listener pins do not change.
- **Object history endpoint.** `ObjectsController::logs` (with `logsForReader`, `logsPage` and `historyObject`) moves to Compliance's `Controller/AuditTrailController`, which already serves the audit-trails route with the same reader redaction. `appinfo/routes.php` keeps `/api/objects/{register}/{schema}/{id}/logs` and `GET`; the route name becomes `auditTrail#objectLogs`. This follows the decision on `ObjectsController` endpoints.
- **Throttled surfaces.** Each controller's `THROTTLE_ACTION` becomes the literal it has today. `ThrottledSurfaces` keeps its list. A unit test asserts that every controller literal is in `ThrottledSurfacesInterface`. This is not a duplicated contract: the constant names the controller's own surface, and the registry is Compliance's.
- **The `AuditRecording` bag** in `DestructionScopeService`: it only carries the audit mapper interface and a logger. Core's `DestructionScopeService` takes P2 and the logger directly (it calls `countObjectTrail` and `tombstoneObjectTrail` with `ActionScope`), and the Compliance users keep the bag.

`Db/AuditTrailMapperInterface` (Core) loses the methods whose values are Compliance's and that no Core
class calls: `recordToolInvocation`, `recordPartyQueryRefusal`, `recordPurposeRefusal`, `recordHardeningChange`,
`recordExport` and `recordRegisterEvent`. Compliance's own callers use `AuditTrailMapper`; the higher callers
use 3d. No fleet app names the interface. What remains is its reference to `AuditTrail` (the return types).
It goes when D02's C5 packages have moved the interface's 38 users below Compliance (Core 32, Search 4, Notifications 2) to P1 and P2 (section 4). Its methods that return `AuditTrail` then leave it; the higher callers use `AuditEventsInterface` or the concrete mapper.

### 3d. Ports owned by Compliance: `lib/Kernel/Contracts/Compliance/`

Higher areas use these. Each is one capability, and its methods are what the callers call today.

| Port | Methods (one line each) | Implemented by | Lines |
|---|---|---|---|
| `AuditEventsInterface` | `public function insertRow(AuditTrail $row): AuditTrail;` · `public function recordRead(ObjectEntity $object, string $action): ?AuditTrail;` · `public function recordToolInvocation(ToolInvocation $invocation): AuditTrail;` · `public function recordExport(ExportOutcome $export): AuditTrail;` · `public function recordPartyQueryRefusal(PartyQueryRefusal $refusal): AuditTrail;` | `Db/AuditTrailMapper` | 11 |
| `ProcessingReadsInterface` | `public function recordRead(ObjectEntity $object, ProcessingRead $read): void;` · `public function recordReadList(array $objects, ProcessingRead $read): void;` | `Service/ProcessingLogService` | 7 |
| `TokenAttributionContextInterface` | `public function claim(?TokenIdentity $identity): void;` | `Service/Audit/TokenContext` | 7 |
| `IdentityAndRegulatorSeamsInterface` | `public function identityProviders(): array;` · `public function regulatorProviders(): array;` (ids without the null provider, as the job computes today) | new `Service/Gdpr/ConnectedSeams` over the two registries | 7 |
| `AnonymisationInterface` | `public const METHODS = [...];` and `public const METHOD_OPENANONYMISER = 'openanonymiser';` (literals of `BackendMethodsInterface`) · `public function effectiveMethod(): string;` (today `getState()->effectiveMethod`) · `public function requestOpenAnonymiser(AnonymiserCall $call): ?array;` · `public function testConnection(string $method): ProbeResult;` · `public function normaliseSettings(array $settings): array;` · `public function defaultSettings(): array;` | `Service/Anonymisation/AnonymisationBackendService` (the last two on `CapacityPolicy` and `OfficialsSource`) | 13 |
| `AnonymisationSettingsSourceInterface` | `public function anonymisationSettings(): array;` | Configuration's `Service/Settings/FileSettingsHandler`. This fixes 4 Compliance → Configuration/Integrations references of the anonymisation backend. | 6 |
| `AuditSettingsInterface` | `public function snapshot(): array;` · `public function announce(array $before, array $after): int;` · `public function isSecret(string $path): bool;` · `public function label(string $path): string;` · `public function readEventGroups(string $raw): array;` | new `Service/Audit/AuditSettings` over `SecuritySettingAnnouncer`, `SecuritySettingRegistry` and `ReadEventVisibility`. Configuration keeps `CONFIG_KEY` as a literal with an equality test. | 11 |
| `ArchiveTransferConnectionsInterface` | `public function getTransportConfig(): array;` · `public function getAvailableProfiles(): array;` · `public function isValidProfile(string $profile): bool;` · `public function testConnection(string $transport, array $config): array;` (today the controller picks the transport interface and calls its `testConnection($config)`) | `Service/Edepot/EdepotTransferService` | 10 |
| `SelectionListSeedingInterface` | `public function seed(array $entries, ?string $appId = null): array;` | `Service/Archival/SelectionListSeeder` | 6 |
| `LifecycleFinalStatesInterface` | `public function isReferenceForm(mixed $final): bool;` · `public function isFinalByReference(mixed $declaration, string $state): bool;` | Processes' `Service/Lifecycle/LifecycleFinalStateResolver`, bound in Processes' registrar. `ArchivalNominationService` uses the port, which removes its Compliance → Processes reference. | 7 |

Existing narrow Compliance interfaces stay as the contract for the one capability they serve. Moving them
into `lib/Kernel/Contracts/Compliance/` would move files out of their Nextcloud directory for no gain:
- `Service/Audit/ObjectHistoryForReadersInterface` (7): Integrations' three activity-feed classes switch to it from the concrete class.
- `Db/AuditFlowAttributionInterface` (7): already a Compliance-owned port that Processes implements.
- `Service/AuditRechainInterface` (9, Platform repair).
- `Service/Hardening/AllowedOriginsInterface` (6, Platform CORS middleware).
- `Service/Audit/AuditAggregationServiceInterface` (11, Configuration audit settings).
- `Service/Settings/ArchivalSettingsReaderInterface` (6, after R8; implemented by Configuration).

Ports deliberately not added:
- A port for the registrars. `ComplianceServiceRegistrar` and `ComplianceMapperRegistrar` stay named by `Application`, as every area's registrar is.
- A port per Compliance service in `ContractBindings`. W1 removes the reason they were exposed.
- A port for Files' use of `PipelineSteps`. `DocumentProcessingHandler` writes the step name as a literal with an equality test. `PipelineSteps` itself names Files' sanitizers (5 references against the order). That is the Files design's to resolve, because the step list describes Files' pipeline.

### 3e. Entities that cross the boundary

| Entity | Today crosses to | After |
|---|---|---|
| `Db/AuditTrail` | Core (19), Processes, Files, Configuration, Search | No Core or Search class names it once P1, P2 and D02's C5 packages are done: Core writes through `AuditEntry`/typed values and reads arrays. It stays named by the higher areas that build a raw row (Files' `FileAuditHandler`, Processes' flow hop, schedule, halt, action history and read history, Configuration's example set and shipped baseline guard), through `AuditEventsInterface::insertRow`. That is allowed (they point down) and costs 79 lines. Replacing it with a widened `AuditEntry` is a 4.5 cut (section 4). |
| `Db/SearchTrail`, `SearchTrailOutcome` | Search | Internal to Compliance (D04 A1). |
| `Db/Verwerkingsactiviteit` | Core | Internal. Core gets the retaining activity as an array (P7). |
| `Db/ContentReport`, `ErasurePreview`, `ProcessingPurpose`, `ProcessingLogSubject` | Core (their mapper interfaces) | Internal once the interfaces count to Compliance (D02 rows). |
| `Db/GdprEntity` | Files (6), Processes, Core `ImageRedactionService` | Stays named by Files and Processes (52 + 13 for the mapper interface), which is allowed. Core's `ImageRedactionService` becomes Files' by D02 C5-12. The entity recognition and the GDPR entity controller are candidates for a narrower port or for Compliance in the Files and Processes designs. |
| `Db/TaskAudit` | Processes | Processes' own (R3). |
| `Db/ObjectEntity`, `Schema`, `Register` (Kernel) | named by the ports | They point down, which is allowed. |

## 4. The budget

Exposed lines of Compliance after each step, on top of the previous one, from the simulation:

| Step | Exposed files | Exposed lines | Against the order into / out of Compliance |
|---|---|---|---|
| Today | 230 | 15,739 | 113 / 73 |
| W: wiring (W1 to W4) | 113 | 5,848 | 112 / 73 |
| R: rows (R1 to R11, D02 and D04 rows) | 69 | 4,914 | 71 / 33 |
| P: Core-owned ports and their callers (3c) | 48 | 3,143 | 14 / 33 |
| K: Compliance-owned ports and their callers (3d) | 40 | 578 | 14 / 30 |
| E: e-Depot job after P46 (R12) | 29 | 474 | 3 / 30 |

The exposed files that remain after E:

| Exposed file after | Lines | Named by |
|---|---|---|
| `Db/AuditTrail` (entity, see 3e) | 79 | Files, Processes, Configuration; Core `AuditTrailMapperInterface` until D02 C5 |
| `Service/Audit/TokenIdentity` (value of `TokenAttributionContextInterface`) | 69 | Integrations |
| `Db/GdprEntity` + `Db/GdprEntityMapperInterface` | 65 | Files, Processes (Core until D02 C5-12) |
| `Listener/Registrar/ComplianceListenerRegistrar` | 40 | Processes |
| `Db/AuditTrail/ExportOutcome`, `PartyQueryRefusal`, `ToolInvocation` (values of `AuditEventsInterface`) | 36 | Configuration, Processes, Integrations |
| `Service/ProcessingRead` (value of `ProcessingReadsInterface`) | 12 | Processes |
| `Service/Anonymisation/AnonymiserCall` (value of `AnonymisationInterface`) | 11 | Files |
| `Service/Audit/NamedIdentifier` (part of `TokenIdentity`) | 10 | Integrations |
| `ComplianceServiceRegistrar`, `ComplianceMapperRegistrar` | 21 | Platform |
| The 10 ports of 3d | 85 | Configuration, Files, Integrations, Processes |
| Existing narrow interfaces (3d list) | 46 | Configuration, Integrations, Platform, Processes |
| **Total** | **474** (29 files) | |

Until P46 lands, the 8 e-Depot interfaces and 3 bags of section 1 (104 lines) stay named by Core's
`TransferExecutionJob`: 578 lines in 40 files, with those 11 Core → Compliance references.

Compliance's share of the target. Kernel takes about 9,700 lines, which leaves about 9,200 for the other
nine areas at 4.0 and about 2,900 at 4.5. Compliance is 35,744 of the 449,016 non-Kernel lines (8.0%).
Its proportional share is therefore about 730 lines at 4.0 and about 230 at 4.5.
- **4.0:** 474 lines fits, and so do 578 lines before P46.
- **4.5:** 474 lines is about 245 over the proportional share. Four cuts would bring it to about 230:
  - The higher areas' raw audit rows go through a widened `AuditEntry` (D02 owns it) instead of `AuditTrail`: −79.
  - `TokenAttributionContextInterface::claim` takes the token, owner and consumer ids as scalars, and `TokenIdentity` stays internal: −79 (with `NamedIdentifier`).
  - Files' and Processes' use of GDPR entities goes behind one detected-entities port of about 8 lines: −57.
  - The typed audit values collapse into one `AuditEvent` value with a kind: about −24.
  - These are named here and not designed further, because each changes a signature the 4.0 step does not need.

Effects on other areas, from the same simulation:
- **Core:** exposed lines go from 65,057 to 64,241. The nine Core ports cost 87 lines, including D02's `AuditRecorderInterface`. The values of R6 and R7 add 212 lines that Compliance names, which is allowed.
- **Search:** 2,072 → 1,645, mostly the search trail (D04 A1).
- **Files:** 3,689 → 3,641. **Processes:** 32,009 → 31,893. **Configuration:** 6,895 → 6,819. **Integrations:** 18,785 → 18,740.

**The three references into Compliance that remain are owned by D02.** `Db/AuditTrailMapperInterface → Db/AuditTrail`
goes when the interface's 38 users below Compliance have moved to P1 and P2 (D02 C5), and the interface's
entity-returning methods leave it. `Service/File/ImageRedactionService → Db/GdprEntity` and `→ GdprEntityMapperInterface`
go with D02 C5-12 (file storage to Files). Nothing here breaks a rule.

**Flagged: ordering, not a forbidden move.**
- R12 waits for P46. Without P46 the row would create two Core → Compliance references (the job-handle lists).
- D02 C5-12 makes `PipelineSteps → ImageRedactionService` a Compliance → Files reference. D02 already flagged this as Compliance's anonymisation needing a Compliance-owned port for file storage. The Files design resolves it together with `PipelineSteps`.

## 5. The build packages

Dependency order. Within a step, packages are independent and touch disjoint files, with three exceptions
that are stacked, never run in parallel:
- `lib/Service/ComplianceServiceBindings.php` is Compliance's single binding map. B1, B4a, B4b and B6 each add their own lines, in that order.
- `lib/Db/AuditTrailMapper.php` implements P1, P2 and `AuditEventsInterface`, so B4a comes before B6.
- `scripts/component-map.csv` with its two generated files (the components file and the deptrac `areas.yaml`) is shared, as in D04. A merge conflict there keeps both sides' rows.

`lib/Service/ContractBindings.php` is shared with D02 (C5-01) and D04 (S7). B1 is its own PR and touches
only the Compliance pairs.

**B1: Compliance's bindings in its own map (W1, W2, W3).**
Files:
- `lib/Service/ContractBindings.php` (remove 75 pairs and their imports)
- `lib/Service/ComplianceServiceBindings.php` (add them)
- `lib/Service/ComplianceServiceRegistrar.php` (call `GdprRegistrar::register`)
- `lib/Service/ServiceBootstrap.php` (drop that call)
- `lib/AppInfo/Application.php` (unused import)
- the binding tests of both maps, and a new test that the union of bound ids is unchanged

It removes 9,335 + 57 lines of exposure and 1 Core → Compliance reference. There is no behaviour change.

**B2: Compliance's listeners through its own registrar (W4).**
Files:
- new `lib/Listener/Registrar/ComplianceListenerRegistrar.php`
- `lib/Listener/ListenerRegistrar.php`
- `scripts/component-map.csv`
- `tests/Unit/Listener/ComplianceListenerRegistrarTest.php` (new) and a run of `ListenerRegistrationOrderGoldenTest` proving the hash is unchanged

**B3: rows (R1 to R5, R8, R9, R11).**
Files:
- `scripts/component-map.csv` (+ generated)
- the tests in `tests/Unit/Architecture` that pin areas, if any name these files

There are no code changes. It removes 18 (R1) + 2 (R4) + 12 (R8) + 5 (R9) Compliance → higher references. Rows that D02's R package or D04's S1 have already landed are skipped. R11 lands with or after
D02's row package, and its mapper-interface row lands after B5c.

**B4a: Core-owned audit ports and their Compliance implementations (P1 if D02 has not added it, P2, P8, P9; R6 rows).**
Files:
- new `lib/Kernel/Contracts/Core/AuditHistoryInterface.php`, `SearchRecordingInterface.php`, `AuthorizationChangeLogInterface.php` (and `AuditRecorderInterface.php` with D02's signature, if D02 C5-05 has not landed)
- `lib/Db/AuditTrailMapper.php` (implements; `AuditedObject::applyTo` moves in)
- `lib/Db/AuditTrail/AuditedObject.php`
- `lib/Service/SearchTrailService.php`
- `lib/Service/AuthorizationAuditService.php`
- `lib/Service/ComplianceServiceBindings.php`
- `scripts/component-map.csv` (R6)
- unit tests for each implementation and the constant equality tests

Nothing outside Compliance names the new ports yet, so nothing is newly exposed.

**B4b: Core-owned archival ports and their Compliance implementations (P3 to P7).**
Files:
- new `lib/Kernel/Contracts/Core/RetentionClassificationInterface.php`, `ArchivalMetadataOnSaveInterface.php`, `ArchivalAnnotationsInterface.php`, `ArchivalHoldInterface.php`, `ProcessingActivityLookupInterface.php`
- `lib/Service/RetentionServiceInterface.php`, `lib/Service/TmloServiceInterface.php` (extend the ports)
- `lib/Service/Archival/ArchivalRetentionGuard.php`
- new `lib/Service/Archival/RecordArchivalAnnotations.php`
- `lib/Db/VerwerkingsactiviteitMapper.php`
- `lib/Service/ComplianceServiceBindings.php` (stacked after B4a)
- `scripts/component-map.csv` (R7 and the new file)
- unit tests, including the literal tests for `STATUS_ACTIVE` and `TRANSFERRED_ALIASES`

**B5a: Core's audit history readers (P2).**
Files:
- `lib/Controller/DeletedController.php`
- `lib/Service/Deletion/DeletedObjectAuthorizer.php`, `DeletedObjectAuthorizerInterface.php`, `DestructionScopeService.php`
- `lib/Service/Rbac/ObjectAccessHistory.php`, `ObjectAccessReport.php`
- `lib/BackgroundJob/StateHistoryRebuildJob.php`
- `lib/Service/Object/MovedObjectLookup.php`, `RevertHandler.php`
- `lib/Service/UrnService.php`, `lib/Service/UserService.php`
- `lib/Controller/RegistersController.php`, `SchemasController.php` (statistics only)
- their tests

A golden test of each reader's response is written first, because they now get arrays.

**B5b: Core's audit writers (P1).** Its file list is D02's C5 caller package for `AuditRecorderInterface`.
If that package has landed, B5b is empty. It is listed here so the Compliance numbers do not depend on it:
`ConflictTrail`, `EntityRelationMapper`, `FolderAccessDenial`, `PublishedDigestService`, `ReferentialIntegrityService`,
`AuditRowWriter`, `RevealFlusher`, `SettingsChangeAuditor`, `ApiTokenAuditRow`, `ObjectSlugBackfillLedger`, `ConflictReport(Interface)`,
`CorrectionService(Interface)`. `SaveObject` is in B5c.

**B5c: the save, render and delete paths (P3 to P7, the declaration check).**
Files:
- `lib/Service/Object/SaveObject.php`, `SaveObject/SaveObjectRecords.php`
- `lib/Service/Object/RecordAnnotations.php`
- `lib/Db/SchemaMapper/RecordAnnotationRefusals.php`
- `lib/Listener/ArchivalDeclarationChecksListener.php` (takes over the inherited appraisal check in its slot)
- `lib/Service/Object/IntegrityCascadeControls.php`, `DeleteRowRules.php`, `OrphanDeletionGuard.php`
- `lib/Service/Deletion/RetentionClockService.php`
- tests: the `SaveObject` retention and TMLO cases, `RecordAnnotations`, the SchemaMapper check tests (hand-built `SchemaMapper` tests need `ProcessDeclarationChecksDispatcher`), the delete rule tests

The order of the checks and the lazy container resolution in `SaveObject` stay the same. The listener is
not added, only extended, so the listener pins do not change.

**B5d: search trail, read purpose and schema teardown callers.**
Files:
- `lib/Service/ObjectService.php` (search recording and `SchemaTeardownInterface`)
- `lib/Service/Object/SearchQueryHandler.php`, `Collaborators/ObjectStorageServices.php`
- `lib/Db/MagicMapper.php`, `lib/Service/Object/RelationHandler.php`
- `lib/Controller/Support/ObjectGovernanceServices.php`, `ObjectRelationsGuards.php`, `ObjectRelationsPurposeScope.php`
- `lib/Service/SchemaTeardownInterface.php` (two methods, two literals)
- `lib/Service/SchemaDeletionService.php`
- tests

The access checks keep their order and position. The purpose guard runs where it runs today.

**B5e: authorization change log callers and throttled surface literals.**
Files:
- `lib/Controller/Support/RegisterAccessAudit.php`
- `lib/Service/Rbac/ReachRevocationLog.php`, `ReachRevocationService.php`
- `lib/Mcp/BuiltIn/RegistersToolProvider.php`, `SchemasToolProvider.php`
- `lib/Controller/AccessLinkController.php`, `CalendarFeedController.php`, `CaseTokenController.php`, `CredentialOauth2Controller.php`, `FederationController.php`, `FormsController.php`, `ObjectShareLinkController.php`
- tests, including the literal-in-registry test

The authorization calls in `RegistersController` and `SchemasController` change in B5a, which already touches those two files.

**B5f: the object history endpoint to Compliance.**
Files:
- `lib/Controller/ObjectsController.php` (`logs` and its helpers out; the purpose refusal catch on `ReadPurposeRefusalInterface`)
- `lib/Controller/AuditTrailController.php` (`objectLogs`)
- `appinfo/routes.php` (route name; URL and verb unchanged)
- `lib/Controller/Support/ObjectsExtensions.php`, if it lists the method
- tests: the `ObjectsController` logs tests retargeted to `AuditTrailControllerTest`, with a golden response for admin and non-admin readers

**B6: Compliance-owned ports and their implementations (3d).**
Files:
- new `lib/Kernel/Contracts/Compliance/AuditEventsInterface.php`, `ProcessingReadsInterface.php`, `TokenAttributionContextInterface.php`, `IdentityAndRegulatorSeamsInterface.php`, `AnonymisationInterface.php`, `AnonymisationSettingsSourceInterface.php`, `AuditSettingsInterface.php`, `ArchiveTransferConnectionsInterface.php`, `SelectionListSeedingInterface.php`, `LifecycleFinalStatesInterface.php`
- `lib/Db/AuditTrailMapper.php` (stacked after B4a)
- `lib/Service/ProcessingLogService.php`, `lib/Service/Audit/TokenContext.php`
- new `lib/Service/Gdpr/ConnectedSeams.php`, new `lib/Service/Audit/AuditSettings.php`
- `lib/Service/Anonymisation/AnonymisationBackendService.php`
- `lib/Service/Edepot/EdepotTransferService.php`, `lib/Service/Archival/SelectionListSeeder.php`
- `lib/Service/ComplianceServiceBindings.php`
- `scripts/component-map.csv`
- tests

This is over 15 files, so it splits into B6a (the audit ports: events, processing reads, token context,
audit settings) and B6b (the rest). Nothing outside Compliance names the ports yet.

**B7a: higher callers of audit events and processing reads.**
Files:
- `lib/AppHost/Scheduling/ScheduleAuditTrail.php`
- `lib/Service/Flow/FlowHopAudit.php`, `lib/Service/Interaction/ReadHistoryService.php`, `lib/Service/Party/PartySearchService.php`
- `lib/Files/Service/File/FileAuditHandler.php`
- `lib/Mcp/BuiltIn/ToolInvocationAudit.php`
- `lib/Service/Export/ExportAuditRecorder.php`, `lib/Service/Exchange/ExportKeyService.php`
- `lib/Service/ExampleSet/ExampleSetAuditEntries.php`, `lib/Service/ShippedBaseline/ShippedConfigurationGuard.php`
- tests

After B7a, `Db/AuditTrailMapperInterface` drops the six methods listed in 3c, in the same PR.

**B7b: Configuration's settings and import callers.**
Files:
- `lib/Service/Settings/SettingsAuditTrail.php`, `ConfigurationSettingsSupport.php`, `ConfigurationSettingsHandler.php`, `FileSettingsHandler.php` (implements `AnonymisationSettingsSourceInterface`)
- `lib/Controller/Settings/EdepotSettingsController.php`, `lib/Controller/Support/EdepotSettingsDomain.php`
- `lib/Service/Configuration/ImportHandler.php`, `ConfigurationServiceRegistrar.php`
- tests

**B7c: Integrations' and Files' callers.**
Files:
- `lib/Service/AuthorizationService.php`, `lib/BackgroundJob/ConnectionSeamReportJob.php`
- `lib/Service/Integration/ActivityFeedService.php`, `BuiltinIntegrationServices.php`, `BuiltinProviders/AuditTrailProvider.php`
- `lib/Files/Controller/Settings/FileSettingsController.php`, `lib/Files/Service/TextExtraction/EntityRecognitionHandler.php`, `lib/Service/TextExtraction/EntityRecognitionBackends.php`
- `lib/Files/Service/File/DocumentProcessingHandler.php` (step literal)
- tests

**B7d: Compliance's own references up (anonymisation settings, final lifecycle states).**
Files:
- `lib/Service/Anonymisation/AnonymisationBackendCollaborators.php` (and the settings read in `AnonymisationBackendService`, stacked after B6b)
- `lib/Service/Archival/ArchivalNominationService.php`
- `lib/Service/Lifecycle/LifecycleFinalStateResolver.php` (implements the port)
- Processes' registrar binding
- tests

**B8: the e-Depot transfer job counts to Compliance (R12).** It waits for P46.
Files:
- `scripts/component-map.csv`
- the job-handle registration of Compliance (P46's mechanism)

Not in a Compliance package:
- The remaining Compliance → higher references (30). These are `AuditTrailExportJob` on Configuration's `RecordedQueuedJob`, `LogCleanUpTask` on `RetentionSettingsReaderInterface`, DSAR cases on `TransitionEngine`, `TmloController`/`TmloGuards` on `ExportGateInterface`, the audit mapper and collaborators on lifecycle and flow attribution classes, three listeners on Processes' listener support traits, the rechain repair, token resolution on Integrations' `Consumer`, `AuditChainKey` on `TenantKeyService`, `PipelineSteps` and `DetectorVersionReader` on Files, and `QualityAuditController`/`TransferController` on Platform. Each needs a Compliance-owned port that the higher area implements, as R8 and `LifecycleFinalStatesInterface` do. They belong to the higher areas' designs and the existing P packages, because the class they name is that area's exposure.
- D02's C5 packages that finish the move of Core's users off `AuditTrailMapperInterface`.

## 6. Fleet impact

The fleet names these Compliance classes (fleet surface, 19 apps, development branches, 2026-11-19):

| Class | Apps | This design |
|---|---|---|
| `Db\AuditTrailMapper` | buildiq, decidiq, dossiq, filinq, hermiq, learniq, planninq, portaliq, shillinq | Unchanged. It gains `AuditHistoryInterface` and `AuditEventsInterface` (and D02's `AuditRecorderInterface`). `AuditTrailMapperInterface` (Core) loses six methods. No fleet app names that interface. |
| `Db\AuditTrail` | decidiq, filinq, hermiq, portaliq | Unchanged. |
| `Db\AuditTrail\RegisterAuditEvent` | shillinq | Unchanged, stays Compliance. |
| `Service\SchemaDeletionService` | dossiq, hermiq, learniq | Unchanged. `SchemaTeardownInterface` widens. |
| `Service\TmloService`, `Service\RetentionService` | dossiq; humaniq, pipelinq | Unchanged. Their interfaces gain a parent port. |
| `Service\AuditHashService` | decidiq, humaniq, learniq | Unchanged. Only its binding moves (W1). |
| `Service\Anonymisation\AnonymisationBackendService` | dossiq, filinq | Unchanged. It gains `AnonymisationInterface`. |
| `Service\Archival\DestructionListCreator`, `DestructionListRepository`, `LegalHoldService(Interface)` | decidiq, dossiq, filinq | Unchanged. |
| `Service\Edepot\EdepotTransferService`, `TransferListService`, `TransferRecordService` | decidiq, opencatalogi | Unchanged. It gains `ArchiveTransferConnectionsInterface`. |
| `Service\Gdpr\*` (DSAR service, erasure preview/runner/store, subject export, evidence, identity and regulator providers and registries, `SubjectQuery`, `ErasureRequest`) | dossiq, humaniq, pipelinq | Unchanged. The identity and regulator registries also back `IdentityAndRegulatorSeamsInterface`. |
| `Db\GdprEntity(Mapper)`, `Db\Verwerkingsactiviteit(Mapper)` | filinq; dossiq | Unchanged. |
| `Service\ContentScanService` | hermiq | Untouched. |

Nothing other apps use is renamed, moved or removed. Every class keeps its name, namespace and directory.
No event other apps read changes in this design. None of the rows moves a class the fleet names to
another area, except that `SearchMeasurement` and `AuthorizationChange` (not used by the fleet) count to Core.

Candidates for promotion to `lib/Contract` (ADR-119 Decision 14) are named here, not done:
- `AuditEventsInterface` and `AuditHistoryInterface`, with a published audit value. Nine apps name `AuditTrailMapper` to write or read the trail, which is the largest Compliance surface in the fleet.
- A DSAR seam contract (identity verification and regulator escalation providers with their registries), which pipelinq implements today on concrete classes.
- `SchemaTeardownInterface`, for the three apps that call `SchemaDeletionService`.
- A retention contract (`RetentionClassificationInterface` with the legal hold and destruction list reads), for humaniq, pipelinq, decidiq and dossiq.

## Notes for the other designs

- **D01 (Kernel):** this design adds no file to Kernel. `lib/Kernel/Contracts/Compliance/` is new and counts to Compliance.
- **D02 (Core):** P1 is D02's `AuditRecorderInterface` with two typed writes added. P2 to P9 are Core-owned ports (87 lines including P1). R6 and R7 add 212 lines of values to Core that Compliance names. D02's rows into Compliance miss `TmloRecords`, `TmloGuards`, `WholeTableRows` and `VerwerkingsactiviteitMapperInterface` (R11). The last three references into Compliance need D02 C5 and C5-12.
- **D04 (Search):** A1 and A3 are taken as given. The Search dashboard reaches the audit statistics through P2 (Core), so Search names no Compliance file.
- **Files design:** `PipelineSteps` and `DetectorVersionReader` name Files classes, and R4 gives Files the text disclosure recorder. Entity recognition's use of `GdprEntity` is a 4.5 candidate for one port.
- **Processes design:** `LifecycleFinalStatesInterface` is implemented by Processes. `GdprEntitiesController` (Processes row, GDPR entity register) is a candidate for Compliance by its work. The three Compliance listeners naming `Listener/Support/EventSchemaLookup` and `HandlesObjectDeletion` need those helpers to count lower or be ported.
- **P46 (job handles):** R12 waits for it.

## Appendix: the 75 Compliance pairs in `ContractBindings`

`Service/Anonymisation/AnonymisationBackendServiceInterface` and `AnonymiserRequestInterface` → `AnonymisationBackendService` ·
`Kernel/Contracts/Core/AuditEntryWriterInterface` → `AuditEntryWriter` ·
`Service/Archival/ArchivalNominationServiceInterface`, `ArchivalNominationTriggerInterface` → `ArchivalNominationService` ·
`Service/Audit/AuditSinkInterface`, `AuditSinkShipperInterface` → `AuditSink` · `AuditSinkStatusInterface` → `AuditSinkStatus` ·
`AuditTrailPageServiceInterface` → `AuditTrailPageService` · `Service/AvgComplianceServiceInterface` → `AvgComplianceService` ·
`Service/AuditHashServiceInterface`, `AuditSealInterface`, `AuditRechainInterface` → `AuditHashService` · `AuditQueryServiceInterface` → `AuditQueryService` ·
`Service/Archival/DestructionListCreatorInterface` → `DestructionListCreator` · `DestructionServiceInterface` → `DestructionService` ·
`DependantRetentionIndexInterface` → `DependantRetentionIndex` · `RetentionEvaluatorInterface` → `RetentionEvaluator` ·
`Service/Audit/ContentReportServiceInterface` → `ContentReportService` · `Service/DsarServiceInterface` → `DsarService` ·
`Service/Edepot/EdepotTransferServiceInterface`, `EdepotTransferAttemptInterface` → `EdepotTransferService` ·
`Service/Gdpr/DataSubjectRequestServiceInterface` → `DataSubjectRequestService` · `Erasure/ErasurePreviewServiceInterface` → `ErasurePreviewService` ·
`Erasure/ErasurePreviewStoreInterface` → `ErasurePreviewStore` · `Erasure/ErasureRunnerInterface` → `ErasureRunner` ·
`Service/Archival/DestructionListRepositoryInterface` → `DestructionListRepository` · `DestructionReviewServiceInterface`, `DestructionReviewReminderInterface` → `DestructionReviewService` ·
`LegalHoldServiceInterface` → `LegalHoldService` · `ReviewOutcomeServiceInterface` → `ReviewOutcomeService` ·
`Service/Audit/ObjectHistoryForReadersInterface` → `ObjectHistoryForReaders` · `ReadableAuditTrailListerInterface` → `ReadableAuditTrailLister` ·
`PurposeGuardInterface` → `PurposeGuard` · `PurposeRegistryInterface` → `PurposeRegistry` ·
`Service/Edepot/Transport/OpenConnectorTransportInterface`, `RestApiTransportInterface`, `SftpTransportInterface` → their transports ·
`Service/Archival/SelectielijstImportServiceInterface` → `SelectielijstImportService` · `SelectielijstReferenceResolverInterface` → `SelectielijstReferenceResolver` ·
`Service/Edepot/TransferListServiceInterface`, `TransferListRunInterface` → `TransferListService` · `TransferRecordServiceInterface`, `TransferRecordStoreInterface` → `TransferRecordService` ·
`Service/TmloServiceInterface` → `TmloService` · `Service/Gdpr/Export/SubjectExportServiceInterface`, `SubjectExportAssemblerInterface` → `SubjectExportService` ·
`Gdpr/Case/CaseAccessControlInterface` → `CaseAccessControl` · `CaseObjectAccessorInterface` → `CaseObjectAccessor` ·
`Gdpr/Policy/DsarPolicyPackResolverInterface` → `DsarPolicyPackResolver` · `Gdpr/Evidence/EvidenceHarvestServiceInterface` → `EvidenceHarvestService` ·
`Gdpr/Export/ExportBundleServiceInterface` → `ExportBundleService` · `Gdpr/Identity/IdentityVerifyRegistryInterface` → `IdentityVerifyRegistry` ·
`Gdpr/Redaction/RedactionWriteServiceInterface` → `RedactionWriteService` · `Gdpr/Regulator/RegulatorEscalateRegistryInterface` → `RegulatorEscalateRegistry` ·
`Service/Archival/ArchivalAnnotationValidatorInterface` → `ArchivalAnnotationValidator` · `ElementMappingValidatorInterface` → `ElementMappingValidator` ·
`ArchiveReferenceReaderInterface` → `ArchiveActionDateCalculator` ·
`Service/Hardening/ElevationServiceInterface` → `ElevationService` · `AllowedOriginsInterface`, `HardeningFloorsInterface` → `HardeningPolicy` ·
`HardeningReportServiceInterface` → `HardeningReportService` · `HardeningSettingsServiceInterface` → `HardeningSettingsService` · `StatementServiceInterface` → `StatementService` ·
`Service/Audit/AuditAggregationServiceInterface` → `AuditAggregationService` · `OrganisationContextAttributionInterface` → `OrganisationContextAttribution` ·
`PurposeAttributionInterface` → `PurposeAttribution` · `TokenAttributionInterface` → `TokenAttribution` ·
`Service/AuditRetentionResolverInterface` → `AuditRetentionResolver` · `Service/SearchTrailServiceInterface` → `SearchTrailService` ·
`Service/RetentionServiceInterface` → `RetentionService` · `Service/SchemaTeardownInterface` → `SchemaDeletionService` ·
`Service/AvgRetentionPassInterface` → `AvgRetentionService` · `Service/Gdpr/DpiaPatternDetectorInterface` → `DpiaPatternDetectionService` ·
`Gdpr/Retention/RetentionSweepInterface` → `RetentionSweepService`.

## How the numbers were made

The graph is `tool.php_dependencies` over `tool.production_files`, with `component_of_factory` on
the generated components file, as in `modularity_check.measure`. Each step edits that graph:
- a binding move drops the map's edges and adds them to the area's own map;
- a row change reassigns one file's area;
- a caller change removes the caller's edges to the replaced files and adds one edge to the port;
- a new file is added with its estimated lines and its edges.

`exposure` and `against_order` are then recomputed with the same arithmetic as `modularity_check.py`.
The binding pairs were read from `ContractBindings::BINDINGS` with the file's `use` imports resolved.
The fleet table is `fleet_surface.measure` over fresh `fleet_checkout.sh` checkouts, filtered to classes
whose file counts to Compliance.
