# Contract design: the Platform area

Wave 5 design for the Platform area (rank 10, the top of the order). It changes nothing in `lib/`; it is the plan
the wave 5 build packages for this area carry out.

Measured on main `911c2702` with `modularity_check.py` (whole app: 155,651 of 459,456 lines exposed,
Kernel 10,440, violation degree 0.0874, 727 references against the order) and the module graph of the
maintainability checker. Line counts are code lines as the scorer counts them (no comments, no blank lines).

## Summary

| | Before | After |
|---|---|---|
| Files of the area | 152 | 128 (24 rows out, no new files) |
| Lines of the area | 8,613 | about 7,800 |
| Exposed files | 26 | 0 (1 until `TaskSequence` is settled, see PL2a) |
| **Exposed lines** | **976** | **0** (11 in the interim) |
| References against the order with a Platform end | 65, all inward | 0 |

Platform is the top area, so every reference another area makes to a Platform file runs against the order.
Exposure and violation are the same list here: 65 file references from six areas (Processes 22,
Configuration 19, Core 14, Integrations 5, Compliance 3, Notifications 2) to 26 Platform files.

Three facts shape the design:

1. **Platform owns no contract.** Nothing below Platform may name it, so the Platform contract set is empty:
   no `lib/Kernel/Contracts/Platform/` directory is proposed. What other areas use of Platform today is either
   a value that already has a Kernel id, a class that does a lower area's work, or a capability a lower area
   must own as a port that Platform's (or Configuration's) class implements.
2. **Most of it is misfiled work.** 651 of the 976 lines go by rows (rule 3) and wiring (rule 5): real-time
   push (Notifications' work), circuit breakers on outbound calls (Integrations'), repair helpers whose users
   are Processes, Core or Integrations repair steps, and the translation value types and interfaces that
   Configuration implements.
3. **Two capabilities need Core-owned ports**, as decided for "Platform action rights, language and
   translation": the served request language and stored object translations (both implemented by
   Configuration), plus two one-method needs (an action check, whether API-call recording is on) that Platform
   implements. The two Kernel ids that already exist (`AppIdentity::APP_ID`,
   `AdminSettingDelegation::OPEN_REGISTER_ADMIN`) cover the other 223 lines.

## 1. The exposed files today

Every Platform file another area references, grouped by what the reference is for.

### Application id and admin-setting id (223 lines, 8 references)

| File | Lines | Outside callers |
|---|---|---|
| `lib/AppInfo/Application.php` | 162 | Core: `Controller/SourcesController`; Compliance: `Controller/TransferController` (both only `Application::APP_ID`) |
| `lib/Settings/OpenRegisterAdmin.php` | 61 | Core: `Controller/ActionRightsController`; Compliance: `Controller/QualityAuditController`; Configuration: `Controller/RegisterDescriptorController`, `Controller/SetupController`; Processes: `Controller/RulesController`; Notifications: `Notifications/Controller/TimelineAdminController` (all only `OpenRegisterAdmin::class` in `#[AuthorizedAdminSetting]`) |

### Real-time push (214 lines, 3 references)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Listener/NotifyPushListener.php` | 214 | Core: `Listener/ListenerBootstrap` (binds `PresencePushInterface` to it), `Listener/NotifyPushBatch` (calls its four static batch methods); Processes: `Listener/ListenerRegistrar` (registers it for `ObjectCreated/Updated/DeletedEvent`) |

### Outbound circuit breakers (112 lines, 3 references)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Service/Resilience/BreakerRegistry.php` | 91 | Integrations: `Service/Outbound/OutboundClientFactory`, `OutboundHttpClient` (`::outboundKey()`, the registry) |
| `lib/Service/Resilience/DependencyUnavailableException.php` | 21 | Integrations: `Service/Outbound/OutboundHttpClient` (catches it) |

