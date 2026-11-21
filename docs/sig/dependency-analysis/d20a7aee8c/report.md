# Dependency analysis of lib/ at d20a7aee8caa890714328a1e9c61719858f19e1b

6846 PHP files, 414315 lines of code, 20992 references between files (lib/Migration left out). Produced by `tools/sig/analyse_dependencies.py`.

## Component views

| View | Components | Largest | Hidden code | Communication lines | Violation degree |
|---|---|---|---|---|---|
| directories under lib/ | 38 | Service (54.7%) | 89.4% | 198 | 0.0505 |
| functional areas | 10 | Core (33.4%) | 64.9% | 83 | 0.1879 |

7 files are not matched by the functional definition and were placed by directory majority (see results.json).

## Layering

Of 19973 references between layered files, 7386 go downward (37.0%), 12332 stay on the same level and 255 go upward (1.3%). 1019 references touch the bootstrap or an unlayered directory.

| Upward reference | Count | Example |
|---|---|---|
| persistence -> service | 171 | `lib/Db/AuditFlowAttribution.php -> lib/Service/Flow/FlowRunContextInterface.php` |
| shared type -> persistence | 48 | `lib/ContextChat/Support/ContentProviderSources.php -> lib/Db/MagicMapperInterface.php` |
| service -> entry point | 22 | `lib/Notifications/Service/Notification/NcNotificationSender.php -> lib/Notifications/BackgroundJob/WebPushDispatchJob.php` |
| shared type -> service | 12 | `lib/Contacts/Support/ContactsMenuSources.php -> lib/Service/DeepLinkResolverInterface.php` |
| persistence -> entry point | 1 | `lib/Db/MagicMapper.php -> lib/BackgroundJob/JobHandles.php` |
| shared type -> entry point | 1 | `lib/Event/ObjectEventSubscription.php -> lib/Listener/ListenerClassesInterface.php` |

## Cycles

113 sets of files reference each other in a cycle, 582 files in all. The largest holds 230 files and 24524 lines (5.9% of lib/).

By directory: Db/MagicMapper 64, Service/Rbac 29, Service 20, Service/Object 18, Event 16, Db 13, BackgroundJob 12, Service/Lifecycle 12, Db/Schema 10, Service/Schema 9, BackgroundJob/Support 7, Service/Query 5, Service/Form 3, Service/Deletion 2, Service/Deferral 2, Notifications/Service 1, Service/Edepot 1, Search/Service 1, Service/Archival 1, Db/AuditTrail 1, Exception 1, Service/Audit 1, Kernel/Contracts 1

By area: Core 162, Processes 23, Kernel 20, Compliance 18, Configuration 2, Search 2, Platform 1, Integrations 1, Notifications 1

Files with the most references inside the set:

- `lib/Db/MagicMapper.php (59 references inside the set)`
- `lib/Db/MagicMapper/MagicSearchHandler.php (21 references inside the set)`
- `lib/Db/MagicMapper/MagicRbacHandler.php (17 references inside the set)`
- `lib/Db/MagicMapperInterface.php (14 references inside the set)`
- `lib/Db/MagicMapper/MagicFacetHandler.php (11 references inside the set)`
- `lib/Service/Lifecycle/TransitionEngine.php (10 references inside the set)`
- `lib/BackgroundJob/JobHandles.php (10 references inside the set)`
- `lib/Service/Object/ObjectEventGate.php (9 references inside the set)`
- `lib/Service/PropertyRbacHandler.php (9 references inside the set)`
- `lib/Service/Schema/SchemaMigrationService.php (9 references inside the set)`

50 of the references inside the set go upward a layer; results.json lists them.

## Functional areas

| Area | Lines | Exposed | Internal refs | Outgoing refs | Incoming refs |
|---|---|---|---|---|---|
| Core | 138388 | 39.6% | 4042 | 2758 | 1934 |
| Processes | 79354 | 43.6% | 3291 | 1461 | 836 |
| Integrations | 51898 | 36.5% | 1495 | 945 | 557 |
| Configuration | 50344 | 17.7% | 1209 | 1715 | 257 |
| Compliance | 30872 | 54.0% | 837 | 592 | 466 |
| Notifications | 18376 | 6.9% | 573 | 283 | 136 |
| Files | 16566 | 3.4% | 415 | 236 | 74 |
| Search | 14129 | 10.6% | 338 | 238 | 154 |
| Kernel | 7720 | 91.0% | 163 | 110 | 3893 |
| Platform | 6668 | 17.0% | 85 | 206 | 237 |

Area pairs that reference each other in both directions, heaviest lighter direction first:

| Pair | References | Lighter direction | Most referenced in the lighter direction |
|---|---|---|---|
| Core / Processes | 295 / 321 | Core -> Processes | `Service/PropertyAccessRequest.php`, `BulkAction/BulkActionInterface.php`, `Service/PropertyRbacHandler.php` |
| Compliance / Core | 254 / 219 | Core -> Compliance | `Db/AuditTrail.php`, `Db/AuditTrail/ObjectAuditEvent.php`, `Db/AuditTrail/NamedActor.php` |
| Core / Integrations | 215 / 413 | Core -> Integrations | `Db/Source.php`, `Db/AccessLink.php`, `Service/LinkTarget.php` |
| Configuration / Core | 609 / 131 | Core -> Configuration | `Service/SchemaProperty.php`, `Db/Configuration.php`, `Service/LanguageService.php` |
| Core / Kernel | 1700 / 82 | Kernel -> Core | `Event/AbstractObjectEntityEvent.php`, `Event/AbstractSchemaEvent.php`, `Event/AbstractPersonLinkEvent.php` |
| Core / Search | 79 / 85 | Core -> Search | `Db/RegisterSchema.php`, `Search/Service/Aggregation/AggregationQuery.php`, `Search/Db/ViewMapperInterface.php` |
| Integrations / Processes | 72 / 108 | Integrations -> Processes | `Db/Flow.php`, `Db/FormLinkMapperInterface.php`, `Service/Flow/FlowServiceInterface.php` |
| Core / Files | 52 / 66 | Core -> Files | `Service/FileHandlerSupportInterface.php`, `Files/Db/FileMapperInterface.php`, `Files/Service/File/FileOwnershipHandlerInterface.php` |
| Core / Platform | 44 / 63 | Core -> Platform | `AppInfo/Application.php`, `Db/TranslationMapperInterface.php`, `Service/LanguageServiceInterface.php` |
| Integrations / Platform | 32 / 36 | Integrations -> Platform | `AppInfo/Application.php`, `Db/TranslationSourceLanguageBackfillInterface.php` |
| Notifications / Processes | 27 / 53 | Notifications -> Processes | `Db/Task.php`, `Service/Interaction/WatcherServiceInterface.php`, `Service/Task/TaskInboxServiceInterface.php` |
| Configuration / Processes | 383 / 26 | Processes -> Configuration | `Db/TaskSequence.php`, `Service/SequenceScope.php`, `Db/Translation.php` |
| Configuration / Integrations | 149 / 24 | Integrations -> Configuration | `Db/Application.php`, `Db/HydrateBySettersTrait.php`, `Db/Configuration.php` |
| Compliance / Configuration | 24 / 155 | Compliance -> Configuration | `Service/Settings/ArchivalSettingsReaderInterface.php`, `Db/HydrateBySettersTrait.php`, `Service/Settings/FileSettingsHandler.php` |
| Core / Notifications | 23 / 41 | Core -> Notifications | `Notifications/Service/TimelineVisibilityServiceInterface.php`, `Notifications/Service/Notification/NotificationDeclarationException.php`, `Db/FeedbackMapperInterface.php` |
