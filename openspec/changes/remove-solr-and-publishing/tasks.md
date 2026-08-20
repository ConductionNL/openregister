## 1. SOLR + Index abstraction — backend code

- [x] 1.1 Delete all SOLR PHP code: `lib/Service/Index/Backends/SolrBackend.php`, `lib/Service/Index/Backends/Solr/*`, `lib/Service/Settings/SolrSettingsHandler.php`, `lib/Service/Aggregation/SolrAggregationQueryBuilder.php`, `lib/EventListener/SolrEventListener.php`
- [x] 1.2 Delete the entire Index abstraction: `lib/Service/IndexService.php`, `lib/Service/Index/SearchBackendInterface.php`, `ElasticsearchBackend.php` + `Elasticsearch/*`, and the `Index/` handlers (Object/File/Schema/Bulk/DocumentBuilder/Warmup/Setup/Configuration)
- [x] 1.3 Delete Solr controllers (`SolrController`, `Settings/SolrSettingsController`, `SolrOperationsController`, `SolrManagementController`), jobs (`SolrWarmupJob`, `SolrNightlyWarmupJob`), and CLI commands (`SolrManagementCommand`, `SolrDebugCommand`)
- [x] 1.4 Rewire `AggregationRunner` (drop the external `SearchBackendInterface` branch + constructor arg; keep Postgres-native → PHP fallback) and route faceting to `MagicFacetHandler`/`MariaDbFacetHandler` only
- [x] 1.5 Remove `SearchBackendInterface`/`SolrBackend`/`ElasticsearchBackend` registration in `lib/AppInfo/Application.php`; remove `SolrNightlyWarmupJob` + commented Solr CLI from `appinfo/info.xml`; bump `info.xml` `<version>`
- [x] 1.6 Remove all `/api/solr/*`, `/api/settings/solr*`, `/api/search/semantic`, `/api/search/hybrid`, `/api/vectors/*`, `/api/objects/*/vectorize*` routes from `appinfo/routes.php` (no orphan methods — ADR-029)
- [x] 1.7 Remove the Solr KNN leg + `storeVectorInSolr` + backend-resolution indirection from `VectorEmbeddings`/`VectorSearchHandler`/`VectorStorageHandler` (keep PostgreSQL cosine path)
- [x] 1.8 Remove `elasticsearch/elasticsearch` from `composer.json`/`composer.lock` (keep guzzle); run `composer dump-autoload`

## 2. SOLR — frontend, resources, docs

- [x] 2.1 Delete `src/views/settings/sections/SolrConfiguration.vue` and all `src/modals/settings/Solr*`/Collection/ConfigSet/Facet/Inspect/Warmup/Vectorization/ClearCache modals tied to Solr; remove Solr state from `src/store/settings.js`
- [x] 2.2 Remove the orphaned Beheer → Observability/System Solr settings menu/section entry (ADR-001)
- [x] 2.3 Remove `resources/solr/*` configsets, the `solr` profile + zookeeper services from `docker-compose.yml`/`docker-compose.dev.yml`, Solr sections from `docker/QUICKSTART.md`, and all `solr-*` docs under `docs/`

## 3. Publishing — remove publish/published completely

- [x] 3.1 Remove `published`/`depublished` columns + getters/setters from `lib/Db/Register.php` and `lib/Db/Schema.php`; remove `$published` filter params from `RegisterMapper`/`SchemaMapper`; remove the published-bypass branch in `MultiTenancyTrait`
- [x] 3.2 Rewrite `RegistersController`/`SchemasController` `#[PublicPage]` index/show guards: remove `isPublishedEntity()` and derive anonymous visibility from existing RBAC `public`-group evaluation (`$now`/`publicatiedatum`) per `auth-system` delta
- [x] 3.3 Remove deprecated object-publish config-key handling (`objectPublishedField`/`objectDepublishedField` logging in `MetadataHydrationHandler`); **rename the file auto-share key `autoPublish`→`autoShare`** in `FilePropertyHandler` (property + schema-level), the schema-editor file-config UI, and any schema-register defaults — stop reading `autoPublish` entirely (log a deprecation warning naming `autoShare` when the legacy key is seen)
- [x] 3.4 Remove the publish/depublish buttons + `publish()`/`depublish()` methods and the "Auto-publish objects"/"Default automatically-publish attachments" toggles from `RegisterSchemaCard.vue` / schema editor; remove `settings#updatePublishingOptions` route + handler
- [x] 3.5 Write the operator migration documentation: re-express a previously-published register/schema as a `public`-group `read` RBAC rule (`$now`/`publicatiedatum`) so anonymous read does not break, and rename `autoPublish`→`autoShare` in any schema file-config to keep file auto-share

## 4. Migrations

- [x] 4.1 Idempotent migration(s): drop `indexed_in_solr` + `file_texts_solr_idx` from `oc_openregister_file_texts` (keep `vectorized`/embedding columns — confirm chunk-table state first), and drop `published`/`depublished` columns + their indexes from `oc_openregister_registers` and `oc_openregister_schemas`

## 5. Tests & verification

- [x] 5.1 Delete `tests/Unit/**/Solr*Test.php` and Elasticsearch tests; add/adjust tests proving DB search/facet/aggregate still work with no backend and that anonymous Register/Schema visibility is RBAC-driven
- [x] 5.2 Grep dependent apps (opencatalogi, softwarecatalog) for the removed routes and for `getPublished`/`published` on registers/schemas (ADR-022); flag any consumer before merge
- [x] 5.3 Run `composer check:strict` and the Hydra route-reachability gate; live-verify search + anonymous public register/schema access through the UI/API

## Acceptance criteria

- No `Solr`/`Elasticsearch`/`SearchBackendInterface`/`IndexService` references remain in `lib/`, `src/`, routes, jobs, CLI, composer, docker, or docs.
- Object/full-text search, faceting, and aggregation work through the DB path with no external backend, returning the standard `{ results, total, page, pages }` envelope.
- No `published`/`depublished` columns, getters, setters, filter params, `isPublishedEntity()`, or published-bypass branch remain.
- Anonymous Register/Schema visibility is decided by RBAC `public`-group evaluation; a previously-published resource is anonymously visible only after adding the documented RBAC rule.
- Nextcloud file-share publishing remains functional; file auto-share works via the renamed `autoShare` key and `autoPublish` is no longer read anywhere.
- All removed routes return HTTP 404 with no orphan controller methods (ADR-029).

## Quality checklist

- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes.
- `info.xml` `<version>` bumped (immutable JS cache-bust).
- Hydra route-reachability + route-auth gates green.
- BREAKING changes (removed routes + anonymous-access gating) documented in the proposal and migration guide.
