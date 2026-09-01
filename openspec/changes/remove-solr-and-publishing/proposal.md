---
kind: code
---

## Why

OpenRegister carries two deprecated subsystems that no longer earn their maintenance cost. The SOLR/Elasticsearch **search Index abstraction** is unused in every live deployment — search, faceting, aggregation, and vector storage all default to and fully work on the built-in Magic-Tables (SQL/PostgreSQL) path, so the entire external-backend layer (~30 PHP classes, controllers, jobs, docker services, configsets, docs) is dead weight with its own attack surface and ops burden. Separately, the `deprecate-published-metadata` change removed object-level `@self.published` but explicitly left the Register/Schema `published`/`depublished` **entity columns** and a scatter of leftover publish toggles, dead UI buttons, and a deprecated settings route in place. Both subsystems can now be removed and the remaining responsibilities expressed through the mechanisms that already replaced them: the DB search path and RBAC `$now`/`publicatiedatum` rules.

## What Changes

### SOLR + the entire search Index abstraction
- **BREAKING**: Remove all SOLR code (`SolrBackend`, `Solr/*` clients, `SolrSettingsHandler`, `SolrAggregationQueryBuilder`, Solr controllers, `SolrEventListener`, Solr warmup jobs, Solr CLI commands) **and** the entire Index abstraction it was built on (`IndexService`, `SearchBackendInterface`, `ElasticsearchBackend` + `Elasticsearch/*`, and the `Index/` handlers: Object/File/Schema/Bulk/DocumentBuilder/Warmup/Setup/Configuration).
- **BREAKING**: Remove all `/api/solr/*`, `/api/settings/solr*`, `/api/search/semantic`, `/api/search/hybrid`, `/api/vectors/*`, and `/api/objects/*/vectorize*` routes and their controller methods (ADR-029: no orphan methods).
- Remove `SolrConfiguration.vue` + all `Solr*`/Collection/ConfigSet/Facet/Inspect/Warmup/Vectorization/ClearCache modals tied to Solr, and Solr state from `src/store/settings.js`. Clean up any orphaned Beheer → Observability/System menu entry (ADR-001).
- Remove `elasticsearch/elasticsearch` from composer (keep guzzle — used elsewhere).
- DB migration: drop `indexed_in_solr` column + `file_texts_solr_idx` index from `oc_openregister_file_texts` (idempotent).
- Remove `resources/solr/*`, the `solr` profile + zookeeper services from compose files, Solr sections of `docker/QUICKSTART.md`, all `solr-*` docs, and the corresponding Solr/Elasticsearch unit tests.
- **Fallback**: object/full-text search, faceting, and aggregation continue exclusively through the Magic-Tables DB path (`MagicSearchHandler`, `MagicFacetHandler`, `AggregationRunner` Postgres-native + PHP fallback). No user-visible regression.

### Publishing — remove publish/published completely (incl. RBAC entity columns)
- Remove leftover deprecated publish config-key handling: `autoPublish`/`objectPublishedField`/`objectDepublishedField` deprecation logging in `MetadataHydrationHandler`; the schema-editor "Auto-publish objects" / "Default automatically-publish attachments" toggles.
- **BREAKING**: Remove the `published`/`depublished` columns + getters/setters from `Register`/`Schema`, the `$published` filter params from `RegisterMapper`/`SchemaMapper`, `isPublishedEntity()` gating in `RegistersController`/`SchemasController`, and the published-bypass branch in `MultiTenancyTrait`. Migration drops these columns + their indexes (idempotent).
- **Replacement**: anonymous/public visibility of Registers and Schemas is expressed entirely through the existing RBAC `$now` + group `public` + `publicatiedatum` mechanism (the same pattern `deprecate-published-metadata` established for objects). The `#[PublicPage]` index/show guards re-derive anonymous visibility from RBAC rules, not columns.
- Remove the dead publish/depublish buttons + `publish()`/`depublish()` methods in `RegisterSchemaCard.vue` (they call non-existent store actions).
- Remove the `settings#updatePublishingOptions` route + `PUT /api/settings/publishing-options` (it only configured deprecated publishing).
- **Keep (file-share publishing)**: Nextcloud file-share publishing (`FilePublishingHandler`, `FileService::publishFile/unpublishFile`, `FileMapper` share_stime) stays — file sharing is a separate concern from object/register/schema publishing.
- **BREAKING (config rename)**: The file auto-share toggle is renamed from the ambiguous `autoPublish` to the clearly file-scoped `autoShare`. `autoPublish` is removed entirely and is no longer read — not even as a file-share fallback. Schemas that used `autoPublish` to auto-share uploaded files MUST migrate to `autoShare` (documented manual step). This is distinct from removing object-publishing.

## Capabilities

### New Capabilities
<!-- none — this change only removes and modifies -->

### Modified Capabilities
- `search-index`: **REMOVED** — the entire SOLR/Elasticsearch/Index-abstraction capability is deleted; all its requirements are removed.
- `auth-system`: the Register/Schema anonymous-visibility requirement (currently gated on `published`/`depublished` entity columns) is re-expressed against RBAC `$now`/`publicatiedatum`/group `public` rules.
- `deprecate-published-metadata`: extended to also remove the Register/Schema `published`/`depublished` entity columns (previously explicitly out of scope) and to define the column → RBAC migration path.
- `zoeken-filteren`: full-text/filter search requirements drop the Solr/Elasticsearch backend branch; the PostgreSQL Magic-Tables path becomes the sole search backend.
- `faceting-configuration`: faceting requirements drop the `SolrFacetProcessor` external-backend branch; `MagicFacetHandler` (SQL) becomes the sole faceting path.
- `aggregations-backend-native`: the dispatch requirement drops the external-backend tier; aggregation runs Postgres-native then PHP fallback only.
- `vector-embeddings`: vector query/storage requirements drop the Solr backend branch; PostgreSQL (`openregister_vectors`/chunks) becomes the sole vector store and the removed semantic/hybrid HTTP surface is dropped.

## Impact

- **BREAKING API surface removed**: all Solr/vector/semantic/hybrid routes; `PUT /api/settings/publishing-options`. Consumers calling these get 404.
- **BREAKING anonymous-access change** (security-sensitive, ADR-005): instances that relied on the Register/Schema `published` columns for anonymous metadata visibility MUST migrate to RBAC `public`-group rules; design.md spells out the migration path so anonymous read does not silently break.
- **BREAKING config rename**: `autoPublish` → `autoShare` for file auto-share. Schemas/properties whose configuration relied on `autoPublish` to auto-create a Nextcloud share on upload stop auto-sharing until migrated to `autoShare`.
- **Dependent apps** (ADR-022): opencatalogi / softwarecatalog consume OR search and may read Register/Schema visibility or set `autoPublish` on schemas — flag for downstream verification (no OR consumer should depend on the removed columns or the old `autoPublish` key; confirm during apply).
- Code: ~30 SOLR/Index PHP classes, 4+ controllers, 2 jobs, 2 CLI commands, ~10 Vue files, `src/store/settings.js`, `Register`/`Schema` entities + mappers + controllers + `MultiTenancyTrait`, two DB migrations, composer dependency, docker compose + configsets, docs, and unit tests.
