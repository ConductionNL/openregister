# Contract design: the Integrations area

Wave 5 design package D07 for the Integrations area (rank 6 in `area-order.json`: above Files, below Processes,
Configuration and Platform). It changes nothing in `lib/`; it is the plan the wave 5 build packages for this area
carry out.

Measured on main `911c2702` with `modularity_check.py` (whole app: 155,651 of 459,456 lines exposed, Kernel 10,440,
violation degree 0.0874, 727 references against the order), the module graph of the maintainability checker and
`fleet_surface.py` over fresh `fleet_checkout.sh` checkouts of the 19 fleet apps (development branches,
19 Nov 2026). Line counts are code lines as the scorer counts them (no comments, no blank lines). The "after" numbers
are a model: the measured graph replayed with every change of this design applied, plus the rows the Core (D02) and
Notifications (D04) designs already plan for files that touch this area, and re-measured with the same code paths
(see "How the numbers were made").

## Summary

| | Before | After |
|---|---|---|
| Files of the area | 887 | 943 (84 files in by row, 60 of them planned by D02/D04; 33 out; 5 new) |
| Lines of the area | 57,624 | 59,047 |
| Exposed files | 237 | 63 |
| **Exposed lines** | **18,785 (32.6%)** | **1,425 (2.4%)** |
| References against the order with an Integrations end | 151 (105 in from lower areas, 46 out) | 44 (0 in, 44 out: wave 4) |
| Exposure the design adds to lower areas (their own ports, rows out) | – | about 114 lines (section 4) |

Integrations exposes a third of its code today; after this design 2.4%, under the 2.75% the whole app needs for 4.5.
Measured on the whole app the changes of this design alone take about 17,300 lines out of exposure (150,938 →
133,639 on the modelled graph after the D02/D04 rows).

Four facts shape the design:

1. **Almost two thirds of the exposure is one file.** `lib/Service/ContractBindings.php` (Configuration) binds 55
   Integrations contracts to their Integrations services and is the only outside file that names 76 Integrations
   files (10,556 lines). With the other registrars that bind or construct Integrations classes (Core
   `ServiceRegistrar` and `MapperWiring`, Processes `ProcessesMapperBindings` and `ListenerRegistrar`, Platform
   `Application`) that is 89 files and 11,587 lines: rule 5, Integrations binds its own classes in
   `IntegrationWiring` and `IntegrationsMapperBindings`.
