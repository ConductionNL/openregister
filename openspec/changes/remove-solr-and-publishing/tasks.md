# Tasks: remove-solr-and-publishing

> **State, measured 2026-10-30 against development.** Most of this change is
> built, but two ticked tasks did not match the code (1.6 and 3.3, now unticked
> and rewritten) and the spec deltas no longer matched the main specs.
>
> The deltas are rewritten against the current main specs. `openspec` 1.12
> refuses a MODIFIED block that drops a scenario, also after a RENAMED, so every
> requirement that loses a Solr or published-column scenario is written as
> REMOVED (old title, with the replacement named under Migration) plus ADDED
> (new title). Requirements that only change wording stay MODIFIED. The
> pgvector KNN and hybrid scenarios of `vector-embeddings` are not touched, so
> archive keeps them. The two live requirements of `search-index` (the
> extraction job and the extraction HTTP surface) move to `text-extraction`, and
> a tombstone requirement keeps `search-index` alive so archive can apply it.
> Do not archive until section 6 is done.

## 1. SOLR + Index abstraction — backend code

- [x] 1.1 Delete all SOLR PHP code: `lib/Service/Index/Backends/SolrBackend.php`, `lib/Service/Index/Backends/Solr/*`, `lib/Service/Settings/SolrSettingsHandler.php`, `lib/Service/Aggregation/SolrAggregationQueryBuilder.php`, `lib/EventListener/SolrEventListener.php`
- [x] 1.2 Delete the entire Index abstraction: `lib/Service/IndexService.php`, `lib/Service/Index/SearchBackendInterface.php`, `ElasticsearchBackend.php` + `Elasticsearch/*`, and the `Index/` handlers (Object/File/Schema/Bulk/DocumentBuilder/Warmup/Setup/Configuration)
- [x] 1.3 Delete Solr controllers (`SolrController`, `Settings/SolrSettingsController`, `SolrOperationsController`, `SolrManagementController`), jobs (`SolrWarmupJob`, `SolrNightlyWarmupJob`), and CLI commands (`SolrManagementCommand`, `SolrDebugCommand`)
- [x] 1.4 Rewire `AggregationRunner` (drop the external `SearchBackendInterface` branch + constructor arg; keep Postgres-native → PHP fallback) and route faceting to `MagicFacetHandler`/`MariaDbFacetHandler` only
- [x] 1.5 Remove `SearchBackendInterface`/`SolrBackend`/`ElasticsearchBackend` registration in `lib/AppInfo/Application.php`; remove `SolrNightlyWarmupJob` + commented Solr CLI from `appinfo/info.xml`; bump `info.xml` `<version>`
- [ ] 1.6 Remove the Solr-facing routes and keep the database vector routes the LLM settings page uses. Removed (verified gone 2026-10-30): `/api/solr/*`, `/api/settings/solr*`, `/api/search/semantic`, `/api/search/hybrid`. Still to do: see 6.1 to 6.4. Kept on purpose (specified in `vector-embeddings` and `zoeken-filteren`): `POST /api/vectors/test-embedding`, `GET /api/vectors/check-model-mismatch`, `DELETE /api/vectors/clear-all`, `GET|POST|PUT|PATCH /api/settings/objects/vectorize`, `POST /api/objects/vectorize/batch`, `GET /api/objects/vectorize/stats`, `POST /api/files/vectorize/batch`, `POST /api/search/files/semantic|hybrid`, `GET /api/settings/search-backend` (read-only report). No orphan methods (ADR-029)
- [x] 1.7 Remove the Solr KNN leg + `storeVectorInSolr` + backend-resolution indirection from `VectorEmbeddings`/`VectorSearchHandler`/`VectorStorageHandler` (keep PostgreSQL cosine path)
- [x] 1.8 Remove `elasticsearch/elasticsearch` from `composer.json`/`composer.lock` (keep guzzle); run `composer dump-autoload`

## 2. SOLR — frontend, resources, docs

- [x] 2.1 Delete `src/views/settings/sections/SolrConfiguration.vue` and all `src/modals/settings/Solr*`/Collection/ConfigSet/Facet/Inspect/Warmup/Vectorization/ClearCache modals tied to Solr; remove Solr state from `src/store/settings.js`
- [x] 2.2 Remove the orphaned Beheer → Observability/System Solr settings menu/section entry (ADR-001)
- [x] 2.3 Remove `resources/solr/*` configsets, the `solr` profile + zookeeper services from `docker-compose.yml`/`docker-compose.dev.yml`, Solr sections from `docker/QUICKSTART.md`, and all `solr-*` docs under `docs/`

