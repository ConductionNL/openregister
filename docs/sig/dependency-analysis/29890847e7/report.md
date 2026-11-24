# Dependency analysis of lib/ at 29890847e796e459279d06b6ce571a63ef0c4289

7482 PHP files, 458606 lines of code, 23450 references between files (lib/Migration left out). Produced by `tools/sig/analyse_dependencies.py`.

## Component views

| View | Components | Largest | Hidden code | Communication lines | Violation degree |
|---|---|---|---|---|---|
| directories under lib/ | 39 | Service (55.3%) | 85.2% | 226 | 0.0554 |
| functional areas | 10 | Core (33.8%) | 62.5% | 85 | 0.1936 |

## Layering

Of 22285 references between layered files, 8433 go downward (37.8%), 13550 stay on the same level and 302 go upward (1.4%). 1165 references touch the bootstrap or an unlayered directory.

| Upward reference | Count | Example |
|---|---|---|
| persistence -> service | 203 | `lib/Db/AuditFlowAttribution.php -> lib/Service/Flow/FlowRunContextInterface.php` |
| shared type -> persistence | 52 | `lib/ContextChat/Support/ContentProviderSources.php -> lib/Db/MagicMapperInterface.php` |
| service -> entry point | 27 | `lib/Notifications/Service/Notification/NcNotificationSender.php -> lib/Notifications/BackgroundJob/WebPushDispatchJob.php` |
| shared type -> service | 17 | `lib/Contacts/Support/ContactsMenuSources.php -> lib/Service/DeepLinkResolverInterface.php` |
| shared type -> entry point | 2 | `lib/Event/AppWebhookEvents.php -> lib/Listener/WebhookEventListener.php` |
| persistence -> entry point | 1 | `lib/Db/MagicMapper.php -> lib/BackgroundJob/JobHandles.php` |

## Cycles

122 sets of files reference each other in a cycle, 685 files in all. The largest holds 280 files and 30625 lines (6.7% of lib/).

By directory: Db/MagicMapper 74, Service/Rbac 34, Service/Object 23, Service 21, Event 16, Service/Lifecycle 16, BackgroundJob 15, Db 13, Db/Schema 10, Service/Schema 9, BackgroundJob/Support 7, Kernel/Contracts 7, Service/Query 6, Service/Schemas 6, Service/JsonLd 4, Service/Form 3, Service/Deferral 2, Db/SchemaMapper 2, Service/Relation 2, Service/Deletion 2, Service/Edepot 1, Service/Audit 1, Search/Service 1, ContextChat/Listener 1, Service/Archival 1, Db/AuditTrail 1, Notifications/Service 1, Exception 1

By area: Core 198, Processes 28, Kernel 26, Compliance 18, Integrations 4, Search 2, Configuration 2, Platform 1, Notifications 1

Files with the most references inside the set:

- `lib/Db/MagicMapper.php (67 references inside the set)`
- `lib/Db/MagicMapper/MagicSearchHandler.php (28 references inside the set)`
- `lib/Db/MagicMapper/MagicRbacHandler.php (21 references inside the set)`
- `lib/Db/MagicMapperInterface.php (14 references inside the set)`
- `lib/Service/Lifecycle/TransitionEngine.php (13 references inside the set)`
- `lib/BackgroundJob/JobHandles.php (12 references inside the set)`
- `lib/Service/Schema/SchemaMigrationService.php (11 references inside the set)`
- `lib/Db/MagicMapper/MagicFacetHandler.php (11 references inside the set)`
- `lib/Service/Schema/SchemaRevalidationService.php (9 references inside the set)`
- `lib/Service/Object/ObjectEventGate.php (9 references inside the set)`

60 of the references inside the set go upward a layer; results.json lists them.

## Functional areas

