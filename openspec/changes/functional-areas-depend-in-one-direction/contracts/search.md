# Contract design: the Search area

Wave 5 design package D04 of the change `functional-areas-depend-in-one-direction` (hydra ADR-119,
Decisions 8, 9, 13 and 14). This file is a design. It changes nothing in `lib/`.

Measured on `origin/main` `e918c58d` with the module graph of `tools/sig/check_sig_maintainability.py`,
the same reading that `tools/sig/modularity_check.py` uses. Lines are code lines as that tool counts
them (no comments, no blank lines).

## Summary

| | Today | After this design |
|---|---|---|
| Exposed lines of Search | 2,270 | 168 |
| Exposed files of Search | 55 | 16 |
| References against the order into Search (all from Core) | 44 | 0 |
| References against the order out of Search | 39 | 33 (the rest belong to the higher areas' designs) |
| References against the order, whole app | 951 | 897 |
| Exposed lines, whole app | 158,870 | 155,105 |

The "after" column is a simulation, not an estimate. The script applied every move listed below to the
graph measured on `e918c58d`: csv rows reassigned, caller edges rewired, new ports added with their
line counts. It then ran the exposure and order arithmetic of `modularity_check.py` again. A port's line
count is an estimate from the existing ports in `lib/Kernel/Contracts/Core/`, which have 6 to 9 lines
for 1 to 3 one-line methods.

Search is a small exposer today. Of its 2,270 exposed lines, 625 are search trail helpers that only
Compliance uses, 343 are `ObjectsProvider`, which Platform names once as `::class`, and 265 are
`ViewService`, which one Core class calls. The real work in this design is in the other direction. Core
calls into Search 44 times against the order, and Core holds about 4,400 lines of Search work:
aggregation, GraphQL, the vector search facade, the view alert sweep and the hybrid search diagnostics.

## 1. The exposed files of Search today

55 files, 2,270 lines. "Callers" lists the other area's files that reference the file.

### Search trail (14 files, 625 lines; only Compliance's trail classes use them)

| File | Lines | Callers |
|---|---|---|
| `lib/Db/SearchTrail/SearchTrailStatistics.php` | 166 | Compliance `Db/SearchTrailMapper` |
| `lib/Service/SearchTrailStatistics.php` | 125 | Compliance `Service/SearchTrailService` |
| `lib/Service/SearchTrailQueryConfig.php` | 65 | Compliance `Service/SearchTrailService` |
| `lib/Service/SearchTrailNameEnricher.php` | 57 | Compliance `Service/SearchTrailService` |
| `lib/Service/SearchTrailActivityInsights.php` | 51 | Compliance `Service/SearchTrailService` |
| `lib/Db/SearchTrail/SearchTrailFieldTypes.php` | 39 | Compliance `Db/SearchTrail` |
| `lib/Service/SearchTrailUserAgentParser.php` | 28 | Compliance `Service/SearchTrailService` |
| `lib/Db/SearchTrailMapperInterface.php` | 25 | Compliance (4: controller, bindings, mapper, service); Core `Controller/Support/SearchTrailControllerServices` |
| `lib/Service/SearchTrailServiceInterface.php` | 16 | Compliance (controller, service); Core `SearchTrailControllerServices`; Configuration `Service/ContractBindings` |
| `lib/Db/SearchTrailCriteria.php` | 13 | Compliance mapper, service |
| `lib/Db/SearchTrail/CreatedRange.php`, `SearchTrailFilterRun.php`, `SearchTrailPage.php` | 11 each | Compliance mapper |
| `lib/Db/SearchTrailCleanupInterface.php` | 7 | Compliance (3); Core `BackgroundJob/LogCleanUpTask` |

### Views and view alerts (6 files, 494 lines)

| File | Lines | Callers |
|---|---|---|
| `lib/Search/Service/ViewService.php` | 265 | Core `Service/Search/VectorResultFilter` (`find`) |
| `lib/Search/Db/View.php` | 160 | Compliance `BackgroundJob/Support/ViewAlertCheck`; Core `BackgroundJob/ViewAlertSweepJob`, `Service/UserMigration/PersonalStateMigrator`, `PersonalStateReader`; Integrations `Calendar/DeclaredDateCalendars`, `Mcp/Db/Agent` (unused import) |
| `lib/Search/Db/ViewMapperInterface.php` | 17 | Core (6: `ViewScopeApplier`, `ObjectService`, `ObjectMapperServices`, `ViewSourceMappers`, `AccessLinkViewReaders`, `PersonalStateMigrator`); Processes `ObjectCalendarFeedService`, `ObjectCalendarFeedSources`; Compliance `ViewAlertSweepSources` |
| `lib/Listener/ViewAlertMail.php` | 14 | Processes `Listener/ViewAlertCrossedListener` |
| `lib/Listener/ViewAlertAudience.php` | 12 | Processes `Listener/ViewAlertCrossedListener` |
| `lib/Search/BackgroundJob/SearchJobHandlesInterface.php` | 6 | Configuration `Service/Operations/OperationsJobsService` (constant) |

### Registration and Platform (6 files, 624 lines)

| File | Lines | Callers |
|---|---|---|
| `lib/Search/ObjectsProvider.php` | 343 | Platform `Capabilities/PlatformIntegrations` (`::class` only) |
| `lib/Search/Listener/AggregationThresholdListener.php` | 93 | Processes `Listener/ListenerRegistrar` |
| `lib/Search/Listener/ObjectMetricsListener.php` | 87 | Processes `Listener/ListenerRegistrar` |
| `lib/Search/SearchScopeCatalog.php` | 81 | Platform `Capabilities/SearchScopesCapability` (`describe`) |
| `lib/Search/SearchRegistrar.php`, `SearchProviderRegistrar.php` | 10 each | Platform `AppInfo/Application` |

### Aggregation, GraphQL, placeholders, property profile (11 files, 357 lines; callers almost all Core)

| File | Lines | Callers |
|---|---|---|
| `lib/Search/Service/Aggregation/AggregationQuery.php` | 102 | Core `Service/Aggregation/*` (3), `Service/GraphQL/ListAggregations`; Processes `Service/Calculation/AggregateReferenceResolver` |
| `lib/Search/Service/Search/PropertySearchProfile.php` | 99 | Core `Db/MagicMapper/MagicSearchHandler`, `Service/Object/FacetableFieldDiscovery`, `Service/Schemas/PropertyValidatorHandler` (static methods, constants) |
| `lib/Search/Service/GraphQL/GraphQLErrorFormatter.php` | 45 | Core `Service/GraphQL/GraphQLResolver` |
| `lib/Search/Service/Aggregation/AggregationSlot.php` | 26 | Core `AggregationRunner` |
| `lib/Search/Service/Aggregation/CrossSchemaTarget.php` | 25 | Core `AggregationRunner` |
| `lib/Search/Service/SearchAnnotationValidators.php` | 15 | Core `Db/SchemaMapper/AdvisoryAnnotationChecks`, `RecordAnnotationRefusals` |
| `lib/Search/Service/Aggregation/AggregationCacheInterface.php` | 11 | Core `AggregationRunner`; Processes `Listener/AggregationCacheInvalidationListener` (`evictForSchema`) |
| `lib/Search/Service/Search/PlaceholderResolvers.php` | 9 | Core `Service/Object/SaveObject` |
| `lib/Search/Service/Aggregation/TimeseriesRequestValidatorInterface.php` | 8 | Core `Service/GraphQL/*` (3) |
| `lib/Search/Service/Search/PlaceholderResolverInterface.php` | 7 | Core `AggregationRunner`; Processes `Service/Calculation/CalculationEvaluator` |

### Geo (7 files, 59 lines; all Core `ObjectsController` and its `Controller/Support/GeoServices` bag)

`FeatureShape` 11, `GetFeatureRequest` 10, `GeoResponseCrsInterface` 9, `GeoResponseCrsFactory` 8,
`GeoFilterParserInterface` 8, `GeoFeatureCollectionBuilderInterface` 7, `GeoFilterApplierInterface` 6.

### Duplicate check on create (2 files, 23 lines)

`Quality/DedupCreatePolicyInterface` 11 and `Quality/CreateCandidate` 12, both from Core `Service/Object/SaveObject`.

### Vectors (11 files, 159 lines)

| File | Lines | Callers |
|---|---|---|
| `lib/Search/Service/Vectorization/EmbeddingProbe.php` | 22 | Configuration `Controller/Settings/LlmSettingsController` |
| `lib/Search/Service/VectorizationServiceInterface.php` | 18 | Configuration (2: LLM settings); Files (4: `FileExtractionController`, `FileSearchController` and their bags); Core `Service/Search/VectorSearchFacade` |
| `lib/Search/Service/Vectorization/VectorEmbeddingsInterface.php` | 16 | Integrations `Mcp/Service/Chat/ContextRetrievalHandler` |
| `lib/Search/Service/Vectorization/HybridSearchRequest.php`, `SemanticSearchRequest.php` | 12 each | Files `FileSearchController`; Integrations `ContextRetrievalHandler`; Core `VectorSearchFacade` |
| `.../Handlers/ChunkVectorSourceInterface.php` | 12 | Files `BackgroundJob/ChunkAsVectorSource` (implements) |
| `.../Handlers/VectorStorageHandlerInterface.php` | 7 | Files `ChunkVectorizationBatch`, `ChunkVectorizationJob` |
| `.../Handlers/ChunkVectorFactoryInterface.php` | 6 | Files `ChunkVectorizationBatch` |
| `.../Handlers/VectorSidecarTableInterface.php` | 6 | Core `Controller/Support/HybridSearchDiagnostics` (constant) |
| `lib/Search/Service/ExampleSet/ExampleSetViewRowsInterface.php` | 7 | Configuration `Service/ExampleSet/ExampleSetOwnRowKinds` |

Distinct caller files per area: Core 27, Compliance 8, Files 7, Processes 7, Configuration 5,
Integrations 3, Platform 3.

## 2. What outside callers actually need

| Capability | Who needs it | What they call |
|---|---|---|
| The stored query of a saved view | Core (view scope on listings, view object source, access link mint guard) | `ViewMapperInterface::find($id, rbac, multitenancy)->getQuery()`. All three pass one flag for both rbac and multitenancy. |
| Saved views for calendars | Integrations `DeclaredDateCalendars`, Processes calendar feeds | `findAll(owner)`, `find(id)`, then `getId`, `getName`, `getPresentation` |
| A user's views in user migration | Core `PersonalStateMigrator` / `PersonalStateReader` | read the owned views as portable rows, insert them back |
| The alert sweep over saved views | Core `ViewAlertSweepJob` with three Compliance support bags | the whole `View` entity and `findWithAlerts` |
| Delivering a crossed view alert | Processes `ViewAlertCrossedListener` | its own helpers `ViewAlertMail`, `ViewAlertAudience` |
| Aggregation results | Processes `AggregateReferenceResolver` | `AggregationQuery::create(...)` and `AggregationRunner::runAdhocByRef(..., bypassRbac: true)` |
| Evicting cached aggregations | Processes `AggregationCacheInvalidationListener` | `evictForSchema(register, schema)` |
| Semantic and hybrid search | Files `FileSearchController`; Integrations `ContextRetrievalHandler` | `semanticSearch(SemanticSearchRequest)`, `hybridSearch(HybridSearchRequest)` |
| Embedding administration | Configuration LLM settings | `checkEmbeddingModelMismatch`, `clearAllEmbeddings`, `getVectorStats`, `testEmbedding(EmbeddingProbe)` |
| Indexing vectors | Files extraction controller and chunk jobs | `vectorizeBatch`, `forChunk` then `storeVector`, `backfillEmbeddingVectors` |
| Search scopes for the capability | Platform `SearchScopesCapability` | `describe()` |
| The search provider's class | Platform `PlatformIntegrations` | `ObjectsProvider::class` |
| Search's object listeners | Processes `ListenerRegistrar` | the class names, at two fixed positions in the registration sequence |
| Duplicate check before create | Core `SaveObject` | `OVERRIDE_KEY`, `guardCreate(CreateCandidate)`, `recordOverride` |
| Geo filters, GeoJSON and WFS | Core `ObjectsController` | the geo endpoints (`geoSearch`, `geoJson`, `wfs`) and the geo filter on the index listing |
| Schema annotation checks | Core `SchemaMapper` checks, `SchemaDeclarationValidators` | `SearchAnnotationValidators::aggregation()`, `::uniqueHint()`, `WidgetAnnotationValidator` |
| Placeholders in values | Core `SaveObject`, Core `AggregationRunner`, Processes `CalculationEvaluator` | `resolve`, `resolveArray` |
| Property match type | Core MagicMapper search, facet discovery, property validation | `matchTypeFor`, `participatesInFreeText`, `declaredBy`, `MATCH_*`, `INPUT_CONTROLS` |
| Job handle, example-set rows | Configuration operations and example sets | `SEARCH_INDEX_REBUILD`, `rows()` |
| Search trail internals | Compliance trail mapper and service | everything; these are the trail's own pieces |

## 3. The proposed contract set

### 3a. Classes that count to the area whose work they do (ADR-119 move 3, csv rows only, no file moves)

Each row change below rests on what the class does and who uses it, never on hiding a reference.

| Id | Files (lines) | From → to | Why it is that area's work |
|---|---|---|---|
| A1 | the 14 search trail files above (625) | Search → Compliance | They were split out of `SearchTrailMapper` and `SearchTrailService` (Compliance), and those two classes are their only users ("The statistics SearchTrailMapper serves"). Six of Search's 39 references against the order are these helpers naming `SearchTrail`, `SearchTrailOutcome` and `SearchPeriod`. |
| A1 | `lib/Controller/Support/SearchTrailControllerServices.php` (19) | Core → Compliance | A `Controller/Support` bag whose only user is Compliance's `SearchTrailController`. This is the decided bag rule. |
| A2 | `lib/Listener/ViewAlertMail.php`, `ViewAlertAudience.php` (26) | Search → Processes | Helpers of Processes' `ViewAlertCrossedListener`, their only user. Delivering the alert is that listener's work. |
| A3 | `lib/BackgroundJob/ViewAlertSweepJob.php` (Core) and `lib/BackgroundJob/Support/ViewAlertCheck.php`, `ViewAlertSweepPlatform.php`, `ViewAlertSweepSources.php` (Compliance) (195) | → Search | Evaluating a saved view's alert threshold is a feature of saved views, next to Search's `AggregationThresholdJob`. The three bags have the job as their only user. |
| A4 | `lib/Service/GraphQL/*` (7 files, 569) | Core → Search | Search's GraphQL schema services are the only users. The GraphQL API (`GraphQLController`, `GraphQLService`, `SchemaGenerator`) is Search. |
| A5 | `lib/Service/Aggregation/*` (24 files, 3,226) | Core → Search | This is the aggregation engine. Its users are Search's aggregation controller, threshold and report services, the GraphQL list (A4), Processes and the wiring. Nothing in the object store calls it. The query, cache, slot and threshold classes are already Search. |
| A6 | `lib/Service/Search/VectorSearchFacade.php`, `VectorResultFilter.php` (234) | Core → Search | Vector search for other apps (hermiq). No openregister class calls them. They sit on `VectorizationServiceInterface` and `ViewService`. |
| A7 | `lib/Controller/Support/HybridSearchDiagnostics.php` (142) | Core → Search | A support bag whose only user is `lib/Controller/SettingsController.php`, which is Search. This is the decided bag rule. |
| A8 | `lib/Search/Service/Search/PlaceholderResolver.php`, `PlaceholderResolverInterface.php`, `PlaceholderResolvers.php` (109) | Search → Core | They resolve session placeholders in values. Their callers are Core's save path, aggregation (A5) and Processes' calculations, and no Search class uses them besides the binding. The lowest consumer is Core, so every reference then points down. The binding moves from `SearchWiring` to Core's `lib/Service/ServiceRegistrar` map. |
| A9 | `lib/Search/Service/Search/PropertySearchProfile.php` (99) | Search → Core | It decides how the object store's `MagicSearchHandler` matches a property. All three callers are Core, and no Search class calls it. It already implements the Kernel `PropertySearchProfileInterface`. The binding moves with it, as in A8. |
| A10 | `lib/Search/Service/Quality/CreateCandidate.php` (12) | Search → Core | It becomes the argument of the Core-owned `CreateGuardInterface` (C3). Only Core's `SaveObject` builds it. |

### 3b. Ports owned by Search: `lib/Kernel/Contracts/Search/`

Higher areas use these. Each is one capability, and its methods are what the callers call today.

| Port | Methods (one line each) | Implemented by | Lines |
|---|---|---|---|
| `SavedViewsInterface` | `public function ownedBy(string $userId): array;` (list of `SavedView`) · `public function find(string $viewId): SavedView;` | `lib/Search/Service/ViewService` | 7 |
| `SavedView` (value) | `id`, `uuid`, `name`, `presentation` (readonly). It replaces the `View` entity for the calendar callers. | built by `ViewService` | 11 |
| `VectorSearchInterface` | `public function semanticSearch(SemanticSearchRequest $request): array;` · `public function hybridSearch(HybridSearchRequest $request): array;` | `Vectorization/VectorEmbeddings`. `VectorizationService` only delegates to it today, so the result is identical. | 9 |
| `EmbeddingAdministrationInterface` | `public function getVectorStats(): array;` · `public function checkEmbeddingModelMismatch(): array;` · `public function clearAllEmbeddings(): array;` · `public function testEmbedding(EmbeddingProbe $probe): array;` | `Vectorization/VectorEmbeddings`, for the same reason | 10 |
| `VectorIndexInterface` | `public function vectorizeBatch(string $entityType, array $options = []): array;` · `public function storeChunk(ChunkVectorSourceInterface $chunk, array $embedding): int;` · `public function backfill(int $batchSize, int $afterId): array;` | new `Vectorization/ChunkVectorIndex`, which composes `VectorizationService::vectorizeBatch`, `ChunkVectorFactory::forChunk` + `VectorStorageHandler::storeVector`, and `backfillEmbeddingVectors` | 9 |
| `AggregationResultsInterface` | `public function runAsSystem(RegisterSchemaRef $table, array $spec): array;` (spec: `metric`, `field`, `filter`, `groupBy`, as `AggregationQuery::create` takes them) | `lib/Service/Aggregation/AggregationRunner` (after A5) | 7 |
| `AggregationCacheEvictionInterface` | `public function evictForSchema(string $registerSlug, string $schemaSlug): void;` | `Aggregation/AggregationCache` | 6 |
| `SearchScopesInterface` | `public function describe(): array;` | `lib/Search/SearchScopeCatalog` | 6 |
| `ReadableFilesInterface` | `public function readable(array $fileIds): array;` | Files (new `lib/Files/Service/File/ReadableFiles`, on `FileReadScope`) | 7 |

`ReadableFilesInterface` serves the other direction. `VectorResultFilter` (A6) and `Quality/NearDuplicateScope`
name Files' `FileReadScope` today, Core → Files and Search → Files against the order. Files implements
the Search-owned port, so those references point down.

Ports deliberately not added:
- A port for the registrars. `SearchRegistrar` and `SearchProviderRegistrar` stay named by `Application`, as every area's registrar is.
- A view port for Compliance or Core. After A3 the alert sweep is Search's own, and Core reaches views only through C1 and C2.

Three more changes take no new contract:
- **Search provider.** `SearchProviderRegistrar` gets `public const PROVIDER = ObjectsProvider::class;` and `PlatformIntegrations` reads `SearchProviderRegistrar::PROVIDER`. The value is the same string, and `ObjectsProvider` (343) is no longer named outside Search.
- **Listeners.** A new `lib/Search/Listener/Registrar/SearchListenerRegistrar` (about 22 lines) has two static methods, `metrics()` and `thresholds()`. Processes' `ListenerRegistrar` calls them at exactly the two positions where it registers `ObjectMetricsListener` (today line 651) and `AggregationThresholdListener` (line 847). The registration sequence is identical, so `listener-order.json` and the sequence hash in `ListenerRegistrationOrderGoldenTest` do not change. Moving the registrations into `SearchRegistrar` would reorder the list, which is not allowed. This follows the `McpListenerRegistrar` and `NotificationsListenerRegistrar` pattern.
- **Unused import.** The `use ...\Search\Db\View;` in `lib/Mcp/Db/Agent.php` is removed. Only a docblock mentions the class.

### 3c. Ports owned by Core: `lib/Kernel/Contracts/Core/`

These implement the decision that Core services calling Search get a Core-owned port per capability.
They count to Core's budget (D02), not to Search's. Search implements them and binds them in `SearchWiring`.

| Id | Port | Methods | Implemented by | Lines |
|---|---|---|---|---|
| C1 | `StoredViewQueryInterface` | `public function queryOf(int\|string $viewId, bool $asCaller): array;` (`$asCaller` turns rbac and multitenancy on together, as all three callers pass them; unreadable throws as `find` does today) | `ViewService` | 6 |
| C2 | `PersonalStateSectionInterface` | `public function export(string $userId): array;` · `public function restore(string $userId, array $row): bool;` | Search `ViewPersonalState` (new), and Notifications for watches (D03). The migrator keeps its file names and manifest order. | 8 |
| C3 | `CreateGuardInterface` | `public const OVERRIDE_KEY = '_dedupOverride';` · `public function guardCreate(CreateCandidate $candidate): array;` · `public function recordOverride(ObjectEntity $object, array $matches): void;` | `Quality/DedupCreatePolicy`, through `DedupCreatePolicyInterface extends CreateGuardInterface`. The interface keeps `OVERRIDE_ACTION`, and `OVERRIDE_KEY` is inherited. `SaveObject` resolves the guard lazily by the Core id, still optional. | 9 |
| C4 | `ListingNarrowingInterface` | `public function narrow(array $params, array $result): array;` (the geo filter of the index listing, today `applyGeoQueryFilters`) | Search `Geo/GeoListingNarrowing` (new, on `GeoFilterParser` + `GeoFilterApplier`) | 6 |

These need no port:
- **Geo endpoints.** `geoSearch`, `geoJson` and `wfs` move to a new Search `lib/Search/Controller/GeoController`. This follows the decision on `ObjectsController` endpoints. `appinfo/routes.php` keeps each URL and verb; the route name becomes `geo#...`. `geocode` and `geoFeatures` stay, because they use no Search class.
- **Schema annotation checks.** `SearchAnnotationValidators` and `WidgetAnnotationValidator` are contributed through `SchemaDeclarationChecksEvent`, in the slot where they run today (#10685), never reordered. This adds one Search listener, so both listener pins change in that package.
- **Job handle.** `SearchJobHandlesInterface::SEARCH_INDEX_REBUILD` reaches Configuration through the job-handle collection, as decided. Search registers its own handle. The collection itself is P46.

### 3d. Entities that cross the boundary

| Entity | Today crosses to | After |
|---|---|---|
| `Search\Db\View` | Core, Compliance, Integrations | Internal to Search. Calendars get the `SavedView` value, Core gets the stored query array (C1) and portable rows (C2), and the sweep is Search's (A3). |
| `Db\SearchTrail` (Compliance) | Search helpers | Internal to Compliance (A1). |
| `Db\Schema`, `Db\ObjectEntity` (Core) | Named by Search ports (`describe()` rows, `recordOverride`) | Stay: they point down, which is allowed. |

## 4. The budget

| Exposed file after | Lines | Named by |
|---|---|---|
| `Vectorization/EmbeddingProbe` (value of `EmbeddingAdministrationInterface`) | 22 | Configuration |
| `Listener/Registrar/SearchListenerRegistrar` | 22 | Processes |
| `Vectorization/HybridSearchRequest`, `SemanticSearchRequest` (values of `VectorSearchInterface`) | 24 | Files, Integrations |
| `Vectorization/Handlers/ChunkVectorSourceInterface` (implemented by Files' chunk) | 12 | Files |
| `SearchRegistrar`, `SearchProviderRegistrar` | 20 | Platform |
| `EmbeddingAdministrationInterface` | 10 | Configuration |
| `VectorSearchInterface` | 9 | Files, Integrations |
| `VectorIndexInterface` | 9 | Files |
| `SavedViewsInterface` (+ `SavedView` 11, named only inside the port's signature) | 7 | Integrations, Processes |
| `AggregationResultsInterface` | 7 | Processes |
| `ReadableFilesInterface` | 7 | Files |
| `ExampleSet/ExampleSetViewRowsInterface` (kept until Configuration's row collection exists, D09) | 7 | Configuration |
| `AggregationCacheEvictionInterface` | 6 | Processes |
| `SearchScopesInterface` | 6 | Platform |
| **Total** | **168** (16 files) | |

Before: 2,270 lines in 55 files. After: 168 lines in 16 files.

Search's share of the target. Kernel takes about 9,700 lines, which leaves about 9,200 for the other
nine areas at 4.0 and about 2,900 at 4.5. Search is 15,078 of the 448,859 non-Kernel lines (3.4%).
Its proportional share is therefore about 310 lines at 4.0 and about 100 at 4.5.
- **4.0:** 168 lines fits.
- **4.5:** 168 lines is about 70 over the proportional share. Three cuts would bring it to about 110:
  - `testEmbedding` takes the probe's provider and config (`EmbeddingProbe` stays internal): −22.
  - The two request values become one `VectorQuery` value: −12.
  - `ExampleSetViewRowsInterface` leaves once D09's row collection exists: −7.
  - These are named here and not designed further, because each changes a signature the 4.0 step does not need.

Effects on other areas, from the same simulation:
- **Core:** exposed lines go from 64,730 to 63,305. `AggregationRunner` (2,378) and `GraphQLResolver` (278) are no longer exposed. The four Core ports cost 29 lines.
- **Compliance:** 16,414 → 16,279.
- **Files:** 3,741 → 3,638 (`FileReadScope` is no longer named across areas).

**Flagged: this only fits if a Core rule holds.** After A4 and A5, Search's GraphQL resolver and
aggregation engine name 15 Core files that no other area names today: `GetObject` 178, `RelationHandler`
338, `DbalObjectSourceProvider` 636, `AggregateVisibility` 95, `DraftListLens` 67, `PreservedListLens` 60,
`RelationLookup` 49 and eight small ones, 1,526 lines in all. The references point down, so they are
allowed, but `modularity_check.py --check` refuses newly exposed files. A4 and A5 therefore wait for
D02's Core contract set: object reads through the Kernel `ObjectOperationsInterface`, a relation lookup
port, aggregate visibility and the list lenses. The simulated totals above count those 1,526 lines as
exposed, so they hold even without D02 doing better. Nothing here breaks a rule. The flag is about
ordering, not about a forbidden move.

The alternative was rejected. It keeps aggregation and GraphQL in Core and gives Core ports for the
Search pieces they use (`AggregationQuery`, slot, cross-schema target, cache, timeseries validator,
error formatter). That leaves about 217 Search lines and the 2,378-line `AggregationRunner` exposed. It
also needs a Core port that carries `AggregationQuery`, which other apps use by name.

## 5. The build packages

Dependency order. Within a step, packages are independent and touch disjoint files, with one exception:
`lib/Search/SearchWiring.php::BINDINGS` is the single Search binding map. Packages S2, S3 and S5 each add
their own lines to it, so those three are stacked in that order, never in parallel. Every package also
regenerates `sigrid.components.yaml` and `tools/sig/deptrac/areas.yaml` when it touches
`scripts/component-map.csv`. Those three generated or shared files are listed once here and not per
package.

**S1: rows for trail, alert delivery, alert sweep, vector facade, diagnostics (A1, A2, A3, A6, A7).**
Files:
- `scripts/component-map.csv` (+ generated)
- the tests that pin areas, if any name these files (`tests/Unit/Architecture`)

There are no code changes. It removes, in Search: 625 + 26 lines of exposure, 6 references against the
order out of Search, and the Core → Search references from `VectorSearchFacade`, `VectorResultFilter`,
`HybridSearchDiagnostics` and `ViewAlertSweepJob`. `LogCleanUpTask` → `SearchTrailCleanupInterface`
keeps its existing against-order entry (now Core → Compliance); P01 owns it.

**S2: placeholders and property profile count to Core (A8, A9), stacked first on `SearchWiring`.**
Files:
- `scripts/component-map.csv`
- `lib/Search/SearchWiring.php` (drop two bindings)
- `lib/Service/ServiceRegistrar.php` (add two bindings)
- the binding tests of both registrars

The rows and the bindings move in the same PR. Otherwise `PlaceholderResolver` becomes newly exposed.

**S3: Core-owned ports Search implements (C1, C3, C4 declarations plus Search implementations).**
Files:
- new `lib/Kernel/Contracts/Core/StoredViewQueryInterface.php`, `CreateGuardInterface.php`, `ListingNarrowingInterface.php`
- `lib/Search/Service/ViewService.php`
- `lib/Search/Service/Quality/DedupCreatePolicyInterface.php`
- new `lib/Search/Service/Geo/GeoListingNarrowing.php`
- `lib/Search/SearchWiring.php`
- `scripts/component-map.csv` (A10 and the new Search file)
- unit tests for the three implementations

Nothing outside Search names the ports yet, so nothing is newly exposed.

**S4a: Core callers of the stored view query (C1).**
Files:
- `lib/Service/Object/ViewScopeApplier.php`
- `lib/Service/ObjectSource/ViewSourceMappers.php`, `ViewObjectSourceProvider.php`
- `lib/Service/Sharing/AccessLinkViewReaders.php`, `AccessLinkMintGuard.php`
- `lib/Service/Object/Collaborators/ObjectMapperServices.php`
- `lib/Service/ObjectService.php`
- their tests

It removes 9 Core → Search references to `ViewMapperInterface`. The access check in `AccessLinkMintGuard` keeps its flags (`asCaller: true`) and its position.

**S4b: duplicate guard in the save path (C3).**
Files:
- `lib/Service/Object/SaveObject.php`
- `tests/Unit/Service/Object/SaveObject*Test.php` (the dedup cases)

Same container id resolution, same order of the check.

**S4c: geo endpoints to Search, listing narrowing through C4.**
Files:
- new `lib/Search/Controller/GeoController.php`
- `appinfo/routes.php` (three route names; URL and verb unchanged)
- `lib/Controller/ObjectsController.php`
- `lib/Controller/Support/GeoServices.php`, `ObjectsExtensions.php`
- `scripts/component-map.csv` (new controller)
- tests: `GeoControllerTest` (new) and the ObjectsController geo and index tests, retargeted

**S4d: user migration sections (C2).** It needs D03 for the watches section.
Files:
- new `lib/Kernel/Contracts/Core/PersonalStateSectionInterface.php`
- new `lib/Search/Service/ViewPersonalState.php`
- `lib/Service/UserMigration/PersonalStateMigrator.php`, `PersonalStateReader.php`
- `lib/Search/SearchWiring.php` (stacked after S3)
- the Notifications section class (D03)
- `scripts/component-map.csv`
- tests, with a golden test of the export archive written first

**S4e: schema annotation checks through `SchemaDeclarationChecksEvent`.**
Files:
- `lib/Db/SchemaMapper/AdvisoryAnnotationChecks.php`, `RecordAnnotationRefusals.php`
- `lib/Service/Schemas/SchemaDeclarationValidators.php`
- new `lib/Search/Listener/SearchDeclarationChecksListener.php`
- Search's listener registration
- `tests/Unit/Listener/fixtures/listener-order.json`, `ListenerRegistrationOrderGoldenTest.php`
- the SchemaMapper check tests (hand-built `SchemaMapper` tests need `ProcessDeclarationChecksDispatcher`)

**S5: Search-owned ports and their implementations (3b, without the aggregation ports).**
Files:
- new `lib/Kernel/Contracts/Search/SavedViewsInterface.php`, `SavedView.php`, `VectorSearchInterface.php`, `EmbeddingAdministrationInterface.php`, `VectorIndexInterface.php`, `SearchScopesInterface.php`, `ReadableFilesInterface.php`
- `lib/Search/Service/ViewService.php` (stacked after S3)
- `lib/Search/Service/Vectorization/VectorEmbeddings.php`
- new `lib/Search/Service/Vectorization/ChunkVectorIndex.php`
- `lib/Search/SearchScopeCatalog.php`
- `lib/Search/SearchWiring.php`
- `scripts/component-map.csv`
- tests

This is about 15 files. If it runs over, the vector ports split into an S5b.

**S6a: higher callers of views, scopes and the provider.**
Files:
- `lib/Calendar/DeclaredDateCalendars.php`
- `lib/Service/Calendar/ObjectCalendarFeedService.php`, `ObjectCalendarFeedSources.php`
- `lib/Mcp/Db/Agent.php` (import)
- `lib/Capabilities/SearchScopesCapability.php`, `PlatformIntegrations.php`
- `lib/Search/SearchProviderRegistrar.php`
- tests

**S6b: higher callers of vector search and administration.**
Files:
- `lib/Controller/Settings/LlmSettingsController.php`, `lib/Controller/Support/LlmSettingsDomain.php`
- `lib/Files/Controller/FileSearchController.php`, `Support/FileSearchControllerServices.php`
- `lib/Mcp/Service/Chat/ContextRetrievalHandler.php`
- tests

**S6c: higher callers of the vector index.**
Files:
- `lib/Files/Controller/FileExtractionController.php`, `Support/FileExtractionDomain.php`
- `lib/Files/BackgroundJob/ChunkVectorizationBatch.php`, `ChunkVectorizationJob.php`
- tests

**S6d: Search's listeners through its own registrar.**
Files:
- new `lib/Search/Listener/Registrar/SearchListenerRegistrar.php`
- `lib/Listener/ListenerRegistrar.php`
- `scripts/component-map.csv`
- a test that the golden sequence hash is unchanged

**S6e: readable files from Files.**
Files:
- new `lib/Files/Service/File/ReadableFiles.php`
- `lib/Files/FilesRegistrar.php` (binding)
- `lib/Service/Search/VectorResultFilter.php`
- `lib/Search/Service/Quality/NearDuplicateScope.php`
- `scripts/component-map.csv`
- tests

**S7: aggregation and GraphQL count to Search (A4, A5).** It waits for D02's Core contracts for object
reads, relations, aggregate visibility and list lenses (section 4).
Files:
- `scripts/component-map.csv`
- `lib/Service/ContractBindings.php` (drop four aggregation bindings)
- `lib/Search/SearchWiring.php` (add them, stacked last)
- new `lib/Kernel/Contracts/Search/AggregationResultsInterface.php`, `AggregationCacheEvictionInterface.php`
- `lib/Service/Aggregation/AggregationRunner.php` (implements)
- `lib/Search/Service/Aggregation/AggregationCache.php` (implements)
- `lib/Service/Calculation/AggregateReferenceResolver.php`
- `lib/Listener/AggregationCacheInvalidationListener.php`
- tests

This removes the remaining Core → Search references (GraphQL, aggregation) and unexposes `AggregationRunner`.

Not in a Search package:
- The job handle waits for P46.
- `ExampleSetViewRowsInterface` waits for D09's row collection.
- Search's own references up to Compliance, Files, Integrations, Processes and Configuration (33 after this design: dashboard audit reads, `FlowTasksProvider`, report writers, `View` → `Configuration`/`Task`/`Agent`) belong to the existing P packages (P03, P07, P13, P15, P33, P34, P37, P39, P44) and the higher areas' designs.

## 6. Fleet impact

Measured with `tools/sig/fleet_checkout.sh` and `tools/sig/fleet_surface.py` on the development branches
of the 19 fleet apps (2026-11-19). The fleet names these classes of this design:

| Class | Apps | This design |
|---|---|---|
| `Service\Aggregation\AggregationRunner` | buildiq, pipelinq, shillinq | Counts to Search (A5). Name, namespace and methods unchanged, and it gains one port. |
| `Search\Service\Aggregation\AggregationQuery` | buildiq, pipelinq, shillinq | Unchanged. Kept out of the internal port on purpose. |
| `Service\Search\VectorSearchFacade` | hermiq | Counts to Search (A6). Unchanged. |
| `Search\Service\ViewService` | filinq (`SavedViewReader`) | Unchanged. It gains `SavedViewsInterface` and `StoredViewQueryInterface`. |
| `Search\Service\Quality\DuplicateDetectionService`, `DedupRun` | dossiq | Untouched. |
| `Db\MagicMapper\MagicSearchHandler` (Core) | dossiq | Untouched. It names `PropertySearchProfile`, which only changes area (A9). |

Nothing other apps use is renamed, moved or removed. Candidates for promotion to `lib/Contract`
(ADR-119 Decision 14) are named here, not done:
- `AggregationResultsInterface` with a published query value, so the three aggregation apps stop naming `AggregationRunner` and `AggregationQuery`.
- `SavedViewsInterface` with `SavedView`, for filinq.
- `VectorSearchInterface`, which is what hermiq's facade use amounts to.

No event other apps read changes in this design.

## Notes for the other designs

- **D01 (Kernel):** `lib/Kernel/Contracts/PropertySearchProfileInterface`, `EmbeddingGeneratorInterface`, `KeywordChunkSearch`, `ChunkMapperInterface`, `ViewInterface` and `ViewAlertInterface` describe Search capabilities but count to Kernel. Moving them under `Kernel/Contracts/Search/` would move their lines from Kernel's budget (over target at 10,282) to Search's. That is D01's call, so this budget does not include it.
- **D02 (Core):** C1 to C4 are Core-owned ports (29 lines). A4 and A5 need Core's read contracts first.
- **D03 (Notifications):** C2 needs a watches section.
- **D05 (Compliance):** A1 makes the search trail wholly Compliance's. `Configuration\ContractBindings` binding `SearchTrailServiceInterface` should move to Compliance's registrar.
