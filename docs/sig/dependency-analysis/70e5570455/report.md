# Dependency analysis of lib/ at 70e557045555e68834e6f55f195211db3bd3b07e

5387 PHP files, 394863 lines of code, 16768 references between files (lib/Migration left out). Produced by `tools/sig/analyse_dependencies.py`.

## Component views

| View | Components | Largest | Hidden code | Communication lines | Violation degree |
|---|---|---|---|---|---|
| directories under lib/ | 38 | Service (54.4%) | 88.7% | 194 | 0.0646 |
| functional areas | 10 | Core (33.3%) | 64.5% | 81 | 0.194 |

4 files are not matched by the functional definition and were placed by directory majority (see results.json).

## Layering

Of 16039 references between layered files, 6401 go downward (39.9%), 9389 stay on the same level and 249 go upward (1.6%). 729 references touch the bootstrap or an unlayered directory.

| Upward reference | Count | Example |
|---|---|---|
| persistence -> service | 153 | `lib/Db/AuditFlowAttribution.php -> lib/Service/Flow/FlowRunContextInterface.php` |
| shared type -> persistence | 54 | `lib/ContextChat/Support/ContentProviderSources.php -> lib/Db/MagicMapperInterface.php` |
| service -> entry point | 23 | `lib/Notifications/Service/Notification/NcNotificationSender.php -> lib/Notifications/BackgroundJob/WebPushDispatchJob.php` |
| shared type -> service | 17 | `lib/BulkAction/Support/ApplyRuleServices.php -> lib/Service/Calculation/CalculationEvaluatorInterface.php` |
| persistence -> entry point | 1 | `lib/Db/MagicMapper.php -> lib/BackgroundJob/JobHandles.php` |
| shared type -> entry point | 1 | `lib/Event/ObjectEventSubscription.php -> lib/Listener/ListenerClassesInterface.php` |

## Cycles

91 sets of files reference each other in a cycle, 434 files in all. The largest holds 190 files and 23989 lines (6.1% of lib/).

By directory: Db/MagicMapper 54, Service/Rbac 27, Event 16, Service/Object 14, BackgroundJob 12, Service/Lifecycle 11, Service 10, Db/Schema 10, Db 8, BackgroundJob/Support 6, Service/Schema 5, Service/Query 5, Service/Form 2, Service/Deferral 2, Service/Archival 1, Notifications/Service 1, Kernel/Contracts 1, Exception 1, Service/Deletion 1, Search/Service 1, Service/Audit 1, Service/Edepot 1

By area: Core 133, Kernel 20, Processes 19, Compliance 15, Platform 1, Notifications 1, Search 1

Files with the most references inside the set:

- `lib/Db/MagicMapper.php (50 references inside the set)`
- `lib/Db/MagicMapper/MagicRbacHandler.php (16 references inside the set)`
- `lib/Db/MagicMapperInterface.php (14 references inside the set)`
- `lib/Db/MagicMapper/MagicSearchHandler.php (13 references inside the set)`
- `lib/BackgroundJob/JobHandles.php (10 references inside the set)`
- `lib/Service/Lifecycle/TransitionEngine.php (10 references inside the set)`
- `lib/Service/Object/ObjectEventGate.php (9 references inside the set)`
- `lib/Service/Schema/SchemaRevalidationService.php (7 references inside the set)`
- `lib/Db/Schema.php (7 references inside the set)`
- `lib/Service/Schema/SchemaMigrationService.php (6 references inside the set)`

49 of the references inside the set go upward a layer; results.json lists them.

## Functional areas