## 3. Publishing — remove publish/published completely

- [x] 3.1 Remove `published`/`depublished` columns + getters/setters from `lib/Db/Register.php` and `lib/Db/Schema.php`; remove `$published` filter params from `RegisterMapper`/`SchemaMapper`; remove the published-bypass branch in `MultiTenancyTrait`
- [x] 3.2 Rewrite `RegistersController`/`SchemasController` `#[PublicPage]` index/show guards: remove `isPublishedEntity()` and derive anonymous visibility from existing RBAC `public`-group evaluation (`$now`/`publicatiedatum`) per `auth-system` delta
- [ ] 3.3 File auto-share key `autoPublish` renamed to `autoShare`: `FilePropertyHandler` acts on `autoShare` only and, when it sees a schema-level `autoPublish`, logs a deprecation warning naming `autoShare` and does not share (done, `lib/Service/Object/SaveObject/FilePropertyHandler.php` around line 495; this detection is the one intended read). `objectPublishedField`/`objectDepublishedField` handling is gone from `lib/` (done). Still to do: see 6.5 and 6.6
- [x] 3.4 Remove the publish/depublish buttons + `publish()`/`depublish()` methods and the "Auto-publish objects"/"Default automatically-publish attachments" toggles from `RegisterSchemaCard.vue` / schema editor; remove `settings#updatePublishingOptions` route + handler
- [x] 3.5 Write the operator migration documentation: re-express a previously-published register/schema as a `public`-group `read` RBAC rule (`$now`/`publicatiedatum`) so anonymous read does not break, and rename `autoPublish`→`autoShare` in any schema file-config to keep file auto-share

## 4. Migrations

- [x] 4.1 Idempotent migration(s): drop `indexed_in_solr` + `file_texts_solr_idx` from `oc_openregister_file_texts` (keep `vectorized`/embedding columns — confirm chunk-table state first), and drop `published`/`depublished` columns + their indexes from `oc_openregister_registers` and `oc_openregister_schemas`

## 5. Tests & verification

- [x] 5.1 Delete `tests/Unit/**/Solr*Test.php` and Elasticsearch tests; add/adjust tests proving DB search/facet/aggregate still work with no backend and that anonymous Register/Schema visibility is RBAC-driven
- [x] 5.2 Grep dependent apps (opencatalogi, softwarecatalog) for the removed routes and for `getPublished`/`published` on registers/schemas (ADR-022); flag any consumer before merge
- [x] 5.3 Run `composer check:strict` and the Hydra route-reachability gate; live-verify search + anonymous public register/schema access through the UI/API

## 6. Remaining work (measured 2026-10-30)