2. **Every inward reference comes from below.** Only Processes, Configuration and Platform may name Integrations.
   The 105 references from Kernel, Core, Notifications, Search, Compliance and Files are all against the order. They
   go by rows (the class does Integrations' work, or Integrations' file does the lower area's work), by
   lower-area-owned ports Integrations implements (rule 2), by Kernel value objects (rule 4) and by contributed
   declaration checks (#10685). None of them becomes an Integrations port.
3. **Integrations owns a lot of other areas' entity plumbing.** 33 files (1,102 lines) of column-type helpers,
   value objects and flow-schedule compilation do Processes', Configuration's, Compliance's and Core's work; the
   `Db/<Entity>/` subdirectory rows put them in Integrations. They go by csv row.
4. **The real contracts are few.** Outside callers that remain need eight capabilities from Integrations that have
   no port yet. Four become new ports in `lib/Kernel/Contracts/Integrations/` (44 lines); the other four already are
   small one-method interfaces and stay.

## 1. The exposed files today

237 Integrations files (18,785 lines) are named by another area. Every one, with lines, outside callers per area
and its treatment, is in Appendix A. Grouped by why the file is exposed:

| Why exposed | Files | Lines | Share |
|---|---|---|---|
| W: named only by another area's binding map or registrar | 89 | 11,587 | 61.7% |
| P: named by callers that need one capability (port, Kernel value, existing interface, contributed check) | 35 | 3,504 | 18.7% |
| R: the file does another area's work, or its callers do Integrations' work (csv row) | 47 | 1,934 | 10.3% |
| O: exposed only through Core or Notifications files those designs already move to Integrations | 14 | 507 | 2.7% |
| K: kept (registrars, plug-in points, base classes, values on port signatures, link toolkit, entities Processes persists) | 52 | 1,253 | 6.7% |

Outside callers per area (a file counts once per area that names it):

| Calling area | Integrations files it names | Their lines | Allowed? |
|---|---|---|---|
| Configuration | 129 | 14,322 | yes (almost all through `ContractBindings`) |
| Processes | 61 | 3,600 | yes |
| Core | 52 | 3,551 | **no**: against the order |
| Platform | 30 | 1,063 | yes |
| Compliance | 14 | 414 | **no** |
| Notifications | 8 | 224 | **no** |
| Search | 4 | 164 | **no** |
| Kernel | 4 | 129 | **no** |
| Files | 1 | 62 | **no** |

### W: wiring (89 files, 11,587 lines)

| Exposed by | Files | Lines | What |
|---|---|---|---|
| Configuration `Service/ContractBindings` | 76 | 10,556 | the 55 bindings whose implementation is Integrations': the 14 link services and their interfaces (`AnalyticsLink`, `BookmarkLink`, `CalendarLink`, `CollectiveLink`, `CospendLink`, `DeckLink`, `EmailLink`, `FormLink`, `MapLink`, `OpenProjectLink`, `PhotoLink`, `PollLink`, `ShareLink`, `TalkLink`, `TimeTrackerLink`, `XwikiLink`), `ContactService`, `DeckCardService`, `PersonLinkService`, `LinkedEntityService`, `ObjectRelationCleanupService`, the OAuth2 and Doriath credential family (19 files), `AuthenticationService`, `HarvestPipelineService`, `SyncScheduleService`, `SourceFetcherRegistry`, `ExternalIntegrationRouter`, `BrpPersonProvider`, `EndpointService`, `FederationShareService`, `CaseTokenService`, `ActivityFilterService`, `AnalyticsSeriesService`, `ProxySettings`, `SecurityTxtBuilder`, `DeepLinkRegistryService`, the handoff service and validators; and the five `Service/Bindings/*` maps (67 lines) |
| Core `Service/ServiceRegistrar` | 2 | 266 | `CredentialStoreResolver`, `RestApiSourceFetcher` (it also names `CredentialStore`, `EmailLinkService`, `SourceFetcherRegistry`) |
| Core `Db/MapperWiring` | 1 | 197 | `WebhookMapper`, constructed with `SecretAtRest` |
| Processes `Db/ProcessesMapperBindings` | 5 | 126 | `CaseTokenMapper`, `FormLinkMapper`, `HandoffQueueEntryMapper` and two of their interfaces |
| Processes `Listener/ListenerRegistrar` | 2 | 269 | `ContextChatSubmissionListener`, `McpListenerRegistrar` |
| Platform `AppInfo/Application` | 3 | 173 | `RegisterCalendarProvider`, `OpenRegisterCloudFederationProvider`, `OutboundClientFactory` (registered inline instead of by `IntegrationRegistrar`) |

### P: callers that need one capability (35 files, 3,504 lines)

| Files | Lines | Outside callers |
|---|---|---|
| `Service/Credential/CredentialBrokerService`, `OAuth2CredentialObjects`, `CredentialStore` | 671+49+8 | Configuration: `Exchange/BrokeredKeys`, `Exchange/OneUseJobKey`, `Config/FederatedConfigService`, `Config/FederatedConfigTransport`; Core: `ServiceRegistrar`, `ObjectSourceWiring` (`CredentialStore` only) |
| `Db/Mapping`, `Db/MappingMapper`, `Db/MappingMapperInterface`, `Service/MappingService` | 66+175+15+372 | Configuration: `Configuration/ExportHandler`, `ImportHandler`, `ConfigurationImportRecords`, `ConfigurationServiceRegistrar`; Processes: `Flow/Nodes/MapNode`; Core: `ObjectSource/OpenConnectorHttpProvider`, `ObjectSourceWiring` |
| `Db/Webhook`, `Db/WebhookMapperInterface`, `Db/WebhookLogMapperInterface` | 188+18+15 | Configuration: `Notification/NotificationsAnnotationInstaller`; Compliance: `BackgroundJob/Support/WebhookDeliveryStores`; Search: `Search/Service/DashboardMappers` |
| `Db/DeckLink`, `TalkLink`, `CalendarLink` and their mapper interfaces | 50+15+78+11+78+13 | Configuration: `ExampleSet/ExampleSetDeckCards`, `ExampleSetTalkConversations`, `ExampleSetAppointments`, `BackgroundJob/BackfillCalendarLinksJob`; Compliance: `BackfillCalendarLinksSources` |
| `Mcp/Db/Agent`, `Mcp/Db/Conversation` | 132+48 | Platform: `AgentsController`, `ChatController`, `ChatStreamController`, `ConversationController`, `FeedbackMapper`; Configuration: `Mcp/McpAgentScope`; Search: `Search/Db/View` (unused import); Core: `ChatStreamTurn`; Notifications: `FeedbackMapperInterface`, `Message*` |
| `Db/Source` | 62 | Core: `SourcesController`, `DbalIntrospectionJob`, `SourceMapperInterface`, `DbalObjectSourceProvider`; Files: `Files/Db/ChunkMapper` (unused import) |
| `Service/DeepLinkRegistryService` | 205 | Core: `ObjectCacheInvalidator`, `Search/ObjectSearchResultFormatter`, `LinkedEntityIntegrations`; Configuration: `Reference/ObjectPreviewFormatter`, `ObjectPreviewSources` (also W) |
| `Service/Handoff/HandoffService` | 585 | Processes: `Flow/Nodes/LinkObjectNode` (the concrete class; `HandoffServiceInterface` exists) |
| `Service/Handoff/HandoffAnnotationValidator(Interface)`, `HandoffContractBindingValidator(Interface)`, `Mcp/Service/Mcp/McpAnnotationValidators` | 156+6+133+6+8 | Core: `Schemas/SchemaDeclarationValidators`, `Db/SchemaMapper/ExchangeAnnotationRefusals` |
| `Db/Consumer`, `Db/ConsumerMapperInterface` | 92+18 | Compliance: `Audit/TokenResolver` |
| `Service/Connection/ConnectionReporter` | 179 | Compliance: `Anonymisation/AnonymisationBackendService`, `AnonymisationBackendCollaborators` |
| `Db/ApiCallRecordMapperInterface` | 11 | Core: `Schema/SchemaChangeNoticeService`; Platform: `ApiCaller/ApiCallRecorder` |
| `Twig/MappingTwigInstallerInterface` | 7 | Core: `SaveObject/ComputedFieldHandler` |
| `Service/Integration/PageWidgetRegistryInterface`, `Service/AnalyticsSeriesServiceInterface` | 8+9 | Search: `MapsOverviewService`, `MapsOverviewSources` (`AnalyticsSeriesServiceInterface` only in a docblock) |
| `Service/Integration/Providers/MessageDispatcherInterface`, `OutboundMessage` | 6+11 | Notifications: `Notification/ScheduledMessageSweep` |

### R: rows (47 files, 1,934 lines)

| Files | Lines | Outside callers | Whose work |
|---|---|---|---|
| `Db/Flow/*` (5), `Db/FlowTimer/*` (2), `Db/FlowVersion/*`, `Db/BulkJob/*` (2), `Db/CaseItem/*` | 329 | Processes entities, mappers and flow services | column types and JSON helpers of Processes entities (`Flow`, `FlowTimer`, `FlowVersion`, `BulkJob`, `CaseItem`) |
| `Db/Application/*` (2), `Db/ExportRun/*`, `Db/TaskSequence/*` | 116 | Configuration entities | the same for Configuration entities |
| `Db/ContentReport/*`; `Db/ObjectRelation/*` | 24; 28 | Compliance `ContentReport`; Core `ObjectRelation` | the same for a Compliance and a Core entity |
| `AppHost/Scheduling/ScheduleActionNodes`, `ScheduleRefusals`, `ScheduleRelease`, `ScheduleReleaseReader`, `ScheduleRefusalReportInterface` (+ `ApplicationRegister`, internal) | 209 | Processes: `ScheduleFlowSync`, `CompiledScheduleFlow`, `ApplicationPublishListener`; Platform: `ScheduleRefusalMiddleware` | compiling a published app's schedules into flows: every other class of that work (`ScheduleFlowSync`, `ScheduleFlowStore`, `CompiledScheduleFlow`, `ScheduleAuditTrail`) is Processes. The manifest values (`ScheduleManifest`, `ScheduleDescriptor`, `CronScheduleEvaluator`, the loader) stay in AppHost |
| `Service/CalendarEvent/VeventCodec`, `UserCalendarSelector`, `VeventDraft`; `Service/ContactMatchingMappers` | 299 | Processes: `CalendarEventService`; `ContactMatchingService` | their only user is that Processes service |
| `Service/TableDeletionOptions`; `Service/TableObjectRef` | 10; 11 | Core `ObjectService`, Compliance `SchemaDeletionService`; Core `LogObjectInTable` (Compliance after D02), Compliance `LogService` | no Integrations class uses them |
| `Mcp/Service/Chat/StreamYieldChannels` (+ `StreamYieldChannel`) | 9 | Platform: `ChatStreamController` | only Platform's stream controller uses them |
| `Service/Connection/ConnectionReportForbiddenException` | 9 | Configuration `ScheduledReportService`, Notifications `ScheduledReportsController` | a scheduled-report refusal; scheduled reports are Notifications' (D04) |
| `Service/WebhookService` | 637 | Processes: `Listener/WebhookEventListener` | the listener only forwards Kernel events to `WebhookService`: it is Integrations' |
| `Service/Integration/LeafDescriptor`, `LeafBundles` | 104 | Kernel `RegisterLeafProvidersEvent`; Processes `LeafScriptListener` | the event is dispatched and listened to by Integrations only, the listener injects leaf bundles using only Integrations, Kernel and Core |
| `Mcp/Tool/ToolInterface` | 9 | Kernel `ToolRegistrationEvent` | same: dispatched and listened to only by Integrations' MCP classes |
| `Service/Sync/HarvestRun`, `SourceFetcherInterface`, `HarvestPipelineServiceInterface`, `SourceFetcherLookupInterface`, `SyncScheduleInterface` | 41 | Core `SourcesController`, `SourcesDomain`; Compliance `SyncDataServices` | the Sources controller and its bags manage the Integrations `Source` entity and use only Integrations and Kernel; `SyncDataServices` is the constructor bag of Integrations' `SyncDataJob` |
| `Service/Credential/OAuth2CredentialSweepInterface`, `ProviderCatalogueInterface` | 14 | Compliance `OAuth2TokenRefreshServices` | the bag of Integrations' `OAuth2TokenRefreshJob` |
| `BackgroundJob/CalendarLinkBackfillRun` | 7 | Configuration `BackfillCalendarLinksJob` | the job (and its two Compliance bags) uses only Integrations calendar links and a Kernel port |
| `Db/ApiCallKey`, `ApiCallKeys` | 23 | Platform `ApiCaller/ApiCallRecorder` | the recorder (and `ApiCallRecorderInterface`, `ApiCallObservation`) writes Integrations' API-caller records and uses nothing else |
| `Db/FederatedShare` | 40 | Core `Db/FederatedShareMapperInterface` | the interface's implementation and its eight users are all Integrations |
| `Service/Integration/AbstractIntegrationProvider`, `UpstreamRequest` | 57 | Core `ObjectSource/ConfiguredSourceUpstream`, `OpenConnectorHttpProvider` | the OpenConnector object-source provider calls only Integrations' router and mappings |

### O: handled by the Core and Notifications designs (14 files, 507 lines)

The link entities and drafts named by Core's `…LinkServiceInterface` files (`MapLink`, `OpenProjectLink`, `PhotoLink`,
`PollLink`, `TimeTrackerLink`, `PoiDraft`, `PollDraft`, `ShareDraft`, `WorkPackageDraft`, `EntityLink`,
`PersonLinkChange`, `UserLinkWriter`) and `EmailServiceInterface`, `ActivityEntriesOptions`. D02 (§3.3, 59 files to
Integrations) and D04 (§3.3) move the interfaces and bags that name them to Integrations; they then have only
Integrations callers. Nothing further here.

### K: kept (52 files, 1,253 lines)

Listed with the reason in section 4.

## 2. What outside callers actually need

The methods and constants called, not the class surface. "Lower" marks callers below Integrations, which can only be
served by a port they own.

| # | Capability | Called today | Callers |
|---|---|---|---|
| C1 | Custody of brokered credentials | `CredentialBrokerService::mintCredential`, `::resolveInjectable`, `::request`, `::REGISTER`, `::SCHEMA`; `OAuth2CredentialObjects::find` (the `provider` of a credential), `::delete`; `CredentialStore::delete` | Configuration exchange export keys, federated configuration transport |
| C2 | Run, export and import a mapping | `MappingMapper::find`, `::createFromArray`, `::updateFromArray`, `::getSlugToIdMap`; `Mapping::getId`, `::getVersion`, serialisation; `MappingService::executeMapping` | Configuration export/import, Processes `MapNode` |
| C3 | Keep webhook subscriptions for annotations | `WebhookMapper::findAll`, `::createFromArray`, `::updateFromArray` | Configuration `NotificationsAnnotationInstaller` |
| C4 | Seed object links from an example set | the four link mappers resolved by class string, `insert` of a new link, `findByObjectAndEvent`, `EmailLink::DETAIL_KEYS` | Configuration example-set row kinds |
| C5 | App manifests, schedules and documents | `ScheduleManifestLoaderInterface::load`, `ScheduleManifest`, `ScheduleDescriptor`, `CronScheduleEvaluator`; `RegisterDocumentLoaderInterface` | Processes schedule sync and repair, Configuration app-register import |
| C6 | The integration catalogue and leaves for capabilities and admin settings | `IntegrationCatalogueInterface`, `IntegrationProvider`, `IntegrationAdminView`, `LeafRegistryInterface`, `ExternalIntegrationRouterInterface::…` | Platform capabilities and admin settings |
| C7 | Calendar events of objects | `CalendarLinkServiceInterface`, `CalendarEventKey`, `CalendarObjectRef`, `CalendarPickerWindow`, `CalendarObjectAddress`, `LinkTarget` | Processes calendar and task-event controllers, `CalendarEventService`, `FlowLinkService` |
| C8 | Hand an object over, look up a person, MCP tools, connection health, tool reach | `HandoffServiceInterface`, `BrpPersonProviderInterface`, `ToolRegistryInterface`, `McpToolCatalogueInterface`, `ConnectionReporterInterface`, `ToolReachLevels`, `TranslationBindingInterface` | Processes, Platform, Configuration |
| L1 | Lower: who called a schema's API (Core) | `ApiCallRecorder::isEnabled`, `ApiCallRecordMapper::countCallersOfRoutes`, `::findCallersOfRoutes` | Core `SchemaChangeNoticeService` |
| L2 | Lower: mapping functions in computed fields (Core) | `MappingTwigInstallerInterface::installOn` | Core `ComputedFieldHandler` |
| L3 | Lower: a connected database source (Core) | `SourceMapperInterface::findForSystem`, `::findList`; `Source::config`, `getAuthConfig`, `getType`, `table`, `getId`, `getUuid` | Core `DbalObjectSourceProvider`, `DbalIntrospectionJob` |
| L4 | Lower: deep links (Core, and Configuration) | `DeepLinkRegistryService::resolveUrl`, `::resolveIcon`, `::resolveDisplayName`, `::requestRegistrations` | Core search formatter, cache invalidator; Configuration previews; Platform boot |
| L5 | Lower: consumers of a token owner (Compliance) | `ConsumerMapper::findAll(['user_id' => …])`, `Consumer::getUuid`, `getName` | Compliance `TokenResolver` |
| L6 | Lower: report a connection's health (Compliance) | `ConnectionReporter::report` | Compliance anonymisation backend |
| L7 | Lower: webhook delivery totals, page widgets (Search) | `WebhookLogMapper::getStatistics(0)`; `PageWidgetRegistry::registerPageWidget`, `::getPageWidget` | Search dashboard and maps overview |
| L8 | Lower: parties of an object, dispatch a scheduled message (Notifications) | `ContactLinkMapper::findPartiesForObject`; `MessageDispatcher::dispatch(string, OutboundMessage)` | Notifications party notices and scheduled-message sweep |
| L9 | Lower: declaration checks (Core) | `HandoffAnnotationValidator`, `HandoffContractBindingValidator`, `McpAnnotationValidators::validator()` | Core `SchemaDeclarationValidators`, `ExchangeAnnotationRefusals` |

Everything else exposed today is wiring, a class doing another area's work, or a Kernel value away from internal.

## 3. The proposed contract set

### 3.1 New ports in `lib/Kernel/Contracts/Integrations/`

Each port is one interface, signatures on one line, importing only what it uses. Line counts are estimates in the
scorer's counting (methods plus about six lines), checked against the existing one-line interfaces of this area.

| Port | Methods and constants | Implemented by | Lines |
|---|---|---|---|
| `CredentialBrokerInterface` (C1) | `REGISTER`, `SCHEMA` (literals equal to today's, asserted by a unit test); `request(string $credentialId, string $appId, string $method, string $path, array $headers = [], ?string $body = null, ?string $actingUserId = null): array`; `resolveInjectable(string $credentialId, string $appId, ?string $actingUserId = null, ?string $actingOrganisationId = null): ?string`; `mintCredential(CredentialMint $mint, CredentialMintOptions $options): ObjectEntity`; `providerOf(string $credentialId): ?string`; `forget(string $credentialId, string $scope): void` | `CredentialBrokerService`; `providerOf` and `forget` hold what `BrokeredKeys::assertProvider()` and `::delete()` do today through `OAuth2CredentialObjects` and `CredentialStore` | 16 |
| `MappingsInterface` (C2) | `run(int\|string $mapping, array $input, bool $list = false): array`; `export(int\|string $mapping): array`; `import(array $mapping, ?int $existingId = null): array`; `slugToIdMap(): array` | new `Service/Mappings` over `MappingMapper` and `MappingService`; `import` returns `['id' => …, 'version' => …]`, the two getters `ImportHandler` reads | 10 |
| `WebhookSubscriptionsInterface` (C3) | `subscriptions(): array`; `subscribe(array $definition): int`; `resubscribe(int $webhookId, array $definition): void` | new `Service/WebhookSubscriptions` over `WebhookMapper` | 9 |
| `ObjectLinkSeedsInterface` (C4) | `KINDS`; `seed(string $kind, array $fields): string`; `isSeeded(string $kind, string $linkId): bool`; `unseed(string $kind, string $linkId): bool` | new `Service/ObjectLinkSeeds` over the email, deck, Talk and calendar link mappers (the class-string lookups of the four row kinds move into it) | 9 |

The existing small interfaces that already are one capability stay where they are and are the contract for C5-C8:
`ScheduleManifestLoaderInterface` (6), `RegisterDocumentLoaderInterface` (6), `IntegrationCatalogueInterface` (9),
`LeafRegistryInterface` (7), `ExternalIntegrationRouterInterface` (10), `CalendarLinkServiceInterface` (13),
`HandoffServiceInterface` (19), `BrpPersonProviderInterface` (8), `ToolRegistryInterface` (9),
`McpToolCatalogueInterface` (6), `ConnectionReporterInterface` (8), `ToolReachLevels` (16),
`TranslationBindingInterface` (6), `ApiCallRecorderInterface` (7, after its row). Moving them into
`lib/Kernel/Contracts/Integrations/` would rename them without lowering exposure; not proposed.

### 3.2 Ports Integrations implements for lower areas

A lower area may not name an Integrations port. Where it needs Integrations' work it owns the port in
`lib/Kernel/Contracts/<LowerArea>/`, Integrations implements it and binds it in `IntegrationWiring` (rule 2, as with
the presence and action-rights ports #10675, #10688). The lines count to the lower area.

| Owner | Port | Methods | Implemented by | Replaces | Lines |
|---|---|---|---|---|---|
| Core | `ApiCallerAudienceInterface` (L1) | `isRecording(): bool`; `countCallersOfRoutes(array $prefixes, DateTime $from): int`; `findCallersOfRoutes(array $prefixes, DateTime $from, int $limit = 500): array` | new `Service/ApiCaller/ApiCallerAudience` over `ApiCallRecorder` and `ApiCallRecordMapper` | `SchemaChangeNoticeService` on `ApiCallRecordMapperInterface` and `ApiCallRecorder` (Platform today: also a Core-to-Platform reference) | 8 |
| Core | `TwigExtensionsInterface` (L2) | `installOn(Environment $twig): void` | `MappingTwigInstaller` (`MappingTwigInstallerInterface` extends it) | `ComputedFieldHandler` on `MappingTwigInstallerInterface` | 6 |
| Core | `ConnectedSourcesInterface` (L3) | `forSystem(string $sourceId): ?SourceConnection`; `connectedSources(ListQuery $query): array` | new `Db/ConnectedSources` over `SourceMapper`; `SourceConnection` is a new Core value (type, config, auth config, table, id, uuid) | `DbalObjectSourceProvider` and `DbalIntrospectionJob` on `Source` and `SourceMapperInterface`; `SourceMapperInterface` keeps its signatures (buildiq uses it) and counts to Integrations (rule 3: implementation and all users are then Integrations') | about 8 + 12 |
| Core | `DeepLinkResolverInterface` (L4, exists) | gains `resolveDisplayName(int $registerId, int $schemaId): ?string` (a widening) | `DeepLinkRegistryService` | the concrete class in `ObjectCacheInvalidator` (container key), `ObjectSearchResultFormatter`, Configuration's `ObjectPreviewFormatter` and `ObjectPreviewSources`; Platform `Application::boot()` asks `DeepLinkRegistryServiceInterface` | +1 |
| Compliance | `TokenConsumersInterface` (L5) | `consumersOf(string $ownerUid): array` (list of the existing `NamedIdentifier`) | new `Service/Consumer/TokenConsumers` over `ConsumerMapper` | `TokenResolver` on `Consumer` and `ConsumerMapperInterface` | 6 |
| Compliance | `ConnectionHealthInterface` (L6) | `report(string $key, string $status, string $message = ''): bool` | `ConnectionReporter` (`ConnectionReporterInterface` extends it) | the anonymisation backend on the concrete `ConnectionReporter` | 6 |
| Search | `WebhookDeliveryStatsInterface` (L7) | `deliveryTotals(): array` | new `Service/WebhookDeliveryStats` over `WebhookLogMapper::getStatistics(0)` | `DashboardMappers` on `WebhookLogMapperInterface` | 6 |
| Search | `PageWidgetsInterface` (L7) | `registerPageWidget(array $descriptor): bool`; `getPageWidget(string $id): ?array` | `IntegrationRegistry` (`PageWidgetRegistryInterface` extends it) | `MapsOverviewService` and `MapsOverviewSources` on `PageWidgetRegistryInterface`; the `AnalyticsSeriesServiceInterface` import is docblock-only and goes | 8 |
| Notifications | `ObjectPartiesInterface` (L8) | `partiesFor(string $objectUuid): array` (list of Kernel `ContactLinkInterface`) | new `Service/ObjectParties` over `ContactLinkMapper` | `PartyNotificationService` on `ContactLink` and `ContactLinkMapperInterface` | 6 |
| Notifications | `ScheduledMessageDispatchInterface` (L8) | `dispatch(string $source, OutboundMessage $message): array` | `MessageDispatchProvider` (`MessageDispatcherInterface` extends it) | `ScheduledMessageSweep` on `MessageDispatcherInterface`; `OutboundMessage` counts to Notifications (its only openregister constructor is the sweep; the Integrations interface may name it) | 6 |
| Core | `SchemaDeclarationCheckInterface` (L9, exists) | `position()`, `check()` | new `Service/Handoff/HandoffDeclarationCheck` and `Mcp/McpAnnotationDeclarationCheck`, contributed through `SchemaDeclarationChecksEvent` in the slots they run in today (#10685) | the handoff validators in `SchemaDeclarationValidators` and the MCP validator in `ExchangeAnnotationRefusals` | 0 new |

L8 is listed by the Notifications design as wave 4 work ("Against the order, outward"); it is included here so the
Integrations budget is complete, and is built by whichever package comes first.

### 3.3 Kernel value objects instead of Integrations entities (rule 4)

| Entity | Crosses to | Replacement |
|---|---|---|
| `Mcp/Db/Agent` | Platform `AgentsController`, Configuration `McpAgentScope` | Kernel `AgentInterface` (the callers already use Kernel `AgentMapperInterface`) |
| `Mcp/Db/Conversation` | Platform chat controllers, `FeedbackMapper(Interface)`, `ChatStreamTurn` (Platform after D02/D04) | Kernel `ConversationInterface` |
| `Db/Source` | Core | Core `SourceConnection` behind `ConnectedSourcesInterface` (3.2); `Files/Db/ChunkMapper` imports `Source` without using it |
| `Db/ContactLink` | Notifications `PartyNotificationService` | Kernel `ContactLinkInterface` behind `ObjectPartiesInterface` |

`Search/Db/View` imports `Agent` without using it (the Search design removes the import).

### 3.4 Rows and wiring

None of these files moves; the csv row follows the work. Rows out of Integrations are listed in section 1 (R).
Rows into Integrations:

| Files | Today | Why it is Integrations' work |
|---|---|---|
| `BackgroundJob/Support/SyncDataServices`, `OAuth2TokenRefreshServices`, `WebhookDeliveryStores`, `BackfillCalendarLinksSources`, `BackfillCalendarLinksPlatform` | Compliance | constructor bags whose only user is an Integrations job (the decided rule for support bags) |
| `BackgroundJob/BackfillCalendarLinksJob` | Configuration | backfills calendar links; uses only Integrations' link mapper and the Kernel calendar-events port |
| `Service/Mcp/McpSessionCaller`, `Service/Mcp/ToolCall` | Configuration | values of the MCP server controller; removes two Integrations-to-Configuration references (`McpAgentScope` stays Configuration: it reads Configuration's tool grants) |
| `Listener/WebhookEventListener`, `Listener/LeafScriptListener`, `Listener/HandoffQueueDrainListener` | Processes | they forward Kernel events to `WebhookService`, inject leaf bundles, drain the handoff queue: Integrations classes only. `HandoffLifecycleListener` stays Processes (it uses Processes' `EventSchemaLookup`) |
| `Service/ApiCaller/ApiCallRecorder`, `ApiCallRecorderInterface`, `ApiCallObservation` | Platform | record API callers in Integrations' `ApiCallRecord` table; Platform's middleware keeps calling the interface (allowed) |
| `Controller/SourcesController`, `Controller/Support/SourcesControllerServices`, `SourcesDomain`, `SourcesMappers`, `SourcesPlatform`; `Db/SourceMapperInterface` | Core | manage and harvest the Integrations `Source` entity |
| `Service/ObjectSource/OpenConnectorHttpProvider`, `ConfiguredSourceUpstream` | Core | the OpenConnector-backed object source: uses only Integrations' router, mappings and `AbstractIntegrationProvider` |
| `Db/FederatedShareMapperInterface` | Core | implemented by Integrations' `FederatedShareMapper`, used only by Integrations (D02 lists its callers under `ObjectGrantsInterface`; the interface itself is Integrations') |
| `Event/ToolRegistrationEvent`, `Event/RegisterLeafProvidersEvent` | Kernel | dispatched and listened to only by Integrations; names and namespaces unchanged (fleet apps keep listening), Kernel shrinks by 33 lines |
| `Service/Connection/ConnectionReportForbiddenException` | Integrations → Notifications | (out) scheduled-report refusal |
| `Service/Integration/Providers/OutboundMessage` | Integrations → Notifications | (out) see 3.2 L8 |

Wiring (rule 5, one hidden map per Nextcloud directory: `IntegrationWiring` for `lib/Service`,
`IntegrationsMapperBindings` for `lib/Db`):

- `Service/ContractBindings` (Configuration) stops binding the 55 contracts whose implementation is Integrations'
  (all keys and values kept, in the same order, now in `IntegrationWiring::BINDINGS`). The two bindings whose
  implementation is Configuration's (`ToolReachLevels`, `TranslationBindingInterface`) stay in `ContractBindings`.
  Aliases have no order dependency between maps; a duplicate key across maps is refused by a unit test.
- `Service/Bindings/*` (five maps, Integrations by a directory row, used only by `ContractBindings`) are emptied:
  Core entries go to the Core map (D02 C5-01), `ConfigurationDraftsInterface` to Configuration's, `OAuth2InstanceHost`
  and `CompanySearchFactory` to `IntegrationWiring`. The empty files are removed (no fleet app names them).
- Core `ServiceRegistrar` stops registering `CredentialStore`, `CredentialStoreResolver`, `EmailLinkService`,
  `RestApiSourceFetcher`, `SourceFetcherRegistry`; Core `MapperWiring` stops constructing `WebhookMapper`;
  Processes `ProcessesMapperBindings` stops binding `CaseTokenMapper`, `FormLinkMapper`, `HandoffQueueEntryMapper`;
  Configuration `ConfigurationServiceRegistrar` stops naming `MappingMapper`; `ObjectSourceWiring` resolves the DBAL
  connection factory's store as Core's `CredentialSecretReaderInterface` (which every store extends) and stops
  listing `OpenConnectorHttpProvider`. Integrations registers each in its own map, with the same factory code.
- `OpenConnectorHttpProvider` is added to the object-source registry by Integrations. The registry is keyed by
  provider id (first registration wins, ids are distinct), so the build first pins `ObjectSourceRegistry::providers()`
  order with a golden test; if any reader depends on insertion order, Core dispatches a small collection event at the
  end of `ObjectSourceWiring::boot()` instead (about 10 lines in Core) so the provider stays last.
- `Listener/ListenerRegistrar` and `Listener/Registrar/FlowsListenerRegistrar` (Processes) stop naming the
  Integrations listeners. Each block they register today becomes a public static slot method of a new
  `Listener/Registrar/IntegrationsListenerRegistrar` (about 25 lines), called at exactly the position the block has
  today, so `listener-order.json` and `ListenerRegistrationOrderGoldenTest` do not change.
- `AppInfo/Application` registers the calendar provider, the outbound client factory and the cloud-federation
  provider through `IntegrationRegistrar` (`register()` for the first two, a new `bootFederation()` called where
  `Application::bootFederation()` runs today), and asks `DeepLinkRegistryServiceInterface` for the registry.
  `Capabilities/PlatformIntegrations` reads `IntegrationRegistrar::CALENDAR_PROVIDER` and `::FEDERATION_PROVIDER`
  (the same class strings, as the Search design does with `SearchProviderRegistrar::PROVIDER`).
- `AppHost/AppHostWiring` stops binding `ScheduleRefusals` / `ScheduleRefusalReportInterface`; Processes'
  `FlowWiring` binds them with their rows.

## 4. The budget

| Capability / group | Before | After | How |
|---|---|---|---|
| Wiring and registrars | 11,670 | 108 | the seven registrars `Application` and the listener registrars call (`AppHostRegistrar` 10, `FeatureToggleRegistrar` 9, `ObservabilityRegistrar` 9, `ContextChatRegistrar` 17, `IntegrationsMapperRegistrar` 10, `McpRegistrar` 12, `IntegrationRegistrar` 16) and the new `IntegrationsListenerRegistrar` (25) |
| C1 Credential broker | 772 | 60 | `CredentialBrokerInterface` 16 + `CredentialMint` 12 and `CredentialMintOptions` 32 on its signature |
| C2 Mappings | 628 | 10 | `MappingsInterface` |
| C3 Webhook subscriptions | 206 | 9 | `WebhookSubscriptionsInterface`; Compliance bag by row |
| C4 Example-set links | 252 | 9 | `ObjectLinkSeedsInterface`; backfill job and bags by row |
| C5 Manifests and schedules | 375 | 166 | the flow-compiling half to Processes by row; the manifest values (`ScheduleManifest` 52, `ScheduleDescriptor` 92, `CronScheduleEvaluator` 10) and two loaders (12) stay |
| C6 Catalogue for Platform | 113 | 70 | `IntegrationCatalogueInterface`, `IntegrationProvider` and `IntegrationAdminView` (plug-in point fleet apps implement), `LeafRegistryInterface`, `ExternalIntegrationRouterInterface`; `AbstractIntegrationProvider` internal once its Core user counts to Integrations |
| C7 Calendar events for Processes | 95 | 95 | unchanged: `CalendarLinkServiceInterface` and five value objects (`LinkTarget` 41) |
| C8 Small one-capability interfaces and values | 140 | 180 | unchanged interfaces (3.1) incl. `ConnectionReportKind` 45 (a scheduled-report kind Configuration runs) and `ApplicationMapperInterface` 23; in by row: `ApiCallRecorderInterface` 7, `ApiCallObservation` 13, `McpSessionCaller` 10, `ToolCall` 10 |
| Lower-area callers (L1-L9, and O) | 2,204 | 0 | lower-owned ports, Kernel values, contributed checks, the D02/D04 rows |
| Rows out (other areas' entity plumbing, schedule compilation, calendar codec, values) | 793 | 0 | csv rows |
| Rows in (callers that do Integrations' work) | 882 | 0 | csv rows of the listeners, bags, jobs, Sources controller, API-caller recorder, Kernel events |
| Plug-in points and base classes | 230 | 230 | `IMetricsProvider` 7 + `MetricSample` 20 (Processes and three fleet apps implement), `GenericInitializeActions` 100 and `GenericActionAuthService` 103 (Platform and fleet apps extend) |
| Processes on Integrations entities and toolkit | 356 | 356 | party links (`ContactLink`, its mapper interface, `PersonLinkRef`, `PersonLinkEvents`: 129), `EmailLink` + interface (72), `FormLink` + interface (44), the three link-mapper traits and the link-actions trait (111) |
| Platform on Integrations entities | 69 | 132 | `SecretAtRest` 55 (Platform repair), `RegisterDescriptorImport` 14 (repair value); plus `Message` 47 and `MessageMapperInterface` 16 that D04 moves to Integrations and Platform's chat still names |
| **Total** | **18,785** | **1,425** | 63 files |

(Groups here follow capabilities, section 1 follows causes, so the two tables split the same 18,785 lines
differently: the registrars, for example, are W in section 1's sense only when another registrar names them.)

Exposure that lands elsewhere, for the owners of those designs:

| Area | Lines | What |
|---|---|---|
| Core | about 45 (+10 if the object-source event is needed) | `ApiCallerAudienceInterface` 8, `TwigExtensionsInterface` 6, `ConnectedSourcesInterface` 8 with `SourceConnection` about 12, `resolveDisplayName` 1, `TableDeletionOptions` 10 (named by Compliance) |
| Compliance | 12 | `TokenConsumersInterface` 6, `ConnectionHealthInterface` 6 |
| Search | 14 | `WebhookDeliveryStatsInterface` 6, `PageWidgetsInterface` 8 |
| Notifications | 32 | `ObjectPartiesInterface` 6, `ScheduledMessageDispatchInterface` 6, `ConnectionReportForbiddenException` 9 (named by Configuration), `OutboundMessage` 11 (named by Integrations) |
| Processes | 22 | `FlowSelection` 16 (named by a Platform repair), `ScheduleRefusalReportInterface` 6 (Platform middleware) |
| Kernel | −33 | the two events leave Kernel; no new Kernel file |

Nothing in this budget fits only by breaking a rule. Places that deserve a reviewer's look:

- **Rows that move a Core file up.** `SourcesController` and its four bags, `OpenConnectorHttpProvider`,
  `ConfiguredSourceUpstream`, `SourceMapperInterface`, `FederatedShareMapperInterface`. Each has, after the change,
  only Integrations users and dependencies (plus Kernel and Core, which are below); none is renamed. They overlap the
  Core design only where D02 listed callers of `FederatedShareMapperInterface` and `SourceMapperInterface` as kept.
- **The two Kernel events.** Moving an event out of Kernel is not the forbidden "into Kernel to hide"; it follows
  who dispatches and listens. Fleet apps listen by class name, which does not change.
- **`ConnectedSourcesInterface` with a new Core value.** `Source` carries the DBAL connection settings the Core
  provider reads; an empty Kernel marker (`SourceInterface`) is not enough. The value is the narrow read Core needs.

## 5. The build packages

Dependency order. File lists are disjoint between these packages; each PR stays at or under about 15 files and adds
or retargets unit tests for every class it changes. "csv" means `scripts/component-map.csv` plus the regenerated
the generated components file and the deptrac areas file; packages that touch it, or `IntegrationWiring`, are
stacked, never parallel. Every PR shows no new entry in `modularity_check.py --check` and `deptrac.sh areas --check`.

### I1: the Integrations service map (3 PRs, stacked)

Moves the 55 bindings of `ContractBindings` whose implementation is Integrations' into `IntegrationWiring::BINDINGS`,
in three slices so each PR carries its own binding tests (26 `tests/Unit/Architecture/*InterfaceBindingsTest` read
`ContractBindings`; each slice retargets the `CLASSES` entries of its classes from `ContractBindings::class` to
`IntegrationWiring::class`). No csv change. A new unit test asserts no contract is bound in two maps.

- **I1a, link services:** the 16 `…LinkService(Interface)` pairs, `ContactService`, `DeckCardService`,
  `PersonLinkService`, `LinkedEntityService`, `ObjectRelationCleanupService`. Files: `lib/Service/ContractBindings.php`,
  `lib/Service/Integration/IntegrationWiring.php`, the binding tests that name them.
- **I1b, credentials:** the OAuth2 and Doriath family, `CredentialAppTokenService`, `ProviderCatalogue`,
  `AuthenticationService`. Same two lib files, their tests.
- **I1c, the rest:** sync, handoff, mapping, endpoint, well-known, proxy, BRP, analytics, activity filter, case token,
  federation share, connection reporter, deep links. Same two lib files, their tests.

Removes about 10,490 exposed lines (the 76 files minus the five `Bindings/*`).

### I2: registrars of other areas (2 PRs, after I1 and after D02 C5-01, which also edits `ServiceRegistrar` and `ConfigurationServiceRegistrar`)

- **I2a, mappers and services:** `lib/Db/ProcessesMapperBindings.php`, `lib/Db/MapperWiring.php`,
  `lib/Db/IntegrationsMapperBindings.php`, `lib/Service/ServiceRegistrar.php`,
  `lib/Service/Configuration/ConfigurationServiceRegistrar.php`, `lib/Service/ObjectSource/ObjectSourceWiring.php`
  (store id only), `lib/Service/Integration/IntegrationWiring.php`, the mapper/registrar tests. A test pins that
  `WebhookMapper` still gets the same `SecretAtRest`.
- **I2b, `Bindings/*` and Platform boot:** `lib/Service/Bindings/*.php` (5, emptied and removed), `ContractBindings.php`,
  `IntegrationWiring.php`, `lib/AppInfo/Application.php`, `lib/Service/Integration/IntegrationRegistrar.php`,
  `lib/Capabilities/PlatformIntegrations.php`, csv (the `Service/Bindings/` row goes), tests.

Removes about 830 lines (`WebhookMapper`, the three mappers, credential store resolver, REST fetcher, the
`Bindings` maps, calendar and federation providers, outbound factory).

### I3: rows (2 PRs, csv, after I2)

- **I3a, out:** the `Db/<Entity>/` helpers (to Processes, Configuration, Compliance, Core), the flow-schedule half of
  `AppHost/Scheduling` with `lib/AppHost/AppHostWiring.php` and `lib/Service/Flow/FlowWiring.php` (their binding moves
  with the row), the calendar codec trio, `ContactMatchingMappers`, `TableDeletionOptions`, `TableObjectRef` (after
  D02's row of `LogObjectInTable`; Core until then), `StreamYieldChannel(s)`, `ConnectionReportForbiddenException`.
  Files: csv, the two wiring files, their tests.
- **I3b, in:** the five Compliance bags and `BackfillCalendarLinksJob`, `McpSessionCaller`, `ToolCall`, the
  API-caller trio (with `lib/Service/PlatformServiceBindings.php` → `IntegrationWiring.php`), `FederatedShareMapperInterface`,
  the two Kernel events. Files: csv, the two wiring files, tests.

Removes about 900 lines and about 30 references against the order.

### I4: listeners (1 PR, after I3)

New `lib/Listener/Registrar/IntegrationsListenerRegistrar.php` (csv row Integrations) with the slot methods;
`lib/Listener/ListenerRegistrar.php`, `lib/Listener/Registrar/FlowsListenerRegistrar.php`; csv rows of
`WebhookEventListener`, `LeafScriptListener`, `HandoffQueueDrainListener`; registrar tests. `listener-order.json` and
the count and hash in `ListenerRegistrationOrderGoldenTest` stay as they are. Removes `ContextChatSubmissionListener`,
`McpListenerRegistrar`, `WebhookService`, `LeafDescriptor`, `LeafBundles` (about 1,000 lines).

### I5: Sources and the OpenConnector object source (1 PR, after I4)

New `lib/Kernel/Contracts/Core/ConnectedSourcesInterface.php`, Core value `lib/Service/ObjectSource/SourceConnection.php`,
new `lib/Db/ConnectedSources.php`; `lib/Service/ObjectSource/DbalObjectSourceProvider.php`,
`lib/BackgroundJob/DbalIntrospectionJob.php`, `lib/Files/Db/ChunkMapper.php` (unused import),
`lib/Service/ObjectSource/ObjectSourceWiring.php`; csv rows of `SourcesController` and its four bags,
`SourceMapperInterface`, `OpenConnectorHttpProvider`, `ConfiguredSourceUpstream`; `IntegrationWiring.php`; tests. A
golden test pins the provider list order of `ObjectSourceRegistry` before the change and the DBAL provider's
connection settings. About 14 files. Removes about 160 lines (`Source`, the sync interfaces,
`AbstractIntegrationProvider`, `UpstreamRequest`).

### I6: the Integrations ports (2 PRs, stacked, after I3)

- **I6a, ports and implementations:** new `lib/Kernel/Contracts/Integrations/CredentialBrokerInterface.php`,
  `MappingsInterface.php`, `WebhookSubscriptionsInterface.php`, `ObjectLinkSeedsInterface.php`; new
  `lib/Service/Mappings.php`, `lib/Service/WebhookSubscriptions.php`, `lib/Service/ObjectLinkSeeds.php`;
  `CredentialBrokerService.php` implements its port; `IntegrationWiring.php` binds; csv rows of the three new
  implementations; tests. Exposes nothing yet.
- **I6b, callers:** `lib/Service/Exchange/BrokeredKeys.php`, `OneUseJobKey.php`, `lib/Service/Config/FederatedConfigService.php`,
  `FederatedConfigTransport.php`, `lib/Service/Configuration/ExportHandler.php`, `ImportHandler.php`,
  `ConfigurationImportRecords.php`, `lib/Service/Flow/Nodes/MapNode.php`,
  `lib/Service/Notification/NotificationsAnnotationInstaller.php`, `lib/Service/ExampleSet/ExampleSetEmailLinks.php`,
  `ExampleSetDeckCards.php`, `ExampleSetTalkConversations.php`, `ExampleSetAppointments.php`, tests. Golden tests pin
  `ImportHandler`'s mapping import and the example-set seed/unseed rows before the change. 13 files plus tests.

Removes about 1,750 lines.

### I7: lower-area ports (2 PRs, after I3)

- **I7a, Core:** new `lib/Kernel/Contracts/Core/ApiCallerAudienceInterface.php`, `TwigExtensionsInterface.php`;
  new `lib/Service/ApiCaller/ApiCallerAudience.php`; `lib/Service/DeepLinkResolverInterface.php` (+1 method);
  `lib/Twig/MappingTwigInstallerInterface.php`; callers `lib/Service/Schema/SchemaChangeNoticeService.php`,
  `lib/Service/Object/SaveObject/ComputedFieldHandler.php`, `lib/Service/Object/ObjectCacheInvalidator.php`,
  `lib/Service/Search/ObjectSearchResultFormatter.php`, `lib/Service/Reference/ObjectPreviewFormatter.php`,
  `ObjectPreviewSources.php`; `IntegrationWiring.php`; tests. About 13 files.
- **I7b, Compliance, Search, Notifications:** new `lib/Kernel/Contracts/Compliance/TokenConsumersInterface.php`,
  `ConnectionHealthInterface.php`, `lib/Kernel/Contracts/Search/WebhookDeliveryStatsInterface.php`,
  `PageWidgetsInterface.php`, `lib/Kernel/Contracts/Notifications/ObjectPartiesInterface.php`,
  `ScheduledMessageDispatchInterface.php`; new `lib/Service/Consumer/TokenConsumers.php`,
  `lib/Service/WebhookDeliveryStats.php`, `lib/Service/ObjectParties.php`; `ConnectionReporterInterface.php`,
  `PageWidgetRegistryInterface.php`, `MessageDispatcherInterface.php` extend their ports; callers
  `lib/Service/Audit/TokenResolver.php`, `lib/Service/Anonymisation/AnonymisationBackendService.php`,
  `AnonymisationBackendCollaborators.php`, `lib/Search/Service/DashboardMappers.php`, `MapsOverviewService.php`,
  `MapsOverviewSources.php`, `lib/Notifications/Service/Party/PartyNotificationService.php`,
  `lib/Notifications/Service/Notification/ScheduledMessageSweep.php`; csv (`OutboundMessage` row, new files); tests.
  Over 15 files: split by owning area (Compliance + Search, then Notifications) if the tests push it over.

Removes about 560 lines and every remaining inward reference except L9 and the Kernel values of I8.

### I8: Kernel values, existing interfaces, declaration checks (1 PR, after I7)

`lib/Controller/AgentsController.php`, `lib/Service/Mcp/McpAgentScope.php` (Agent → Kernel `AgentInterface`);
`lib/Controller/ChatController.php`, `ChatStreamController.php`, `ConversationController.php`, `lib/Db/FeedbackMapper.php`,
`lib/Db/FeedbackMapperInterface.php`, `lib/Controller/Support/ChatStreamTurn.php` (Conversation → Kernel
`ConversationInterface`); `lib/Service/Flow/Nodes/LinkObjectNode.php` (→ `HandoffServiceInterface`); the two
contributed declaration checks with `lib/Service/Schemas/SchemaDeclarationValidators.php` and
`lib/Db/SchemaMapper/ExchangeAnnotationRefusals.php`; `SchemaMapper` tests built with
`ProcessDeclarationChecksDispatcher`. Contributing the checks needs an Integrations listener on
`SchemaDeclarationChecksEvent`: that changes `listener-order.json` and the golden test's count and hash on purpose
(say so in the PR); if the Core design gives contributed checks a non-listener route first, use that. If the PR
exceeds 15 files, the declaration checks go in their own PR. Removes `Agent`, `Conversation`, `HandoffService`, the
validators (about 1,070 lines).

## 6. Fleet impact

`fleet_checkout.sh` on the development branches of the 19 fleet apps (19 Nov 2026), then `fleet_surface.py`. Fleet
references to Integrations classes and to the classes whose rows this design changes:

| Class | Apps | Effect of this design |
|---|---|---|
| `Mcp\IMcpToolProvider` | 9 (buildiq, decidiq, hermiq, launchpad, opencatalogi, portaliq, shillinq, stackiq, versioniq) | unchanged; **candidate for promotion to `lib/Contract`** (the plug-in point for MCP tools) |
| `Mcp\IMcpScannableServices`, `Mcp\Attribute\McpTool`, `McpToolTitle` | 7 | unchanged; promotion candidate with `IMcpToolProvider` |
| `Service\Integration\LeafDescriptor`, `LeafIdentity`, `LeafAvailability`, `LeafRendering` | 8 | unchanged (internal to Integrations after this design); **promotion candidate**: the leaf-registration contract |
| `Service\Integration\IntegrationProvider`, `RefIntegrationProvider`, `AbstractIntegrationProvider`, `IntegrationRegistry` | 4, 2, 1, 3 | unchanged; `IntegrationProvider` is a **promotion candidate** (plug-in point) |
| `AppHost\IMetricsProvider`, `Observability\MetricSample`, `IHealthCheckProvider` | 3, 3, 1 | unchanged; **promotion candidates** (plug-in points) |
| `AppHost\Bootstrap`, `Generic*Controller`, `GenericAdminSettings`, `GenericSettingsSection`, `GenericStoreService`, `StoreDescriptor`, `StoreActionAuthorizer`, `LeafAdoption`, `GenericDeepLinkRegistrationListener`, `Observability\HealthCheckExecutor`, `ManifestLoader`, `MetricsEngine`, `Repair\GenericInitializeActions`, `Service\GenericActionAuthService` | 2-13 each | unchanged: the AppHost base classes fleet apps build on. Publishing them is a separate decision (an app-host SDK), named here, not part of the port set |
| `Service\Credential\CredentialBrokerService`, `CredentialMint`, `CredentialMintOptions`, `CredentialAccessDeniedException`, `CredentialRelinkRequiredException`, `CredentialUpstreamException`, `CredentialStoreResolver`, `ProviderCatalogue`, `CredentialAppTokenService`, `DoriathApplicationRegistrar` | 1-6 | unchanged names; `CredentialBrokerService` implements the new port. **`CredentialBrokerInterface` is a promotion candidate** (six apps call the broker) |
| `Service\WebhookService`, `Db\WebhookMapper` | 2, 1 | unchanged |
| `Db\Mapping`, `Db\MappingMapper`, `Service\MappingService` | 2, 1, 1 | unchanged; `MappingsInterface` is a promotion candidate |
| `Mcp\Db\Agent`, `AgentMapper`, `Conversation`, `ConversationMapper`, `Mcp\Service\ChatService`, `ToolRegistryFacade`, `Mcp\Tool\ToolInterface` | hermiq, opencatalogi | unchanged; they could use the Kernel `AgentInterface` / `ConversationInterface` / `ChatServiceInterface` |
| `Service\Integration\Providers\MessageDispatchProvider`, `OutboundMessage` | pipelinq | unchanged names; `OutboundMessage`'s row moves to Notifications (no rename, no namespace change) |
| `Db\Flow\FlowSelection`, `Db\FlowTimer\TimerSubject` | hermiq, opencatalogi | unchanged names; rows move to Processes |
| `Db\ConsumerMapper`, `Service\Consumer\ConsumerSource`, `ResolvedConsumer`, `Db\FederatedShareMapper`, `Service\FederationShareService`, `CaseTokenService`, `ContactService`, `DeckCardService`, `TalkLinkService`, `CalendarLinkService`, `EmailLinkService`, `EmailMessageRef`, `LinkTarget`, `FormLinkServiceInterface`, `Handoff\HandoffService`, `Providers\BrpPersonProvider`, `KvkProvider`, `AnalyticsSeriesService`, `AnalyticsSeriesDraft`, `AuthorizationService` | 1-2 each | unchanged |
| `Db\SourceMapperInterface` | buildiq | unchanged signatures; row to Integrations |
| `Event\ToolRegistrationEvent`, `Event\RegisterLeafProvidersEvent` | none found | rows only; names kept |

Nothing other apps use is renamed or removed. The promotions are named, not done (ADR-119 Decision 14).

## Findings

- **Against the order, outward.** 44 references run from Integrations to higher areas after this design (46 today).
  They are wave 4 work and not exposure of Integrations, in five themes: built-in integration providers and the
  calendar link service calling Processes' `CalendarEventService`, `TaskService`, `FlowLinkService` and flow-link
  mapper (should use the Kernel `ObjectCalendarEventsInterface`, `ObjectCalendarTasksInterface` and a
  Processes-implemented Integrations port for flow links); the MCP flow tools on Processes' flow services (an
  Integrations-owned tool port Processes implements); AppHost on Configuration's configuration and shareable-type
  services; `DeclaredDateCalendars` on Processes' calendar feeds; outbound HTTP on Platform's breaker registry
  (`BreakerRegistry` is a resilience primitive, a candidate for Core). Plus `McpServerController` on Configuration's
  `McpAgentScope` and `ApplicationMapperInterface` naming Configuration's `Application` (an Integrations interface for
  a Configuration entity: it should take the Kernel `ApplicationInterface`).
- **Flow links straddle two areas.** The flow-link entity, mapper and service are Processes', but they are an
  integration leaf built on Integrations' link toolkit (the four traits, 111 lines exposed) and the flow provider
  names them. Deciding which area owns "object ↔ flow" links removes either the toolkit exposure or the outward
  references, not both; left for the Processes design.
- **Party links are the largest kept entity group (129 lines).** Processes' party services insert, update, merge and
  delete contact links directly. A port would copy `ContactLinkMapperInterface` method for method (forbidden); the
  honest fix is moving party persistence behind one Integrations "party links" service with intent-level methods
  (link, relink on merge, unlink), which needs Processes' party rules pinned first. Not planned in this wave.
- **The AppHost base classes** (`GenericInitializeActions`, `GenericActionAuthService`, 203 lines) are extended by
  Platform and by fleet apps. They are kept; if Platform's two subclasses were the only users they would count to
  Platform, but fleet apps extend them, so they stay with the host.
- **`ContractBindings` is shared by every area.** The Core design moves 362 Core files' bindings, this design 55
  Integrations bindings. The build packages of both touch the same file; they must be stacked (I1 after or before
  C5-01, never in parallel) and each must keep the binding tests green.

## How the numbers were made

1. The module graph and line counts of `modularity_check.measure()` (`php_dependencies`, `code_lines`,
   `component_of_factory` with the generated components file) on `911c2702`. Today's figures equal
   `modularity_check.py`'s output: exposed 155,651 of 459,456 lines, Kernel 10,440, 727 references against the order.
2. Callers' symbols: `grep` over the callers for `Class::member`, `new`, `->method(` on the properties typed as the
   Integrations class, and the container class strings in the registrars.
3. The model applies, in order: the rows D02 (Appendix A, "R row → …") and D04 (§3.3) plan for files that reach this
   area, with D04's move of the webhook and message bindings to Integrations; wiring (registrar and binding-map
   edges to Integrations classes re-pointed to `IntegrationWiring` / `IntegrationsMapperBindings`, the listener
   blocks to `IntegrationsListenerRegistrar`); the rows of 3.4 and section 1 R; every caller of 3.1-3.3 redirected to
   its port or Kernel value, value objects on port signatures kept. Port lines are estimates (methods + about 6).
   `ConnectedSourcesInterface` is modelled as the Kernel `SourceInterface` edge (same effect on Integrations).
4. References against the order are recounted on the modelled graph: no new pair with an Integrations end. The
   inward count goes from 105 to 74 through the D02/D04 rows and from 74 to 0 through this design.

## Appendix A: every Integrations file another area names today

Treatment: W wiring, O handled by the Core/Notifications designs, R row, P port (owner named when not Integrations),
V Kernel value, I existing interface, C contributed declaration check, K kept (reason). Callers: number of outside
files per area (Ker Kernel, Not Notifications, Sea Search, Com Compliance, Fil Files, Pro Processes, Cfg
Configuration, Pla Platform).

| File (`lib/…`) | Lines | Outside callers | Treatment |
|---|---|---|---|
| `AppHost/AppHostRegistrar.php` | 10 | Pla 1 | K registrar |
| `AppHost/FeatureToggleRegistrar.php` | 9 | Pla 1 | K registrar |
| `AppHost/IMetricsProvider.php` | 7 | Pro 1 | K plug-in point |
| `AppHost/Observability/MetricSample.php` | 20 | Pro 1 | K plug-in point |
| `AppHost/ObservabilityRegistrar.php` | 9 | Pla 1 | K registrar |
| `AppHost/Repair/GenericInitializeActions.php` | 100 | Pla 1 | K base class |
| `AppHost/Scheduling/CronScheduleEvaluator.php` | 10 | Pro 1 | K manifest schedule value |
| `AppHost/Scheduling/ScheduleActionNodes.php` | 23 | Pro 1 | R row → Processes |
| `AppHost/Scheduling/ScheduleDescriptor.php` | 92 | Pro 2 | K manifest schedule value |
| `AppHost/Scheduling/ScheduleManifest.php` | 52 | Pro 1 | K manifest schedule value |
| `AppHost/Scheduling/ScheduleManifestLoaderInterface.php` | 6 | Pro 1 | K one-capability interface or value |
| `AppHost/Scheduling/ScheduleRefusalReportInterface.php` | 6 | Pla 1 | R row → Processes |
| `AppHost/Scheduling/ScheduleRefusals.php` | 34 | Pro 1 | R row → Processes |
| `AppHost/Scheduling/ScheduleRelease.php` | 43 | Pro 3 | R row → Processes |
| `AppHost/Scheduling/ScheduleReleaseReader.php` | 103 | Pro 1 | R row → Processes |
| `AppHost/Service/GenericActionAuthService.php` | 103 | Pla 1 | K base class |
| `AppHost/Service/RegisterDocumentLoaderInterface.php` | 6 | Cfg 1 | K one-capability interface or value |
| `BackgroundJob/CalendarLinkBackfillRun.php` | 7 | Cfg 1 | R callers count to Integrations |
| `Calendar/RegisterCalendarProvider.php` | 95 | Pla 2 | W binding/registration moves to Integrations |
| `ContextChat/ContextChatRegistrar.php` | 17 | Pro 1, Pla 1 | K registrar |
| `Controller/Trait/ObjectLinkActionsTrait.php` | 67 | Pro 1 | K link toolkit trait |
| `Db/ApiCallKey.php` | 15 | Pla 1 | R callers count to Integrations |
| `Db/ApiCallKeys.php` | 8 | Pla 1 | R callers count to Integrations |
| `Db/ApiCallRecordMapperInterface.php` | 11 | Core 1, Pla 1 | P Core `ApiCallerAudienceInterface` |
| `Db/Application/ApplicationFieldTypes.php` | 26 | Cfg 1 | R row → Configuration |
| `Db/Application/ApplicationQuota.php` | 41 | Cfg 1 | R row → Configuration |
| `Db/ApplicationMapperInterface.php` | 23 | Cfg 5 | K one-capability interface (wave 4: names `Application`) |
| `Db/BulkJob/BulkJobFieldTypes.php` | 31 | Pro 1 | R row → Processes |
| `Db/BulkJob/BulkJobJson.php` | 21 | Pro 1 | R row → Processes |
| `Db/CalendarLink.php` | 78 | Cfg 2 | P `ObjectLinkSeedsInterface` (backfill job by row) |
| `Db/CalendarLinkMapperInterface.php` | 13 | Com 1, Cfg 2 | P `ObjectLinkSeedsInterface` (backfill job and bag by row) |
| `Db/CaseItem/CaseItemFieldTypes.php` | 45 | Pro 1 | R row → Processes |
| `Db/CaseTokenMapper.php` | 33 | Pro 1 | W binding/registration moves to Integrations |
| `Db/CaseTokenMapperInterface.php` | 12 | Pro 1 | W binding/registration moves to Integrations |
| `Db/Consumer.php` | 92 | Com 1 | P Compliance `TokenConsumersInterface` |
| `Db/ConsumerMapperInterface.php` | 18 | Com 1 | P Compliance `TokenConsumersInterface` |
| `Db/ContactLink.php` | 73 | Core 1, Not 1, Pro 7, Cfg 1 | K party links (Processes writes them) |
| `Db/ContactLinkMapperInterface.php` | 22 | Not 1, Pro 4, Cfg 1 | K party links |
| `Db/ContentReport/ContentReportFieldTypes.php` | 24 | Com 1 | R row → Compliance |
| `Db/DeckLink.php` | 50 | Cfg 1 | P `ObjectLinkSeedsInterface` |
| `Db/DeckLinkMapperInterface.php` | 15 | Cfg 1 | P `ObjectLinkSeedsInterface` |
| `Db/DeleteLinksByObjectUuidTrait.php` | 11 | Pro 1 | K link toolkit trait |
| `Db/EmailLink.php` | 52 | Pro 1, Cfg 1 | K entity Processes reads |
| `Db/EmailLinkMapperInterface.php` | 20 | Pro 2, Cfg 1 | K entity Processes reads |
| `Db/ExportRun/ExportRunFieldTypes.php` | 25 | Cfg 1 | R row → Configuration |
| `Db/FederatedShare.php` | 40 | Core 1 | R callers count to Integrations |
| `Db/FindLinksByObjectUuidTrait.php` | 14 | Pro 1 | K link toolkit trait |
| `Db/Flow/FlowFieldTypes.php` | 40 | Pro 1 | R row → Processes |
| `Db/Flow/FlowJson.php` | 25 | Pro 1 | R row → Processes |
| `Db/Flow/FlowSelection.php` | 16 | Pro 6, Pla 1 | R row → Processes |
| `Db/Flow/FlowTriggerSet.php` | 11 | Pro 3 | R row → Processes |
| `Db/Flow/TriggerLookup.php` | 16 | Pro 3 | R row → Processes |
| `Db/FlowTimer/FlowTimerFieldTypes.php` | 49 | Pro 1 | R row → Processes |
| `Db/FlowTimer/TimerSubject.php` | 10 | Pro 6 | R row → Processes |
| `Db/FlowVersion/FlowVersionFieldTypes.php` | 23 | Pro 1 | R row → Processes |
| `Db/FormLink.php` | 32 | Pro 1 | K entity Processes reads |
| `Db/FormLinkMapper.php` | 34 | Pro 1 | W binding/registration moves to Integrations |
| `Db/FormLinkMapperInterface.php` | 12 | Pro 2 | K entity Processes reads |
| `Db/HandoffQueueEntryMapper.php` | 36 | Pro 1 | W binding/registration moves to Integrations |
| `Db/HandoffQueueEntryMapperInterface.php` | 11 | Pro 1 | W binding/registration moves to Integrations |
| `Db/IntegrationsMapperRegistrar.php` | 10 | Pla 1 | K registrar |
| `Db/MapLink.php` | 32 | Core 1 | O caller rows of the Core / Notifications designs |
| `Db/Mapping.php` | 66 | Cfg 2 | P `MappingsInterface` |
| `Db/MappingMapper.php` | 175 | Cfg 2 | P `MappingsInterface` (registrar binding moves, W) |
| `Db/MappingMapperInterface.php` | 15 | Core 2, Pro 1, Cfg 2 | P `MappingsInterface` |
| `Db/ObjectKeyLinkTrait.php` | 19 | Pro 1 | K link toolkit trait |
| `Db/ObjectRelation/ObjectRelationFieldTypes.php` | 28 | Core 1 | R row → Core |
| `Db/OpenProjectLink.php` | 70 | Core 1 | O caller rows of the Core / Notifications designs |
| `Db/PhotoLink.php` | 30 | Core 1 | O caller rows of the Core / Notifications designs |
| `Db/PollLink.php` | 39 | Core 1 | O caller rows of the Core / Notifications designs |
| `Db/Source.php` | 62 | Core 4, Fil 1 | P Core `ConnectedSourcesInterface`; `ChunkMapper` import unused |
| `Db/Support/SecretAtRest.php` | 55 | Core 1, Pla 1 | K Platform repair |
| `Db/TalkLink.php` | 78 | Cfg 1 | P `ObjectLinkSeedsInterface` |
| `Db/TalkLinkMapperInterface.php` | 11 | Cfg 1 | P `ObjectLinkSeedsInterface` |
| `Db/TaskSequence/TaskSequenceFieldTypes.php` | 24 | Cfg 1 | R row → Configuration |
| `Db/TimeTrackerLink.php` | 36 | Core 1 | O caller rows of the Core / Notifications designs |
| `Db/Webhook.php` | 188 | Cfg 1 | P `WebhookSubscriptionsInterface` |
| `Db/WebhookLogMapperInterface.php` | 15 | Sea 1, Com 1 | P Search `WebhookDeliveryStatsInterface` (Compliance bag by row) |
| `Db/WebhookMapper.php` | 197 | Core 1 | W binding/registration moves to Integrations |
| `Db/WebhookMapperInterface.php` | 18 | Com 1, Cfg 1 | P `WebhookSubscriptionsInterface` (Compliance bag by row) |
| `Federation/OpenRegisterCloudFederationProvider.php` | 56 | Pla 2 | W binding/registration moves to Integrations |
| `Kernel/Contracts/Integrations/TranslationBindingInterface.php` | 6 | Cfg 2 | K one-capability interface or value |
| `Mcp/Db/Agent.php` | 132 | Sea 1, Cfg 1, Pla 1 | V Kernel `AgentInterface` |
| `Mcp/Db/Conversation.php` | 48 | Core 1, Not 4, Pla 4 | V Kernel `ConversationInterface` |
| `Mcp/Listener/ContextChatSubmissionListener.php` | 258 | Pro 1 | W binding/registration moves to Integrations |
| `Mcp/Listener/Registrar/McpListenerRegistrar.php` | 11 | Pro 1 | W binding/registration moves to Integrations |
| `Mcp/McpRegistrar.php` | 12 | Pla 1 | K registrar |
| `Mcp/Service/Chat/StreamYieldChannels.php` | 9 | Pla 1 | R row → Platform |
| `Mcp/Service/Mcp/McpAnnotationValidators.php` | 8 | Core 1 | C contributed declaration check |
| `Mcp/Service/Mcp/McpToolCatalogueInterface.php` | 6 | Cfg 1 | K one-capability interface or value |
| `Mcp/Service/ToolRegistryInterface.php` | 9 | Ker 1, Core 1, Pla 1 | K one-capability interface or value |
| `Mcp/Tool/ToolInterface.php` | 9 | Ker 1 | R callers count to Integrations |
| `Notifications/Service/EmailServiceInterface.php` | 13 | Core 2 | O caller rows of the Core / Notifications designs |
| `Repair/RegisterDescriptorImport.php` | 14 | Pro 1, Pla 5 | K value object |
| `Service/ActivityEntriesOptions.php` | 10 | Not 1 | O caller rows of the Core / Notifications designs |
| `Service/ActivityFilterService.php` | 197 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/AnalyticsLinkService.php` | 273 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/AnalyticsLinkServiceInterface.php` | 13 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/AnalyticsSeriesService.php` | 117 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/AnalyticsSeriesServiceInterface.php` | 9 | Sea 1, Cfg 1 | P unused import in `MapsOverviewService` |
| `Service/AuthenticationService.php` | 288 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Bindings/AccessBindings.php` | 13 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Bindings/FeatureBindings.php` | 13 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Bindings/ObjectsBindings.php` | 19 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Bindings/RootBindings.php` | 8 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Bindings/SchemasBindings.php` | 14 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/BookmarkLinkService.php` | 378 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/BookmarkLinkServiceInterface.php` | 13 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/CalendarEvent/CalendarObjectAddress.php` | 10 | Pro 1 | K one-capability interface or value |
| `Service/CalendarEvent/UserCalendarSelector.php` | 95 | Pro 1 | R row → Processes |
| `Service/CalendarEvent/VeventCodec.php` | 179 | Pro 1 | R row → Processes |
| `Service/CalendarEvent/VeventDraft.php` | 13 | Pro 1 | R row → Processes |
| `Service/CalendarEventKey.php` | 10 | Pro 2 | K one-capability interface or value |
| `Service/CalendarLinkService.php` | 455 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/CalendarLinkServiceInterface.php` | 13 | Core 2, Pro 2, Cfg 1 | K one-capability interface or value |
| `Service/CalendarObjectRef.php` | 10 | Pro 1 | K one-capability interface or value |
| `Service/CalendarPickerWindow.php` | 11 | Pro 1 | K one-capability interface or value |
| `Service/Capability/ToolReachLevels.php` | 16 | Cfg 2 | K one-capability interface or value |
| `Service/CaseTokenService.php` | 176 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/CaseTokenServiceInterface.php` | 6 | Core 1, Cfg 1 | W binding/registration moves to Integrations |
| `Service/CollectiveLinkService.php` | 371 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/CollectiveLinkServiceInterface.php` | 14 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Connection/ConnectionReportForbiddenException.php` | 9 | Not 1, Cfg 1 | R row → Notifications |
| `Service/Connection/ConnectionReportKind.php` | 45 | Not 2, Cfg 1 | K one-capability interface or value |
| `Service/Connection/ConnectionReporter.php` | 179 | Com 2, Cfg 1 | P Compliance `ConnectionHealthInterface` |
| `Service/Connection/ConnectionReporterInterface.php` | 8 | Cfg 6 | K one-capability interface or value |
| `Service/ContactMatchingMappers.php` | 12 | Pro 1 | R row → Processes |
| `Service/ContactService.php` | 540 | Core 2, Cfg 1 | W binding/registration moves to Integrations |
| `Service/ContactServiceInterface.php` | 11 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/CospendLinkService.php` | 424 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/CospendLinkServiceInterface.php` | 14 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/CredentialAppTokenService.php` | 129 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/CredentialAppTokenServiceInterface.php` | 8 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/CredentialBrokerService.php` | 671 | Cfg 4 | P `CredentialBrokerInterface` |
| `Service/Credential/CredentialMint.php` | 12 | Cfg 1 | K one-capability interface or value |
| `Service/Credential/CredentialMintOptions.php` | 32 | Cfg 1 | K one-capability interface or value |
| `Service/Credential/CredentialStore.php` | 8 | Core 2, Cfg 1 | P `CredentialBrokerInterface`; Core wiring on `CredentialSecretReaderInterface` |
| `Service/Credential/CredentialStoreResolver.php` | 109 | Core 1 | W binding/registration moves to Integrations |
| `Service/Credential/DoriathApplicationRegistrar.php` | 102 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/DoriathApplicationRegistrarInterface.php` | 6 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/DoriathCredentialKeys.php` | 8 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/DoriathCredentialStore.php` | 150 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2ConnectService.php` | 102 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2ConnectServiceInterface.php` | 13 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2ConnectionRepository.php` | 66 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2ConnectionRepositoryInterface.php` | 12 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2CredentialObjects.php` | 49 | Cfg 1 | P `CredentialBrokerInterface` |
| `Service/Credential/OAuth2CredentialSweepInterface.php` | 7 | Com 1, Cfg 1 | R callers count to Integrations |
| `Service/Credential/OAuth2Endpoints.php` | 39 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2EndpointsInterface.php` | 9 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2InstanceClient.php` | 104 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2InstanceClientInterface.php` | 6 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2RefreshService.php` | 188 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2RelayGuard.php` | 69 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2RelayGuardInterface.php` | 6 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2StateService.php` | 107 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/OAuth2StateServiceInterface.php` | 10 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/ProviderCatalogue.php` | 54 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Credential/ProviderCatalogueInterface.php` | 7 | Com 1, Cfg 1 | R callers count to Integrations |
| `Service/DeckCardService.php` | 292 | Core 1, Cfg 1 | W binding/registration moves to Integrations |
| `Service/DeckCardServiceInterface.php` | 11 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/DeckLinkService.php` | 383 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/DeckLinkServiceInterface.php` | 14 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/DeepLinkRegistryService.php` | 205 | Core 3, Cfg 3, Pla 1 | I Core `DeepLinkResolverInterface` (+ `resolveDisplayName`) |
| `Service/EmailLinkService.php` | 289 | Core 1, Cfg 1 | W binding/registration moves to Integrations |
| `Service/EmailLinkServiceInterface.php` | 15 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/EndpointService.php` | 164 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/EndpointServiceInterface.php` | 7 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/EntityLink.php` | 10 | Core 1 | O caller rows of the Core / Notifications designs |
| `Service/FederationShareService.php` | 144 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/FederationShareServiceInterface.php` | 13 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/FormLinkService.php` | 284 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/FormLinkServiceInterface.php` | 15 | Core 1, Cfg 1 | W binding/registration moves to Integrations |
| `Service/Handoff/HandoffAnnotationValidator.php` | 156 | Core 1, Cfg 1 | C contributed declaration check |
| `Service/Handoff/HandoffAnnotationValidatorInterface.php` | 6 | Core 1, Cfg 1 | C contributed declaration check |
| `Service/Handoff/HandoffContractBindingValidator.php` | 133 | Core 1, Cfg 1 | C contributed declaration check |
| `Service/Handoff/HandoffContractBindingValidatorInterface.php` | 6 | Core 1, Cfg 1 | C contributed declaration check |
| `Service/Handoff/HandoffService.php` | 585 | Pro 1, Cfg 1 | I `HandoffServiceInterface` |
| `Service/Handoff/HandoffServiceInterface.php` | 19 | Pro 2, Cfg 1 | K one-capability interface or value |
| `Service/Integration/AbstractIntegrationProvider.php` | 43 | Core 1 | R callers count to Integrations |
| `Service/Integration/ExternalIntegrationRouter.php` | 342 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Integration/ExternalIntegrationRouterInterface.php` | 10 | Core 2, Cfg 1, Pla 1 | K one-capability interface or value |
| `Service/Integration/IntegrationAdminView.php` | 28 | Pla 1 | K plug-in point value |
| `Service/Integration/IntegrationCatalogueInterface.php` | 9 | Pla 3 | K one-capability interface or value |
| `Service/Integration/IntegrationProvider.php` | 16 | Ker 1, Pla 2 | K plug-in point |
| `Service/Integration/IntegrationRegistrar.php` | 16 | Pla 1 | K registrar |
| `Service/Integration/LeafBundles.php` | 9 | Pro 1 | R callers count to Integrations |
| `Service/Integration/LeafDescriptor.php` | 95 | Ker 1, Pro 1 | R callers count to Integrations |
| `Service/Integration/LeafRegistryInterface.php` | 7 | Pro 1, Pla 2 | K one-capability interface or value |
| `Service/Integration/PageWidgetRegistryInterface.php` | 8 | Sea 2 | P Search `PageWidgetsInterface` |
| `Service/Integration/Providers/BrpPersonProvider.php` | 400 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Integration/Providers/BrpPersonProviderInterface.php` | 8 | Pro 1, Cfg 1 | K one-capability interface or value |
| `Service/Integration/Providers/MessageDispatcherInterface.php` | 6 | Not 1 | P Notifications `ScheduledMessageDispatchInterface` |
| `Service/Integration/Providers/OutboundMessage.php` | 11 | Not 1 | P Notifications `ScheduledMessageDispatchInterface` |
| `Service/Integration/UpstreamRequest.php` | 14 | Core 1 | R callers count to Integrations |
| `Service/LinkTarget.php` | 41 | Core 5, Pro 5 | K one-capability interface or value |
| `Service/LinkedEntityService.php` | 238 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/MapLinkService.php` | 204 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/MappingService.php` | 372 | Core 2, Pro 1, Cfg 1 | P `MappingsInterface` |
| `Service/MappingServiceInterface.php` | 8 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/ObjectRelationCleanupService.php` | 133 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/OpenProjectLinkService.php` | 259 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Outbound/OutboundClientFactory.php` | 22 | Pla 1 | W binding/registration moves to Integrations |
| `Service/Outbound/ProxySettings.php` | 118 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Outbound/ProxySettingsInterface.php` | 6 | Cfg 1, Pla 1 | W binding/registration moves to Integrations |
| `Service/PersonLinkChange.php` | 11 | Core 1 | O caller rows of the Core / Notifications designs |
| `Service/PersonLinkEvents.php` | 23 | Pro 1 | K party links |
| `Service/PersonLinkRef.php` | 11 | Core 1, Pro 2, Cfg 1 | K party links |
| `Service/PersonLinkService.php` | 210 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/PhotoLinkService.php` | 237 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/PoiDraft.php` | 37 | Core 1 | O caller rows of the Core / Notifications designs |
| `Service/PollDraft.php` | 75 | Core 1 | O caller rows of the Core / Notifications designs |
| `Service/PollLinkService.php` | 317 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/ShareDraft.php` | 63 | Core 1 | O caller rows of the Core / Notifications designs |
| `Service/ShareLinkService.php` | 360 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Sync/HarvestPipelineService.php` | 270 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/Sync/HarvestPipelineServiceInterface.php` | 6 | Core 1, Com 1, Cfg 1 | R callers count to Integrations |
| `Service/Sync/HarvestRun.php` | 13 | Core 1 | R callers count to Integrations |
| `Service/Sync/RestApiSourceFetcher.php` | 157 | Core 1 | W binding/registration moves to Integrations |
| `Service/Sync/SourceFetcherInterface.php` | 8 | Core 1 | R callers count to Integrations |
| `Service/Sync/SourceFetcherLookupInterface.php` | 6 | Core 1, Com 1, Cfg 1 | R callers count to Integrations |
| `Service/Sync/SourceFetcherRegistry.php` | 21 | Core 1, Cfg 1 | W binding/registration moves to Integrations |
| `Service/Sync/SyncScheduleInterface.php` | 8 | Com 1, Cfg 1 | R callers count to Integrations |
| `Service/Sync/SyncScheduleService.php` | 34 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/TableDeletionOptions.php` | 10 | Core 1, Com 1 | R row → Core |
| `Service/TableObjectRef.php` | 11 | Core 1, Com 1 | R row → Compliance |
| `Service/TalkLinkService.php` | 296 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/TalkLinkServiceInterface.php` | 15 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/TimeTrackerLinkService.php` | 507 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/UserLinkWriter.php` | 70 | Core 1 | O caller rows of the Core / Notifications designs |
| `Service/WebhookService.php` | 637 | Pro 1 | R callers count to Integrations |
| `Service/WellKnown/SecurityTxtBuilder.php` | 84 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/WellKnown/SecurityTxtBuilderInterface.php` | 7 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/WorkPackageDraft.php` | 11 | Core 1 | O caller rows of the Core / Notifications designs |
| `Service/XwikiLinkService.php` | 299 | Cfg 1 | W binding/registration moves to Integrations |
| `Service/XwikiLinkServiceInterface.php` | 15 | Cfg 1 | W binding/registration moves to Integrations |
| `Twig/MappingTwigInstallerInterface.php` | 7 | Core 1 | P Core `TwigExtensionsInterface` |
