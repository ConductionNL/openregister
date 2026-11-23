# Contract design: the Core area

Wave 5 design package D02. Core is rank 1 in `area-order.json`: every area above it may use it, so
its exposure is the largest of all areas and its contract set decides most of the wave 5 budget. This file
proposes the narrow set of contracts other areas use instead of Core's classes, and cuts the caller changes
into build packages. It changes nothing in `lib/`.

Measured on `origin/main` `e918c58d` with `modularity_check.py` (the module graph of
the maintainability checker), `analyse_dependencies.py` and `fleet_surface.py`
over fresh `fleet_checkout.sh` checkouts of the 19 fleet apps (development branches, 2026-11-19).

| | Today | After this design |
|---|---|---|
| Core files another area names | 806 | 176 |
| Their lines (counted as `modularity_check.py` counts them) | 64,730 | ≈ 3,976 |
| All exposed lines in `lib/` (every area) | 158,870 | ≈ 96,900 |
| References against the order, total / from Core / to Core | 951 / 559 / 19 | 830 / 427 / 19 (no new pair from C5-01 to C5-11; C5-12 flagged in §5) |

The "after" column is a model, not a promise: the measured graph is replayed with each proposed change applied
(wiring moved, rows moved, callers redirected to the ports, value objects kept) and re-measured with the same
code paths `modularity_check.py` uses. How it is built is in "How the numbers were made" at the end.

## 1. The exposed files of Core today

806 Core files (64,730 lines) are named by another area. The full list, with lines, outside callers per area and
the treatment this design gives each file, is Appendix A. Grouped by **why** the file is exposed:

| Why exposed | Files | Lines | Share |
|---|---|---|---|
| W: named only by another area's registrar or binding map | 273 | 24,281 | 37.5% |
| P: named by callers that need one capability (redirected to a port) | 151 | 12,115 | 18.7% |
| I: a concrete class named where a Core interface already exists | 41 | 11,763 | 18.2% |
| M: a controller named only by a Platform middleware's `instanceof` | 3 | 5,798 | 9.0% |
| R: the file does the work of the one area that uses it (csv row) | 180 | 4,880 | 7.5% |
| K: kept (value objects, Db toolkit, job base, context, Kernel-caused) | 131 | 3,105 | 4.8% |
| F: file storage (`lib/Service/File/*`, `FileService`), decided to be Files' work | 27 | 2,788 | 4.3% |

Outside callers per area (a file counts once per area that names it):

| Calling area | Core files it names | Their lines |
|---|---|---|
| Configuration | 469 | 44,497 |
| Integrations | 174 | 10,741 |
| Processes | 153 | 9,015 |
| Compliance | 122 | 7,988 |
| Platform | 61 | 7,253 |
| Files | 52 | 2,738 |
| Search | 52 | 2,191 |
| Notifications | 14 | 331 |
| Kernel (against the order) | 16 | 1,115 |

Configuration's number is almost entirely one file: `lib/Service/ContractBindings.php` (csv row
Configuration) names 362 Core files, 40,597 lines, 29,154 of them exposed by nothing else. It is the single
largest item in the whole wave.

The biggest single files exposed today and what exposes them:

| File | Lines | Exposed by | Treatment |
|---|---|---|---|
| `Controller/ObjectsController.php` | 3,325 | `Middleware/SchemaChangeHeaderMiddleware` `instanceof` | M |
| `Service/ObjectService.php` | 3,010 | 4 Integrations + 2 Configuration files: class-string container lookups and `instanceof` | P (Kernel `ObjectOperationsInterface`) |
| `Service/Aggregation/AggregationRunner.php` | 2,378 | Processes `AggregateReferenceResolver` constructor type | I, then P `AggregationInterface` |
| `Service/Object/RenderObject.php` | 2,325 | Compliance `HistoryEntryRedactor`: `RenderObject::METADATA_COPIES` | I, then P `ObjectRenderingInterface` |
| `Controller/SchemasController.php` | 1,600 | `Middleware/AnonymousEnvelopeMiddleware` `instanceof` | M |
| `Service/Object/CacheHandler.php` | 1,248 | Configuration `CacheSettingsHandler` | I (Kernel `ObjectNameCacheInterface`) |
| `Service/OrganisationService.php` | 957 | Configuration `BackfillSystemOwnerCommand` | I, then P `TenancyInterface` |
| `Service/Object/ValidateObject.php` | 894 | Configuration ×2, Integrations ×1 | I, then P `ObjectWriterInterface` |
| `Controller/RegistersController.php` | 873 | `Middleware/AnonymousEnvelopeMiddleware` `instanceof` | M |
| `Service/FileService.php` | 697 | Configuration ×2, Integrations ×2 (class strings) | F |

## 2. What outside callers actually need

Read from the caller files (comments stripped): the methods called on a variable or property typed as the Core
class, its constants and static calls, `new`, `instanceof`, trait use and class strings. The per-file symbol
list is summarised here, grouped into capabilities:

| Capability | What callers use | Today's files (examples) | Outside callers |
|---|---|---|---|
| Read objects | `find`, `findMultiple`, `findAcrossAllSources`, `findBySchema`, `findInRegisterSchemaTable`, `findAllInRegisterSchemaTable`, `searchObjects`, `findByLinkedEntity`, read-state `markSeen`/`uuidsForUser`, `getTranslatableProperties` | `MagicMapperInterface` (62 callers, 31× `find`), `MagicObjectFinderInterface`, `MagicTableRowReaderInterface`, `GetObject`, `RelationHandler` | 106 files, all areas |
| Write objects | `update` (16×), `saveObject`, `validateObject`, `releaseRunLocks`, `recompute`, bulk-action `register`/`describe` | `MagicMapperInterface`, `SaveObjectWriterInterface`, `ObjectValidatorInterface` | 89 |
| Object tables | `ensureTableForRegisterSchema`, `tableExistsForRegisterSchema`, `getTableNameForRegisterSchema`, `listMagicTableNames`, column inspect/heal/widen, `TABLE_PREFIX`, table reconcile | `MagicMapperInterface`, `MagicColumnDriftInterface`, `TablesSchemaReconcilerInterface`, `MigrationServiceInterface` | 80 (mostly Configuration repair commands and Compliance) |
| Drafts | own draft lookup, draft owner, promotion policy, `create` | `DraftFinder`, `DraftOwner`, `DraftStatusPolicy`, `DraftService` | 7 (Processes) |
| Object access checks | `hasPermission` (10×), `checkPermission`, `resolveAuthorization`, `hasGroupPermission`, `getReadableByUsers`, `maySummariseValues`, file write guard | `PermissionHandlerInterface` (22 callers), `PermissionCheckInterface`, `ObjectScopeResolverInterface` | 57 |
| Property access | `filterReadableProperties`, `stripWriteOnlyProperties`, state-field rules `getReadOnly`/`getHidden`/`getRequired` | `PropertyRbacHandlerInterface`, `StateFieldRulesInterface` | 11 (mostly Search) |
| Grants and sharing | claim/assign/reassign owner, grant/revoke/list shares, grant resolution, special rights `mayUse`, `mayManage`, permission catalogue `verbs`, token-grant `refusalFor`, share lifetime | `ObjectOwnershipService(Interface)`, `ObjectSharingService(Interface)`, `ObjectGrantResolver`, `TokenGrantValidator` | 26 |
| Viewer reach | `currentUid`, `reachOf`, `reaches`, `refusedFields`, `assertMayNameRecipients`, envelope `redact` | `ViewerReachCheckInterface`, `ViewerReachResolverInterface`, `AnonymousEnvelopeInterface` | 11 |
| Explicit reveal | `mask`, `maskAll`, `isExplicit`, reveal audit `run`, `flush` | `ExplicitReveal`, `ExplicitRevealMasks`, `ExplicitRevealTrailMaskerInterface` | 14 |
| Tenancy | `getActiveOrganisation` (16×), `isOrganisationAdmin`, `getManageableOrganisations`, `findByUuid`, `findBySlug`, `findParentChain`, tenant status constants, tenant key rotation | `OrganisationServiceInterface` (26), `OrganisationMapperInterface` (17), `Organisation` entity (8) | 51 + 7 |
| Audit recording | `recordObjectEvent` (13×), `insertAuditTrails`, `createAuditTrail`, `recordExport`, refusal records; reads (`findTrail`, statistics) by Compliance | `AuditTrailMapperInterface` (72 callers, 35 of them Compliance) | 76 |
| Schema evolution | `classify`, `nextVersion`, `recordChange`, change sets, diff/plan/migrate/rollback, revalidate, schema teardown, dedupe, application migration | `SchemaVersioningService`, `SchemaMigrationServiceInterface`, `SchemaDiffService` | 12 |
| Schema lookup | `resolveSchemaWithin`, `resolvePair`, `resolveRegister`, register CRUD, type restore, schema cache clear | `RegisterScopedSchemaResolverInterface`, `RegisterServiceInterface`, `SchemaCacheHandlerInterface` | 8 |
| Object destruction | `refusalFor` (destroy right, retention clock), destruction `preview`, `deleteObject`, retention destruction `run`, `correct` | `DestroyRightService`, `DestructionScopeService`, `RetentionClockService`, `DeleteObject` | 7 (Compliance) |
| Rendering | `RenderObject::METADATA_COPIES`, `withholdRowCopies` | `RenderObject` | 1 (Compliance) |
| Listener deferral | `isDeferralEnabled`, `defer`, deferred entry `resolve` | `ListenerDeferralInterface`, `ListenerDeferralService` | 12 |
| Aggregation | `run`, `runAdhocByRef` | `AggregationRunnerInterface`, `AggregationRunner` | 7 (Search, Processes) |
| Deep links | `resolveUrl`, `resolveIcon`, `registrations`, `add`, `resolveTaskUrl` | `DeepLinkResolverInterface`, `DeepLinkRegistryServiceInterface` | 16 |
| API versions | catalogue `all`/`current`/`inspect`, `negotiate`, `publicCapabilities`, URN slug | `ApiVersion*Interface`, `UrnServiceInterface` | 7 (Platform) |
| Problem details | `build`, `validationFailed`, `CONTENT_TYPE`, request `validate` | `ProblemDetailsBuilderInterface`, `OasRequestValidatorInterface` | 3 (Platform) |
| Request security | trusted client IP, auth rate limit check/record/clear, token fetch | `InboundAuthRateLimitInterface`, `RateLimitResetInterface`, `AuthenticationTokenFetcherInterface` | 5 |
| View alerts | `ViewAlert::declaredIn`, `::parse`, `USER_PREFIX`, `GTE` | `ViewAlert` | 5 |
| Schema declaration checks | annotation blocks, faults, discarded annotations, `SchemaDeclarationOrder` slots, declaration exceptions | `Db/SchemaMapper/*`, `Service/*/…DeclarationException` | 7 (Processes) |
| Settings | `getLLMSettingsOnly`, `getRetentionSettingsOnly` | `SettingsServiceInterface` | 9 → Kernel `AppSettingsInterface` (exists) |
| Object name cache | `getMultipleObjectNames`, `getStats`, warm/clear | `CacheHandler(Interface)` | 2 → Kernel `ObjectNameCacheInterface` (exists) |
| Access links | read/publish an access link | `AccessLinkService(Interface)`, `AccessLinkMintGuard` | 3 → Kernel `AccessLinkReaderInterface` (exists) |
| Object service | container lookup by class string, `instanceof ObjectService`, `setRegister` | `ObjectService` | 6 → Kernel `ObjectOperationsInterface` (exists) |

What callers do **not** need is just as telling: no outside caller uses `MagicMapper` itself, `SchemaMapper`,
`RegisterMapper` or `OrganisationMapper` by name inside `lib/` (they arrive through `SchemaCatalogInterface`,
8 lines, 55 callers, which already is the narrow contract and stays). The width comes from interfaces that
copy a whole class (`MagicMapperInterface` 30 methods, `AuditTrailMapperInterface` 30 methods,
`OrganisationServiceInterface` 24 methods) where callers use two or three.

## 3. The proposed contract set

### 3.1 Before any port: four changes that need no new contract