- [ ] 6.1 Restore `GET /api/vectors/stats`: add `Settings\LlmSettings#getVectorStats` to `appinfo/routes.php` and an admin-only `getVectorStats()` on `lib/Controller/Settings/LlmSettingsController.php` that returns `VectorizationService::getVectorStats()`. `src/store/settings.js` `getVectorStats()` (called from `src/views/settings/sections/LlmConfiguration.vue` `loadVectorStats()`) calls this path and today gets a 404 and shows zeroed stats. Unit test for the controller method
- [ ] 6.2 Remove `GET /api/settings/search/semantic` and `GET /api/settings/search/hybrid` from `appinfo/routes.php` and `SettingsController::semanticSearch()` / `SettingsController::hybridSearch()` from `lib/Controller/SettingsController.php` (no caller in `src/`; file search has its own routes). Keep `VectorizationService::hybridSearch()`, `VectorEmbeddings::hybridSearch()` and `VectorSearchHandler::hybridSearch()`: `FileSearchController::hybridSearch()` uses them
- [ ] 6.3 Remove `GET /api/objects/vectorize/count` from `appinfo/routes.php` and `ObjectsController::getObjectVectorizationCount()` from `lib/Controller/ObjectsController.php` (no caller; the modal uses `/api/objects/vectorize/stats`)
- [ ] 6.4 Remove `PUT`/`PATCH /api/settings/search-backend` from `appinfo/routes.php`, `SettingsController::updateSearchBackend()`, and `updateSearchBackendConfig()` from `lib/Service/SettingsService.php` and `lib/Service/Settings/SearchBackendHandler.php` (only `database` is accepted, so there is nothing to select). Keep `GET /api/settings/search-backend` as the read-only report
- [ ] 6.5 Drop `'autoPublish'` from the boolean-normalised configuration keys in `lib/Db/Schema.php` (`$boolFields`, around line 2885) so the legacy key is no longer normalised as a live setting; `FilePropertyHandler` still detects it for the warning
- [ ] 6.6 Remove the `autoPublishAttachments` / `autoPublishObjects` toggles from `src/components/shared/EXAMPLES.md`
- [ ] 6.7 Remove the Solr vector-backend option and the `solr-config` block from `src/modals/settings/LLMConfigModal.vue` (`selectedVectorBackend.id === 'solr'`, `solrField: '_embedding_'`); the database is the only vector store
- [ ] 6.8 Remove the default `solr` settings block from `lib/Service/Settings/ConfigurationSettingsHandler.php` (around line 344), the `solr` key from `lib/Service/ConfigurationDeployment/ConfigurationKeyRegistry.php`, and the `solr.username` / `solr.password` / `solr.zookeeperPassword` entries from `lib/Service/Audit/SecuritySettingRegistry.php`
- [ ] 6.9 Remove stale Solr comments: `lib/Service/ImportService.php` ("Schedule SOLR warmup job", two places), `lib/Controller/ObjectsController.php` ("magic tables or SOLR"), `lib/Service/ObjectService.php` ("since SOLR is now our index", four places), `lib/Service/Object/DeleteObject.php`, the `@todo hotfix for solr` lines in `lib/Db/RegisterMapper.php` and `lib/Db/SchemaMapper.php`, the "index chunks in SOLR" comment in `appinfo/routes.php`, and the SOLR example in `src/components/shared/README.md` (around line 165)
- [ ] 6.10 Delete the Solr docs `docs/development/solr-development.md`, `docs/development/solr-elasticsearch-legacy.md`, `docs/technical/solr-dashboard-management.md`, `docs/technical/solr-guzzle-migration.md`, `docs/technical/solr-published-only-indexing.md`, `docs/technical/solr-setup-configuration.md`, `docs/user-guide/solr-dashboard-user-guide.md`, `docs/user/solr-dashboard.md` (keep `docs/technical/removing-solr-and-publishing-migration.md`, task 3.5)
- [ ] 6.11 At archive: repoint the `@spec` anchors that name a requirement this change removes or renames: `lib/Service/Settings/SearchBackendHandler.php` (`#requirement-backend-agnostic-search-architecture`, three places, to `#requirement-database-backed-search-architecture`) and `lib/Controller/SearchController.php` (`#requirement-dutch-language-search-support-i18n`, two places, to `#requirement-dutch-language-search-must-match-case-insensitively-on-the-database-backend`)
- [ ] 6.12 At archive: rewrite the `## Purpose` of `openspec/specs/search-index/spec.md` (it still describes Solr) to say the capability only guards that no external search index exists, and drop its Solr `## Notes`
- [ ] 6.13 Run the diff check on the changed files, then `composer check:strict` once, and the Hydra route-reachability gate; confirm the LLM settings page shows real vector counts

## Acceptance criteria

- No `Solr`/`Elasticsearch`/`SearchBackendInterface`/`IndexService` references remain in `lib/`, `src/`, routes, jobs, CLI, composer, docker, or docs (except the migration guide and deprecation messages).
- Object/full-text search, faceting, and aggregation work through the DB path with no external backend, returning the standard `{ results, total, page, pages }` envelope.
- No `published`/`depublished` columns, getters, setters, filter params, `isPublishedEntity()`, or published-bypass branch remain.
- Anonymous Register/Schema visibility is decided by RBAC `public`-group evaluation; a previously-published resource is anonymously visible only after adding the documented RBAC rule.
- Nextcloud file-share publishing remains functional; file auto-share works via the renamed `autoShare` key and `autoPublish` is never acted on (it is only detected to log the deprecation warning).
- All removed routes return HTTP 404 with no orphan controller methods (ADR-029); the kept database vector routes answer, including `GET /api/vectors/stats`.

## Quality checklist

- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes.
- `info.xml` `<version>` bumped (immutable JS cache-bust).
- Hydra route-reachability + route-auth gates green.
- BREAKING changes (removed routes + anonymous-access gating) documented in the proposal and migration guide.