| Area | Lines | Exposed | Internal refs | Outgoing refs | Incoming refs |
|---|---|---|---|---|---|
| Core | 154939 | 43.4% | 4510 | 3160 | 2041 |
| Processes | 84858 | 45.4% | 3454 | 1646 | 895 |
| Configuration | 56697 | 18.8% | 1401 | 1848 | 292 |
| Integrations | 55901 | 36.2% | 1576 | 1102 | 614 |
| Compliance | 34940 | 51.0% | 940 | 745 | 512 |
| Notifications | 19855 | 8.7% | 637 | 315 | 154 |
| Files | 18732 | 20.0% | 451 | 296 | 101 |
| Search | 15113 | 15.1% | 353 | 266 | 174 |
| Kernel | 9059 | 89.2% | 252 | 114 | 4702 |
| Platform | 8512 | 17.8% | 120 | 264 | 271 |

Area pairs that reference each other in both directions, heaviest lighter direction first:

| Pair | References | Lighter direction | Most referenced in the lighter direction |
|---|---|---|---|
| Core / Processes | 317 / 319 | Core -> Processes | `Service/PropertyAccessRequest.php`, `BulkAction/BulkActionInterface.php`, `Service/PropertyRbacHandler.php` |
| Compliance / Core | 280 / 244 | Core -> Compliance | `Db/AuditTrail.php`, `Db/AuditTrail/ObjectAuditEvent.php`, `Db/AuditTrail/NamedActor.php` |
| Core / Integrations | 236 / 435 | Core -> Integrations | `Db/Source.php`, `Db/FederatedShare.php`, `Db/AccessLink.php` |
| Configuration / Core | 630 / 143 | Core -> Configuration | `Service/SchemaProperty.php`, `Db/Configuration.php`, `Service/LanguageService.php` |
| Core / Search | 92 / 92 | Core -> Search | `Db/RegisterSchema.php`, `Search/Db/ViewMapperInterface.php`, `Search/Service/Aggregation/AggregationQuery.php` |
| Integrations / Processes | 82 / 113 | Integrations -> Processes | `Db/Flow.php`, `Db/FormLinkMapperInterface.php`, `Service/Flow/FlowServiceInterface.php` |
| Core / Kernel | 1984 / 80 | Kernel -> Core | `Event/AbstractObjectEntityEvent.php`, `Event/AbstractSchemaEvent.php`, `Event/AbstractPersonLinkEvent.php` |
| Core / Files | 60 / 93 | Core -> Files | `Service/FileHandlerSupportInterface.php`, `Files/Db/FileMapperInterface.php`, `Files/Db/EntityRelationMapperInterface.php` |
| Core / Platform | 57 / 82 | Core -> Platform | `AppInfo/Application.php`, `Service/ActionRightsCatalogueInterface.php`, `Db/TranslationMapperInterface.php` |
| Integrations / Platform | 37 / 42 | Integrations -> Platform | `AppInfo/Application.php`, `Service/Resilience/BreakerRegistry.php`, `Db/TranslationSourceLanguageBackfillInterface.php` |
| Compliance / Configuration | 32 / 160 | Compliance -> Configuration | `Service/Settings/ArchivalSettingsReaderInterface.php`, `Db/NewRowStamps.php`, `Service/Export/SpreadsheetCell.php` |
| Configuration / Processes | 387 / 31 | Processes -> Configuration | `Db/TaskSequence.php`, `Service/SequenceScope.php`, `Db/Translation.php` |
| Notifications / Processes | 31 / 58 | Notifications -> Processes | `Db/Task.php`, `Service/Interaction/WatcherServiceInterface.php`, `Service/Task/TaskInboxServiceInterface.php` |
| Platform / Processes | 28 / 53 | Platform -> Processes | `Db/FlowMapperInterface.php`, `Db/Flow.php`, `Db/TaskMapper.php` |
| Core / Notifications | 27 / 30 | Core -> Notifications | `Notifications/Service/TimelineVisibilityServiceInterface.php`, `Notifications/Db/WatcherMapperInterface.php`, `Notifications/Service/Notification/NotificationDeclarationException.php` |