### Repair helpers (146 lines, 13 references)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Repair/AdminUserIds.php` | 17 | Integrations: `Command/TablesSyncCommand` |
| `lib/Repair/AppRegisterDescriptor.php` | 32 | Processes: `Repair/SeedFlowTimerRegister` |
| `lib/Repair/SchemaListing.php` | 21 | Core: `Repair/MarkImplicitCrossOrganisationReadRules` |
| `lib/Repair/Support/ApprovalTaskStores.php` | 16 | Processes: `Repair/MigrateApprovalChainsToTasks`, `SeedTaskFixtures` |
| `lib/Repair/Support/ChainMigrationRun.php` | 10 | Processes: `Repair/MigrateApprovalChainsToTasks` |
| `lib/Repair/Support/EveryFlow.php` | 25 | Processes: `Repair/BackfillFlowSemanticVersions`, `BackfillFlowTriggerIndex`, `BackfillFlowVersions` |
| `lib/Repair/Support/RepairPlatform.php` | 14 | Processes: `Repair/MigrateApprovalChainsToTasks`, `SeedTaskFixtures`; Compliance: `Repair/RechainAuditTrailForFlowAttribution` |
| `lib/Repair/Support/SequenceMigrationRun.php` | 11 | Processes: `Repair/MigrateApprovalChainsToTasks` |

### Action check and API-call recording (102 lines, 3 references)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Service/OpenRegisterActionAuthService.php` | 16 | Processes: `Service/Flow/FlowAccess` (`can`); Core: `Service/Object/CorrectionService` (`requireAction`) |
| `lib/Service/ApiCaller/ApiCallRecorder.php` | 86 | Core: `Service/Schema/SchemaChangeNoticeService` (`isEnabled` only) |

### Chat feedback (31 lines, 1 reference)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Db/Feedback.php` | 31 | Notifications: `Db/FeedbackMapperInterface` (the interface of Platform's own `FeedbackMapper`) |

### Language and translations (148 lines, 34 references)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Db/TranslationSlot.php` | 11 | Core: `Service/Object/RenderObject`, `SaveObject/OutdatedTranslations`; Processes: `Controller/TranslationController`, `Service/BulkTranslationService`, `Service/PropertyTranslation`; Configuration: `Db/TranslationMapper`, `Service/TranslationProjectionService`, `TranslationStatusService` |
| `lib/Db/TranslationEdit.php` | 31 | Processes: `BulkTranslationService`; Configuration: `TranslationMapper`, `TranslationProjectionService`, `TranslationStatusService` |
| `lib/Db/TranslationFilter.php` | 24 | Processes: `TranslationController`; Configuration: `TranslationMapper`, `TranslationSearch`, `TranslationStatusService` |
| `lib/Db/TranslationMapperInterface.php` | 19 | Core: `Controller/Support/TranslationMappers`, `Service/Object/RenderTranslations`; Processes: `TranslationController`; Configuration: `TranslationMapper` (implements), `TranslationStatusService` |
| `lib/Db/TranslationBackfillScope.php` | 11 | Configuration: `TranslationMapper` |
| `lib/Db/TranslationSourceLanguageBackfillInterface.php` | 7 | Integrations: `Command/BackfillTranslationSourceLanguageCommand`; Configuration: `TranslationMapper` (implements) |
| `lib/Service/LanguageServiceInterface.php` | 14 | Core: `Db/MagicMapper`, `Db/MagicMapper/MagicSearchHandler`; Configuration: `LanguageService` (implements) |
| `lib/Service/TranslationStatusServiceInterface.php` | 13 | Core: `Controller/Support/TranslationControllerServices`; Processes: `TranslationController`; Configuration: `TranslationStatusService` (implements) |
| `lib/Service/TranslationProjectionServiceInterface.php` | 6 | Processes: `BackgroundJob/TranslationProjectionJob`; Configuration: `TranslationProjectionService` (implements) |
| `lib/Service/TranslationStores.php` | 12 | Processes: `BulkTranslationService`; Configuration: `TranslationProjectionService` |

Totals: 223 + 214 + 112 + 146 + 102 + 31 + 148 = 976 lines in 26 files, 65 references.

## 2. What outside callers actually need