1. **Wiring (W, 24,281 lines).** Core binds its own classes (ADR-119 Decision 12, BRIEF rule 5). A new Core map
   `lib/Service/CoreServiceBindings.php` (csv row Core) takes every `interface => class` entry whose class is
   Core's from `ContractBindings::BINDINGS`, from the Core entries of `Service/Bindings/{Objects,Root,Access,
   Schemas,Feature}Bindings`, `Service/Configuration/ConfigurationServiceRegistrar` and `Files/FilesRegistrar`,
   and is registered by Core's existing `Service/ServiceRegistrar`. Same alias, same shared instance, same
   registration point (`registerServiceAlias` during `register()`), so resolution is unchanged. Entries whose
   class moves area by a row in §3.3 are left where they are until that row lands (then they go to that area's
   map). Core listeners registered by Processes' `Listener/ListenerRegistrar` (16 files) and
   `Listener/Registrar/AccessListenerRegistrar` are registered through one Core file,
   `Listener/CoreListenerRegistrar`, called by `ListenerRegistrar` at each original position, so the order of
   listeners per event is byte-identical; the `listener-order.json` pin and the count and hash in
   `ListenerRegistrationOrderGoldenTest` must not change, and are the proof.
2. **Markers (M, 5,798 lines).** `AnonymousEnvelopeMiddleware` and `SchemaChangeHeaderMiddleware` (Platform)
   only ask "is this one of those controllers". Two empty Core-owned marker interfaces,
   `Kernel/Contracts/Core/EnvelopedDefinitionControllerInterface` (implemented by `RegistersController`,
   `SchemasController`) and `Kernel/Contracts/Core/SchemaChangeReportingControllerInterface` (implemented by
   `ObjectsController`), 4 lines each, replace the `instanceof` targets. The middlewares keep their position,
   their order and their behaviour; no route or controller name changes.
3. **Existing interfaces (I, 11,763 lines).** 41 concrete classes are named where an interface already exists
   and is bound in `ContractBindings`. The caller switches its constructor type or container key to the
   interface (Appendix A names it). Where the caller reads a constant of the concrete class (`RenderObject::
   METADATA_COPIES`, `RelationCascadeHandler::SYSTEM_SCOPE`, `DestructionExecutionJob::DEFAULT_BATCH_SIZE`) the
   constant is declared on the interface and the class constant refers to it.
4. **Kernel contracts that already exist (0 new lines).** `ObjectService` by class string or `instanceof` →
   `Kernel/Contracts/ObjectOperationsInterface`; `SettingsServiceInterface` → `Kernel/Contracts/
   AppSettingsInterface`; `CacheHandlerInterface`, `ObjectCacheMaintenanceInterface` → `Kernel/Contracts/
   ObjectNameCacheInterface`; `AccessLinkServiceInterface`, `AccessLinkMintGuardInterface`,
   `AccessLinkSubjectInterface`, `AccessLinkMapperInterface` → `Kernel/Contracts/AccessLinkReaderInterface`
   (and `AccessLinkInterface`).

### 3.2 The ports

One port per capability in `lib/Kernel/Contracts/Core/` (counted to Core automatically). Each port is
implemented by **one** Core class: either the existing service (when one class already does the whole
capability) or a thin Core facade in the capability's Nextcloud directory that delegates to the existing
services without changing their order, checks or failure behaviour. A facade is not an interface copied method
for method (ADR-119 Decision 13): it exposes the subset callers use, over several classes. Methods take at most
two parameters, using Kernel value objects that exist (`ObjectFindTarget`, `ObjectFindOptions`,
`ObjectSaveOptions`, `RegisterSchemaRef`, `ObjectRef`, `ListQuery`, `GrantQuery`, `ObjectDeletion`) or the
Core request value object the caller already builds (§3.4). Signatures below are written on one line, as the
duplication gate requires; the final parameter names follow the implementing method.

The "lines" column is the port's code lines (what `modularity_check.py` counts), estimated as methods + 6.

| Port (`Kernel/Contracts/Core/…`) | Implemented by | Replaces for outside callers (exposed lines today) | Lines |
|---|---|---|---|
| `ObjectReaderInterface` | `Db/MagicMapper` (+ `ObjectReadStateMapper` read state through `MagicMapper`'s facade method) | `MagicObjectFinderInterface`, `MagicTableRowReaderInterface`, `MagicObjectSearchInterface`, `MagicRelationReaderInterface`, read half of `MagicMapperInterface`, `GetObject`, `RelationHandler`, `RelationLookup`, `ObjectReadersInterface`, `TranslationHandler(Interface)`, `ObjectReadStateMapperInterface`, `ObjectSearchResultFormatterInterface`, `SearchResultDeclarations`, `ObjectFilesInterface` (412 + 516 via I) | 16 |
| `ObjectWriterInterface` | `Service/Object/ObjectWrites` (new facade over `MagicMapper::update`, `SaveObject`, `ValidateObject`, `RunLockRegistry`, `MasterRecomputeService`, `BulkActionRegistry`) | write half of `MagicMapperInterface`, `SaveObjectWriterInterface`, `ObjectValidatorInterface`, `ValidateObjectInterface`, `RunLockReleaseInterface`, `MasterRecomputeInterface`, `ObjectRelationCleanupServiceInterface`, `BulkActionRegistryInterface`, `UserTaskListInterface`, `SharedMasterDataServiceInterface`, `RelationCascadeHandler` (459) | 12 |
| `ObjectTablesInterface` | `Db/MagicMapper` | table half of `MagicMapperInterface`, `MagicColumnDriftInterface`, `MagicUnaccentProbe`, `TablesCatalogueInterface`, `TablesSchemaReconcilerInterface`, `MigrationServiceInterface`, `Command/MagicTable*` option bags, `RegisterRepairOptions` (403) | 17 |
| `ObjectDraftsInterface` | `Service/Object/DraftService` | `DraftFinder`, `DraftOwner`, `DraftStatusPolicy`, `OwnDraftSearch`, `DraftVersion(Mapper)` (571) | 9 |
| `ObjectDestructionInterface` | `Service/Deletion/DestructionServices` (existing bundle, made the facade) | `DestroyRightService`, `DestructionRecorder`, `DestructionScopeService`, `RetentionClockService`, `DeleteObject`, `RetentionDestructionInterface`, `CorrectionServiceInterface`, `CorrectionTargetsInterface` (1,206) | 11 |
| `ObjectRenderingInterface` | `Service/Object/RenderObject` | `RenderObjectInterface` (21, after I: 2,325) | 7 |
| `ObjectAccessInterface` | `Service/Object/PermissionHandler` (facade methods for the file write guard, aggregate visibility, event and transition gates) | `PermissionHandlerInterface`, `PermissionCheckInterface`, `PermissionEnforcementInterface`, `PermissionCacheInterface`, `ObjectReadAccessInterface`, `ObjectScopeResolverInterface`, `AggregateVisibilityInterface`, `FileWriteGuard(Interface)`, `ObjectEventGateInterface`, `ChecksObjectUpdateRight` trait, `SharerFolderAccess`, `DepartmentMatrix{Compiler,Validator}`, `RbacChecks`, `RbacRuleChecks`, `AccessDeclarationValidators`, `AuthorizationRulesTrait` (954) | 14 |
| `PropertyAccessInterface` | `Service/PropertyRbacHandler` | `PropertyRbacHandlerInterface`, `StateFieldRulesInterface`, `StateFieldLists` (69) | 8 |
| `ObjectGrantsInterface` | `Service/Rbac/ObjectGrants` (new facade over ownership, sharing, grant resolver, special rights, definition write rights, permission catalogue, token grants, federation share grantor) | `ObjectOwnershipServiceInterface`, `ObjectSharingServiceInterface`, `ObjectGrantResolverInterface`, `OrganisationSpecialRightsInterface`, `DefinitionWriteRightsInterface`, `PermissionCatalogueInterface`, `SharePrincipalDeriverInterface`, `TokenGrant`, `TokenGrantValidator`, `ExternalGrantLapseNotifierInterface`, `CrossOrganisationMarkerChangeInterface`, `ShareGrantorInterface`, `ShareLifetime`, `FederatedShareMapperInterface`, `SettingsChangeAuditor`, `GroupProvisioner`, `RbacGroupCollector`, `ObjectSharingEndpoint` (1,007 + 845 via I) | 16 |
| `ViewerReachInterface` | `Service/Rbac/ViewerReachResolver` | `ViewerReachCheckInterface`, `ViewerReachResolverInterface`, `ViewShareResolverInterface`, `AnonymousEnvelopeInterface` (48 + 379 via I) | 9 |
| `RevealInterface` | `Service/Rbac/ExplicitReveal` | `ExplicitRevealInterface`, `ExplicitRevealMasks`, `ExplicitRevealTrailMaskerInterface`, `ProcessRevealAuditInterface`, `RevealFlusherInterface` (192) | 8 |
| `TenancyInterface` | `Service/OrganisationService` | `OrganisationServiceInterface`, `OrganisationMapperInterface`, `OrganisationContext`, `TenancyServices`, `TenantLifecycleServiceInterface` (constants), `TenantKeyRotationInterface`, `TenantUsageMapperInterface` (170 + 957 via I) | 14 |
| `OrganisationInterface` | `Db/Organisation` | the `Organisation` entity for readers (288) | 9 |
| `AuditRecorderInterface` | `Db/AuditTrailMapper` (Compliance; binds it in Compliance's own map) | the write half of `AuditTrailMapperInterface` for every area except Compliance; `AuditRowWriter`, `StateHistoryMapperInterface`, `StateHistoryRebuildInterface` (137) | 12 |
| `SchemaEvolutionInterface` | `Service/Schema/SchemaMigrationService` (facade methods for versioning, diff, revalidation, teardown) | `SchemaVersioningService`, `SchemaChangeSet`, `SchemaDiffService`, `SchemaMigrationPlanner`, `SchemaMigrationServiceInterface`, `SchemaRevalidation*`, `SchemaRun*MapperInterface`, `SchemaChangelog(MapperInterface)`, `SchemaTeardownInterface`, `SharedSchemaDedupeServiceInterface`, `SchemaApplicationMigratorInterface` (856 + 245 via I) | 12 |
| `SchemaLookupInterface` | `Service/RegisterScopedSchemaResolver` | `RegisterScopedSchemaResolverInterface`, `RegisterScopedSchemaResolvers`, `RegisterSchemaMappers`, `SchemaTypeConverterInterface`, `SchemaCacheHandlerInterface`, `FacetResultCacheClearerInterface`, `RegisterServiceInterface` (101 + 782 via I) | 9 |
| `ListenerDeferralInterface` | `Service/Deferral/ListenerDeferralService` | `Deferral/ListenerDeferralInterface`, `DeferredEntryObjectResolverInterface` (20 + 328 via I) | 9 |
| `AggregationInterface` | `Service/Aggregation/AggregationRunner` | `AggregationRunnerInterface`, `AggregationRunner` (2,395) | 8 |
| `DeepLinksInterface` | `Service/DeepLinkRegistryService` | `DeepLinkRegistryServiceInterface`, `DeepLinkResolverInterface` for callers above Kernel (24) | 9 |
| `ApiVersionsInterface` | `Service/ApiVersion/ApiVersionCatalogue` | `ApiVersionCatalogueInterface`, `ApiVersionNegotiatorInterface`, `ApiCapabilitiesServiceInterface`, `UrnServiceInterface` (39); the returned types `ApiVersionInterface`, `ApiVersionNegotiationInterface` stay (19) | 10 |
| `ProblemDetailsInterface` | `Service/Oas/ProblemDetailsBuilder` | `ProblemDetailsBuilderInterface`, `OasRequestValidatorInterface` (37) | 9 |
| `RequestSecurityInterface` | `Service/SecurityService` | `InboundAuthRateLimitInterface`, `RateLimitResetInterface`, `AuthenticationTokenFetcherInterface` (43 + 310 via I) | 7 |
| `ViewAlertsInterface` | `Service/View/ViewAlertDeclarations` (new, wraps the static `ViewAlert::declaredIn`/`::parse`) | `ViewAlert` statics and constants, `ViewObjectQueryInterface` (184) | 8 |
| `SchemaDeclarationFindings` (value object) | – | `SchemaAnnotation`, `AnnotationFaults`, `DiscardedAnnotations`, `SchemaDeclarationOrder` slots, `DeclaredPair`, `EmptyPropertyClaim`, `RequiredWhenDeclaration` statics, `GeneratedIdentifierDeclarationInterface`, `SemanticRoleHandlerInterface`, as seen by the contributed checks of #10685 (372) | 14 |
| `SchemaDeclarationException` | – | base of the four `…DeclarationException`s, which keep their names and extend it; checks name the base (64) | 8 |
| `EnvelopedDefinitionControllerInterface`, `SchemaChangeReportingControllerInterface` | the three controllers | §3.1.2 (5,798) | 4 + 4 |

Existing contracts in `lib/Kernel/Contracts/Core/` stay as they are: `ActionRightsInterface`, `AuditEntry`,
`AuditEntryWriterInterface`, `FieldRulesSourceInterface`, `ObjectPresenceInterface`,
`SchemaDeclarationCheckInterface` (53 lines). `Db/SchemaCatalogInterface` (8 lines, 55 callers) is already the
narrow catalogue contract and stays where it is; moving it into `lib/Kernel/Contracts/Core/` would move a file
out of its Nextcloud directory for no gain.

Methods, one line each (the parameter value objects in brackets exist today unless marked *new*):

```php
interface ObjectReaderInterface {
	public function find(string|int $identifier, ObjectFindTarget $target=new ObjectFindTarget()): ObjectEntity;
	public function findAcrossAllSources(string|int $identifier, bool $includeDeleted=false): ObjectEntity;
	public function findMultiple(array $ids): array;
	public function findBySchema(int $schemaId, ListQuery $listing=new ListQuery()): array;
	public function findInTable(string|int $identifier, RegisterSchema $table): ObjectEntity;
	public function findAllInTable(RegisterSchema $table, ListQuery $listing=new ListQuery(limit: ListQuery::UNBOUNDED)): array;
	public function search(array $query, ObjectFindOptions $options=new ObjectFindOptions()): array|int;
	public function findByLinkedEntity(Schema $schema, LinkColumn $column): array;
	public function translatableProperties(Schema $schema): array;
	public function readState(string $userId, string $objectUuid): ?ObjectReadState;
}
interface ObjectWriterInterface {
	public function update(ObjectEntity $object): ObjectEntity;
	public function save(array $data, ObjectSaveOptions $options): ObjectEntity;
	public function validate(array $object, Schema|int|string|null $schema=null): ValidationResult;
	public function releaseRunLocks(string $runId): int;
	public function recomputeMaster(string $objectUuid): void;
	public function registerBulkAction(string $name, BulkActionDescriptor $descriptor): void;
}
interface ObjectTablesInterface {
	public const TABLE_PREFIX = MagicMapper::TABLE_PREFIX; // literal value, not a reference, in the build
	public function ensureTable(RegisterSchema $table): void;
	public function tableExists(RegisterSchema $table): bool;
	public function tableName(RegisterSchema $table): string;
	public function tableNames(): array;
	public function tablePairs(): array;
	public function existingColumns(RegisterSchema $table): array;
	public function missingColumns(RegisterSchema $table): array;
	public function healMetadataColumns(RegisterSchema $table, bool $dryRun=false): array;
	public function widenColumns(RegisterSchema $table, bool $dryRun=false): array;
	public function reconcileTables(string $source): array;
	public function migrateStorage(RegisterSchemaRef $table, string $target): array;
}
interface ObjectDraftsInterface {
	public function ownDraft(OwnDraftSearch $search): ?ObjectEntity;
	public function draftOwner(ObjectEntity $object): DraftOwner;
	public function createDraft(DraftAddress $address, array $data): ObjectEntity;
}
interface ObjectDestructionInterface {
	public function destroyRefusal(ObjectEntity $object, string $userId): ?string;
	public function retentionRefusal(ObjectEntity $object): ?string;
	public function previewDestruction(array $objectUuids): array;
	public function destroy(RecordDestructionCommand $command): void;
	public function delete(ObjectDeletion $deletion): bool;
}
interface ObjectRenderingInterface {
	public const METADATA_COPIES = [/* the literal map RenderObject declares today */];
}
interface ObjectAccessInterface {
	public function hasPermission(Schema $schema, PermissionCheckOptions $check): bool;
	public function checkPermission(Schema $schema, PermissionCheckOptions $check): void;
	public function resolveAuthorization(Schema $schema, string $action): array;
	public function hasGroupPermission(Schema $schema, GroupRuleRequest $request): bool;
	public function readableByUsers(ObjectEntity $object): array;
	public function maySummariseValues(Schema $schema, string $property): bool;
	public function assertFileWritable(ObjectEntity $object, string $property): void;
	public function clearPermissionCache(): void;
}
interface PropertyAccessInterface {
	public function filterReadable(Schema $schema, array $object): array;
	public function stripWriteOnly(Schema $schema, array $object): array;
	public function stateFieldRules(Schema $schema, string $state): array;
}
interface ObjectGrantsInterface {
	public function claim(OwnedRecord $record, bool $cascade=false): array;
	public function reassign(array $identifiers, OwnershipReassignment $reassignment): array;
	public function grant(ObjectEntity $object, GrantRequest $request): array;
	public function revoke(ObjectEntity $object, string $shareId): void;
	public function grantsOf(ObjectEntity $object): array;
	public function resolveGrant(GrantLookup $lookup): ?array;
	public function mayUse(string $specialRight, ?string $userId=null): bool;
	public function mayManageDefinitions(?string $userId=null): bool;
	public function verbs(): array;
	public function tokenGrantRefusal(TokenGrantIssue $issue): ?string;
}
interface ViewerReachInterface {
	public function currentUid(): string;
	public function reachOf(string $userId): ViewerReach;
	public function reaches(array $view, ViewerReach $reach): bool;
	public function refusedFields(ReachedView $reached, array $update): array;
	public function assertMayNameRecipients(ReachedView $reached, array $data): void;
	public function redact(array $definition): array;
}
interface RevealInterface {
	public function mask(array $object, ?Schema $schema): array;
	public function isExplicit(Schema $schema, string $property): bool;
	public function maskTrail(array $entries): array;
	public function auditReveal(RevealingProcess $process): void;
}
interface TenancyInterface {
	public const STATUS_ACTIVE = 'active'; // and the other tenant status and environment constants, as literals
	public function activeOrganisation(): ?OrganisationInterface;
	public function isOrganisationAdmin(string $organisationUuid, ?string $userId=null): bool;
	public function manageableOrganisations(string $userId): array;
	public function hasAccessToOrganisation(string $organisationUuid): bool;
	public function organisation(string $uuid): OrganisationInterface;
	public function organisationBySlug(string $slug): OrganisationInterface;
	public function parentChain(string $organisationUuid): array;
	public function rotateTenantKey(string $organisationUuid): void;
}
interface OrganisationInterface {
	public function getUuid(): ?string;
	public function getName(): ?string;
	public function getSlug(): ?string;
}
interface AuditRecorderInterface {
	public function record(AuditEntry $entry): void;
	public function recordChange(?ObjectEntity $old, ?ObjectEntity $new): void;
	public function recordBatch(array $entries): void;
	public function trailOf(ListQuery $query): array;
}
interface SchemaEvolutionInterface {
	public function classify(SchemaChangeSet $changes): string;
	public function nextVersion(Schema $schema, string $level): string;
	public function recordChange(SchemaChangeRecord $record): void;
	public function diff(Schema $schema, IncomingDefinition $incoming): SchemaChangeSet;
	public function startMigration(MigrationRequest $request, ?string $startedBy=null): SchemaRun;
	public function rollback(int $runId, ?string $startedBy=null): SchemaRun;
	public function revalidate(Schema $schema): array;
	public function teardown(Schema $schema, SchemaTeardownOverride $override): array;
}
interface SchemaLookupInterface {
	public function resolveRegister(int|string $registerRef): Register;
	public function resolveSchemaWithin(Register $register, int|string $schemaRef): Schema;
	public function resolvePair(int|string $registerRef, int|string $schemaRef): array;
	public function restoreStringTypedValues(Schema $schema, array $object): array;
	public function clearSchemaCaches(int $schemaId): void;
}
interface ListenerDeferralInterface {
	public const DEFAULT_CHUNK_SIZE = 50; // literal, as on today's interface
	public function isDeferralEnabled(): bool;
	public function defer(string $jobClass, DeferralRequest $request): void; // new value object: entry, chunk size, dedupe key
	public function resolveEntryObject(array $entry): ?ObjectEntity;
}
interface AggregationInterface {
	public function run(NamedAggregationRef $ref, ?NamedAggregationOptions $options=null): array;
	public function runAdhoc(RegisterSchemaRef $table, AggregationQuery $query): array;
}
interface DeepLinksInterface {
	public function resolveUrl(ObjectRef $object, array $objectData): ?string;
	public function resolveIcon(int $registerId, int $schemaId): ?string;
	public function resolveTaskUrl(string $appId, array $task): ?string;
}
interface ApiVersionsInterface {
	public function all(): array;
	public function current(): ApiVersionInterface;
	public function negotiate(IRequest $request): ApiVersionNegotiationInterface;
	public function publicCapabilities(): array;
}
interface ProblemDetailsInterface {
	public const CONTENT_TYPE = 'application/problem+json';
	public function build(ProblemDescription $problem): JSONResponse;
	public function validationFailed(ProblemStatement $statement): JSONResponse;
	public function validateRequest(IRequest $request, string $operationId): array;
}
interface RequestSecurityInterface {
	public function trustedClientIp(IRequest $request): string;
	public function checkAuthRateLimit(string $identifier, string $ip): array;
	public function recordAuthAttempt(FailedAttempt $attempt): void;
	public function clearRateLimits(string $identifier): void;
}
interface ViewAlertsInterface {
	public const USER_PREFIX = 'user:'; // literals as on ViewAlert today
	public function declaredIn(array $view): array;
	public function parse(array $declaration): ViewAlertInterface;
}
```

A port must never hand out one of the interfaces it replaces (a method returning `ObjectOwnershipServiceInterface`
would leave that interface exposed); each port lists the operations themselves.

`AuditRecorderInterface` takes the existing `Kernel/Contracts/Core/AuditEntry` value object and returns nothing:
today's `AuditTrailMapperInterface` names Compliance's `AuditTrail`, `ObjectAuditEvent` and `ExportOutcome`
(Core → Compliance, against the order), and the port must not. The callers below Compliance (Core,
Notifications, Search) build an `AuditEntry`; Compliance's own callers (35 files) use `AuditTrailMapper`
directly; the callers above Compliance may do either. `trailOf` returns arrays, not entities.

Signature notes for the build packages: `findByLinkedEntity` today takes three scalars (schema, column name,
entity id); the port takes the existing `Db/LinkColumn` value object (which is kept, §3.4) to stay within two
parameters. `defer` today takes four; `DeferralRequest` is the one new value object (Core, in
`Service/Deferral/`) and is kept exposed. Constants on ports are literals equal to today's value, asserted equal
by a unit test, never `= OtherClass::X` (that would name the class again).

### 3.3 Rows (R): counts to the area whose work it is

209 files move by csv row; 180 of them (4,880 lines) are exposed today, the other 29 are used only by those.

Found by a fixed point over the graph, not by name: a Core file counts to area X when every file that names it
is in X or is itself such a file, wiring maps excluded (they follow the class). Then read by hand; refused
were the object store, its RBAC services, the access-link service, the register service and the
controllers. Accepted (full list with lines in Appendix A, treatment "R"):

| To | Files | Lines | What it is |
|---|---|---|---|
| Compliance | 41 | 1,215 | Controller support bags of Compliance controllers (decided), processing-log/purpose/verwerkingsactiviteit/content-report/erasure-preview mapper interfaces implemented by Compliance mappers, retention job base, log export formatter, schema teardown guard, DSAR/TMLO/AVG service interfaces, quality audit evaluator |
| Integrations | 59 | 1,043 | Link-controller support bags (decided), the eight `…LinkMapperInterface` / `…LinkServiceInterface` pairs whose implementations are Integrations', federation share notification and creator filter, time-tracker value types, linked-entity helpers, token-grant validator for consumers |
| Configuration | 40 | 888 | Settings/setup controller support bags (decided), vocabulary import readers (SKOS, CSV, concept edges, tally, store), register-import route/call/document, job-run mapper interface and filter, record-template mapper interface, app-register import runner, manifest sources, demo data, schema application migrator and dedupe service interfaces |
| Processes | 23 | 738 | Translation and calendar/task controller bags (decided), consent envelope evaluators, delegation service, resolver, request, verdict and refusal (flows' delegation check), reference-loop check and positioned declaration check contributed by Processes |
| Platform | 21 | 512 | Chat/agent/conversation controller bags (decided), repair helpers used only by Platform repair steps (`PerformerTyping`, `MigratedFlowOrigins`, `NcEntitySemanticMap`, `RevealRelations`), maintenance-reachable controllers list |
| Files | 13 | 551 | Antivirus scan adapter and gate, archive member enumeration, text-signature store, access-link reader filters, entity-recognition backends |
| Search | 12 | 885 | GraphQL resolver and its list request/connection/aggregation helpers, settings diagnostics bags (`HybridSearchDiagnostics`, `DatabaseServerProfile`) |

Rows that land in a higher area may make files of that area newly exposed to areas above it (for example
Processes' flow services naming the Integrations link interfaces): that is the allowed direction and is
already in the "after" numbers.

### 3.4 Kept exposed, with the reason (≈ 3,976 lines after)

| Kept | Files | Lines | Why it stays |
|---|---|---|---|
| Ports and markers (§3.2) | 33 | 336 | the contract |
| Value objects callers construct as port parameters (`PermissionCheckOptions`, `GroupRuleRequest`, `GrantRequest`, `GrantLookup`, `OwnershipReassignment`, `TokenGrantIssue`, `DraftAddress`, `OwnDraftSearch`, `RecordDestructionCommand`, `DeletionRequest`, `SchemaChangeRecord`, `IncomingDefinition`, `NamedAggregationRef/Options`, `ProblemDescription/Statement`, `FailedAttempt`, `RevealingProcess`, `HoldOrder`-style request bags, …) | 54 | 1,021 | ADR-011/120: parameters travel on value objects; moving them to `lib/Kernel/Contracts/Core/` would move files out of their directory. Each one that duplicates a Kernel value object (`ObjectFindTarget`, `ListQuery`, `RegisterSchemaRef`) is replaced instead in the caller package that touches it. |
| Db toolkit (`TypedFieldsJson(Trait)`, `LinkColumn`, `SelectByColumnTrait`, `MultiTenancyTrait`, `HydrateEmptyJsonAsNullTrait`, `HydrationKey`, `EmptyJsonHydration`, `ColumnFiltersTrait`, `DeleteByObjectTrait`, `OrganisationFieldTrait`, `ObjectLinkTypesTrait`, `QueryPage`, `OutcomeFilter`, `AbstractObjectMapper`, `RegisterSchema`, `SchemaCatalogInterface`) | 17 | 475 | every area's entities and mappers use these traits and column types; a trait cannot sit behind a port. Not moved to Kernel: that would be a move "only to hide" while Kernel is already over its budget. |
| Job base and payloads (`ActorForwardedJob`, `ActorForwardingServices`, `JobRuntime`, `JobHandles`, `QueuedJobClassesInterface`, `DeferredListenerContext`) | 6 | 259 | other areas' jobs extend `ActorForwardedJob` (run as the queuing actor). `JobHandles` and `QueuedJobClassesInterface` shrink when each area registers its own handles (decided), not in this design. |
| Controller toolkit (`ControllerSession`, `AdministratorOnlyGate`, `SessionPrincipal`, `HandlesExceptionsTrait`, `RequiresJsonBodyTrait`, `TrimmedRequestParam`, `ResultWindow`, `RequestListPage`, `ObjectLinkRoute`, `LinkedSourceResponses`, `…Platform` bags) | 11 | 232 | shared HTTP plumbing used by controllers of most areas. `ObjectLinkRoute` and `LinkedSourceResponses` (Integrations 31, Processes 4) are row candidates for Integrations once Processes' four users are checked. |
| Write and operation context (`WriteCause`, `SystemOperationContext`, `CauseFrame`, `EntryScripts`, `ConfigLookup`, `LinkSessionRuntime`) | 4–6 | 141 | static context (`WriteCause::asLookup` 39×, `SystemOperationContext::run`); fleet apps use `SystemOperationContext`. A port would turn 50 static call sites into injections for 141 lines. |
| Registrars Platform's `Application` calls (`DbRegistrar`, `MapperRegistrar`, `ListenerBootstrap`, `CoreListenerRegistrar`, `ObjectSourceRegistrar`, `RbacRegistrar`, `ServiceBootstrap`) | 7 | 151 | the entry points; thin by rule 5. |
| Named only by Kernel (Kernel → Core, against the order) | 11 | 737 | `Db/Schema.php` (Kernel) constructs `Db/Schema/*` validators (488 lines) and `UniqueConstraintEvaluator` (205); `Event/Abstract*Event`, `ListenerClassesInterface`, `EmptyJsonHydration`. Decided: the entity methods are deprecated and every openregister caller moves to a Core service, but the methods keep working, so these references and their exposure remain until the deprecated methods are removed in a major release. |
| Events (`ObjectWriteRequestEvent`, `SchemaDeclarationChecksEvent`, …) | 3 | 70 | an event is the allowed way across (BRIEF way 1). |
| Remaining small items | 30 | 554 | entities other areas read (`EntityRelation` 39, `DelegationGrant` 64, `TextSignature`/`TextSignatureMapper`, `FileDigest(Mapper)`), and small one-capability interfaces with one or two outside users (`AppConfigurationImporterInterface`, `IntegrationIdIndexInterface`, `SourceMapperInterface`, `NotifyPushBatchInterface`, `Quality*Interface`, `Vectorization/Strategies/*Interface`, `SemanticTypeResolverInterface`, `JsonLdContextServiceInterface`, …). Each is a candidate for the area that uses it in a later pass; none is worth a port. |

### 3.5 Entities that cross the boundary

| Entity | Today | Proposal |
|---|---|---|
| `ObjectEntity`, `Schema`, `Register` | Kernel | unchanged; ports use them. |
| `Organisation` (288 lines, 8 callers) | Core | readers get `OrganisationInterface` (uuid, name, slug); writers (Configuration's `BackfillSystemOwnerCommand`, the import that creates organisations) go through `TenancyInterface`/`NewOrganisation`. `Kernel/Event/OrganisationUpdatedEvent` keeps naming `Organisation` (its payload is read by other apps). |
| `AuditTrail` | Compliance | not named by any Core port: `AuditRecorderInterface` takes `Kernel/Contracts/Core/AuditEntry` and returns nothing or arrays (§3.2). `AuditTrailMapperInterface` stays Core-internal until Core's own writers have moved to the port; then its reference to `AuditTrail` (against the order today) can go. |
| `EntityRelation`, `DelegationGrant`, `ObjectReadState`, `DraftVersion` | Core | `ObjectReadState`, `DraftVersion` behind their ports; `EntityRelation` and `DelegationGrant` stay (kept list) until their readers (Files' extraction, Processes' delegation) are designed in their own areas. |
| `SchemaChangelog`, `SchemaRun` | Core | behind `SchemaEvolutionInterface`; callers get the entity as a return value only. |

## 4. The budget

Exposed Core lines, before → after, per step (each step on top of the previous, measured on the modelled graph):

| Step | Core exposed files | Core exposed lines | Saved |
|---|---|---|---|
| Today | 806 | 64,730 | |
| 1 Core service map + Core listener registrar (W) | 540 | 40,600 | 24,130 |
| 2 Markers + existing interfaces (M, I) | 516 | 19,961 | 20,639 |
| 3 Rows (R) | 351 | 18,682 | 1,279 net (4,880 moved out, files they use newly exposed) |
| 4 Ports, value objects kept (P) | 197 | ≈ 5,974 | ≈ 12,708 |
| 5 File storage to Files behind `FileStorageInterface` (F) | 179 | ≈ 4,512 | ≈ 1,462 |
| 6 The moved file classes use the ports too | 176 | ≈ 3,976 | ≈ 536 |

Per contract, lines it costs against the lines it takes out of the exposed set (direct + via the existing
interfaces it absorbs): see the table in §3.2. The largest single savings: `AggregationInterface` 8 for 2,395,
`ObjectGrantsInterface` 16 for ≈ 1,850, `ObjectDestructionInterface` 11 for 1,206, `SchemaEvolutionInterface`
12 for ≈ 1,100, `TenancyInterface` + `OrganisationInterface` 23 for ≈ 1,415, `ObjectAccessInterface` 14 for 954,
`ObjectReaderInterface` + `ObjectWriterInterface` + `ObjectTablesInterface` 45 for ≈ 1,800.

Against the wave 5 targets (all areas together): 4.0 needs at most ≈ 18,900 exposed lines, of which Kernel takes
≈ 9,700, leaving ≈ 9,200 for the nine other areas. Core at ≈ 3,976 takes 43% of that. That fits 4.0 only if
the other eight areas together stay under ≈ 5,200. For 4.5 (≈ 12,600 total, ≈ 2,900 outside Kernel) Core alone
is over by ≈ 1,100: it then needs the Kernel → Core references gone (737, needs the deprecated `Schema`
methods removed, a major release) and the kept value objects folded into Kernel value objects where one exists
(up to ≈ 400).

Nothing above fits only by breaking a rule. Flagged, so nobody "fixes" the number the wrong way:

- Moving the Db toolkit (475) or the controller toolkit (232) into Kernel would make the number look better and
  is forbidden (move only to hide); Kernel is over budget anyway.
- `ObjectGrantsInterface`, `ObjectAccessInterface` and `ObjectWriterInterface` are facades over several
  classes. They must expose only what §3.2 lists; widening them per request turns them back into
  `MagicMapperInterface`.
- Constants copied onto ports are literals; a port constant defined as `= ConcreteClass::X` re-exposes the class.
- The rows of §3.3 are only for files whose work is the receiving area's; the "refused" list in §3.3 is part of
  the design.

## 5. The build packages

In dependency order. File sets are disjoint between packages (every caller file is in exactly one caller
package, which switches all its Core references at once). Every package: unit tests for each changed or added
class, `modularity_check.py --check` with no new reference against the order, `deptrac.sh areas --check`, all of
`tests/Unit/Architecture`, and BRIEF §4. Caller packages need packages 1–5 merged; they are independent of each
other.

| # | Package | PRs | Files | Depends on |
|---|---|---|---|---|
| C5-01 | Core service map: `Service/CoreServiceBindings.php` (new), `Service/ServiceRegistrar.php`, `Service/ContractBindings.php`, `Service/Bindings/{Objects,Root,Access,Schemas,Feature}Bindings.php`, `Service/Configuration/ConfigurationServiceRegistrar.php`, `Files/FilesRegistrar.php`, csv row + generated yaml; PR 2 retargets the `tests/Unit/Architecture/*InterfaceBindingsTest` that read `ContractBindings::BINDINGS` for Core entries | 2 | 13 + tests | – |
| C5-02 | Core listener registrar: `Listener/CoreListenerRegistrar.php` (new), `Listener/ListenerRegistrar.php`, `Listener/Registrar/AccessListenerRegistrar.php`, csv row; the order pin and `ListenerRegistrationOrderGoldenTest` unchanged | 1 | 4 + tests | – |
| C5-03 | Markers: the two marker interfaces, `ObjectsController`, `RegistersController`, `SchemasController`, `Middleware/AnonymousEnvelopeMiddleware`, `Middleware/SchemaChangeHeaderMiddleware` | 1 | 7 + tests | – |
| C5-04 | Rows: `scripts/component-map.csv` (the 209 rows of §3.3; the 180 exposed today are Appendix A "R"), the generated components file, the generated deptrac areas file; the bindings of the moved classes move from `CoreServiceBindings` to their area's map | 1 | 3 + maps | C5-01 |
| C5-05 | Ports: the 26 new files of §3.2 plus `implements`/facade classes. PR a: reader, writer, tables, drafts, destruction, rendering, aggregation, deferral (+ `ObjectWrites`, `DeferralRequest`); PR b: access, property access, grants, viewer reach, reveal, request security (+ `ObjectGrants`); PR c: tenancy, organisation, audit recorder, schema evolution, schema lookup, view alerts, deep links, API versions, problem details, declaration findings/exception (+ `ViewAlertDeclarations`); bindings in `CoreServiceBindings` (and Compliance's map for `AuditRecorderInterface`) | 3 | ≈ 40 + tests | C5-01 |
| C5-06 | Compliance callers (96 files, 38 of them only switch `AuditTrailMapperInterface` to Compliance's own `AuditTrailMapper`) | 4 + 3 | Appendix B | C5-05 |
| C5-07 | Configuration callers (70) | 4 + 1 | Appendix B | C5-05 |
| C5-08 | Integrations callers (77) | 4 + 2 | Appendix B | C5-05 |
| C5-09 | Processes callers (101) | 4 + 3 | Appendix B | C5-05 |
| C5-10 | Search and Notifications callers (43 + 16) | 3 + 2 | Appendix B | C5-05 |
| C5-11 | Platform and Files callers (31 + 20) | 3 + 2 | Appendix B | C5-05 |
| C5-12 | File storage: `lib/Service/File/*` and `FileService` count to Files after Core's `Kernel/Contracts/Core/FileStorageInterface` (what Core's save, merge, render and destruction paths need) is implemented by Files; Compliance's anonymisation (`ImageBurner`, `ImageRedactionService`) and `SharerAccessSweepJob` (`FolderManagementHandler`) then need a Compliance-owned port, because Compliance ranks below Files | – | – | the Files area design |

A package marked "a + b" is larger than one package of four PRs: it is two consecutive packages (a PRs, then
b PRs) of the same area or pair of areas. The per-PR file lists are Appendix B, already chunked at 15 files.

## 6. Fleet impact

`fleet_surface.py` over the 19 fleet apps finds 73 Core classes named by other apps (top of the
list; the full list is Appendix C):

| Class | Fleet references | Apps | In this design |
|---|---|---|---|
| `Service\ObjectService` | 1,143 | 16 | unchanged; internal callers move to Kernel `ObjectOperationsInterface` |
| `Db\SchemaMapper` | 151 | 16 | unchanged |
| `Db\RegisterMapper` | 98 | 14 | unchanged |
| `Service\FileService` | 59 | 10 | counts to Files (C5-12); class name and namespace unchanged |
| `Db\OrganisationMapper` | 37 | 7 | unchanged; implements `TenancyInterface` through `OrganisationService` |
| `Service\OrganisationService` | 28 | 9 | unchanged; implements `TenancyInterface` |
| `Service\Deferral\ListenerDeferralService` | 22 | 4 | unchanged; implements `ListenerDeferralInterface` |
| `Service\SystemOperationContext` | 19 | 4 | unchanged (kept) |
| `Db\EntityRelationMapper` | 18 | 2 | unchanged |
| `Db\MagicMapper` | 14 | 8 | unchanged; implements the three object ports |
| `Service\Deferral\DeferredListenerContext` | 14 | 4 | unchanged (kept) |
| `Service\RegisterService`, `Service\Aggregation\AggregationRunner`, `Service\RegisterSlugResolver`, `Service\HoldOrder`, `Db\Organisation`, `BackgroundJob\ActorForwardedJob`, … | ≤ 7 each | | unchanged; `HoldOrder` counts to Compliance by row, same class name |

Nothing other apps use is renamed, moved out of its directory or removed; every changed class only gains an
`implements`. Ports other apps would need, and so candidates for promotion to `lib/Contract` (ADR-119 Decision
14), named and not done here:

- `ListenerDeferralInterface` (integriq, learniq, pipelinq, shillinq defer their listeners through the concrete
  service today);
- `TenancyInterface` + `OrganisationInterface` (9 apps use `OrganisationService`, 7 `OrganisationMapper`);
- `AggregationInterface` (buildiq, pipelinq, shillinq use `AggregationRunner`);
- `ObjectReaderInterface` (8 apps use `MagicMapper`, mostly for `find`);
- `ObjectGrantsInterface` (dossiq, opencatalogi, launchpad use the ownership/sharing/grant services).

## How the numbers were made

1. The module graph and line counts of `modularity_check.measure()` (`php_dependencies`, `code_lines`,
   `component_of_factory` with the generated components file), on `e918c58d`. Today's figures equal
   `modularity_check.py`'s output: exposed 158,870 of 459,141 lines, Kernel 10,282, 951 references against the
   order.
2. Symbols per caller: comments stripped, then `Class::member`, `new`, `instanceof`, trait `use`,
   `extends/implements`, class strings, and `->method(` on variables, promoted properties and getters typed as
   the Core class.
3. The model applies, in order: Core wiring moved to a Core map (registrar → Core edges removed, Core map →
   class edges added); Core listeners behind one Core registrar; marker interfaces; concrete → bound interface
   (the smallest interface bound to the class; the build picks the one carrying the caller's methods); the rows
   of §3.3 (fixed point, refusal list applied); every outside edge to a §3.2 file redirected to its port, except
   value objects callers construct, which stay; Compliance's own audit callers to `AuditTrailMapper`; file
   storage to Files with Core behind `FileStorageInterface`. Port lines are estimates (methods + 6).
4. References against the order are recounted on the modelled graph: no new pair from or to Core. The only new
   pairs are Kernel events whose existing Core target is renamed to the port (same count; the Kernel events keep
   their current targets in the build) and Compliance → Files for anonymisation (C5-12, flagged).

## Appendix A: every Core file another area names today

Treatment: W wiring, M marker, I existing interface, P port, R row (area), F file storage to Files, K kept
(category). Callers: number of outside files per area (Ker Kernel, Not Notifications, Sea Search, Com
Compliance, Fil Files, Int Integrations, Pro Processes, Cfg Configuration, Pla Platform).

| File (`lib/…`) | Lines | Outside callers | Treatment |
|---|---|---|---|
| `BackgroundJob/AbstractRetentionJob.php` | 50 | Com 2 | R row → Compliance |
| `BackgroundJob/ActorForwardedJob.php` | 118 | Int 1, Pro 4, Sea 1 | K job base and job payloads |
| `BackgroundJob/DestructionExecutionJob.php` | 28 | Com 2 | I use DestructionExecutionJobInterface |
| `BackgroundJob/JobHandles.php` | 28 | Com 6, Cfg 3, Int 1, Pro 6 | K job base and job payloads |
| `BackgroundJob/JobRuntime.php` | 12 | Com 2, Cfg 2, Int 2, Pro 3 | K job base and job payloads |
| `BackgroundJob/QueuedJobClassesInterface.php` | 7 | Com 1, Pro 1 | K job base and job payloads |
| `BackgroundJob/Support/ActorForwardingServices.php` | 16 | Int 1, Pro 4, Sea 1 | K job base and job payloads |
| `Command/DescriptorImportTarget.php` | 10 | Cfg 1 | R row → Configuration |
| `Command/MagicTableCommand.php` | 60 | Cfg 1 | P ObjectTablesInterface |
| `Command/MagicTableRun.php` | 29 | Cfg 1 | P ObjectTablesInterface |
| `Command/MagicTableSelection.php` | 49 | Cfg 1, Pla 1 | P ObjectTablesInterface |
| `Command/MagicTableTarget.php` | 21 | Cfg 1, Pla 1 | P ObjectTablesInterface |
| `Command/RegisterRepairOptions.php` | 33 | Cfg 1 | P ObjectTablesInterface |
| `Controller/ObjectsController.php` | 3325 | Pla 1 | M marker interface |
| `Controller/RegistersController.php` | 873 | Pla 1 | M marker interface |
| `Controller/SchemasController.php` | 1600 | Pla 1 | M marker interface |
| `Controller/Support/AccessLinkPlatform.php` | 14 | Int 1 | P AccessLinkReaderInterface |
| `Controller/Support/ActivityLinksControllerServices.php` | 14 | Int 1 | R row → Integrations |
| `Controller/Support/AdminGroupMembership.php` | 18 | Com 2 | R row → Compliance |
| `Controller/Support/AdministratorOnlyGate.php` | 23 | Com 4, Cfg 1, Pro 2 | K controller toolkit |
| `Controller/Support/AgentsControllerServices.php` | 16 | Pla 1 | R row → Platform |
| `Controller/Support/ApiTokenProbe.php` | 12 | Cfg 1 | R row → Configuration |
| `Controller/Support/ArchivalNominationSubject.php` | 12 | Com 1 | R row → Compliance |
| `Controller/Support/ArchivalReviewAnswer.php` | 11 | Com 1 | R row → Compliance |
| `Controller/Support/ArchivalReviewEntry.php` | 11 | Com 1 | R row → Compliance |
| `Controller/Support/AuditObjectRef.php` | 10 | Com 1 | R row → Compliance |
| `Controller/Support/AuditTrailPlatform.php` | 12 | Com 1, Pro 1 | P AuditRecorderInterface |
| `Controller/Support/BulkTranslation.php` | 14 | Pro 1 | R row → Processes |
| `Controller/Support/CacheSettingsControllerServices.php` | 16 | Cfg 1 | R row → Configuration |
| `Controller/Support/CalendarEventsControllerServices.php` | 16 | Pro 1 | R row → Processes |
| `Controller/Support/CaseTokenControllerServices.php` | 14 | Int 1 | R row → Integrations |
| `Controller/Support/ChatControllerServices.php` | 11 | Pla 1 | R row → Platform |
| `Controller/Support/ChatFamilyControllerInterface.php` | 5 | Pla 5 | R row → Platform |
| `Controller/Support/ChatHealthControllerServices.php` | 12 | Pla 1 | R row → Platform |
| `Controller/Support/ChatStreamControllerServices.php` | 10 | Pla 1 | R row → Platform |
| `Controller/Support/ChatStreamTurn.php` | 12 | Pla 1 | R row → Platform |
| `Controller/Support/ConfigurationAction.php` | 12 | Cfg 1 | R row → Configuration |
| `Controller/Support/ConfigurationSettingsControllerServices.php` | 12 | Cfg 1 | R row → Configuration |
| `Controller/Support/ControllerSession.php` | 21 | Com 6, Cfg 10, Fil 2, Int 4, Not 7, Pro 3, Sea 4 | K controller toolkit |
| `Controller/Support/ConversationChange.php` | 17 | Pla 1 | R row → Platform |
| `Controller/Support/ConversationControllerServices.php` | 11 | Pla 1 | R row → Platform |
| `Controller/Support/ConversationFailure.php` | 20 | Pla 1 | R row → Platform |
| `Controller/Support/ConversationPlatform.php` | 10 | Pro 1 | K controller toolkit |
| `Controller/Support/CorrectionsControllerServices.php` | 14 | Com 1 | R row → Compliance |
| `Controller/Support/CredentialCaller.php` | 10 | Int 1 | R row → Integrations |
| `Controller/Support/CredentialUpdateTarget.php` | 12 | Int 1 | R row → Integrations |
| `Controller/Support/DatabaseServerProfile.php` | 136 | Sea 1 | R row → Search |
| `Controller/Support/DestructionListApproval.php` | 38 | Com 1 | R row → Compliance |
| `Controller/Support/DsarCasePlatform.php` | 10 | Com 1, Pro 2 | K controller toolkit |
| `Controller/Support/DsarControllerServices.php` | 16 | Com 1 | R row → Compliance |
| `Controller/Support/FormLinksControllerServices.php` | 16 | Int 1 | R row → Integrations |
| `Controller/Support/GdprRegisterAccess.php` | 27 | Com 2 | R row → Compliance |
| `Controller/Support/GdprRegisterReplies.php` | 43 | Com 2 | R row → Compliance |
| `Controller/Support/GdprRequestParams.php` | 59 | Com 4 | R row → Compliance |
| `Controller/Support/GitHubIssueDraft.php` | 12 | Cfg 1 | R row → Configuration |
| `Controller/Support/GitHubIssueListing.php` | 12 | Cfg 1 | R row → Configuration |
| `Controller/Support/GitHubTokenFailure.php` | 18 | Cfg 1 | R row → Configuration |
| `Controller/Support/HybridSearchDiagnostics.php` | 142 | Sea 1 | R row → Search |
| `Controller/Support/LinkRemoval.php` | 11 | Int 1 | R row → Integrations |
| `Controller/Support/LinkedEntityControllerServices.php` | 11 | Int 1 | R row → Integrations |
| `Controller/Support/LinkedSourceActions.php` | 52 | Int 8 | R row → Integrations |
| `Controller/Support/LinkedSourceResponses.php` | 30 | Int 14, Pro 1 | K controller toolkit |
| `Controller/Support/MaintenanceReachableControllersInterface.php` | 13 | Pla 1 | R row → Platform |
| `Controller/Support/ManifestControllerServices.php` | 14 | Cfg 1 | R row → Configuration |
| `Controller/Support/MapLinksControllerServices.php` | 12 | Int 1 | R row → Integrations |
| `Controller/Support/MergeControllerServices.php` | 12 | Com 1 | R row → Compliance |
| `Controller/Support/OAuth2StartRequest.php` | 11 | Int 1 | R row → Integrations |
| `Controller/Support/OAuth2StartRun.php` | 15 | Int 1 | R row → Integrations |
| `Controller/Support/ObjectIntegrationsAccess.php` | 12 | Int 2 | R row → Integrations |
| `Controller/Support/ObjectLinkRoute.php` | 11 | Int 16, Pro 3 | K value objects callers construct |
| `Controller/Support/ObjectShareLinkControllerServices.php` | 16 | Int 1 | R row → Integrations |
| `Controller/Support/ObjectShareLinkThrottle.php` | 21 | Int 2, Pro 1 | P ObjectGrantsInterface |
| `Controller/Support/ObjectSharingEndpoint.php` | 88 | Pro 1 | P ObjectGrantsInterface |
| `Controller/Support/OpenProjectLinksControllerServices.php` | 12 | Int 1 | R row → Integrations |
| `Controller/Support/OperationsActingRequest.php` | 32 | Cfg 3 | R row → Configuration |
| `Controller/Support/PhotoLinksControllerServices.php` | 12 | Int 1 | R row → Integrations |
| `Controller/Support/PollLinksControllerServices.php` | 12 | Int 1 | R row → Integrations |
| `Controller/Support/PreferencesControllerServices.php` | 11 | Cfg 1 | R row → Configuration |
| `Controller/Support/PreferencesUserValues.php` | 50 | Cfg 1 | R row → Configuration |
| `Controller/Support/ProcessingActivityAuditCounts.php` | 56 | Com 1 | R row → Compliance |
| `Controller/Support/ProcessingLogAccess.php` | 49 | Com 1 | R row → Compliance |
| `Controller/Support/ProcessingLogControllerServices.php` | 12 | Com 1 | R row → Compliance |
| `Controller/Support/ProcessingLogPolicy.php` | 44 | Com 1 | R row → Compliance |
| `Controller/Support/RegisterAccessAudit.php` | 47 | Int 1 | K controller toolkit |
| `Controller/Support/RegisterDescriptorControllerServices.php` | 14 | Cfg 1 | R row → Configuration |
| `Controller/Support/RegisterUpdate.php` | 10 | Int 1 | K value objects callers construct |
| `Controller/Support/RelationTypeLookup.php` | 10 | Int 1 | R row → Integrations |
| `Controller/Support/RelationsObjectLookup.php` | 20 | Int 2 | R row → Integrations |
| `Controller/Support/RelationsRecords.php` | 16 | Int 2 | R row → Integrations |
| `Controller/Support/RepositoryCallFailure.php` | 10 | Cfg 1 | R row → Configuration |
| `Controller/Support/RequestListPage.php` | 26 | Int 1 | K value objects callers construct |
| `Controller/Support/ResultWindow.php` | 36 | Com 1 | K controller toolkit |
| `Controller/Support/RetentionPlatform.php` | 14 | Com 1 | K controller toolkit |
| `Controller/Support/SearchTrailControllerServices.php` | 19 | Com 1 | R row → Compliance |
| `Controller/Support/SearchTrailPageLinks.php` | 30 | Com 1 | R row → Compliance |
| `Controller/Support/SecuritySettingsControllerServices.php` | 12 | Cfg 1 | R row → Configuration |
| `Controller/Support/SessionPrincipal.php` | 12 | Com 2, Cfg 1 | K controller toolkit |
| `Controller/Support/SettingsControllerServices.php` | 10 | Sea 1 | R row → Search |
| `Controller/Support/SetupControllerServices.php` | 14 | Cfg 1 | R row → Configuration |
| `Controller/Support/ShareLinksControllerServices.php` | 12 | Int 1 | R row → Integrations |
| `Controller/Support/TaskEventsControllerServices.php` | 16 | Pro 1 | R row → Processes |
| `Controller/Support/TasksPlatform.php` | 12 | Int 1, Pro 1 | K controller toolkit |
| `Controller/Support/TimeTrackerLinksControllerServices.php` | 12 | Int 1 | R row → Integrations |
| `Controller/Support/TmloControllerServices.php` | 10 | Com 1 | R row → Compliance |
| `Controller/Support/TranslationControllerServices.php` | 13 | Pro 1 | R row → Processes |
| `Controller/Support/TranslationFilter.php` | 12 | Pro 1 | R row → Processes |
| `Controller/Support/TranslationSearchOptions.php` | 24 | Pro 1 | R row → Processes |
| `Controller/Support/TranslationSearchRequest.php` | 36 | Pro 1 | R row → Processes |
| `Controller/Support/TranslationSlot.php` | 11 | Pro 1 | R row → Processes |
| `Controller/Support/TrimmedRequestParam.php` | 17 | Pro 1 | K controller toolkit |
| `Controller/Support/UserSettingsControllerServices.php` | 14 | Cfg 1 | R row → Configuration |
| `Controller/Support/ValidationSettingsControllerServices.php` | 12 | Cfg 1 | R row → Configuration |
| `Controller/Support/VerwerkingsactiviteitenControllerServices.php` | 15 | Com 1 | R row → Compliance |
| `Controller/Support/WebhookLogQuery.php` | 9 | Int 1 | R row → Integrations |
| `Controller/Support/XwikiLinksControllerServices.php` | 12 | Int 1 | R row → Integrations |
| `Controller/Trait/ChecksObjectUpdateRight.php` | 19 | Int 1, Not 1, Pro 1 | P ObjectAccessInterface |
| `Controller/Trait/HandlesExceptionsTrait.php` | 113 | Cfg 1, Fil 1, Int 2 | K Db toolkit for every area's entities and mappers |
| `Controller/Trait/RequiresJsonBodyTrait.php` | 19 | Fil 1, Pro 1 | K Db toolkit for every area's entities and mappers |
| `Controller/Trait/ResolvesLinkedObjectTrait.php` | 15 | Int 3 | R row → Integrations |
| `Db/AbstractObjectMapper.php` | 55 | Int 1 | K Db toolkit for every area's entities and mappers |
| `Db/AccessLinkMapperInterface.php` | 12 | Cfg 1 | P AccessLinkReaderInterface |
| `Db/ApiCall/ApiCallPeriod.php` | 13 | Int 3 | R row → Integrations |
| `Db/AuditTrailMapperInterface.php` | 73 | Com 36, Cfg 9, Fil 4, Int 6, Not 2, Pro 11, Sea 6 | P AuditRecorderInterface |
| `Db/AuthorizationRulesTrait.php` | 24 | Cfg 1 | P ObjectAccessInterface |
| `Db/ColumnFiltersTrait.php` | 19 | Cfg 1, Int 3 | K Db toolkit for every area's entities and mappers |
| `Db/ContentReportMapperInterface.php` | 13 | Com 5 | R row → Compliance |
| `Db/DbRegistrar.php` | 71 | Com 1, Cfg 1, Int 1, Pla 3, Pro 1 | W wiring |
| `Db/DeleteByObjectTrait.php` | 11 | Not 1, Pro 1 | K Db toolkit for every area's entities and mappers |
| `Db/DraftVersion.php` | 48 | Pro 1 | P ObjectDraftsInterface |
| `Db/DraftVersionMapper.php` | 32 | Pro 1 | P ObjectDraftsInterface |
| `Db/EmptyJsonHydration.php` | 30 | Int 1, Ker 1 | K value objects callers construct |
| `Db/EmptyPropertyClaim.php` | 14 | Pro 1 | P SchemaDeclarationFindings |
| `Db/EntityRelation.php` | 39 | Fil 5, Ker 1, Pro 9 | K remaining |
| `Db/ErasurePreviewMapperInterface.php` | 9 | Com 3 | R row → Compliance |
| `Db/FederatedShareMapperInterface.php` | 14 | Int 6 | P ObjectGrantsInterface |
| `Db/HydrateEmptyJsonAsNullTrait.php` | 22 | Com 2, Cfg 2, Int 2, Pro 1 | K Db toolkit for every area's entities and mappers |
| `Db/HydrationKey.php` | 22 | Cfg 1, Int 2 | K Db toolkit for every area's entities and mappers |
| `Db/Integration/IntegrationIdIndexInterface.php` | 7 | Int 5 | K remaining |
| `Db/JobRunFilter.php` | 13 | Cfg 3 | R row → Configuration |
| `Db/JobRunMapperInterface.php` | 27 | Cfg 8 | R row → Configuration |
| `Db/LinkColumn.php` | 33 | Com 3, Cfg 6, Int 18, Pro 11 | K Db toolkit for every area's entities and mappers |
| `Db/MagicMapper/AccentFoldingSetting.php` | 21 | Cfg 1 | K value objects callers construct |
| `Db/MagicMapper/MagicColumnDrift.php` | 50 | Cfg 1 | I use MagicColumnDriftInterface |
| `Db/MagicMapper/MagicColumnNameTrait.php` | 10 | Pla 1 | K Db toolkit for every area's entities and mappers |
| `Db/MagicMapper/MagicListRequest.php` | 14 | Com 5, Cfg 1, Pro 1 | K value objects callers construct |
| `Db/MagicMapper/MagicObjectFinderInterface.php` | 21 | Com 4, Cfg 1, Fil 3, Int 1, Not 1, Pro 3, Sea 2 | P ObjectReaderInterface |
| `Db/MagicMapper/MagicObjectSearchInterface.php` | 26 | Com 1 | P ObjectReaderInterface |
| `Db/MagicMapper/MagicRelationReaderInterface.php` | 13 | Not 1 | P ObjectReaderInterface |
| `Db/MagicMapper/MagicTableRowReaderInterface.php` | 23 | Pro 5, Sea 1 | P ObjectReaderInterface |
| `Db/MagicMapper/MagicUnaccentProbe.php` | 60 | Cfg 1 | P ObjectTablesInterface |
| `Db/MagicMapper/StatisticsScope.php` | 11 | Sea 1 | K value objects callers construct |
| `Db/MagicMapperInterface.php` | 34 | Com 34, Cfg 9, Fil 2, Int 4, Not 1, Pla 3, Pro 6, Sea 4 | P ObjectReaderInterface |
| `Db/MapLinkMapperInterface.php` | 10 | Int 5 | R row → Integrations |
| `Db/MapperRegistrar.php` | 9 | Pla 1 | W wiring |
| `Db/MultiTenancyTrait.php` | 55 | Cfg 2, Int 6, Sea 1 | K Db toolkit for every area's entities and mappers |
| `Db/ObjectHandling.php` | 14 | Cfg 1 | P ObjectTablesInterface |
| `Db/ObjectLinkTypesTrait.php` | 10 | Int 11, Pro 1 | K Db toolkit for every area's entities and mappers |
| `Db/ObjectReadState.php` | 30 | Cfg 1, Pro 3 | K value objects callers construct |
| `Db/ObjectReadStateMapperInterface.php` | 13 | Cfg 1, Pro 1 | P ObjectReaderInterface |
| `Db/OpenProjectLinkMapperInterface.php` | 11 | Int 5 | R row → Integrations |
| `Db/Organisation.php` | 288 | Cfg 1, Int 4, Ker 1, Sea 2 | P OrganisationInterface |
| `Db/OrganisationFieldTrait.php` | 12 | Cfg 1, Int 2 | K Db toolkit for every area's entities and mappers |
| `Db/OrganisationMapperInterface.php` | 41 | Com 2, Cfg 4, Int 7, Pro 3, Sea 1 | P TenancyInterface |
| `Db/OutcomeFilter.php` | 17 | Pro 5 | K value objects callers construct |
| `Db/PhotoLinkMapperInterface.php` | 11 | Int 5 | R row → Integrations |
| `Db/PollLinkMapperInterface.php` | 10 | Int 5 | R row → Integrations |
| `Db/ProcessingLogMapperInterface.php` | 13 | Com 6 | R row → Compliance |
| `Db/ProcessingLogReadScope.php` | 13 | Com 2 | R row → Compliance |
| `Db/ProcessingPurposeMapperInterface.php` | 12 | Com 5 | R row → Compliance |
| `Db/QueryPage.php` | 19 | Com 3, Cfg 2, Fil 2, Int 5, Pro 4 | K Db toolkit for every area's entities and mappers |
| `Db/RecordTemplateMapperInterface.php` | 13 | Cfg 3 | R row → Configuration |
| `Db/RegisterSchema.php` | 16 | Com 9, Cfg 9, Int 4, Pro 6, Sea 2 | K Db toolkit for every area's entities and mappers |
| `Db/RunObjectLockMapper.php` | 106 | Pro 1 | W wiring |
| `Db/RunObjectLockMapperInterface.php` | 12 | Pro 1 | W wiring |
| `Db/Schema/AuthorizationRulesValidator.php` | 161 | Ker 1 | K named only by Kernel |
| `Db/Schema/ConfigurationValidator.php` | 48 | Ker 1 | K named only by Kernel |
| `Db/Schema/DeclaredActions.php` | 37 | Ker 1 | K named only by Kernel |
| `Db/Schema/PropertyAuthorization.php` | 50 | Ker 1 | K named only by Kernel |
| `Db/Schema/SchemaGrantEvaluator.php` | 81 | Ker 1 | K named only by Kernel |
| `Db/Schema/VocabularyEntries.php` | 111 | Ker 1 | K named only by Kernel |
| `Db/SchemaCatalog.php` | 20 | Cfg 1 | W wiring |
| `Db/SchemaCatalogInterface.php` | 8 | Com 11, Cfg 11, Fil 1, Int 7, Not 2, Pla 3, Pro 13, Sea 7 | K Db toolkit for every area's entities and mappers |
| `Db/SchemaChangelog.php` | 38 | Pla 1 | P SchemaEvolutionInterface |
| `Db/SchemaChangelogMapperInterface.php` | 11 | Int 1, Pla 1 | P SchemaEvolutionInterface |
| `Db/SchemaMapper/AnnotationFaults.php` | 40 | Pro 1 | K value objects callers construct |
| `Db/SchemaMapper/DiscardedAnnotations.php` | 24 | Pro 3 | P SchemaDeclarationFindings |
| `Db/SchemaMapper/PositionedDeclarationCheck.php` | 19 | Pro 1 | R row → Processes |
| `Db/SchemaMapper/ReferenceLoops.php` | 82 | Pro 1 | R row → Processes |
| `Db/SchemaMapper/SchemaAnnotation.php` | 41 | Pro 2 | P SchemaDeclarationFindings |
| `Db/SchemaMapper/SchemaDeclarationChecksEvent.php` | 14 | Pro 2 | K events |
| `Db/SchemaMapper/SchemaDeclarationOrder.php` | 63 | Pro 1 | P SchemaDeclarationFindings |
| `Db/SchemaRunEntryMapperInterface.php` | 7 | Int 1 | P SchemaEvolutionInterface |
| `Db/SchemaRunMapperInterface.php` | 9 | Int 1 | P SchemaEvolutionInterface |
| `Db/SchemaRunStoreInterface.php` | 8 | Com 1 | P SchemaEvolutionInterface |
| `Db/SelectByColumnTrait.php` | 35 | Com 3, Cfg 6, Int 5, Pro 10 | K Db toolkit for every area's entities and mappers |
| `Db/SourceMapperInterface.php` | 16 | Com 1, Int 3 | K remaining |
| `Db/StateHistoryMapperInterface.php` | 14 | Com 1 | P AuditRecorderInterface |
| `Db/Tenancy/TenancyServices.php` | 14 | Cfg 2, Int 5, Sea 1 | P TenancyInterface |
| `Db/TenantUsageMapperInterface.php` | 11 | Com 2 | P TenancyInterface |
| `Db/TextSignatureMapper.php` | 36 | Sea 2 | K remaining |
| `Db/TimeTrackerLinkMapperInterface.php` | 12 | Int 5 | R row → Integrations |
| `Db/TypedFieldsJson.php` | 37 | Com 4, Cfg 8, Fil 2, Int 7, Pro 6 | K value objects callers construct |
| `Db/TypedFieldsJsonTrait.php` | 16 | Com 3, Cfg 6, Fil 4, Int 17, Pla 1, Pro 10 | K Db toolkit for every area's entities and mappers |
| `Db/VerwerkingsactiviteitMapperInterface.php` | 13 | Com 9 | K remaining |
| `Db/XwikiLinkMapperInterface.php` | 11 | Int 5 | R row → Integrations |
| `Event/AbstractOrganisationEvent.php` | 12 | Ker 2 | K named only by Kernel |
| `Event/AbstractTaskEvent.php` | 13 | Ker 2 | K named only by Kernel |
| `Event/AbstractTaskSequenceEvent.php` | 13 | Ker 2 | K named only by Kernel |
| `Event/ObjectLeavingEvent.php` | 5 | Pro 1 | W wiring |
| `Event/ObjectWriteRequestEvent.php` | 27 | Int 2 | K events |
| `Kernel/Contracts/Core/ActionRightsInterface.php` | 9 | Pla 2 | K ports and markers |
| `Kernel/Contracts/Core/AuditEntry.php` | 16 | Com 1 | K ports and markers |
| `Kernel/Contracts/Core/AuditEntryWriterInterface.php` | 6 | Com 1, Cfg 1 | K ports and markers |
| `Kernel/Contracts/Core/FieldRulesSourceInterface.php` | 8 | Pro 2 | K ports and markers |
| `Kernel/Contracts/Core/ObjectPresenceInterface.php` | 9 | Not 3 | K ports and markers |
| `Listener/CodedValueValidationListener.php` | 97 | Pro 1 | W wiring |
| `Listener/ConceptDeleteGuardListener.php` | 114 | Pro 1 | W wiring |
| `Listener/FacetCacheInvalidationListener.php` | 23 | Pro 1 | W wiring |
| `Listener/GeneratedIdentifierListener.php` | 210 | Pro 1 | W wiring |
| `Listener/GrantableRightsInvalidationListener.php` | 29 | Pro 1 | W wiring |
| `Listener/IdentityClaimsLoginListener.php` | 58 | Pro 1 | W wiring |
| `Listener/IdentityGuardListener.php` | 72 | Pro 1 | W wiring |
| `Listener/ListenerBootstrap.php` | 16 | Pla 1 | W wiring |
| `Listener/ListenerClassesInterface.php` | 6 | Ker 1 | K named only by Kernel |
| `Listener/NotifyPushBatch.php` | 17 | Pro 1 | W wiring |
| `Listener/NotifyPushBatchInterface.php` | 9 | Cfg 2, Pro 1 | K remaining |
| `Listener/ObjectCleanupListener.php` | 40 | Pro 1 | W wiring |
| `Listener/ObjectStateDeleteGuardListener.php` | 44 | Pro 1 | W wiring |
| `Listener/PresencePushInterface.php` | 7 | Pla 1 | K remaining |
| `Listener/SchemaFlowImportListenerInterface.php` | 7 | Cfg 1, Pro 2 | K remaining |
| `Listener/StateHistoryProjectionListener.php` | 55 | Pro 1 | W wiring |
| `Listener/Support/ConflictVeto.php` | 10 | Pro 2 | K remaining |
| `Listener/SurvivorshipRecomputeListener.php` | 194 | Pro 1 | W wiring |
| `Listener/UniqueConstraintListener.php` | 166 | Pro 1 | W wiring |
| `Listener/UniqueConstraintListenerInterface.php` | 6 | Pro 1 | W wiring |
| `Listener/ValidatesPreWriteObject.php` | 26 | Pro 2 | K remaining |
| `Listener/WrittenObjectOfEvent.php` | 29 | Cfg 1, Pro 2 | K events |
| `Repair/MigratedFlowOrigins.php` | 12 | Pla 1 | R row → Platform |
| `Repair/PerformerTyping.php` | 162 | Pla 1 | R row → Platform |
| `Service/Aggregation/AggregationRunner.php` | 2378 | Cfg 1, Pro 1 | P AggregationInterface |
| `Service/Aggregation/AggregationRunnerInterface.php` | 17 | Cfg 1, Sea 3 | P AggregationInterface |
| `Service/Aggregation/NamedAggregationOptions.php` | 11 | Sea 3 | K value objects callers construct |
| `Service/Aggregation/NamedAggregationRef.php` | 15 | Sea 3 | K value objects callers construct |
| `Service/Aggregation/WidgetAnnotationValidator.php` | 113 | Cfg 1 | W wiring |
| `Service/Aggregation/WidgetAnnotationValidatorInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/ApiVersion/ApiCapabilitiesService.php` | 112 | Com 1, Cfg 1 | I use ApiCapabilitiesServiceInterface |
| `Service/ApiVersion/ApiCapabilitiesServiceInterface.php` | 7 | Cfg 1, Pla 1 | P ApiVersionsInterface |
| `Service/ApiVersion/ApiContractService.php` | 61 | Cfg 1 | W wiring |
| `Service/ApiVersion/ApiContractServiceInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/ApiVersion/ApiVersionCatalogue.php` | 166 | Cfg 1 | W wiring |
| `Service/ApiVersion/ApiVersionCatalogueInterface.php` | 12 | Cfg 1, Int 2 | P ApiVersionsInterface |
| `Service/ApiVersion/ApiVersionInterface.php` | 11 | Pla 2 | P ApiVersionsInterface |
| `Service/ApiVersion/ApiVersionNegotiationInterface.php` | 8 | Pla 1 | P ApiVersionsInterface |
| `Service/ApiVersion/ApiVersionNegotiator.php` | 77 | Cfg 1 | W wiring |
| `Service/ApiVersion/ApiVersionNegotiatorInterface.php` | 9 | Cfg 1, Pla 2 | P ApiVersionsInterface |
| `Service/AppConfigurationImporterInterface.php` | 7 | Cfg 6, Int 1 | K remaining |
| `Service/AppRegisterImportRunnerInterface.php` | 6 | Cfg 3 | R row → Configuration |
| `Service/AuthenticationTokenFetcherInterface.php` | 8 | Cfg 1, Int 2 | R row → Integrations |
| `Service/Authorization/DeclaredGroupInventoryInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Authorization/DeclaredGroupInventoryService.php` | 65 | Cfg 1 | W wiring |
| `Service/Authorization/GrantableRightsIndex.php` | 147 | Cfg 1 | W wiring |
| `Service/Authorization/GrantableRightsIndexInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Authorization/GroupProvisioner.php` | 93 | Cfg 2 | P ObjectGrantsInterface |
| `Service/Authorization/GroupReconciler.php` | 90 | Cfg 1 | W wiring |
| `Service/Authorization/GroupReconcilerInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Authorization/RbacGroupCollector.php` | 123 | Cfg 2 | P ObjectGrantsInterface |
| `Service/AvgComplianceServiceInterface.php` | 6 | Com 2, Cfg 1 | R row → Compliance |
| `Service/AvgRetentionPassInterface.php` | 6 | Com 2, Cfg 1 | R row → Compliance |
| `Service/BulkActionRegistryInterface.php` | 9 | Cfg 1, Ker 1, Pro 3 | P ObjectWriterInterface |
| `Service/BulkJob/ReversibilityDeclarationException.php` | 16 | Pro 1 | K value objects callers construct |
| `Service/BulkTranslationServiceInterface.php` | 6 | Cfg 1, Pro 2 | R row → Processes |
| `Service/Calculation/CalculationDeclarationException.php` | 16 | Pro 1 | K value objects callers construct |
| `Service/CauseFrame.php` | 10 | Cfg 3 | K value objects callers construct |
| `Service/ConfigLookup.php` | 19 | Int 1 | K value objects callers construct |
| `Service/Consent/ConsentAnnotationValidator.php` | 83 | Cfg 1 | W wiring |
| `Service/Consent/ConsentAnnotationValidatorInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Consent/ConsentEnvelopeEvaluatorInterface.php` | 6 | Pro 1 | R row → Processes |
| `Service/Consent/ConsentEnvelopeEvaluators.php` | 8 | Pro 1 | R row → Processes |
| `Service/Consent/ConsentEvidenceSource.php` | 11 | Pro 1 | R row → Processes |
| `Service/Consent/ConsentPropertyWrite.php` | 24 | Pro 1 | R row → Processes |
| `Service/DateTimeNormalizer.php` | 85 | Cfg 1 | W wiring |
| `Service/DateTimeNormalizerInterface.php` | 13 | Cfg 1 | W wiring |
| `Service/Dbal/DatabaseIntrospectionService.php` | 451 | Cfg 1 | W wiring |
| `Service/Dbal/DatabaseIntrospectionServiceInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Dbal/DbalConnectionFactory.php` | 129 | Cfg 1 | W wiring |
| `Service/Dbal/DbalConnectionFactoryInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Decision/ReviewDeclarationException.php` | 16 | Pro 1 | K value objects callers construct |
| `Service/DeclaredPair.php` | 10 | Pro 1 | K value objects callers construct |
| `Service/DeepLinkRegistryServiceInterface.php` | 12 | Cfg 1, Int 2, Ker 1, Not 1, Pla 1, Pro 1, Sea 2 | P DeepLinksInterface |
| `Service/DeepLinkResolverInterface.php` | 12 | Cfg 1, Int 4, Not 5 | P DeepLinksInterface |
| `Service/Deferral/DeferredEntryObjectResolver.php` | 58 | Cfg 1, Pro 1 | I use DeferredEntryObjectResolverInterface |
| `Service/Deferral/DeferredEntryObjectResolverInterface.php` | 7 | Com 1, Cfg 1, Int 1, Pro 3, Sea 1 | P ListenerDeferralInterface |
| `Service/Deferral/DeferredListenerContext.php` | 78 | Int 1, Pro 4, Sea 1 | K job base and job payloads |
| `Service/Deferral/ListenerDeferralInterface.php` | 13 | Cfg 1, Int 2, Pro 3, Sea 1 | P ListenerDeferralInterface |
| `Service/Deferral/ListenerDeferralService.php` | 270 | Cfg 1, Pro 1 | I use ListenerDeferralInterface |
| `Service/Delegation/DelegationConsentService.php` | 133 | Cfg 1 | W wiring |
| `Service/Delegation/DelegationConsentServiceInterface.php` | 13 | Cfg 1 | W wiring |
| `Service/Delegation/DelegationNotifier.php` | 77 | Cfg 1 | W wiring |
| `Service/Delegation/DelegationNotifierInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Delegation/DelegationRefused.php` | 32 | Pro 5 | R row → Processes |
| `Service/Delegation/DelegationRequest.php` | 13 | Pro 3 | R row → Processes |
| `Service/Delegation/DelegationService.php` | 87 | Pro 4 | R row → Processes |
| `Service/Delegation/DelegationVerdict.php` | 50 | Pro 3 | R row → Processes |
| `Service/Deletion/DeletedObjectAuthorizer.php` | 101 | Cfg 1 | W wiring |
| `Service/Deletion/DeletedObjectAuthorizerInterface.php` | 14 | Cfg 1 | W wiring |
| `Service/Deletion/DestroyRightService.php` | 125 | Com 1 | P ObjectDestructionInterface |
| `Service/Deletion/DestructionRecorder.php` | 76 | Com 1 | P ObjectDestructionInterface |
| `Service/Deletion/DestructionRefusedException.php` | 9 | Com 2 | K remaining |
| `Service/Deletion/DestructionScopeService.php` | 186 | Com 2 | P ObjectDestructionInterface |
| `Service/Deletion/RecordDestructionCommand.php` | 13 | Com 1 | K value objects callers construct |
| `Service/Deletion/RetentionClockService.php` | 153 | Com 2 | P ObjectDestructionInterface |
| `Service/DemoDataServiceInterface.php` | 10 | Cfg 3 | R row → Configuration |
| `Service/DisposalSweep.php` | 10 | Com 4 | R row → Compliance |
| `Service/DsarServiceInterface.php` | 9 | Com 2, Cfg 1 | R row → Compliance |
| `Service/EntryScripts.php` | 8 | Pla 1, Pro 3 | K write and operation context |
| `Service/ExecutedDestruction.php` | 11 | Com 3 | R row → Compliance |
| `Service/ExternalLink/ExternalLinkAnnotationValidator.php` | 124 | Cfg 1 | W wiring |
| `Service/ExternalLink/ExternalLinkAnnotationValidatorInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/ExternalLink/ExternalLinkResolver.php` | 125 | Cfg 1 | W wiring |
| `Service/ExternalLink/ExternalLinkResolverInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/FailedAttempt.php` | 11 | Pla 1 | K value objects callers construct |
| `Service/Federation/CreatorPropertyFilter.php` | 46 | Int 2 | R row → Integrations |
| `Service/Federation/PlainQueryMatch.php` | 31 | Int 1 | R row → Integrations |
| `Service/Federation/RemoteShareNotification.php` | 52 | Int 1 | R row → Integrations |
| `Service/Federation/ShareCreatorCheck.php` | 52 | Int 2 | R row → Integrations |
| `Service/Federation/ShareGrantor.php` | 73 | Cfg 1, Int 1 | I use ShareGrantorInterface |
| `Service/Federation/ShareGrantorInterface.php` | 7 | Cfg 1, Int 1 | P ObjectGrantsInterface |
| `Service/Federation/ShareLifetime.php` | 26 | Int 1, Pla 1 | P ObjectGrantsInterface |
| `Service/FieldEncryptionHandler.php` | 126 | Cfg 1 | W wiring |
| `Service/FieldEncryptionHandlerInterface.php` | 10 | Cfg 1 | W wiring |
| `Service/File/AntivirusScannerAdapter.php` | 63 | Fil 1 | W wiring |
| `Service/File/AntivirusScannerInterface.php` | 6 | Fil 1 | W wiring |
| `Service/File/ArchiveBounds.php` | 47 | Cfg 1, Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/ArchiveListing.php` | 30 | Fil 1 | R row → Files |
| `Service/File/ArchiveMemberEnumerator.php` | 138 | Fil 1 | R row → Files |
| `Service/File/DocumentAnonymization.php` | 12 | Fil 2 | F to Files behind FileStorageInterface |
| `Service/File/DocumentStore.php` | 83 | Fil 3, Pro 1 | F to Files behind FileStorageInterface |
| `Service/File/FetchedFile.php` | 25 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/FileBatchHandler.php` | 97 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/FileBatchRequest.php` | 14 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/FileLockHandler.php` | 174 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/FileMetadataChange.php` | 15 | Fil 3 | F to Files behind FileStorageInterface |
| `Service/File/FileOverwrite.php` | 13 | Fil 2 | F to Files behind FileStorageInterface |
| `Service/File/FilePreviewHandler.php` | 39 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/FilePropertyRules.php` | 53 | Pro 1 | F to Files behind FileStorageInterface |
| `Service/File/FileRename.php` | 12 | Fil 4 | F to Files behind FileStorageInterface |
| `Service/File/FileTarget.php` | 11 | Cfg 2, Fil 4, Pro 1 | F to Files behind FileStorageInterface |
| `Service/File/FileTransfer.php` | 12 | Fil 2 | F to Files behind FileStorageInterface |
| `Service/File/FileUpload.php` | 12 | Cfg 2, Fil 5, Pro 1 | F to Files behind FileStorageInterface |
| `Service/File/FileVersioningHandler.php` | 183 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/FolderManagementHandler.php` | 655 | Com 1 | F to Files behind FileStorageInterface |
| `Service/File/ImageBurner.php` | 74 | Com 1 | F to Files behind FileStorageInterface |
| `Service/File/ImageRedactionService.php` | 253 | Com 1, Fil 2 | F to Files behind FileStorageInterface |
| `Service/File/ImageRegionNotBurnableException.php` | 21 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/MalwareScanGate.php` | 98 | Fil 1 | R row → Files |
| `Service/File/MalwareScanState.php` | 45 | Fil 2 | R row → Files |
| `Service/File/ManagedFileOwnerLookup.php` | 33 | Fil 1, Pro 1 | F to Files behind FileStorageInterface |
| `Service/File/OcrWords.php` | 50 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/OfficeMediaBurner.php` | 107 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/ShareLinkRequest.php` | 20 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/UrlFetchRefused.php` | 14 | Fil 1 | F to Files behind FileStorageInterface |
| `Service/File/UrlFileSource.php` | 62 | Fil 2 | F to Files behind FileStorageInterface |
| `Service/FileService.php` | 697 | Cfg 4, Int 2 | F to Files behind FileStorageInterface |
| `Service/Geo/PdokGeocoder.php` | 165 | Cfg 1 | W wiring |
| `Service/Geo/PdokGeocoderInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/GraphQL/GraphQLResolver.php` | 278 | Sea 3 | R row → Search |
| `Service/Hinge/HingeAnnotationValidator.php` | 210 | Cfg 1 | W wiring |
| `Service/Hinge/HingeAnnotationValidatorInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Hinge/InheritedGeoCollector.php` | 189 | Cfg 1 | W wiring |
| `Service/Hinge/InheritedGeoCollectorInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Hinge/ListPresentationResolver.php` | 66 | Cfg 1 | W wiring |
| `Service/Hinge/ListPresentationResolverInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Hinge/ReferencedByService.php` | 227 | Cfg 1 | W wiring |
| `Service/Hinge/ReferencedByServiceInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/History/StateHistoryProjector.php` | 73 | Cfg 1 | W wiring |
| `Service/History/StateHistoryProjectorInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/History/StateHistoryRebuild.php` | 123 | Cfg 1 | W wiring |
| `Service/History/StateHistoryRebuildInterface.php` | 7 | Com 1, Cfg 1 | P AuditRecorderInterface |
| `Service/History/StateHistoryRecorderInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/HoldOrder.php` | 10 | Com 7 | R row → Compliance |
| `Service/InboundAuthRateLimitInterface.php` | 10 | Cfg 1, Pla 1 | P RequestSecurityInterface |
| `Service/JsonLd/JsonLdContextService.php` | 316 | Cfg 1, Int 2 | I use JsonLdContextServiceInterface |
| `Service/JsonLd/JsonLdContextServiceInterface.php` | 10 | Cfg 1 | W wiring |
| `Service/JsonLd/JsonLdSerializer.php` | 203 | Cfg 1 | W wiring |
| `Service/JsonLd/JsonLdSerializerInterface.php` | 10 | Cfg 1 | W wiring |
| `Service/Lifecycle/StateFieldLists.php` | 11 | Pro 1 | K value objects callers construct |
| `Service/Lifecycle/StateFieldRules.php` | 50 | Pro 2 | I use StateFieldRulesInterface |
| `Service/Lifecycle/StateFieldRulesInterface.php` | 9 | Pro 1 | P PropertyAccessInterface |
| `Service/LinkPageWindow.php` | 16 | Int 1 | R row → Integrations |
| `Service/LinkServiceRuntime.php` | 23 | Int 18 | R row → Integrations |
| `Service/LinkSessionRuntime.php` | 12 | Com 1, Int 2, Pro 1 | K write and operation context |
| `Service/LinkedEntityIntegrations.php` | 13 | Int 1 | R row → Integrations |
| `Service/LinkedEntityMappers.php` | 21 | Int 1 | R row → Integrations |
| `Service/LinkedEntityServiceInterface.php` | 11 | Cfg 1, Int 2 | R row → Integrations |
| `Service/LinkedEventMerge.php` | 44 | Int 1 | R row → Integrations |
| `Service/LogExportFormatter.php` | 124 | Com 1 | R row → Compliance |
| `Service/LogObjectInTable.php` | 37 | Com 1 | R row → Compliance |
| `Service/LoginSecurityInterface.php` | 12 | Cfg 1 | W wiring |
| `Service/ManifestServiceInterface.php` | 6 | Cfg 3 | R row → Configuration |
| `Service/ManifestSources.php` | 12 | Cfg 1 | R row → Configuration |
| `Service/MapLinkServiceInterface.php` | 13 | Cfg 1, Int 3 | R row → Integrations |
| `Service/MdiIconSvg.php` | 11 | Int 1 | W wiring |
| `Service/MdiIconSvgInterface.php` | 6 | Cfg 1, Int 1 | K remaining |
| `Service/Merge/MasterRecomputeInterface.php` | 6 | Cfg 1, Pro 2 | P ObjectWriterInterface |
| `Service/Merge/MasterRecomputeService.php` | 39 | Cfg 1 | W wiring |
| `Service/Merge/MergeService.php` | 887 | Cfg 1 | W wiring |
| `Service/Merge/ReferenceFinderInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Merge/ReferenceWriterInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Merge/RelationIndexReferenceFinder.php` | 35 | Cfg 1 | W wiring |
| `Service/Merge/SavePathReferenceWriter.php` | 45 | Cfg 1 | W wiring |
| `Service/MigrationServiceInterface.php` | 21 | Cfg 4 | R row → Configuration |
| `Service/Oas/OasETagComputer.php` | 58 | Cfg 1 | W wiring |
| `Service/Oas/OasETagComputerInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Oas/OasRequestValidator.php` | 97 | Cfg 3, Pro 1 | I use OasRequestValidatorInterface |
| `Service/Oas/OasRequestValidatorInterface.php` | 6 | Cfg 1, Pla 1 | P ProblemDetailsInterface |
| `Service/Oas/ProblemDescription.php` | 11 | Pla 3 | K value objects callers construct |
| `Service/Oas/ProblemDetailsBuilder.php` | 64 | Cfg 1 | W wiring |
| `Service/Oas/ProblemDetailsBuilderInterface.php` | 8 | Cfg 1, Pla 3 | P ProblemDetailsInterface |
| `Service/Oas/ProblemStatement.php` | 12 | Pla 2 | K value objects callers construct |
| `Service/OasService.php` | 922 | Cfg 1 | W wiring |
| `Service/OasServiceInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Object/ArchiveHandler.php` | 177 | Cfg 1 | W wiring |
| `Service/Object/ArchiveHandlerInterface.php` | 11 | Cfg 1 | W wiring |
| `Service/Object/AuthorizationResolutionInterface.php` | 15 | Cfg 1 | W wiring |
| `Service/Object/BatchOperationStatus.php` | 107 | Cfg 1 | W wiring |
| `Service/Object/CacheHandler.php` | 1248 | Cfg 2 | I use CacheHandlerInterface |
| `Service/Object/CacheHandlerInterface.php` | 12 | Cfg 2 | P ObjectNameCacheInterface |
| `Service/Object/ConflictReport.php` | 127 | Cfg 1 | W wiring |
| `Service/Object/ConflictReportInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Object/CorrectionService.php` | 151 | Cfg 1 | W wiring |
| `Service/Object/CorrectionServiceInterface.php` | 10 | Com 1, Cfg 1 | P ObjectDestructionInterface |
| `Service/Object/CorrectionTarget.php` | 11 | Com 1 | P ObjectDestructionInterface |
| `Service/Object/CorrectionTargets.php` | 9 | Int 1 | W wiring |
| `Service/Object/CorrectionTargetsInterface.php` | 7 | Com 1, Int 1 | P ObjectDestructionInterface |
| `Service/Object/DeleteObject.php` | 605 | Com 1 | P ObjectDestructionInterface |
| `Service/Object/DeletionRequest.php` | 13 | Com 1 | K value objects callers construct |
| `Service/Object/DraftActivation.php` | 146 | Cfg 1 | W wiring |
| `Service/Object/DraftActivationInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Object/DraftAddress.php` | 13 | Pro 1 | K value objects callers construct |
| `Service/Object/DraftExpiry.php` | 270 | Cfg 1 | W wiring |
| `Service/Object/DraftExpiryInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Object/DraftFinder.php` | 127 | Pro 1 | P ObjectDraftsInterface |
| `Service/Object/DraftOwner.php` | 53 | Cfg 1, Pro 5 | P ObjectDraftsInterface |
| `Service/Object/DraftRemovalFrame.php` | 8 | Int 1 | W wiring |
| `Service/Object/DraftRemovalFrameInterface.php` | 6 | Int 1 | W wiring |
| `Service/Object/DraftService.php` | 143 | Pro 1 | P ObjectDraftsInterface |
| `Service/Object/DraftStatusPolicy.php` | 141 | Pro 1 | K value objects callers construct |
| `Service/Object/FacetCacheVersion.php` | 135 | Cfg 1 | W wiring |
| `Service/Object/FacetCacheVersionInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Object/FileWriteGuard.php` | 22 | Cfg 1 | W wiring |
| `Service/Object/FileWriteGuardInterface.php` | 9 | Cfg 1, Fil 3, Pro 1 | P ObjectAccessInterface |
| `Service/Object/GroupRuleRequest.php` | 41 | Int 1 | K value objects callers construct |
| `Service/Object/MoveObject.php` | 247 | Cfg 1 | W wiring |
| `Service/Object/MoveObjectInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Object/MovedObjectLookup.php` | 71 | Cfg 1 | W wiring |
| `Service/Object/MovedObjectLookupInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Object/ObjectCacheInvalidationInterface.php` | 10 | Cfg 1 | W wiring |
| `Service/Object/ObjectCacheMaintenanceInterface.php` | 9 | Cfg 2 | P ObjectNameCacheInterface |
| `Service/Object/ObjectEntityCacheInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Object/ObjectEventGate.php` | 50 | Cfg 1, Pro 1 | I use ObjectEventGateInterface |
| `Service/Object/ObjectEventGateInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Object/ObjectNameWriterInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Object/ObjectReadAccess.php` | 77 | Com 1, Cfg 1 | I use ObjectReadAccessInterface |
| `Service/Object/ObjectReadAccessInterface.php` | 13 | Cfg 1 | W wiring |
| `Service/Object/ObjectReadersInterface.php` | 7 | Cfg 1, Pla 1 | P ObjectReaderInterface |
| `Service/Object/ObjectSaveCheck.php` | 8 | Pro 1 | P ObjectAccessInterface |
| `Service/Object/ObjectSlugBackfillService.php` | 147 | Cfg 1 | W wiring |
| `Service/Object/ObjectSlugBackfillServiceInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Object/ObjectValidatorInterface.php` | 14 | Cfg 3, Pro 1 | P ObjectWriterInterface |
| `Service/Object/OwnDraftSearch.php` | 14 | Pro 1 | K value objects callers construct |
| `Service/Object/PermissionCacheInterface.php` | 7 | Cfg 1, Pro 1 | P ObjectAccessInterface |
| `Service/Object/PermissionCheckInterface.php` | 18 | Cfg 2, Int 1, Not 2, Pla 1, Pro 5 | P ObjectAccessInterface |
| `Service/Object/PermissionCheckOptions.php` | 13 | Int 2, Pro 1 | K value objects callers construct |
| `Service/Object/PermissionEnforcementInterface.php` | 13 | Cfg 1, Int 1 | P ObjectAccessInterface |
| `Service/Object/PermissionExplanationInterface.php` | 13 | Cfg 1 | W wiring |
| `Service/Object/PermissionHandler.php` | 1562 | Cfg 1 | W wiring |
| `Service/Object/PermissionHandlerInterface.php` | 14 | Cfg 2, Fil 1, Int 6, Not 4, Pro 9, Sea 1 | P ObjectAccessInterface |
| `Service/Object/PermissionScopeInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Object/PlannedAccountsCheck.php` | 12 | Cfg 1 | K value objects callers construct |
| `Service/Object/PropertySourceLanguage.php` | 32 | Cfg 1 | K value objects callers construct |
| `Service/Object/QueryHandler.php` | 338 | Cfg 1 | W wiring |
| `Service/Object/QueryHandlerInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Object/RelationAddressRewrite.php` | 42 | Cfg 1 | W wiring |
| `Service/Object/RelationAddressRewriteInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Object/RenderObject.php` | 2325 | Com 1, Cfg 1 | I use RenderObjectInterface |
| `Service/Object/RenderObjectInterface.php` | 21 | Cfg 1 | W wiring |
| `Service/Object/RetentionDestruction.php` | 17 | Int 1 | W wiring |
| `Service/Object/RetentionDestructionInterface.php` | 7 | Com 1, Int 1 | P ObjectDestructionInterface |
| `Service/Object/RevertHandler.php` | 122 | Cfg 1 | W wiring |
| `Service/Object/RevertHandlerInterface.php` | 11 | Cfg 1 | W wiring |
| `Service/Object/RunLockRegistry.php` | 130 | Cfg 1 | W wiring |
| `Service/Object/RunLockReleaseInterface.php` | 8 | Cfg 1, Pro 3 | P ObjectWriterInterface |
| `Service/Object/SaveObject.php` | 2083 | Cfg 1 | W wiring |
| `Service/Object/SaveObject/ObjectSlugHandler.php` | 155 | Cfg 3 | I use ObjectSlugTargetInterface |
| `Service/Object/SaveObject/ObjectSlugTargetInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Object/SaveObject/RelationCascadeHandler.php` | 313 | Cfg 2 | P ObjectWriterInterface |
| `Service/Object/SaveObject/RevealRelations.php` | 28 | Pla 1 | R row → Platform |
| `Service/Object/SaveObject/SlugRequest.php` | 12 | Cfg 1 | K value objects callers construct |
| `Service/Object/SaveObjectInterface.php` | 33 | Cfg 1 | W wiring |
| `Service/Object/SaveObjectRelatedUpdateGuardInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Object/SaveObjectRelationScannerInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Object/SaveObjectUpdaterInterface.php` | 11 | Cfg 1 | W wiring |
| `Service/Object/SaveObjectWriterInterface.php` | 18 | Com 6, Cfg 1 | P ObjectWriterInterface |
| `Service/Object/SchemaTypeConverter.php` | 110 | Cfg 1, Pro 2 | I use SchemaTypeConverterInterface |
| `Service/Object/SchemaTypeConverterInterface.php` | 7 | Cfg 1, Pro 1 | P SchemaLookupInterface |
| `Service/Object/SchemaUriResolverInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Object/TransitionAuthorizationInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Object/TransitionGate.php` | 11 | Pro 1 | K value objects callers construct |
| `Service/Object/TranslationHandler.php` | 118 | Cfg 3, Pla 1 | P ObjectReaderInterface |
| `Service/Object/TranslationHandlerInterface.php` | 7 | Cfg 1, Pro 1 | P ObjectReaderInterface |
| `Service/Object/UpsertOnKeyHandler.php` | 167 | Cfg 1 | W wiring |
| `Service/Object/UpsertOnKeyHandlerInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Object/ValidateObject.php` | 894 | Cfg 3, Int 1 | I use ValidateObjectInterface |
| `Service/Object/ValidateObjectInterface.php` | 10 | Cfg 4 | P ObjectWriterInterface |
| `Service/Object/ValidationErrorResponseInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Object/WriteConstraintValidatorInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/ObjectFilesInterface.php` | 5 | Cfg 2, Fil 2 | P ObjectReaderInterface |
| `Service/ObjectHandlerAccessInterface.php` | 14 | Cfg 1 | W wiring |
| `Service/ObjectRelationCleanupLinks.php` | 13 | Int 1 | R row → Integrations |
| `Service/ObjectRelationCleanupServiceInterface.php` | 6 | Cfg 1, Int 1 | P ObjectWriterInterface |
| `Service/ObjectService.php` | 3010 | Cfg 3, Int 4 | P ObjectOperationsInterface (Kernel, exists) |
| `Service/ObjectSource/DbalObjectSourceException.php` | 15 | Pla 1 | P ObjectTablesInterface |
| `Service/ObjectSource/DbalWriteException.php` | 32 | Pla 1 | P ObjectTablesInterface |
| `Service/ObjectSource/NcEntitySemanticMap.php` | 91 | Pla 2 | R row → Platform |
| `Service/ObjectSource/ObjectSourceRegistrar.php` | 13 | Pla 1 | W wiring |
| `Service/ObjectSource/TablesCatalogueInterface.php` | 7 | Int 1, Pla 1 | P ObjectTablesInterface |
| `Service/ObjectSource/TablesSchemaReconcilerInterface.php` | 7 | Int 1, Pla 1, Pro 1 | P ObjectTablesInterface |
| `Service/ObjectStreamingSaveInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/OpenProjectLinkServiceInterface.php` | 13 | Cfg 1, Int 3 | R row → Integrations |
| `Service/Organisation/OrganisationEditAuditorInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Organisation/OrganisationLifecycleAuditor.php` | 86 | Cfg 1 | W wiring |
| `Service/OrganisationContext.php` | 32 | Com 2 | P TenancyInterface |
| `Service/OrganisationService.php` | 957 | Cfg 2 | I use OrganisationServiceInterface |
| `Service/OrganisationServiceInterface.php` | 33 | Com 2, Cfg 4, Int 9, Pla 4, Pro 7, Sea 1 | P TenancyInterface |
| `Service/OrganisationUsageService.php` | 99 | Cfg 1 | W wiring |
| `Service/OrganisationUsageServiceInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/PersonLinkServiceInterface.php` | 11 | Cfg 1, Int 3 | R row → Integrations |
| `Service/PersonLinkTarget.php` | 11 | Int 3 | R row → Integrations |
| `Service/PersonLinkTargets.php` | 10 | Int 1 | R row → Integrations |
| `Service/PhotoLinkServiceInterface.php` | 13 | Cfg 1, Int 3 | R row → Integrations |
| `Service/PollLinkServiceInterface.php` | 13 | Cfg 1, Int 3 | R row → Integrations |
| `Service/PropertyAccessRequest.php` | 30 | Cfg 1 | K value objects callers construct |
| `Service/PropertyRbacHandler.php` | 326 | Cfg 3 | I use PropertyRbacHandlerInterface |
| `Service/PropertyRbacHandlerInterface.php` | 19 | Com 1, Cfg 1, Sea 5 | P PropertyAccessInterface |
| `Service/Quality/QualityAuditEvaluator.php` | 152 | Com 1 | R row → Compliance |
| `Service/Quality/QualityListingRequest.php` | 12 | Com 1 | K value objects callers construct |
| `Service/Quality/QualityListingWindow.php` | 12 | Com 1 | K value objects callers construct |
| `Service/Quality/QualityScopeMappers.php` | 12 | Com 1 | K remaining |
| `Service/Quality/QualityScorer.php` | 178 | Cfg 1 | W wiring |
| `Service/Quality/QualityScorerInterface.php` | 8 | Com 1, Cfg 1 | K remaining |
| `Service/Quality/QualityStatisticsService.php` | 226 | Cfg 1 | W wiring |
| `Service/Quality/QualityStatisticsServiceInterface.php` | 9 | Com 1, Cfg 1 | K remaining |
| `Service/Quality/TextSignature.php` | 81 | Sea 2 | K remaining |
| `Service/Quality/TextSignatureStore.php` | 41 | Fil 1 | R row → Files |
| `Service/Quality/UniqueHintAnnotationValidatorInterface.php` | 7 | Sea 3 | K remaining |
| `Service/Quality/UniqueHintWarnings.php` | 17 | Cfg 1 | W wiring |
| `Service/Quality/UniqueHintWarningsInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/RateLimitResetInterface.php` | 7 | Cfg 2 | P RequestSecurityInterface |
| `Service/Rbac/AccessDeclarationValidators.php` | 21 | Sea 1 | P ObjectAccessInterface |
| `Service/Rbac/AccessReviewGate.php` | 133 | Cfg 1 | W wiring |
| `Service/Rbac/AccessReviewGateInterface.php` | 11 | Cfg 1 | W wiring |
| `Service/Rbac/AdminGate.php` | 31 | Cfg 1 | W wiring |
| `Service/Rbac/AdminGateInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Rbac/AggregateVisibilityInterface.php` | 9 | Sea 1 | P ObjectAccessInterface |
| `Service/Rbac/AnonymousEnvelope.php` | 67 | Cfg 2 | I use AnonymousEnvelopeInterface |
| `Service/Rbac/AnonymousEnvelopeInterface.php` | 7 | Cfg 1, Pla 1 | P ViewerReachInterface |
| `Service/Rbac/AuditRowWriter.php` | 31 | Com 3 | K value objects callers construct |
| `Service/Rbac/CallerContext.php` | 12 | Com 1, Pro 1, Sea 1 | P ObjectAccessInterface |
| `Service/Rbac/CrossOrganisationMarkerChange.php` | 50 | Cfg 2 | I use CrossOrganisationMarkerChangeInterface |
| `Service/Rbac/DefinitionWriteRights.php` | 38 | Cfg 1 | W wiring |
| `Service/Rbac/DefinitionWriteRightsInterface.php` | 7 | Cfg 1, Int 2 | P ObjectGrantsInterface |
| `Service/Rbac/DenyEnforcementMode.php` | 58 | Int 1 | W wiring |
| `Service/Rbac/DenyEnforcementModeInterface.php` | 7 | Int 1 | W wiring |
| `Service/Rbac/DenyResolver.php` | 184 | Cfg 1 | W wiring |
| `Service/Rbac/DenyResolverInterface.php` | 18 | Cfg 1 | W wiring |
| `Service/Rbac/DepartmentMatrixCompiler.php` | 191 | Fil 1, Pro 1 | P ObjectAccessInterface |
| `Service/Rbac/DepartmentMatrixValidator.php` | 220 | Fil 1 | P ObjectAccessInterface |
| `Service/Rbac/DerivedGrantLoginInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Rbac/DerivedGrantStore.php` | 141 | Cfg 1 | W wiring |
| `Service/Rbac/DerivedGrantStoreInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Rbac/ExplicitReveal.php` | 138 | Com 1, Cfg 2 | P RevealInterface |
| `Service/Rbac/ExplicitRevealInterface.php` | 11 | Cfg 1 | W wiring |
| `Service/Rbac/ExplicitRevealMasks.php` | 15 | Cfg 1, Fil 1, Int 1, Sea 1 | P RevealInterface |
| `Service/Rbac/ExplicitRevealTrailMasker.php` | 92 | Com 1, Cfg 1 | I use ExplicitRevealTrailMaskerInterface |
| `Service/Rbac/ExplicitRevealTrailMaskerInterface.php` | 6 | Com 2, Cfg 1 | P RevealInterface |
| `Service/Rbac/ExternalGrantLapseNotifier.php` | 63 | Cfg 1 | W wiring |
| `Service/Rbac/ExternalGrantLapseNotifierInterface.php` | 7 | Com 1, Cfg 1 | P ObjectGrantsInterface |
| `Service/Rbac/GrantChangeAuditor.php` | 70 | Cfg 1 | W wiring |
| `Service/Rbac/GrantChangeAuditorInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Rbac/GrantLookup.php` | 10 | Int 1 | K value objects callers construct |
| `Service/Rbac/GrantRequest.php` | 11 | Cfg 1 | K value objects callers construct |
| `Service/Rbac/GrantTerms.php` | 29 | Cfg 1 | K value objects callers construct |
| `Service/Rbac/MatrixRowKeys.php` | 6 | Cfg 1 | W wiring |
| `Service/Rbac/MatrixRowWhere.php` | 83 | Cfg 1 | W wiring |
| `Service/Rbac/ObjectAccessReport.php` | 82 | Cfg 1 | W wiring |
| `Service/Rbac/ObjectAccessReportInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Rbac/ObjectGrantResolver.php` | 213 | Cfg 1, Int 1 | I use ObjectGrantResolverInterface |
| `Service/Rbac/ObjectGrantResolverInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Rbac/ObjectGrantsReport.php` | 174 | Cfg 1 | W wiring |
| `Service/Rbac/ObjectGrantsReportInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Rbac/ObjectOwnershipService.php` | 526 | Cfg 2 | I use ObjectOwnershipServiceInterface |
| `Service/Rbac/ObjectOwnershipServiceInterface.php` | 16 | Cfg 1 | W wiring |
| `Service/Rbac/ObjectScopeResolver.php` | 98 | Cfg 1, Pro 1 | I use ObjectScopeResolverInterface |
| `Service/Rbac/ObjectScopeResolverInterface.php` | 18 | Cfg 1, Pro 1 | P ObjectAccessInterface |
| `Service/Rbac/ObjectSharingService.php` | 319 | Cfg 2 | I use ObjectSharingServiceInterface |
| `Service/Rbac/ObjectSharingServiceInterface.php` | 17 | Cfg 1 | W wiring |
| `Service/Rbac/OrganisationSpecialRights.php` | 264 | Cfg 1 | W wiring |
| `Service/Rbac/OrganisationSpecialRightsInterface.php` | 24 | Cfg 2, Int 1 | P ObjectGrantsInterface |
| `Service/Rbac/OwnershipReassignment.php` | 13 | Cfg 1 | K value objects callers construct |
| `Service/Rbac/PermissionCatalogue.php` | 167 | Cfg 1, Pro 1 | I use PermissionCatalogueInterface |
| `Service/Rbac/PermissionCatalogueInterface.php` | 42 | Cfg 1, Int 2 | P ObjectGrantsInterface |
| `Service/Rbac/PermissionReportScope.php` | 36 | Cfg 1 | W wiring |
| `Service/Rbac/PermissionReportScopeInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Rbac/PrincipalReachListerInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Rbac/PrincipalReachService.php` | 165 | Cfg 1 | W wiring |
| `Service/Rbac/ProcessRevealAudit.php` | 19 | Cfg 1 | W wiring |
| `Service/Rbac/ProcessRevealAuditInterface.php` | 6 | Cfg 2, Pro 1, Sea 1 | P RevealInterface |
| `Service/Rbac/RbacChecks.php` | 18 | Com 1, Int 1, Sea 1 | P ObjectAccessInterface |
| `Service/Rbac/RbacRegistrar.php` | 9 | Pla 1 | W wiring |
| `Service/Rbac/RbacRuleChecks.php` | 24 | Int 1, Sea 2 | P ObjectAccessInterface |
| `Service/Rbac/ReachRevocationService.php` | 187 | Cfg 1 | W wiring |
| `Service/Rbac/ReachRevocationServiceInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Rbac/ReachedView.php` | 10 | Sea 1 | K value objects callers construct |
| `Service/Rbac/RevealFlusher.php` | 90 | Cfg 1 | W wiring |
| `Service/Rbac/RevealFlusherInterface.php` | 6 | Cfg 1, Pla 1 | P RevealInterface |
| `Service/Rbac/RevealingProcess.php` | 10 | Cfg 1, Pro 1, Sea 1 | K value objects callers construct |
| `Service/Rbac/SchemaManageGate.php` | 102 | Cfg 1 | W wiring |
| `Service/Rbac/SchemaManageGateInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Rbac/ScopeAudit.php` | 68 | Cfg 1 | W wiring |
| `Service/Rbac/ScopeAuditInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Rbac/SettingsChangeAuditor.php` | 137 | Cfg 2 | P ObjectGrantsInterface |
| `Service/Rbac/SharerFolderAccess.php` | 209 | Com 1, Pro 1 | P ObjectAccessInterface |
| `Service/Rbac/TokenGrant.php` | 134 | Int 1 | P ObjectGrantsInterface |
| `Service/Rbac/TokenGrantIssue.php` | 12 | Int 1 | K value objects callers construct |
| `Service/Rbac/TokenGrantSource.php` | 38 | Int 1 | P ObjectGrantsInterface |
| `Service/Rbac/TokenGrantValidator.php` | 84 | Int 2 | P ObjectGrantsInterface |
| `Service/Rbac/ViewShareResolver.php` | 159 | Cfg 1 | I use ViewShareResolverInterface |
| `Service/Rbac/ViewerReach.php` | 11 | Cfg 3, Sea 3 | P ViewerReachInterface |
| `Service/Rbac/ViewerReachCheckInterface.php` | 13 | Cfg 1, Sea 3 | P ViewerReachInterface |
| `Service/Rbac/ViewerReachResolver.php` | 153 | Cfg 2 | I use ViewerReachResolverInterface |
| `Service/Rbac/ViewerReachResolverInterface.php` | 7 | Cfg 3 | P ViewerReachInterface |
| `Service/RegisterDescriptorServiceInterface.php` | 12 | Cfg 4 | R row → Configuration |
| `Service/RegisterImportCall.php` | 11 | Cfg 2 | R row → Configuration |
| `Service/RegisterImportDocument.php` | 24 | Cfg 2 | R row → Configuration |
| `Service/RegisterImportRoute.php` | 16 | Cfg 1 | R row → Configuration |
| `Service/RegisterImporters.php` | 11 | Cfg 1 | R row → Configuration |
| `Service/RegisterMappingFunctionsEvent.php` | 19 | Int 1 | R row → Integrations |
| `Service/RegisterResolverService.php` | 203 | Cfg 1 | W wiring |
| `Service/RegisterResolverServiceInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/RegisterSchemaLinkageRepairService.php` | 157 | Cfg 1 | W wiring |
| `Service/RegisterSchemaLinkageRepairServiceInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/RegisterSchemaMappers.php` | 12 | Com 1, Cfg 1 | P SchemaLookupInterface |
| `Service/RegisterScopedSchemaResolver.php` | 85 | Cfg 2 | I use RegisterScopedSchemaResolverInterface |
| `Service/RegisterScopedSchemaResolverInterface.php` | 12 | Com 1, Cfg 1, Sea 2 | P SchemaLookupInterface |
| `Service/RegisterScopedSchemaResolvers.php` | 13 | Com 1, Sea 1 | P SchemaLookupInterface |
| `Service/RegisterService.php` | 366 | Cfg 2 | I use RegisterServiceInterface |
| `Service/RegisterServiceInterface.php` | 20 | Cfg 1, Int 2 | P SchemaLookupInterface |
| `Service/Registers/EntityEventPublisher.php` | 158 | Int 1 | W wiring |
| `Service/Registers/RegisterCacheHandler.php` | 26 | Cfg 1 | W wiring |
| `Service/Registers/RegisterCacheHandlerInterface.php` | 5 | Cfg 1 | W wiring |
| `Service/Registry/RegistryAnnotationValidator.php` | 100 | Cfg 1 | W wiring |
| `Service/Registry/RegistryAnnotationValidatorInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Registry/RegistrySubscriptionLifecycleInterface.php` | 13 | Cfg 1 | W wiring |
| `Service/Registry/RegistrySubscriptionService.php` | 168 | Cfg 1 | W wiring |
| `Service/Registry/RegistrySubscriptionServiceInterface.php` | 11 | Cfg 1 | W wiring |
| `Service/Relation/LinkExposure.php` | 73 | Cfg 1 | W wiring |
| `Service/Relation/LinkExposureInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Relation/ObjectRelationService.php` | 214 | Cfg 1 | W wiring |
| `Service/Relation/ObjectRelationServiceInterface.php` | 22 | Cfg 1 | W wiring |
| `Service/Relation/ObjectTreeService.php` | 324 | Cfg 1 | W wiring |
| `Service/Relation/ObjectTreeServiceInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Relation/RelationAnnotationValidator.php` | 259 | Cfg 1 | W wiring |
| `Service/Relation/RelationAnnotationValidatorInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Relation/RelationGraphService.php` | 261 | Cfg 1 | W wiring |
| `Service/Relation/RelationGraphServiceInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Relation/RelationTypeResolver.php` | 203 | Cfg 1 | W wiring |
| `Service/Relation/RelationTypeResolverInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Rules/DependentValueDeclarationException.php` | 16 | Pro 1 | K value objects callers construct |
| `Service/Schema/IncomingDefinition.php` | 10 | Cfg 1, Int 1 | K value objects callers construct |
| `Service/Schema/PropertyConversionService.php` | 124 | Cfg 1 | W wiring |
| `Service/Schema/PropertyConversionServiceInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Schema/SchemaBatchMigratorInterface.php` | 7 | Com 1, Cfg 1 | P SchemaEvolutionInterface |
| `Service/Schema/SchemaChangeRecord.php` | 12 | Cfg 1, Int 1 | K value objects callers construct |
| `Service/Schema/SchemaChangeSet.php` | 39 | Cfg 2, Int 1, Pro 1 | P SchemaEvolutionInterface |
| `Service/Schema/SchemaDiffService.php` | 229 | Int 1 | P SchemaEvolutionInterface |
| `Service/Schema/SchemaMigrationPlanner.php` | 171 | Int 1 | P SchemaEvolutionInterface |
| `Service/Schema/SchemaMigrationService.php` | 245 | Cfg 1, Int 1 | I use SchemaMigrationServiceInterface |
| `Service/Schema/SchemaMigrationServiceInterface.php` | 12 | Cfg 1 | W wiring |
| `Service/Schema/SchemaObjectReader.php` | 61 | Cfg 1 | W wiring |
| `Service/Schema/SchemaObjectReaderInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Schema/SchemaPopulationRuns.php` | 16 | Int 1 | P SchemaEvolutionInterface |
| `Service/Schema/SchemaRevalidationService.php` | 106 | Int 2 | P SchemaEvolutionInterface |
| `Service/Schema/SchemaRevalidatorInterface.php` | 8 | Com 1, Int 1 | P SchemaEvolutionInterface |
| `Service/Schema/SchemaVersioningService.php` | 132 | Cfg 3, Int 2 | P SchemaEvolutionInterface |
| `Service/SchemaApplicationMigratorInterface.php` | 8 | Cfg 3 | R row → Configuration |
| `Service/SchemaDeletionStores.php` | 21 | Com 1 | R row → Compliance |
| `Service/SchemaImport/SchemaImportService.php` | 89 | Cfg 1 | W wiring |
| `Service/SchemaImport/SchemaImportServiceInterface.php` | 12 | Cfg 1 | W wiring |
| `Service/SchemaProperty.php` | 11 | Cfg 1 | K value objects callers construct |
| `Service/SchemaTeardownGuard.php` | 93 | Com 1 | R row → Compliance |
| `Service/SchemaTeardownInterface.php` | 13 | Com 1, Cfg 1 | P SchemaEvolutionInterface |
| `Service/SchemaTeardownOverride.php` | 10 | Com 1, Cfg 1 | K value objects callers construct |
| `Service/Schemas/ExtendingFormDeclaration.php` | 206 | Cfg 1, Int 1 | W wiring |
| `Service/Schemas/ExtendingFormDeclarationInterface.php` | 12 | Cfg 1 | W wiring |
| `Service/Schemas/ExtendingFormValidatorInterface.php` | 6 | Int 1 | W wiring |
| `Service/Schemas/FacetResultCacheClearer.php` | 33 | Cfg 2 | I use FacetResultCacheClearerInterface |
| `Service/Schemas/FacetResultCacheClearerInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Schemas/GeneratedIdentifierDeclaration.php` | 278 | Pro 1 | I use GeneratedIdentifierDeclarationInterface |
| `Service/Schemas/PropertyValidatorHandler.php` | 763 | Cfg 1 | W wiring |
| `Service/Schemas/PropertyVocabulary.php` | 138 | Cfg 1 | W wiring |
| `Service/Schemas/PropertyVocabularyInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Schemas/ReferenceOptionsReader.php` | 44 | Cfg 1 | W wiring |
| `Service/Schemas/ReferenceOptionsReaderInterface.php` | 11 | Cfg 1 | W wiring |
| `Service/Schemas/RequiredWhenDeclaration.php` | 172 | Pro 1 | P SchemaDeclarationFindings |
| `Service/Schemas/SchemaCacheHandler.php` | 331 | Cfg 2 | I use SchemaCacheHandlerInterface |
| `Service/Schemas/SchemaCacheHandlerInterface.php` | 8 | Cfg 1 | W wiring |
| `Service/Schemas/SemanticRoleHandler.php` | 117 | Cfg 1 | W wiring |
| `Service/Schemas/SemanticRoleHandlerInterface.php` | 8 | Cfg 1, Pro 1 | P SchemaDeclarationFindings |
| `Service/Schemas/UniqueConstraintEvaluator.php` | 205 | Cfg 1, Ker 1 | I use UniqueConstraintEvaluatorInterface |
| `Service/Schemas/UniqueConstraintEvaluatorInterface.php` | 12 | Cfg 1 | W wiring |
| `Service/Search/ObjectSearchResultFormatter.php` | 196 | Int 1 | W wiring |
| `Service/Search/ObjectSearchResultFormatterInterface.php` | 8 | Int 2, Sea 1 | P ObjectReaderInterface |
| `Service/Search/SearchResultDeclarations.php` | 50 | Sea 1 | P ObjectReaderInterface |
| `Service/SecurityHeadersInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/SecurityService.php` | 310 | Com 1, Cfg 1 | I use SecurityHeadersInterface |
| `Service/SemanticTypeResolver.php` | 222 | Cfg 1, Int 2 | I use SemanticTypeResolverInterface |
| `Service/SemanticTypeResolverInterface.php` | 9 | Cfg 1 | W wiring |
| `Service/Serializer/RegisterSerializer.php` | 97 | Cfg 1 | W wiring |
| `Service/Serializer/RegisterSerializerInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/ServiceBootstrap.php` | 13 | Pla 1 | W wiring |
| `Service/SettingsServiceInterface.php` | 49 | Cfg 3, Fil 2, Int 2, Pro 1, Sea 1 | P AppSettingsInterface |
| `Service/ShareLinkServiceInterface.php` | 11 | Cfg 1, Int 2 | R row → Integrations |
| `Service/SharedMasterDataService.php` | 210 | Cfg 1, Pro 1 | I use SharedMasterDataServiceInterface |
| `Service/SharedMasterDataServiceInterface.php` | 12 | Cfg 1 | W wiring |
| `Service/SharedSchemaDedupeServiceInterface.php` | 10 | Cfg 3 | R row → Configuration |
| `Service/Sharing/AccessLinkActs.php` | 35 | Cfg 1 | W wiring |
| `Service/Sharing/AccessLinkActsInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Sharing/AccessLinkMintGuard.php` | 81 | Cfg 2 | I use AccessLinkMintGuardInterface |
| `Service/Sharing/AccessLinkMintGuardInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Sharing/AccessLinkReaderFilters.php` | 14 | Fil 1 | R row → Files |
| `Service/Sharing/AccessLinkService.php` | 283 | Cfg 2 | I use AccessLinkServiceInterface |
| `Service/Sharing/AccessLinkServiceInterface.php` | 19 | Cfg 1 | W wiring |
| `Service/Sharing/AccessLinkSubject.php` | 30 | Cfg 1 | W wiring |
| `Service/Sharing/AccessLinkSubjectInterface.php` | 7 | Cfg 1, Fil 1 | P AccessLinkReaderInterface |
| `Service/Sharing/AccessLinkTerms.php` | 15 | Cfg 1 | K value objects callers construct |
| `Service/Sharing/LinkMintRequest.php` | 12 | Cfg 1 | K value objects callers construct |
| `Service/Sharing/ObjectShareLookup.php` | 102 | Int 2 | R row → Integrations |
| `Service/Sharing/ShareGrantee.php` | 25 | Int 2 | K value objects callers construct |
| `Service/Sharing/ShareListCheck.php` | 10 | Int 2 | K value objects callers construct |
| `Service/Sharing/SharePrincipalDeriver.php` | 74 | Cfg 1, Int 1 | I use SharePrincipalDeriverInterface |
| `Service/Sharing/SharePrincipalDeriverInterface.php` | 11 | Cfg 1, Int 2 | P ObjectGrantsInterface |
| `Service/SkosNodeReader.php` | 139 | Cfg 1 | R row → Configuration |
| `Service/Survivorship/SourceRecordResolver.php` | 200 | Cfg 1 | W wiring |
| `Service/Survivorship/SourceRecordResolverInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Survivorship/SurvivorshipAnnotationValidator.php` | 122 | Cfg 1 | W wiring |
| `Service/Survivorship/SurvivorshipAnnotationValidatorInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Survivorship/SurvivorshipResolver.php` | 262 | Cfg 1 | W wiring |
| `Service/Survivorship/SurvivorshipResolverInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Survivorship/TrustTierResolver.php` | 86 | Cfg 1 | W wiring |
| `Service/Survivorship/TrustTierResolverInterface.php` | 10 | Cfg 1 | W wiring |
| `Service/SystemOperationContext.php` | 55 | Com 1, Cfg 2, Int 1, Pla 1, Pro 2 | K write and operation context |
| `Service/TenantKeyRotationInterface.php` | 8 | Cfg 3 | R row → Configuration |
| `Service/TenantLifecycleService.php` | 190 | Cfg 1 | W wiring |
| `Service/TenantLifecycleServiceInterface.php` | 31 | Com 1, Cfg 1, Pla 1 | P TenancyInterface |
| `Service/TenantLogRedactor.php` | 168 | Cfg 1 | W wiring |
| `Service/TenantLogRedactorInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/TextExtraction/EntityRecognitionBackends.php` | 18 | Fil 1 | R row → Files |
| `Service/TimeTracker/ClientSearch.php` | 10 | Int 1 | R row → Integrations |
| `Service/TimeTracker/EntryRowLookup.php` | 11 | Int 1 | R row → Integrations |
| `Service/TimeTracker/NewClient.php` | 10 | Int 1 | R row → Integrations |
| `Service/TimeTracker/TimeEntry.php` | 10 | Int 2 | R row → Integrations |
| `Service/TimeTrackerLinkServiceInterface.php` | 15 | Cfg 1, Int 4 | R row → Integrations |
| `Service/TmloServiceInterface.php` | 14 | Com 2, Cfg 1 | R row → Compliance |
| `Service/UrnService.php` | 236 | Cfg 1 | W wiring |
| `Service/UrnServiceInterface.php` | 11 | Cfg 1, Pla 1 | P ApiVersionsInterface |
| `Service/UserMigration/PersonalStateMigrator.php` | 201 | Pla 1 | W wiring |
| `Service/UserService.php` | 942 | Cfg 1 | W wiring |
| `Service/UserServiceInterface.php` | 28 | Cfg 1 | W wiring |
| `Service/UserTaskListInterface.php` | 8 | Cfg 1, Pro 1 | P ObjectWriterInterface |
| `Service/Vectorization/Strategies/FileVectorizationStrategy.php` | 91 | Cfg 1 | W wiring |
| `Service/Vectorization/Strategies/FileVectorizationStrategyInterface.php` | 5 | Cfg 1, Sea 1 | K remaining |
| `Service/Vectorization/Strategies/ObjectVectorizationStrategy.php` | 195 | Cfg 1 | W wiring |
| `Service/Vectorization/Strategies/ObjectVectorizationStrategyInterface.php` | 5 | Cfg 1, Sea 1 | K remaining |
| `Service/Vectorization/Strategies/VectorizationStrategyInterface.php` | 8 | Sea 4 | K remaining |
| `Service/View/ViewAlert.php` | 178 | Com 1, Pro 1, Sea 2 | P ViewAlertsInterface |
| `Service/View/ViewObjectQuery.php` | 72 | Cfg 1 | W wiring |
| `Service/View/ViewObjectQueryInterface.php` | 6 | Com 1, Cfg 1 | P ViewAlertsInterface |
| `Service/Vocabulary/CodedOptionsBuilder.php` | 128 | Cfg 1 | W wiring |
| `Service/Vocabulary/CodedOptionsBuilderInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Vocabulary/CodedValueGuard.php` | 103 | Cfg 1 | W wiring |
| `Service/Vocabulary/CodedValueGuardInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Vocabulary/ConceptDeleteGuard.php` | 113 | Cfg 1 | W wiring |
| `Service/Vocabulary/ConceptDeleteGuardInterface.php` | 11 | Cfg 1 | W wiring |
| `Service/Vocabulary/ConceptRepository.php` | 208 | Cfg 1 | W wiring |
| `Service/Vocabulary/ConceptRepositoryInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/Vocabulary/ConceptShapeGuard.php` | 78 | Cfg 1 | W wiring |
| `Service/Vocabulary/ConceptShapeGuardInterface.php` | 7 | Cfg 1 | W wiring |
| `Service/Vocabulary/VocabularyDeclarationResolver.php` | 76 | Cfg 1 | W wiring |
| `Service/Vocabulary/VocabularyDeclarationResolverInterface.php` | 6 | Cfg 1 | W wiring |
| `Service/VocabularyConceptEdges.php` | 37 | Cfg 1 | R row → Configuration |
| `Service/VocabularyCsvReader.php` | 91 | Cfg 1 | R row → Configuration |
| `Service/VocabularyImportTally.php` | 20 | Cfg 1 | R row → Configuration |
| `Service/VocabularyObjectStore.php` | 96 | Cfg 1 | R row → Configuration |
| `Service/WriteCause.php` | 66 | Com 5, Cfg 6, Fil 2, Int 14, Not 2, Pro 8, Sea 1 | K write and operation context |
| `Service/WritePhase.php` | 14 | Pla 1 | W wiring |
| `Service/XwikiLinkServiceInterface.php` | 15 | Cfg 1, Int 3 | R row → Integrations |

## Appendix B: caller files per build package

Each caller file is in one PR, which switches every Core reference it has to the ports listed (`_existing_interfaces` = §3.1.3, the bound interface named in Appendix A). PRs of at most 15 files, ordered by path.

### C5-06 Compliance (96 files, 7 PRs)

PR 1:

- `BackgroundJob/ArchivalRetentionTask.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `BackgroundJob/ArchivalSweep.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `BackgroundJob/BlobMigrationRun.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `BackgroundJob/BulkLegalHoldJob.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `BackgroundJob/DestructionCheckJob.php`: ObjectWriterInterface
- `BackgroundJob/DestructionExecutionJobInterface.php`: existing interface
- `BackgroundJob/DestructionListRun.php`: AuditRecorderInterface, ObjectDestructionInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface, existing interface
- `BackgroundJob/DsarDpiaDetectionJob.php`: AuditRecorderInterface
- `BackgroundJob/ExternalGrantLapseJob.php`: ObjectAccessInterface, ObjectGrantsInterface
- `BackgroundJob/ProcessingLogRetentionJob.php`: AuditRecorderInterface
- `BackgroundJob/SharerAccessSweepJob.php`: ObjectAccessInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `BackgroundJob/Support/ArchivalRetentionSources.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `BackgroundJob/Support/AutoTransitionServices.php`: ListenerDeferralInterface
- `BackgroundJob/Support/DeferredObjectEventLookup.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `BackgroundJob/Support/DsarDpiaDetectionServices.php`: AuditRecorderInterface

PR 2:

- `BackgroundJob/Support/LogCleanUpTrails.php`: AuditRecorderInterface
- `BackgroundJob/Support/ProcessingLogRetentionStores.php`: AuditRecorderInterface
- `BackgroundJob/Support/RelatedRetentionLookup.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `BackgroundJob/Support/SchemaRunServices.php`: SchemaEvolutionInterface
- `BackgroundJob/Support/StateHistoryRebuildSources.php`: AuditRecorderInterface
- `BackgroundJob/Support/TenantPurgeStores.php`: TenancyInterface
- `BackgroundJob/Support/TenantRecords.php`: TenancyInterface
- `BackgroundJob/Support/ViewAlertCheck.php`: ViewAlertsInterface
- `BackgroundJob/Support/ViewAlertSweepSources.php`: ViewAlertsInterface
- `Controller/ArchivalController.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Controller/AuditTrailController.php`: AuditRecorderInterface, RevealInterface
- `Controller/ContentReportController.php`: ObjectReaderInterface
- `Controller/CorrectionsController.php`: ObjectDestructionInterface
- `Controller/ProcessingLogController.php`: TenancyInterface
- `Controller/ProcessingPurposeController.php`: AuditRecorderInterface

PR 3:

- `Controller/RetentionController.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Controller/Support/ArchivalMappers.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Controller/Support/AuditTrailControllerServices.php`: AuditRecorderInterface
- `Controller/Support/AuditTrailMappers.php`: AuditRecorderInterface
- `Controller/Support/AuditTrailReaders.php`: RevealInterface
- `Controller/Support/ContentReportControllerServices.php`: ObjectReaderInterface
- `Controller/Support/CorrectionsControllerServices.php`: ObjectDestructionInterface
- `Controller/Support/ProcessingActivityVisibility.php`: TenancyInterface
- `Controller/Support/ProcessingLogAccess.php`: TenancyInterface
- `Controller/Support/ProcessingPurposeControllerServices.php`: AuditRecorderInterface
- `Controller/Support/RetentionDomain.php`: ObjectWriterInterface
- `Controller/Support/RetentionMappers.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Controller/Support/VerwerkingsactiviteitenControllerServices.php`: TenancyInterface
- `Controller/TmloController.php`: SchemaLookupInterface
- `Db/AuditTrailMapper.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface

PR 4:

- `Listener/ContentReportRemovalListener.php`: AuditRecorderInterface
- `Listener/RecordTypeAuditListener.php`: AuditRecorderInterface
- `Listener/SchemaAuthorizationAuditListener.php`: AuditRecorderInterface
- `Service/Archival/ActionDateFollower.php`: AuditRecorderInterface
- `Service/Archival/ArchivalRegisterMappers.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Archival/ArchiveActionDateCalculator.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Archival/DestructionDryRun.php`: ObjectReaderInterface
- `Service/Archival/DestructionInfrastructure.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Archival/DestructionListConflictRefresh.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Archival/DestructionListWriter.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Archival/InheritedAppraisal.php`: ObjectReaderInterface
- `Service/Archival/LegalHoldRecords.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Archival/LinkedRetentionConflictFinder.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Archival/RetentionRowScanner.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Archival/ReviewOutcomeService.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface

PR 5:

- `Service/Archival/SelectielijstStorage.php`: ObjectWriterInterface
- `Service/Audit/AuditEntryWriter.php`: AuditRecorderInterface
- `Service/Audit/EntityEditAuditor.php`: AuditRecorderInterface
- `Service/Audit/HistoryEntryRedactor.php`: PropertyAccessInterface, RevealInterface, existing interface
- `Service/Audit/ObjectHistoryForReaders.php`: existing interface
- `Service/Audit/ObjectHistoryServices.php`: AuditRecorderInterface
- `Service/Audit/OrganisationContextAttribution.php`: TenancyInterface
- `Service/Audit/OrganisationContextAttributionInterface.php`: TenancyInterface
- `Service/Audit/PurposeGuard.php`: AuditRecorderInterface
- `Service/Audit/ReadEventVisibility.php`: ObjectAccessInterface
- `Service/Audit/ReadableAuditTrailLister.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/AvgRetentionService.php`: ObjectDestructionInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/DsarService.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/DsarStores.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Edepot/AuditRecording.php`: AuditRecorderInterface

PR 6:

- `Service/Edepot/MdtoContainment.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Edepot/MdtoEventMapper.php`: AuditRecorderInterface
- `Service/Edepot/TransferAuditLog.php`: AuditRecorderInterface
- `Service/Edepot/TransferStores.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Gdpr/Erasure/ErasureDestroyGuard.php`: ObjectDestructionInterface
- `Service/Gdpr/Erasure/ErasureDestructionSteps.php`: ObjectDestructionInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Gdpr/Erasure/ErasureScopeCounter.php`: ObjectDestructionInterface
- `Service/Gdpr/Export/ExportDossierSources.php`: AuditRecorderInterface
- `Service/Gdpr/Subject/SubjectDeclaredFieldIndex.php`: ObjectReaderInterface
- `Service/Gdpr/Subject/SubjectObjectLoader.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Hardening/HardeningAuditWriter.php`: AuditRecorderInterface
- `Service/Hardening/HardeningReportService.php`: existing interface
- `Service/Hardening/HardeningSettingsStore.php`: AuditRecorderInterface
- `Service/LogObjectInTable.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/LogService.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface, existing interface

PR 7:

- `Service/RetentionStores.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/SchemaDeletionService.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface, SchemaEvolutionInterface
- `Service/SchemaDeletionStores.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/SchemaTeardownGuard.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/SearchTrailService.php`: SchemaLookupInterface
- `Service/WholeTableRows.php`: ObjectAccessInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface

### C5-07 Configuration (70 files, 5 PRs)

PR 1:

- `Command/BackfillSystemOwnerCommand.php`: ObjectTablesInterface, existing interface
- `Command/DedupeSharedSchemasCommand.php`: ObjectTablesInterface, SchemaEvolutionInterface
- `Command/MigrateSchemaApplicationCommand.php`: SchemaEvolutionInterface
- `Command/MigrateStorageCommand.php`: ObjectTablesInterface
- `Command/ReconcileMagicTablesCommand.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Command/RotateTenantKeyCommand.php`: TenancyInterface
- `Command/Support/RematerialiseStorage.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Command/Support/SchemaColumnDriftPass.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface, existing interface
- `Controller/MigrationController.php`: ObjectTablesInterface
- `Controller/RecordTemplatesController.php`: ViewerReachInterface
- `Controller/Settings/SecuritySettingsController.php`: RequestSecurityInterface
- `Controller/Support/RecordTemplatesControllerServices.php`: ViewerReachInterface
- `Controller/Support/SecuritySettingsControllerServices.php`: RequestSecurityInterface
- `Db/Application.php`: ObjectAccessInterface
- `Db/ApplicationMapper.php`: TenancyInterface

PR 2:

- `Db/ConfigurationMapper.php`: TenancyInterface
- `Service/Config/BundleCrossingGuard.php`: existing interface
- `Service/Config/Types/RegisterSchemaShareableConfigType.php`: existing interface
- `Service/Configuration/AppImportJobRecorder.php`: AuditRecorderInterface
- `Service/Configuration/CacheHandler.php`: TenancyInterface
- `Service/Configuration/ExportHandler.php`: ObjectGrantsInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Configuration/ImportHandler.php`: ObjectGrantsInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface, SchemaEvolutionInterface, existing interface
- `Service/Configuration/ImportedSchemaChangeLog.php`: SchemaEvolutionInterface
- `Service/Configuration/ImportedSchemaShape.php`: existing interface
- `Service/Configuration/ObjectChangePreview.php`: ObjectReaderInterface
- `Service/Configuration/ReadableExportObjects.php`: RevealInterface, existing interface
- `Service/Configuration/RegisterBundleEntryRules.php`: existing interface
- `Service/Configuration/SchemaImportSteps.php`: existing interface
- `Service/ConfigurationDeployment/ConfigurationSeedService.php`: AppSettingsInterface
- `Service/ExampleSet/ExampleSetAccessLinks.php`: AccessLinkReaderInterface, existing interface

PR 3:

- `Service/ExampleSet/ExampleSetAuditEntries.php`: AuditRecorderInterface
- `Service/ExampleSet/ExampleSetDraft.php`: ObjectDraftsInterface
- `Service/ExampleSet/ExampleSetFiles.php`: ObjectReaderInterface, existing interface
- `Service/ExampleSet/ExampleSetFlows.php`: TenancyInterface
- `Service/ExampleSet/ExampleSetGrants.php`: existing interface
- `Service/ExampleSet/ExampleSetInstance.php`: OrganisationInterface, TenancyInterface
- `Service/ExampleSet/ExampleSetReadStates.php`: ObjectReaderInterface
- `Service/ExampleSet/ExampleSetRegisters.php`: AuditRecorderInterface
- `Service/ExampleSet/ExampleSetStore.php`: ObjectWriterInterface, existing interface
- `Service/Exchange/ExportKeyService.php`: AuditRecorderInterface
- `Service/Export/ExportAuditRecorder.php`: AuditRecorderInterface
- `Service/Export/ExportRightService.php`: ObjectAccessInterface
- `Service/ExportService.php`: ObjectNameCacheInterface, RevealInterface, existing interface
- `Service/Import/ImportPreviewTargets.php`: ObjectWriterInterface
- `Service/Import/ImportServiceStores.php`: AuditRecorderInterface, ObjectWriterInterface

PR 4:

- `Service/Import/ImportValueCaster.php`: ObjectTablesInterface
- `Service/ImportService.php`: AuditRecorderInterface, ObjectWriterInterface
- `Service/MassValidationRun.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Mcp/McpAgentScope.php`: ObjectGrantsInterface
- `Service/MigrationService.php`: ObjectTablesInterface, existing interface
- `Service/Notification/ReferenceLookupServices.php`: ObjectWriterInterface
- `Service/Notification/ReferencedAddressNotifier.php`: ObjectWriterInterface
- `Service/RecordTemplate/RecordTemplateService.php`: ObjectAccessInterface, ViewerReachInterface, existing interface
- `Service/RecordTemplate/RecordTemplateStoreInterface.php`: ViewerReachInterface
- `Service/SchemaApplicationMigrator.php`: SchemaEvolutionInterface
- `Service/Settings/CacheSettingsHandler.php`: ObjectNameCacheInterface, existing interface
- `Service/Settings/OwnSettingsChangeRecorder.php`: ObjectGrantsInterface
- `Service/Settings/SchemaCacheClearers.php`: existing interface
- `Service/Settings/SearchBackendHandler.php`: ObjectTablesInterface
- `Service/Settings/SettingsAuditTrail.php`: ObjectGrantsInterface

PR 5:

- `Service/Settings/SettingsDirectory.php`: TenancyInterface
- `Service/Settings/ValidationOperationsHandler.php`: ObjectWriterInterface, existing interface
- `Service/SettingsService.php`: AppSettingsInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/SharedSchema/SchemaTableMigrator.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/SharedSchemaDedupeService.php`: SchemaEvolutionInterface
- `Service/ShippedBaseline/ShippedConfigurationGuard.php`: AuditRecorderInterface
- `Service/TenantKeyService.php`: TenancyInterface
- `Service/Translation/TranslationCsvCodec.php`: ObjectReaderInterface, existing interface
- `Service/TranslationProjectionService.php`: SchemaLookupInterface
- `Service/TranslationStatusService.php`: ObjectReaderInterface, existing interface

### C5-08 Integrations (77 files, 6 PRs)

PR 1:

- `AppHost/Search/AbstractSchemaSearchProvider.php`: ObjectReaderInterface
- `BackgroundJob/ContextChatSubmissionJob.php`: ListenerDeferralInterface
- `Command/FederationShareRightsCommand.php`: ObjectGrantsInterface
- `Command/TablesSyncCommand.php`: ObjectTablesInterface
- `Contacts/ContactsMenuProvider.php`: DeepLinksInterface
- `Contacts/Support/ContactsMenuSources.php`: DeepLinksInterface
- `ContextChat/ContentProvider.php`: DeepLinksInterface
- `ContextChat/Support/ContentProviderLinks.php`: DeepLinksInterface
- `ContextChat/Support/ContentProviderSources.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Controller/ApiCallersController.php`: ApiVersionsInterface
- `Controller/CaseTokenController.php`: ObjectGrantsInterface
- `Controller/ConsumersController.php`: ObjectGrantsInterface
- `Controller/CredentialController.php`: ObjectGrantsInterface, TenancyInterface
- `Controller/CredentialOrganisationController.php`: TenancyInterface
- `Controller/EmailLinksController.php`: ObjectAccessInterface

PR 2:

- `Controller/FederationController.php`: ObjectGrantsInterface
- `Controller/IntegrationControllersWiring.php`: ObjectAccessInterface
- `Controller/MappingsController.php`: TenancyInterface
- `Controller/ObjectIntegrationsController.php`: ObjectAccessInterface
- `Controller/ObjectShareLinkController.php`: ObjectGrantsInterface
- `Controller/Support/ApiCallersControllerServices.php`: ApiVersionsInterface
- `Controller/Support/ConsumersControllerServices.php`: ObjectGrantsInterface
- `Controller/Support/ContactsDomain.php`: DeepLinksInterface
- `Controller/Support/ContactsPlatform.php`: ObjectAccessInterface
- `Controller/Support/CredentialOauth2ControllerServices.php`: AccessLinkReaderInterface
- `Controller/Support/CredentialTokens.php`: ObjectGrantsInterface, TenancyInterface
- `Controller/Support/EmailLinksControllerServices.php`: ObjectAccessInterface
- `Controller/Support/FederationControllerServices.php`: ObjectGrantsInterface
- `Controller/Support/MappingsControllerServices.php`: TenancyInterface
- `Controller/Support/ObjectIntegrationsAccess.php`: ObjectAccessInterface

PR 3:

- `Db/EndpointMapper.php`: TenancyInterface
- `Db/FederatedShareMapper.php`: ObjectGrantsInterface, TenancyInterface
- `Db/MappingMapper.php`: TenancyInterface
- `Db/SourceMapper.php`: TenancyInterface
- `Db/WebhookMapper.php`: TenancyInterface
- `Mcp/BuiltIn/AttributeToolProvider.php`: AuditRecorderInterface
- `Mcp/BuiltIn/RegistersToolProvider.php`: ObjectGrantsInterface, SchemaLookupInterface
- `Mcp/BuiltIn/SchemaDerivedToolProvider.php`: AuditRecorderInterface
- `Mcp/BuiltIn/SchemaDerivedToolServices.php`: AuditRecorderInterface
- `Mcp/BuiltIn/SchemasToolProvider.php`: ObjectAccessInterface, ObjectGrantsInterface
- `Mcp/BuiltIn/ToolInvocationAudit.php`: AuditRecorderInterface
- `Mcp/Db/Agent.php`: OrganisationInterface
- `Mcp/Db/AgentMapper.php`: OrganisationInterface, TenancyInterface
- `Mcp/Db/Conversation.php`: OrganisationInterface
- `Mcp/Listener/ContextChatSubmissionListener.php`: ListenerDeferralInterface, ObjectAccessInterface

PR 4:

- `Mcp/Listener/ContextChatSubmissionServices.php`: ListenerDeferralInterface
- `Mcp/McpProviderDiscovery.php`: AuditRecorderInterface
- `Mcp/Service/Chat/ConversationManagementHandler.php`: AppSettingsInterface
- `Mcp/Service/Chat/ResponseGenerationHandler.php`: AppSettingsInterface
- `Mcp/Service/ChatService.php`: ObjectGrantsInterface
- `Mcp/Service/Mcp/McpResourcesService.php`: RevealInterface
- `Mcp/Tool/RegisterTool.php`: SchemaLookupInterface
- `Mcp/Tool/SchemaTool.php`: SchemaEvolutionInterface
- `Service/AuthenticationService.php`: RequestSecurityInterface
- `Service/AuthorizationService.php`: ObjectGrantsInterface
- `Service/Credential/CredentialBrokerService.php`: existing interface
- `Service/Credential/CredentialPrincipals.php`: TenancyInterface
- `Service/Credential/OAuth2ConnectionRepository.php`: TenancyInterface
- `Service/DeepLinkRegistryService.php`: DeepLinksInterface
- `Service/Federation/CreatorPropertyFilter.php`: PropertyAccessInterface, RevealInterface

PR 5:

- `Service/Federation/RemoteShareNotification.php`: ObjectGrantsInterface
- `Service/Federation/ShareCreatorCheck.php`: ObjectGrantsInterface
- `Service/FederationShareService.php`: ObjectGrantsInterface, existing interface
- `Service/Handoff/HandoffLedger.php`: AuditRecorderInterface
- `Service/Handoff/HandoffObjectAccess.php`: ObjectAccessInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Handoff/HandoffProviders.php`: existing interface
- `Service/Handoff/HandoffService.php`: existing interface
- `Service/Integration/BuiltinIntegrationServices.php`: SchemaEvolutionInterface, existing interface
- `Service/Integration/BuiltinProviders/FilesProvider.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Integration/IntegrationWiring.php`: existing interface
- `Service/Integration/PropertySemanticReferenceValidator.php`: existing interface
- `Service/LinkedEntityIntegrations.php`: ObjectAccessInterface
- `Service/LinkedEntityMappers.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface, TenancyInterface
- `Service/LinkedEntityService.php`: ObjectAccessInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface, OrganisationInterface, TenancyInterface
- `Service/ObjectRelationCleanupService.php`: ObjectWriterInterface

PR 6:

- `Service/ShareLinkService.php`: existing interface
- `Twig/AuthenticationRuntime.php`: RequestSecurityInterface

### C5-09 Processes (101 files, 7 PRs)

PR 1:

- `BackgroundJob/AnnotationNotificationDispatchJob.php`: ListenerDeferralInterface
- `BackgroundJob/AutoTransitionJob.php`: ListenerDeferralInterface
- `BackgroundJob/FlowRunRecoveryServices.php`: ObjectWriterInterface
- `BackgroundJob/FlowRunWorker.php`: ObjectWriterInterface
- `BackgroundJob/SourceRecordRecomputeJob.php`: ObjectWriterInterface
- `BackgroundJob/TranslationProjectionJob.php`: ListenerDeferralInterface
- `Command/RematerialiseCalculationsCommand.php`: SchemaLookupInterface
- `Controller/BulkJobsController.php`: ObjectWriterInterface
- `Controller/CalculationsController.php`: ObjectReaderInterface
- `Controller/CalendarEventsController.php`: ObjectAccessInterface
- `Controller/CalendarFeedController.php`: ObjectGrantsInterface
- `Controller/EntityRelationsController.php`: ObjectAccessInterface, ObjectReaderInterface
- `Controller/FlowNodeRunController.php`: ObjectAccessInterface
- `Controller/FlowRunController.php`: TenancyInterface
- `Controller/GdprEntitiesController.php`: TenancyInterface

PR 2:

- `Controller/ObjectFavouriteController.php`: ObjectGrantsInterface
- `Controller/Support/BulkJobsControllerServices.php`: AuditRecorderInterface
- `Controller/Support/BulkJobsDomain.php`: ObjectWriterInterface
- `Controller/Support/CalculationsControllerServices.php`: ObjectReaderInterface
- `Controller/Support/CalendarEventsControllerServices.php`: ObjectAccessInterface
- `Controller/Support/EntityRelationsDomain.php`: ObjectAccessInterface
- `Controller/Support/FlowNodeRunExecution.php`: ObjectAccessInterface
- `Controller/Support/FlowRunAccess.php`: TenancyInterface
- `Controller/Support/GdprEntitiesDomain.php`: TenancyInterface
- `Controller/Support/PartyObjectAccess.php`: ObjectAccessInterface
- `Controller/Support/PartySearch.php`: ObjectAccessInterface
- `Controller/Support/TranslationMappers.php`: ObjectReaderInterface
- `Controller/TranslationController.php`: ObjectReaderInterface
- `Db/SchemaMapper/CalculationAnnotationCheck.php`: SchemaDeclarationFindings
- `Db/SchemaMapper/LifecycleAnnotationCheck.php`: SchemaDeclarationFindings

PR 3:

- `Db/SchemaMapper/PositionedDeclarationCheck.php`: SchemaDeclarationFindings
- `Listener/AnnotationNotificationListener.php`: ListenerDeferralInterface
- `Listener/AssigneeFollowListener.php`: ObjectAccessInterface
- `Listener/AuthorizationCacheInvalidationListener.php`: ObjectAccessInterface
- `Listener/ChangeGateListener.php`: ObjectDraftsInterface
- `Listener/CommentsEntityListener.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Listener/DependentValueListener.php`: ObjectAccessInterface
- `Listener/FlowRunLockReleaseListener.php`: ObjectWriterInterface
- `Listener/FrozenNodeWriteListener.php`: ObjectAccessInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Listener/RequiredWhenListener.php`: SchemaDeclarationFindings
- `Listener/SchemaDeclarationChecksListener.php`: SchemaDeclarationFindings
- `Listener/SchemaFlowImportListener.php`: TenancyInterface
- `Listener/SharerViewsEndWithUserListener.php`: ObjectAccessInterface
- `Listener/StateEntryFreezeListener.php`: AuditRecorderInterface
- `Listener/StateFieldRuleListener.php`: PropertyAccessInterface

PR 4:

- `Listener/Support/AssigneeFollowServices.php`: SchemaDeclarationFindings
- `Listener/Support/LifecycleTransitionAccess.php`: ObjectAccessInterface
- `Listener/Support/SourceRecordChangeServices.php`: ListenerDeferralInterface, ObjectWriterInterface
- `Listener/TablesTableDeletedListener.php`: ObjectTablesInterface
- `Listener/TranslationProjectionListener.php`: ListenerDeferralInterface, ObjectReaderInterface
- `Listener/ViewAlertCrossedListener.php`: ViewAlertsInterface
- `Service/BulkActionRegistry.php`: ObjectWriterInterface
- `Service/BulkJob/BulkJobExecutor.php`: AuditRecorderInterface, ObjectAccessInterface
- `Service/BulkJob/BulkJobExecutorServices.php`: ObjectAccessInterface
- `Service/BulkJob/BulkJobExecutorStores.php`: AuditRecorderInterface
- `Service/Calculation/AggregateReferenceResolver.php`: AggregationInterface, existing interface
- `Service/Calculation/ReferenceTenantGuard.php`: TenancyInterface, existing interface
- `Service/Calculation/ReferencedCalculationRefresher.php`: existing interface
- `Service/Calculation/TemporalCalculationSweepService.php`: ObjectReaderInterface
- `Service/Calculation/TemporalSweep/TemporalObjectRefresher.php`: existing interface

PR 5:

- `Service/Calculation/TemporalSweep/TemporalSchemaSweeper.php`: ObjectReaderInterface
- `Service/CommentsCollectionCatalog.php`: DeepLinksInterface
- `Service/Flow/FlowAccess.php`: ObjectAccessInterface
- `Service/Flow/FlowCaller.php`: TenancyInterface
- `Service/Flow/FlowHopAudit.php`: AuditRecorderInterface
- `Service/Flow/FlowRunAdvancer.php`: RevealInterface
- `Service/Flow/FlowRunAttribution.php`: TenancyInterface
- `Service/Flow/FlowTenantMembership.php`: TenancyInterface
- `Service/Flow/FlowTriggerRenameCheck.php`: SchemaEvolutionInterface
- `Service/Flow/FlowWiring.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Flow/Oversight/OrganisationHaltRecords.php`: AuditRecorderInterface
- `Service/Flow/Oversight/OrganisationHaltService.php`: AuditRecorderInterface, TenancyInterface
- `Service/Form/FormDestinationValidator.php`: existing interface
- `Service/Form/FormSubmission.php`: ObjectDraftsInterface
- `Service/Form/FormSubmitDestinations.php`: ObjectWriterInterface

PR 6:

- `Service/Form/FormSubmitService.php`: ObjectDraftsInterface, existing interface
- `Service/Form/FormSubmitter.php`: ObjectDraftsInterface
- `Service/Form/FormWriteContext.php`: ObjectDraftsInterface
- `Service/Form/OwnedDraft.php`: ObjectDraftsInterface
- `Service/Interaction/ReadHistoryService.php`: AppSettingsInterface, AuditRecorderInterface
- `Service/Interaction/ReadStateService.php`: ObjectReaderInterface
- `Service/Interaction/WatcherService.php`: ObjectAccessInterface, existing interface
- `Service/Lifecycle/AutoTransitionDecider.php`: existing interface
- `Service/Lifecycle/AutoTransitionQueue.php`: existing interface
- `Service/Lifecycle/LifecycleFinalStateResolver.php`: ObjectReaderInterface
- `Service/Lifecycle/StateFieldRuleResolver.php`: existing interface
- `Service/Lifecycle/StateFieldRuleResolverInterface.php`: existing interface
- `Service/Lifecycle/TransitionEngine.php`: ObjectAccessInterface
- `Service/ObjectAction/ObjectActionAudit.php`: AuditRecorderInterface
- `Service/ObjectAction/ObjectActionGate.php`: ObjectAccessInterface

PR 7:

- `Service/ObjectAction/ObjectActionHistory.php`: AuditRecorderInterface
- `Service/ObjectAction/ObjectActionInputs.php`: existing interface
- `Service/ObjectAction/ObjectActionSelfAssign.php`: SchemaDeclarationFindings
- `Service/ObjectAction/ObjectActionSelfAssignShape.php`: existing interface
- `Service/Party/PartyRoleRecords.php`: AuditRecorderInterface
- `Service/Party/PartySearchService.php`: AuditRecorderInterface
- `Service/Portal/PortalPartyResolver.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Quota/ObjectQuotaService.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/Rules/RuleTrialService.php`: ObjectReaderInterface
- `Service/Task/TaskInboxService.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/TaskService.php`: ObjectWriterInterface

### C5-10 Search (43 files, 3 PRs)

PR 1:

- `Listener/ViewAlertAudience.php`: ViewerReachInterface
- `Search/BackgroundJob/ReportRenderJob.php`: ObjectReaderInterface
- `Search/BackgroundJob/Support/AggregationThresholdServices.php`: ListenerDeferralInterface, RevealInterface
- `Search/BackgroundJob/Support/ReportRenderSources.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Search/Controller/ReportsController.php`: ObjectReaderInterface
- `Search/Controller/Support/AggregationControllerServices.php`: AggregationInterface
- `Search/Controller/Support/ReportsControllerServices.php`: ObjectReaderInterface
- `Search/Controller/Support/ViewsControllerServices.php`: ViewerReachInterface
- `Search/Controller/ViewsController.php`: ViewAlertsInterface, ViewerReachInterface
- `Search/Db/View.php`: OrganisationInterface
- `Search/Db/ViewMapper.php`: ObjectAccessInterface, OrganisationInterface, TenancyInterface, ViewerReachInterface
- `Search/Db/ViewMapperAccess.php`: TenancyInterface
- `Search/Db/ViewMapperInterface.php`: ViewerReachInterface
- `Search/FlowTasksProvider.php`: DeepLinksInterface
- `Search/Listener/AggregationThresholdListener.php`: ListenerDeferralInterface

PR 2:

- `Search/ObjectsProviderOutput.php`: ObjectReaderInterface
- `Search/SearchScopeCatalog.php`: DeepLinksInterface
- `Search/Service/Aggregation/AggregationCache.php`: TenancyInterface
- `Search/Service/Aggregation/ThresholdEvaluationService.php`: AggregationInterface
- `Search/Service/DashboardAuditReads.php`: AuditRecorderInterface
- `Search/Service/DashboardMappers.php`: AuditRecorderInterface
- `Search/Service/DashboardSizes.php`: AuditRecorderInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Search/Service/GraphQL/GraphQLAccessServices.php`: AuditRecorderInterface, ObjectAccessInterface, PropertyAccessInterface
- `Search/Service/GraphQL/SchemaGenerator.php`: PropertyAccessInterface
- `Search/Service/GraphQL/SchemaGenerator/SchemaCaller.php`: PropertyAccessInterface
- `Search/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php`: ObjectAccessInterface, PropertyAccessInterface
- `Search/Service/MapsOverviewService.php`: ObjectAccessInterface
- `Search/Service/Quality/DedupCreatePolicy.php`: AuditRecorderInterface
- `Search/Service/Quality/DedupOverrideAudit.php`: AuditRecorderInterface
- `Search/Service/Quality/DedupRuleResolver.php`: SchemaLookupInterface

PR 3:

- `Search/Service/Quality/DuplicateDetectionService.php`: SchemaLookupInterface
- `Search/Service/Reporting/ReportRenderService.php`: AggregationInterface
- `Search/Service/Search/PropertySearchProfile.php`: RevealInterface
- `Search/Service/Search/SearchIndexMaintenance.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Search/Service/Search/SearchIndexStores.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Search/Service/Vectorization/VectorEmbeddings.php`: AppSettingsInterface
- `Search/Service/ViewPresentationService.php`: ObjectAccessInterface, PropertyAccessInterface
- `Search/Service/ViewService.php`: ObjectAccessInterface, ViewAlertsInterface, ViewerReachInterface
- `Service/GraphQL/GraphQLResolver.php`: AggregationInterface, ObjectAccessInterface, ObjectReaderInterface, PropertyAccessInterface, RevealInterface
- `Service/GraphQL/GraphQLResolverObjects.php`: ObjectReaderInterface
- `Service/GraphQL/GraphQLResolverRights.php`: ObjectAccessInterface, PropertyAccessInterface
- `Service/GraphQL/GraphQLResolverShaping.php`: AggregationInterface, ObjectReaderInterface
- `Service/GraphQL/ListAggregations.php`: AggregationInterface

### C5-10 Notifications (16 files, 2 PRs)

PR 1:

- `Notifications/BackgroundJob/ScheduledNotificationJob.php`: ObjectReaderInterface
- `Notifications/Controller/EmailsController.php`: ObjectAccessInterface
- `Notifications/Controller/Support/EmailsObjectAccess.php`: ObjectAccessInterface
- `Notifications/Search/TimelineEntriesProvider.php`: DeepLinksInterface
- `Notifications/Service/EmailLinkedSchemas.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Notifications/Service/EmailService.php`: ObjectReaderInterface
- `Notifications/Service/GraphQL/SubscriptionService.php`: ObjectAccessInterface
- `Notifications/Service/Notification/AnnotationNotificationDispatcher.php`: DeepLinksInterface
- `Notifications/Service/Notification/MessageRendering.php`: DeepLinksInterface
- `Notifications/Service/Notification/NotificationRecipientResolver.php`: ObjectAccessInterface
- `Notifications/Service/Notification/ObjectDetailLinks.php`: DeepLinksInterface
- `Notifications/Service/Notification/ScheduledMessageScheduler.php`: AuditRecorderInterface
- `Notifications/Service/ObjectDeepLinks.php`: DeepLinksInterface
- `Notifications/Service/Timeline/MentionDelivery.php`: DeepLinksInterface
- `Notifications/Service/Timeline/MentionReadCheck.php`: ObjectAccessInterface

PR 2:

- `Notifications/Service/TimelineVisibilityService.php`: AuditRecorderInterface, ObjectAccessInterface

### C5-11 Platform (31 files, 3 PRs)

PR 1:

- `Capabilities/PlatformCapability.php`: ApiVersionsInterface, DeepLinksInterface
- `Capabilities/SearchScopesCapability.php`: ObjectAccessInterface
- `Capabilities/UrnCapability.php`: ApiVersionsInterface
- `Controller/AgentsController.php`: TenancyInterface
- `Controller/ChatController.php`: TenancyInterface
- `Controller/ConversationController.php`: TenancyInterface
- `Controller/Support/AgentsControllerServices.php`: TenancyInterface
- `Controller/Support/ChatDomain.php`: TenancyInterface
- `Controller/Support/ConversationDomain.php`: TenancyInterface
- `Listener/NotifyPushListener.php`: ObjectReaderInterface
- `Middleware/AnonymousEnvelopeMiddleware.php`: ViewerReachInterface
- `Middleware/ApiVersionMiddleware.php`: ApiVersionsInterface
- `Middleware/Exception/ApiVersionRefusedException.php`: ApiVersionsInterface
- `Middleware/LoadSheddingMiddleware.php`: ProblemDetailsInterface
- `Middleware/OasValidationMiddleware.php`: ProblemDetailsInterface

PR 2:

- `Middleware/ObjectSourceErrorMiddleware.php`: ObjectTablesInterface
- `Middleware/ProblemDetailsMiddleware.php`: ProblemDetailsInterface
- `Middleware/RateLimitMiddleware.php`: RequestSecurityInterface
- `Middleware/RevealAuditMiddleware.php`: RevealInterface
- `Middleware/SchemaChangeHeaderMiddleware.php`: SchemaEvolutionInterface
- `Middleware/Support/ApiCallerServices.php`: ApiVersionsInterface
- `Middleware/TenantQuotaMiddleware.php`: TenancyInterface
- `Repair/AddObjectStateColumns.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Repair/GiveFederationSharesALifetime.php`: ObjectGrantsInterface
- `Repair/MagicTableSweepRun.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Repair/MigratedFlowOrigins.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Repair/SeedTablesVirtualSchemas.php`: ObjectTablesInterface
- `Repair/WidenMagicNumberColumns.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Repair/WithholdRevealValuesFromRelations.php`: ObjectTablesInterface
- `Service/Object/SaveObject/RevealRelations.php`: RevealInterface

PR 3:

- `Service/TranslationStores.php`: ObjectReaderInterface, existing interface

### C5-11 Files (20 files, 2 PRs)

PR 1:

- `Files/Controller/FileExtractionController.php`: ObjectReaderInterface
- `Files/Controller/FilesController.php`: ObjectAccessInterface
- `Files/Controller/ObjectFolderController.php`: ObjectAccessInterface
- `Files/Controller/Support/FileExtractionMappers.php`: ObjectReaderInterface
- `Files/Controller/Support/FileGuards.php`: ObjectAccessInterface
- `Files/Service/File/FileAuditHandler.php`: AuditRecorderInterface
- `Files/Service/File/ManualEntityRecording.php`: AuditRecorderInterface
- `Files/Service/File/ManualEntityService.php`: AuditRecorderInterface
- `Files/Service/File/ObjectFileAccess.php`: ObjectAccessInterface
- `Files/Service/NoteVersionService.php`: AuditRecorderInterface
- `Files/Service/Sharing/AccessLinkReader.php`: RevealInterface
- `Files/Service/Sharing/AccessLinkReaderSources.php`: AccessLinkReaderInterface
- `Files/Service/TextExtraction/EntityRecognitionHandler.php`: AppSettingsInterface, ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Files/Service/TextExtraction/ObjectHandler.php`: ObjectReaderInterface
- `Files/Service/TextExtraction/TextExtractionAnalysis.php`: AppSettingsInterface

PR 2:

- `Files/Service/TextExtraction/TextExtractionObjects.php`: ObjectReaderInterface, ObjectTablesInterface, ObjectWriterInterface
- `Service/FileHandlerSupportInterface.php`: ObjectReaderInterface
- `Service/FileServiceInterface.php`: ObjectReaderInterface
- `Service/Sharing/AccessLinkReaderFilters.php`: PropertyAccessInterface
- `Service/TextExtraction/EntityRecognitionBackends.php`: AppSettingsInterface

## Appendix C: Core classes other apps name

From `fleet_surface.py` over `fleet_checkout.sh` checkouts (development branches, 2026-11-19). References, lines, class, apps.

| References | Lines | Class | Apps |
|---|---|---|---|
| 1143 | 3010 | `OCA\OpenRegister\Service\ObjectService` | buildiq, decidiq, dossiq, filinq, hermiq, humaniq, integriq, larpinq, launchpad, learniq, opencatalogi, pipelinq, planninq, portaliq, shillinq, stackiq |
| 151 | 836 | `OCA\OpenRegister\Db\SchemaMapper` | buildiq, decidiq, dossiq, filinq, hermiq, humaniq, integriq, larpinq, launchpad, learniq, opencatalogi, pipelinq, planninq, portaliq, shillinq, stackiq |
| 98 | 452 | `OCA\OpenRegister\Db\RegisterMapper` | buildiq, decidiq, dossiq, hermiq, humaniq, integriq, larpinq, learniq, opencatalogi, pipelinq, planninq, portaliq, shillinq, stackiq |
| 59 | 697 | `OCA\OpenRegister\Service\FileService` | buildiq, decidiq, dossiq, filinq, humaniq, integriq, learniq, opencatalogi, planninq, portaliq |
| 37 | 627 | `OCA\OpenRegister\Db\OrganisationMapper` | buildiq, dossiq, hermiq, learniq, opencatalogi, portaliq, stackiq |
| 28 | 957 | `OCA\OpenRegister\Service\OrganisationService` | dossiq, filinq, hermiq, integriq, learniq, pipelinq, portaliq, shillinq, stackiq |
| 22 | 270 | `OCA\OpenRegister\Service\Deferral\ListenerDeferralService` | integriq, learniq, pipelinq, shillinq |
| 19 | 55 | `OCA\OpenRegister\Service\SystemOperationContext` | dossiq, integriq, pipelinq, planninq |
| 18 | 515 | `OCA\OpenRegister\Db\EntityRelationMapper` | filinq, opencatalogi |
| 14 | 5580 | `OCA\OpenRegister\Db\MagicMapper` | dossiq, hermiq, humaniq, learniq, opencatalogi, pipelinq, portaliq, stackiq |
| 14 | 78 | `OCA\OpenRegister\Service\Deferral\DeferredListenerContext` | integriq, learniq, pipelinq, shillinq |
| 7 | 366 | `OCA\OpenRegister\Service\RegisterService` | buildiq, filinq, stackiq |
| 7 | 11 | `OCA\OpenRegister\Service\File\FileTarget` | integriq, planninq |
| 7 | 12 | `OCA\OpenRegister\Service\File\FileUpload` | integriq, planninq |
| 6 | 2378 | `OCA\OpenRegister\Service\Aggregation\AggregationRunner` | buildiq, pipelinq, shillinq |
| 6 | 110 | `OCA\OpenRegister\Service\RegisterSlugResolver` | buildiq, decidiq, hermiq, integriq, pipelinq, shillinq |
| 6 | 10 | `OCA\OpenRegister\Service\HoldOrder` | dossiq, filinq, humaniq |
| 6 | 288 | `OCA\OpenRegister\Db\Organisation` | dossiq, stackiq |
| 4 | 118 | `OCA\OpenRegister\BackgroundJob\ActorForwardedJob` | integriq, learniq, pipelinq, shillinq |
| 4 | 203 | `OCA\OpenRegister\Service\RegisterResolverService` | integriq, larpinq, opencatalogi, stackiq |
| 3 | 1562 | `OCA\OpenRegister\Service\Object\PermissionHandler` | dossiq, integriq |
| 3 | 177 | `OCA\OpenRegister\Service\Object\ArchiveHandler` | dossiq, stackiq |
| 3 | 12 | `OCA\OpenRegister\Service\Object\ObjectStateRequest` | dossiq, stackiq |
| 3 | 19 | `OCA\OpenRegister\Service\ConfigLookup` | larpinq, opencatalogi, stackiq |
| 2 | 213 | `OCA\OpenRegister\Service\Rbac\ObjectGrantResolver` | dossiq, launchpad |
| 2 | 67 | `OCA\OpenRegister\Service\Object\AuditHandler` | humaniq |
| 2 | 19 | `OCA\OpenRegister\Service\RegisterMappingFunctionsEvent` | integriq |
| 2 | 129 | `OCA\OpenRegister\Service\ObjectServiceMapperAdapter` | integriq |
| 2 | 12 | `OCA\OpenRegister\Service\File\FileTransfer` | integriq, opencatalogi |
| 2 | 11 | `OCA\OpenRegister\Service\Organisation\NewOrganisation` | stackiq |
| 1 | 16 | `OCA\OpenRegister\Db\SourceMapperInterface` | buildiq |
| 1 | 310 | `OCA\OpenRegister\Service\SecurityService` | buildiq |
| 1 | 264 | `OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights` | dossiq |
| 1 | 25 | `OCA\OpenRegister\Service\Rbac\SpecialRightGrant` | dossiq |
| 1 | 526 | `OCA\OpenRegister\Service\Rbac\ObjectOwnershipService` | dossiq |
| 1 | 319 | `OCA\OpenRegister\Service\Rbac\ObjectSharingService` | dossiq |
| 1 | 14 | `OCA\OpenRegister\Service\Object\ProvenanceRequest` | dossiq |
| 1 | 10 | `OCA\OpenRegister\Service\Rbac\GrantLookup` | dossiq |
| 1 | 8 | `OCA\OpenRegister\Service\Rbac\ObjectGrantResolverInterface` | dossiq |
| 1 | 887 | `OCA\OpenRegister\Service\Merge\MergeService` | dossiq |
| 1 | 85 | `OCA\OpenRegister\Service\RegisterScopedSchemaResolver` | dossiq |
| 1 | 214 | `OCA\OpenRegister\Service\Relation\ObjectRelationService` | dossiq |
| 1 | 12 | `OCA\OpenRegister\Service\Relation\ParentInheritance` | dossiq |
| 1 | 148 | `OCA\OpenRegister\Db\ObjectReadStateMapper` | dossiq |
| 1 | 153 | `OCA\OpenRegister\Service\Rbac\ViewerReachResolver` | dossiq |
| 1 | 76 | `OCA\OpenRegister\Service\Deletion\DestructionRecorder` | dossiq |
| 1 | 186 | `OCA\OpenRegister\Service\Deletion\DestructionScopeService` | dossiq |
| 1 | 2624 | `OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler` | dossiq |
| 1 | 136 | `OCA\OpenRegister\Service\Deletion\DeletionWindowService` | dossiq |
| 1 | 168 | `OCA\OpenRegister\Service\Registry\RegistrySubscriptionService` | dossiq |
| 1 | 15 | `OCA\OpenRegister\Service\Sharing\AccessLinkTerms` | dossiq |
| 1 | 12 | `OCA\OpenRegister\Service\Sharing\LinkMintRequest` | dossiq |
| 1 | 283 | `OCA\OpenRegister\Service\Sharing\AccessLinkService` | dossiq |
| 1 | 138 | `OCA\OpenRegister\Service\Rbac\ExplicitReveal` | dossiq |
| 1 | 7 | `OCA\OpenRegister\Service\Object\RetentionDestructionInterface` | filinq |
| 1 | 96 | `OCA\OpenRegister\Service\Object\BulkWriteEvents` | hermiq |
| 1 | 99 | `OCA\OpenRegister\Service\Search\VectorSearchFacade` | hermiq |
| 1 | 30 | `OCA\OpenRegister\Service\PropertyAccessRequest` | humaniq |
| 1 | 326 | `OCA\OpenRegister\Service\PropertyRbacHandler` | humaniq |
| 1 | 11 | `OCA\OpenRegister\Service\SchemaProperty` | humaniq |
| 1 | 11 | `OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface` | larpinq |
| 1 | 15 | `OCA\OpenRegister\Service\File\FileMetadataChange` | opencatalogi |
| 1 | 16 | `OCA\OpenRegister\Service\Rbac\ObjectOwnershipServiceInterface` | opencatalogi |
| 1 | 17 | `OCA\OpenRegister\Service\Rbac\OwnedRecord` | opencatalogi |
| 1 | 18 | `OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException` | opencatalogi |
| 1 | 7 | `OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesServiceInterface` | pipelinq |
| 1 | 222 | `OCA\OpenRegister\Service\SemanticTypeResolver` | pipelinq |
| 1 | 11 | `OCA\OpenRegister\Service\Object\DiscardDraftRequest` | portaliq |
| 1 | 62 | `OCA\OpenRegister\Service\Object\DraftDiscard` | portaliq |
| 1 | 53 | `OCA\OpenRegister\Service\Object\DraftOwner` | portaliq |
| 1 | 208 | `OCA\OpenRegister\Service\Vocabulary\ConceptRepository` | portaliq |
| 1 | 6 | `OCA\OpenRegister\Service\Object\MoveObjectInterface` | stackiq |
| 1 | 14 | `OCA\OpenRegister\Service\Object\PermissionHandlerInterface` | stackiq |