| Area | Lines | Exposed | Internal refs | Outgoing refs | Incoming refs |
|---|---|---|---|---|---|
| Core | 131485 | 39.9% | 3028 | 2402 | 1538 |
| Processes | 74739 | 45.1% | 2467 | 1223 | 758 |
| Integrations | 50105 | 37.7% | 1199 | 739 | 493 |
| Configuration | 48165 | 16.2% | 918 | 1570 | 206 |
| Compliance | 29319 | 55.8% | 606 | 469 | 377 |
| Notifications | 17445 | 6.5% | 439 | 216 | 114 |
| Files | 15809 | 3.5% | 339 | 197 | 75 |
| Search | 13705 | 10.1% | 285 | 197 | 83 |
| Kernel | 7374 | 92.0% | 102 | 112 | 3595 |
| Platform | 6717 | 17.3% | 70 | 190 | 76 |

Area pairs that reference each other in both directions, heaviest lighter direction first:

| Pair | References | Lighter direction | Most referenced in the lighter direction |
|---|---|---|---|
| Core / Processes | 250 / 234 | Processes -> Core | `Db/TypedFieldsJsonTrait.php`, `Db/AuditTrailMapperInterface.php`, `Db/MagicMapperInterface.php` |
| Core / Integrations | 189 / 313 | Core -> Integrations | `Db/AccessLink.php`, `Db/Source.php`, `Service/LinkTarget.php` |
| Compliance / Core | 193 / 156 | Core -> Compliance | `Db/AuditTrail.php`, `Service/AuthorizationAuditService.php`, `Service/Audit/PurposeGuardInterface.php` |
| Configuration / Core | 534 / 108 | Core -> Configuration | `Service/ConfigurationServiceInterface.php`, `Service/LanguageService.php`, `Service/Export/ExportGateInterface.php` |
| Core / Kernel | 1575 / 80 | Kernel -> Core | `Event/AbstractObjectEntityEvent.php`, `Event/AbstractSchemaEvent.php`, `Event/AbstractPersonLinkEvent.php` |
| Integrations / Processes | 53 / 77 | Integrations -> Processes | `Service/CalendarEventService.php`, `Db/Flow.php`, `Db/FormLinkMapperInterface.php` |
| Core / Files | 53 / 43 | Files -> Core | `Db/EntityRelation.php`, `Db/AuditTrailMapperInterface.php`, `Service/Object/FileWriteGuardInterface.php` |
| Core / Search | 40 / 61 | Core -> Search | `Search/Db/ViewMapperInterface.php`, `Search/Service/Aggregation/AggregationQuery.php`, `Search/Service/Search/PropertySearchProfile.php` |
| Notifications / Processes | 21 / 41 | Notifications -> Processes | `Db/Task.php`, `Service/Interaction/WatcherServiceInterface.php`, `Service/Task/TaskInboxServiceInterface.php` |
| Compliance / Configuration | 20 / 152 | Compliance -> Configuration | `Service/Settings/ArchivalSettingsReaderInterface.php`, `Db/HydrateBySettersTrait.php`, `Service/Settings/FileSettingsHandler.php` |
| Configuration / Processes | 380 / 19 | Processes -> Configuration | `Db/TaskSequence.php`, `Db/Translation.php`, `Command/Support/RematerialiseEvaluation.php` |
| Core / Notifications | 19 / 23 | Core -> Notifications | `Notifications/Service/TimelineVisibilityServiceInterface.php`, `Notifications/Service/Notification/NotificationClearingServiceInterface.php`, `Notifications/Service/Notification/NotificationDeclarationException.php` |
| Configuration / Platform | 30 / 18 | Platform -> Configuration | `Service/Configuration/DescriptorConfigIdsInterface.php`, `Db/Translation.php`, `Service/Configuration/ConfigurationServiceRegistrar.php` |
| Configuration / Integrations | 147 / 16 | Integrations -> Configuration | `Db/Application.php`, `Db/HydrateBySettersTrait.php`, `Db/Configuration.php` |
| Platform / Processes | 24 / 16 | Processes -> Platform | `Settings/OpenRegisterAdmin.php`, `Service/TranslationProjectionServiceInterface.php`, `Db/TranslationMapperInterface.php` |