| # | Capability | Called today | Callers |
|---|---|---|---|
| C1 | The app id | `Application::APP_ID` | Core and Compliance controllers' `parent::__construct()` |
| C2 | The delegated admin-setting id | `OpenRegisterAdmin::class` as an attribute argument | six controllers in five areas |
| C3 | Push object changes to notify_push | the listener class (registration, binding of `PresencePushInterface`), `setBatchMode`, `hasBatchedCollections`, `addBatchedCollection`, `flushBatch` | Core bootstrap and batch adapter, Processes listener registrar |
| C4 | Break the circuit on a failing outbound dependency | `BreakerRegistry::outboundKey()`, the registry, `DependencyUnavailableException` | Integrations' outbound HTTP client |
| C5 | Shared repair-step plumbing | `AdminUserIds::of`, `AppRegisterDescriptor::read/import`, `SchemaListing::allOrSkip`, four constructor bags | repair steps of Core, Compliance, Integrations, Processes |
| C6 | Whether a user may perform a named action | `can(IUser, string)`, `requireAction(IUser, string)` | Processes `FlowAccess`, Core `CorrectionService` |
| C7 | Whether API calls are recorded | `ApiCallRecorder::isEnabled()` | Core `SchemaChangeNoticeService` |
| C8 | The served request language | `getPreferredLanguage`, `getAcceptedLanguages`, `getTargetLanguage`, `shouldReturnAllTranslations`, `resolveLanguageForRegister`, `setFallbackUsed` (the last four on the concrete `LanguageService` today) | Core `MagicMapper`, `MagicSearchHandler`, `TranslationHandler`, `AggregationRunner`, `RenderObject` via `RenderTranslations` |
| C9 | Stored object translations and their status | mapper `findOne`, `findByObject`, `upsert`, `getDominantSourceLanguage`; status `setStatus`, `completenessForObject`, `markDerivedTranslationsOutdated`, `search`, `searchWithSourceValues`; the values `TranslationSlot`, `TranslationEdit`, `TranslationFilter`, `TranslationSearch`; `Translation::getStatus`, `STATUS_DRAFT` | Core render and save path, Processes translation controller and bulk translation, Platform's `LanguageMiddleware` |
| C10 | Translation projection and the source-language back-fill | `TranslationProjectionInterface::project` (through Platform's extending interface), `backfillSourceLanguage`, `countMissingSourceLanguage` | Processes projection job, Integrations back-fill command |

C1, C2 and C3-C5 are not capabilities Platform should offer: they are ids that already live in Kernel, and
work that belongs to a lower area. C6-C9 are capabilities a lower area needs; by the order the lower area owns
the port. C10 is already served by a Processes port, or is Configuration's own work.

## 3. The proposed contract set

### 3.1 Ports in `lib/Kernel/Contracts/Platform/`

None. No area may depend on Platform, so a Platform-owned port would itself be a reference against the order.

### 3.2 Ports a lower area owns, in `lib/Kernel/Contracts/Core/`

Core ranks lowest among the callers, so Core owns these ports; the implementing area binds them in its own
registrar, as with the presence and action-rights ports (#10675, #10688). Signatures one line each, importing
only what they use; line counts measured on drafts with the scorer's line counter. These lines count to Core.

| Port | Methods and constants | Implemented by | Bound by | Lines |
|---|---|---|---|---|
| `ActionCheckInterface` (exists, widened) | `can(IUser $user, string $action): bool`; new `requireAction(IUser $user, string $action): void` (throws `OCSForbiddenException`, as today) | `OpenRegisterActionAuthService` (already, through `GenericActionAuthService`) | `PlatformServiceBindings` (already) | 7 → 8 |
| `ApiCallRecordingInterface` (new) | `isEnabled(): bool` | `ApiCallRecorder` | `PlatformServiceBindings` | 6 |
| `ServedLanguageInterface` (new, C8) | `getPreferredLanguage(): string`; `getAcceptedLanguages(): array`; `getTargetLanguage(): ?string`; `shouldReturnAllTranslations(): bool`; `resolveLanguageForRegister(array $registerLanguages): string`; `setFallbackUsed(bool $fallback): void` | `LanguageService` (Configuration); `LanguageServiceInterface` extends the port and keeps only the negotiation setters and the static header parser | Configuration's `ContractBindings`, as an alias of `LanguageService::class` | 11 |
| `ObjectTranslationsInterface` (new, C9) | `findOne(TranslationSlot $slot): ?TranslationRecordInterface`; `findByObject(string $objectUuid): array`; `dominantSourceLanguage(string $objectUuid): ?string`; `upsert(TranslationSlot $slot, TranslationEdit $edit): TranslationRecordInterface`; `setStatus(TranslationSlot $slot, string $status): TranslationRecordInterface`; `markDerivedOutdated(TranslationSlot $source): int`; `completenessForObject(string $objectUuid, Schema $schema): array`; `search(TranslationSearch $search): array`; `searchWithSourceValues(TranslationSearch $search): array` | new `Service/ObjectTranslations` (Configuration) delegating to `TranslationMapper` and `TranslationStatusService` | Configuration's `ContractBindings` | 18 |
| `TranslationRecordInterface` (new, the entity's Kernel-side contract, rule 4) | `STATUS_DRAFT`, `STATUS_OUTDATED`; `getObjectUuid()`, `getProperty()`, `getLanguage()`, `getValue()`, `getStatus()`, `getSourceLanguage()`, all `: ?string`; extends `JsonSerializable` | `Db/Translation` (Configuration); the entity's getters are magic, so it gains six explicit one-line getters | n/a | 14 |

`ServedLanguageInterface` is a read port of six of the fourteen public methods of `LanguageService`, the six
Core calls; it is not a copy of the class. The writers (Platform's `LanguageMiddleware`, three fleet apps)
keep `LanguageServiceInterface` / `LanguageService`, which is downward for all of them.

`ObjectTranslationsInterface` covers one capability over two Configuration classes (nine of their public
methods). `findObjectsMissingLanguage`, `getCompletenessByObject`, the back-fill and `deleteByObject`
stay Configuration-internal: no caller below Configuration uses them.

### 3.3 Values that cross the boundary

| Value | Today | Decision |
|---|---|---|
| `Db/TranslationSlot` | Platform, 11 | Counts to Core: the key of one object property in one language, carried by the Core port and constructed by Core's render and save path. Pure value, no dependencies. |
| `Db/TranslationEdit` | Platform, 31 | Counts to Core as a pure value. Its `applyTo(Translation)` (the entity setters and the draft default) moves into `TranslationMapper::upsert()` as a private step, so the value no longer names the Configuration entity (about 12 lines left). Its only caller is `TranslationMapper`; a golden test pins `upsert()` status and translator handling first. |
| `Db/TranslationFilter` | Platform, 24 | Counts to Core; `equalities()` reads `TranslationRecordInterface::STATUS_OUTDATED` instead of `Translation::STATUS_OUTDATED` (same string). |
| `Service/TranslationSearch` | Configuration, 13 | Counts to Core: the search request the port's two search methods take; it only holds a `TranslationFilter` and paging. |
| `Db/Translation` | Configuration, 51 | Stays in Configuration; crosses only as `TranslationRecordInterface`. |
| `Db/Feedback` | Platform, 31 | Stays in Platform; the Notifications design (N1a) gives `FeedbackMapperInterface` its Platform row, so the reference becomes internal. |

### 3.4 Classes that count to another area (rule 3) and wiring (rule 5)

None of these files moves; their csv row follows the work they do.

| Files | Today | Counts to | Why it is that area's work |
|---|---|---|---|
| `Listener/NotifyPushListener`, `NotifyPushRun`; `Listener/NotifyPushBatch`; `Listener/Support/NotifyPushPlatform`, `PushSlugResolver` | Platform; Core; Processes | Notifications | Real-time push of object changes to notify_push. The channel names it pushes on (`Push/PushChannels`, `PushEvents`) and the in-app push (`NcNotificationSender::enqueuePush`) are Notifications; the listener uses only Kernel events, Core readers and `PushChannels`; the batch adapter and the two support bags have the listener as their only user. |
| `Service/Resilience/BreakerRegistry`, `CircuitBreaker`, `BreakerThresholds`, `DependencyUnavailableException` | Platform | Integrations | Circuit breakers on outbound dependencies: the only consumer is Integrations' `OutboundHttpClient` (through `OutboundClientFactory`), the keys are outbound host names. Platform's `LoadSheddingMiddleware` turns the exception into a 503, which is downward. |
| `Repair/AdminUserIds` | Platform | Integrations | The admin uids Nextcloud Tables shares are made for; its users are `TablesSyncCommand` (Integrations) and the Tables seeding repair step. |
| `Repair/AppRegisterDescriptor` | Platform | Integrations | Reads and imports an app's register descriptor through `RegisterDescriptorImport` (Integrations), its only dependency. |
| `Repair/SchemaListing` | Platform | Core | "All schemas, or skip the step": one call on the Kernel `SchemaMapperInterface`; used by a Core and a Platform repair step. |
| `Repair/Support/RepairPlatform` | Platform | Core | A constructor bag of three Nextcloud services (`IDBConnection`, `IAppConfig`, logger) for repair steps of Processes and Compliance: shared plumbing, the repair-step counterpart of Core's controller toolkit. |
| `Repair/Support/ApprovalTaskStores`, `ChainMigrationRun`, `EveryFlow` | Platform | Processes | Constructor bags and run values of Processes' task and flow migrations; they name only Processes mappers and entities. |
| `Repair/Support/SequenceMigrationRun` | Platform | Processes, **after** `Db/TaskSequence` | Same as above, but it names `Db/TaskSequence`, filed under Configuration. Rowing it now adds a Processes-to-Configuration reference; it follows `TaskSequence` when the Processes or Configuration design settles that entity (it is probably Processes' work). |
| `Db/TranslationMapperInterface`, `TranslationBackfillScope`, `TranslationSourceLanguageBackfillInterface`; `Service/LanguageServiceInterface`, `TranslationStatusServiceInterface`, `TranslationProjectionServiceInterface`, `TranslationStores`; `Command/BackfillTranslationSourceLanguageCommand` | Platform; Integrations (the command) | Configuration | After PL5 their only users are Configuration's `TranslationMapper`, `TranslationStatusService`, `TranslationProjectionService`, `LanguageService` and `ObjectTranslations`, and Platform's middleware (downward). The back-fill command does nothing but call `TranslationMapper`'s back-fill; it has no integration in it. |

Wiring:

- `AppInfo/Application` stops building `OutboundClientFactory` with `BreakerRegistry`: the `IClientService`
  registration moves verbatim (server-container lookup included, see its recursion comment) into Integrations'
  `IntegrationWiring`, called from `IntegrationRegistrar::register()`, which `Application` already calls.
- `Listener/ListenerBootstrap` (Core) stops binding `PresencePushInterface`, and `Listener/ListenerRegistrar`
  (Processes) stops binding `NotifyPushBatchInterface`; `NotificationsWiring` binds both. The three
  `NotifyPushListener` registrations in `ListenerRegistrar::registerPushAndCleanupListeners()` become a public
  static slot `NotificationsListenerRegistrar::registerObjectPush()`, called at exactly that position, so
  `listener-order.json` and `ListenerRegistrationOrderGoldenTest` do not change.
- `Db/PlatformMapperBindings` stops binding Configuration's `TranslationMapper` (two entries) and
  `Service/PlatformServiceBindings` stops binding `LanguageService`, `TranslationStatusService` and
  `TranslationProjectionService`; `ConfigurationMapperBindings` and `ContractBindings` bind them. Platform then
  binds only Platform classes.
- `Service/ServiceRegistrar` (Core) registers the request-scoped `LanguageService` singleton today. The
  registration moves to Configuration's `ConfigurationServiceRegistrar` under the same id
  (`LanguageService::class`), and `ServedLanguageInterface` and `LanguageServiceInterface` alias that id, so the
  middleware's writes and Core's reads stay on one instance. Three fleet apps resolve the class name from the
  container (section 6); the id must not change.

## 4. The budget

| Capability / group | Before | After | How |
|---|---|---|---|
| C1 App id | 162 | 0 | `AppIdentity::APP_ID` (Kernel, same value) |
| C2 Admin-setting id | 61 | 0 | `AdminSettingDelegation::OPEN_REGISTER_ADMIN` (Kernel, same string, already pinned by a test) |
| C3 Push | 214 | 0 | rows to Notifications, bindings and a positioned slot |
| C4 Circuit breakers | 112 | 0 | rows to Integrations |
| C5 Repair helpers | 146 | 11 → 0 | rows; `SequenceMigrationRun` follows `TaskSequence` |
| C6 Action check | 16 | 0 | callers type `ActionCheckInterface` |
| C7 API-call recording | 86 | 0 | `ApiCallRecordingInterface` |
| Chat feedback | 31 | 0 | the Notifications design's N1a row |
| C8-C10 Language and translations | 148 | 0 | Core ports, values to Core, interfaces to Configuration |
| **Total** | **976** | **0** (11 interim) | |

Exposure that lands elsewhere, for the owners of those designs:

| Area | Lines | What |
|---|---|---|
| Core | about +145 | ports: `ApiCallRecordingInterface` 6, `ServedLanguageInterface` 11, `ObjectTranslationsInterface` 18, `TranslationRecordInterface` 14, `ActionCheckInterface` +1; values: `TranslationSlot` 11, `TranslationEdit` about 12, `TranslationFilter` 24, `TranslationSearch` 13; repair helpers `SchemaListing` 21 (named by a Platform repair step), `RepairPlatform` 14 (Processes, Compliance) |
| Integrations | about +70, −22 | `AppRegisterDescriptor` 32 (two Platform seeders), `DependencyUnavailableException` 21 (`LoadSheddingMiddleware`), `AdminUserIds` 17 (`SeedTablesVirtualSchemas`); `OutboundClientFactory` (22) is no longer named by `Application` |
| Processes | +25, −51 | `EveryFlow` (named by Platform's `TypePerformerReferences`); `PushSlugResolver` and `NotifyPushPlatform`, today exposed only because Platform names them, leave with the push rows |
| Configuration | +14, −374 | `LanguageServiceInterface` (Platform's middleware writes through it); `TranslationMapper` (230) and `LanguageCoverageCheck` (11) are no longer named outside Configuration once the bindings move, `TranslationStatusService` (120) once Core uses the port, `TranslationSearch` (13) counts to Core, and `TranslationMapperInterface`'s consumers are all Configuration |
| Notifications | about +4, −7 | the `registerObjectPush()` slot in the already exposed `NotificationsListenerRegistrar`; `PushChannels` becomes internal |
| Kernel | 0 | no new Kernel file |

Net effect on the whole app: about −976 (Platform) +145 (Core) +48 (Integrations) −26 (Processes)
−360 (Configuration) −3 (Notifications), about −1,170 exposed lines, and 65 references against the order fewer,
plus about ten Core- and Processes-to-Configuration references about translations (section 5, PL5b-c).

Nothing in this budget fits only by breaking a rule. Two places deserve a reviewer's look:

- **`RepairPlatform` and `SchemaListing` to Core.** Both are generic Nextcloud plumbing for repair steps of
  several areas. If the Core design prefers not to take them, the fallback is a copy per user area: three bags
  of three fields, which the duplication gate tolerates (under six lines each).
- **The translation values to Core.** They are rowed because the Core-owned port carries them in its
  signatures and Core constructs `TranslationSlot` itself. `TranslationEdit` is a pure value only after
  `applyTo()` moves into the mapper; without that step it would name the Configuration entity from Core.

## 5. The build packages

Dependency order. File lists are disjoint between packages; each PR stays at or under about 15 files. Every PR
adds or retargets unit tests for each class it changes, and must show no new entry in
`modularity_check.py --check` and `deptrac.sh areas --check`. "csv" means `scripts/component-map.csv` plus the
regenerated components file and deptrac areas file; packages that touch it are stacked, never in parallel.

### PL1: Kernel ids (1 PR, no csv)

`lib/Controller/SourcesController.php`, `TransferController.php` (`AppIdentity::APP_ID`);
`lib/Controller/ActionRightsController.php`, `QualityAuditController.php`, `RulesController.php`,
`SetupController.php`, `RegisterDescriptorController.php`, `lib/Notifications/Controller/TimelineAdminController.php`
(`AdminSettingDelegation::OPEN_REGISTER_ADMIN`). Test: an architecture test that reads every
`#[AuthorizedAdminSetting]` by reflection and asserts its `settings` equals `OpenRegisterAdmin::class`, so the
delegation keeps matching. Removes 8 references and 223 lines.

### PL2: rows and outbound wiring (2 PRs)

- **PL2a, rows only (csv):** `Repair/AdminUserIds`, `AppRegisterDescriptor` → Integrations; `Repair/SchemaListing`,
  `Repair/Support/RepairPlatform` → Core; `Repair/Support/ApprovalTaskStores`, `ChainMigrationRun`, `EveryFlow`
  → Processes; `Service/Resilience/*` (4 files) → Integrations. Each target names only its new area or lower,
  so no new reference against the order. Removes 15 references and 247 lines (`SequenceMigrationRun` is left).
- **PL2b, outbound client wiring (after PL2a):** `lib/AppInfo/Application.php`,
  `lib/Service/Integration/IntegrationWiring.php`, `lib/Service/Integration/IntegrationRegistrar.php` (only if
  the call needs it), the `Application` registration test. Behaviour: same closure, same server-container
  lookup. Lowers Integrations' exposure (`BreakerRegistry` and `OutboundClientFactory` no longer named by
  Platform).

### PL3: push to Notifications (1 PR, after PL2a; stacked on the Notifications design's N2)

csv (the five push rows), `lib/Listener/ListenerBootstrap.php`, `lib/Listener/ListenerRegistrar.php`,
`lib/Notifications/NotificationsWiring.php`,
`lib/Notifications/Listener/Registrar/NotificationsListenerRegistrar.php`, the binding tests in
`tests/Unit/Architecture` that list these bindings, the `ListenerBootstrap` and registrar tests.
`ListenerRegistrar` and `NotificationsListenerRegistrar` are also N2's files, so PL3 is stacked on N2 (or
folded into it by the coordinator). `listener-order.json` and the golden test stay unchanged. Removes
3 references and 214 lines.

### PL4: action check and API-call recording (1 PR)

`lib/Kernel/Contracts/Core/ActionCheckInterface.php` (adds `requireAction`), new
`lib/Kernel/Contracts/Core/ApiCallRecordingInterface.php`, `lib/Service/ApiCaller/ApiCallRecorder.php`
(implements it), `lib/Service/PlatformServiceBindings.php`, `lib/Service/Flow/FlowAccess.php`,
`lib/Service/Object/CorrectionService.php`, `lib/Service/Schema/SchemaChangeNoticeService.php`, tests of those
three callers retargeted to the ports. New exposed file: `ApiCallRecordingInterface` (Core, deliberate).
Removes 3 references and 102 lines.

### PL5: language and translations (4 PRs, stacked, after PL2a)

- **PL5a, ports, implementation, values (csv):** new `lib/Kernel/Contracts/Core/ServedLanguageInterface.php`,
  `ObjectTranslationsInterface.php`, `TranslationRecordInterface.php`; new `lib/Service/ObjectTranslations.php`
  (csv row Configuration); `lib/Service/LanguageServiceInterface.php` (extends the port);
  `lib/Db/Translation.php` (implements the record, explicit getters); `lib/Db/TranslationEdit.php` (pure value),
  `lib/Db/TranslationMapper.php` (takes `applyTo` in), `lib/Db/TranslationFilter.php` (record constant);
  `lib/Service/ContractBindings.php` (binds the two new ports); csv rows `TranslationSlot`, `TranslationEdit`,
  `TranslationFilter`, `TranslationSearch` → Core. Golden test on `TranslationMapper::upsert()` first. 11 files
  plus tests. Removes 16 references (every reference to the three values) and 66 lines.
- **PL5b, read side:** `lib/Service/Object/RenderTranslations.php`, `RenderObject.php` (record interface,
  `findOne` through the port), `TranslationHandler.php`, `lib/Service/Aggregation/AggregationRunner.php`,
  `lib/Db/MagicMapper.php` (resolves `ServedLanguageInterface`, still once per mapper),
  `lib/Db/MagicMapper/MagicSearchHandler.php`, `lib/Middleware/LanguageMiddleware.php`
  (`dominantSourceLanguage` through the port), tests. Removes 3 references against the order to Platform and
  the Core-to-Configuration references to `LanguageService`, `TranslationStatusService` and `Translation` in
  these files.
- **PL5c, write and editing side:** `lib/Service/Object/SaveObject/OutdatedTranslations.php`,
  `SaveObjectValueHandlers.php`, `lib/Service/Object/SaveObject.php` (field type only),
  `lib/Controller/Support/TranslationMappers.php`, `TranslationControllerServices.php`,
  `lib/Controller/TranslationController.php`, `lib/Service/BulkTranslationService.php` (port and
  `TranslationHandlerInterface` instead of `TranslationStores`), `lib/BackgroundJob/TranslationProjectionJob.php`
  (Processes' own `TranslationProjectionInterface`), tests. Removes 6 references to Platform and the remaining
  Core-to-Configuration `TranslationStatusService` references. No new I/O on save: the same service call through
  the port.
- **PL5d, rows and bindings (csv):** the Configuration rows of 3.4 (seven interfaces and values and the
  back-fill command), `lib/Db/PlatformMapperBindings.php`, `lib/Db/ConfigurationMapperBindings.php`,
  `lib/Service/PlatformServiceBindings.php`, `lib/Service/ContractBindings.php`,
  `lib/Service/ServiceRegistrar.php`, `lib/Service/Configuration/ConfigurationServiceRegistrar.php` (the
  `LanguageService` singleton), the binding tests in `tests/Unit/Architecture`. Removes the last 9 references.
  `ContractBindings.php` is also in PL5a: PL5d is stacked on it.

### Later: `SequenceMigrationRun`

One csv row, with or after the change that settles `Db/TaskSequence`. Removes the last reference and 11 lines.

## 6. Fleet impact

`fleet_checkout.sh` on the development branches of the 19 fleet apps (19 Nov 2026), then `fleet_surface.py`
(6,873 references from 19 apps to 433 OpenRegister classes).

| Class | Apps | Effect of this design |
|---|---|---|
| any Platform class (`AppInfo\Application`, `Settings\*`, `Listener\NotifyPushListener`, `Service\Resilience\*`, `Repair\*`, `Service\ApiCaller\*`, `Service\OpenRegisterActionAuthService`, the translation interfaces and values) | none | free to re-row and retarget |
| `Service\LanguageService` (Configuration, concrete) | filinq (constructor-injected in its language middleware: setters, `parseAcceptLanguageHeader`), opencatalogi (resolved by class-name string: setters, `getPreferredLanguage`, `isFallbackUsed`), pipelinq | unchanged name and container id; it implements `ServedLanguageInterface` through `LanguageServiceInterface`. The singleton must stay registered under `LanguageService::class` (3.4, wiring). **Candidate for promotion to `lib/Contract`:** request-language negotiation (`ServedLanguageInterface` plus the setters), which three apps drive today through the concrete class |
| `AppHost\Service\GenericActionAuthService` (Integrations) | integriq, learniq | unchanged; `OpenRegisterActionAuthService` keeps extending it |

Nothing other apps use is renamed or removed. The promotion is named, not done (ADR-119 Decision 14).

## Platform as the caller

Not exposure of Platform, but for the owners of the other designs: Platform makes 283 references to lower
areas, and 85 files (9,114 lines) are exposed only because Platform names them: Core 6,657 (the three
controllers a Platform middleware checks by `instanceof`, 5,798, already in the Core design's marker group),
Configuration 1,023 (`ConfigurationServiceRegistrar` 390, `TranslationMapper` 230, the seed importer and
report 330), Integrations 517, Search 444 (the Search design's provider and scopes), Processes 327, Files 98,
Notifications 27, Compliance 21. This design takes 321 of them out (`TranslationMapper`, `LanguageCoverageCheck`,
`OutboundClientFactory`, the two push bags, `PushChannels`); the rest are wiring the entry point calls
(registrars, thin by rule 5) or other designs' work.

## Findings

- **The top area's contract is empty, and that is the target.** Platform reaches 0 exposed lines without a
  single Platform-owned port. Any future need from below must become a port of the lower area, never a file in
  Platform; `modularity_check.py` reports a new Platform exposure as a reference against the order anyway.
- **`SequenceMigrationRun` waits for `TaskSequence`.** Rowing it now trades a Processes-to-Platform reference
  for a Processes-to-Configuration one, which `--check` refuses as new. It moves with the entity.
- **Translations are still split between Configuration and Processes.** The editing controller and bulk
  translation are Processes, storage, status, projection and providers are Configuration. With the Core port
  in place the split is harmless for the order; whether the editing side belongs with storage is the Processes
  and Configuration designs' call, not needed here.
- **Left to the Core design:** Core's save path still names Configuration's `TranslationProjectionService`
  (`resolveSourceLanguage` with Configuration's `SourceLanguageLookup`, and `project`/`purge` through
  `SaveObject`), and `Service/Schemas/PropertyTitles` calls the static `LanguageService::parseAcceptLanguageHeader`.
  Both are Core-to-Configuration references outside Platform's exposure; a `sourceLanguageFor()` on
  `ObjectTranslationsInterface` and a static-free header parser in Core would close them.
- **`Translation` getters.** The entity's getters are magic (`__call`); implementing `TranslationRecordInterface`
  needs six explicit getters. Behaviour is the same; the `jsonSerialize()` output is pinned before PL5a.
- **One request singleton.** The language port is only correct if every id resolves to the same
  `LanguageService` instance; a test in PL5d asserts that `ServedLanguageInterface`, `LanguageServiceInterface`
  and `LanguageService::class` return one object.
