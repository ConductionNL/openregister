## Context

OpenRegister carries two deprecated subsystems whose responsibilities have already been superseded:

1. **SOLR + the search Index abstraction.** Built around `SearchBackendInterface` (`SolrBackend`, `ElasticsearchBackend`) with `IndexService` as a facade plus a large `lib/Service/Index/` tree, Solr controllers, warmup jobs, CLI commands, docker `solr`/zookeeper services, and configsets. Research confirmed that every live search/facet/aggregate/vector path already defaults to and fully works on the database (Magic-Tables) path: `MagicSearchHandler` (full-text ILIKE/pg_trgm), `MagicFacetHandler`/`MariaDbFacetHandler` (SQL `GROUP BY`), `AggregationRunner` (Postgres-native → PHP fallback), and the PostgreSQL vector store. The external backend is non-fatal-optional today; removing it does not regress behaviour.

2. **Publishing.** The prior `deprecate-published-metadata` change removed object-level `@self.published` but explicitly scoped OUT the Register/Schema `published`/`depublished` **entity columns** (then used for a multi-tenancy bypass and an anonymous-visibility gate). Around them sit dead UI buttons (`RegisterSchemaCard.vue` calling non-existent store actions), leftover deprecated config-key logging, and a `PUT /api/settings/publishing-options` route that only configured deprecated publishing.

Constraints: ADR-001 (Solr config lived under Beheer → Observability/System — removal must not orphan menu entries); ADR-005 (the anonymous-access gating change is security-sensitive); ADR-022 (downstream apps consume OR abstractions); ADR-029 (every removed route must leave no orphan controller method); ADR-031 (declarative over imperative); ADR-032 (`kind:` honesty and mixed-change splitting).

## Goals / Non-Goals

**Goals:**
- Delete the entire SOLR + Index abstraction (code, routes, jobs, CLI, frontend, docker, configsets, docs, tests).
- Delete all remaining publishing surface, including the Register/Schema `published`/`depublished` entity columns.
- Re-express anonymous Register/Schema visibility through declarative RBAC `public`-group rules (`$now`/`publicatiedatum`), matching the object-level pattern `deprecate-published-metadata` established.
- Keep search/facet/aggregate/vector behaviour working unchanged via the DB path; keep Nextcloud file-share publishing intact and preserve the file auto-share behaviour under a renamed, file-scoped `autoShare` config key.
- Provide a documented migration path so anonymous read does not silently break, and a documented manual rename step for `autoPublish` → `autoShare`.

**Non-Goals:**
- Re-implementing semantic/hybrid search on a new external engine (out of scope; only the removed HTTP surface is dropped, in-process PostgreSQL vector search stays).
- Changing the `text-extraction`/`file-actions` capabilities (extraction and chunk persistence are unaffected; only the indexing-to-backend coupling is removed).
- An automated data migration of `autoPublish` config values (handled as a documented manual step, consistent with the published-column migration).
- Removing guzzle (used elsewhere) — only `elasticsearch/elasticsearch` is dropped.

## Decisions

### Declarative-vs-imperative decision (ADR-031)
The anonymous Register/Schema visibility replacement is **declarative**, not a new Service class. Visibility is decided by evaluating the entity's existing RBAC `authorization` rules (`x-openregister`-style `{"read": [{"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}}}]}`) through the **existing** `MagicRbacHandler`/`ConditionMatcher` `$now` resolver that `deprecate-published-metadata` already added. The `#[PublicPage]` `index`/`show` guards in `RegistersController`/`SchemasController` are rewired to ask the existing RBAC evaluation "may the `public` group read this?" instead of reading `published`/`depublished` columns. No new authorization service is introduced; the imperative `isPublishedEntity()` gate and the `MultiTenancyTrait` published-bypass branch are deleted.

### Search/facet/aggregate fallback (why removal is safe)
`AggregationRunner` already dispatches external → Postgres-native → PHP and treats a null/throwing external backend as a fall-through; faceting already has `MagicFacetHandler` as the default SQL path; full-text search already runs through `MagicSearchHandler` when no engine is configured. Removing the external tier means: delete the `if ($this->searchBackend !== null)` branch and the `?SearchBackendInterface $searchBackend = null` constructor arg, delete `SolrAggregationQueryBuilder`/`SolrFacetProcessor`, and drop the `SearchBackendInterface` DI registration in `Application.php`. The Postgres-native and PHP paths remain fully wired. **Alternative considered:** keep `SearchBackendInterface` as a one-implementation seam for a future engine — rejected because it leaves dead abstraction and an empty registration, violating the "remove, don't gold-plate" goal.

### Vector storage stays on PostgreSQL
The vector store already defaults to `'php'` (database) and the Solr branch was opt-in. We delete `storeVectorInSolr`/the Solr KNN leg and the backend-resolution indirection, keeping `storeVectorInDatabase`/`VectorSearchHandler` cosine search. The `/api/search/semantic`, `/api/search/hybrid`, `/api/vectors/*`, and `/api/objects/*/vectorize*` HTTP routes are removed (BREAKING). In-process semantic search via `VectorEmbeddings::semanticSearch` stays.

### `file_texts` column drop scope
Drop `indexed_in_solr` + `file_texts_solr_idx` (Solr-only). Research found that text extraction has largely moved to a chunks table and `vectorized` is backend-agnostic (used by the PostgreSQL vector pipeline, not exclusively Solr). **Decision:** drop only the Solr-specific column/index; keep `vectorized`/embedding columns. (Recorded as a deferred question because the exact chunk-table migration state should be confirmed at apply time.)

### File auto-share key rename: `autoPublish` → `autoShare`
The word "publish" on `autoPublish` conflated two unrelated concerns: object/register/schema publishing (being removed) and Nextcloud file sharing (staying). To keep the file-sharing behaviour while removing every "publish" surface cleanly, the config key is renamed to the file-scoped `autoShare`. `FilePropertyHandler` reads `autoShare` (property-level, falling back to schema-level `autoShare`) and passes it as the `share` argument to `FileService::addFile`; `autoPublish` is no longer read at all. **Legacy values are NOT auto-migrated**: a schema still carrying `autoPublish` simply stops auto-sharing and logs a deprecation warning naming the new key — consistent with the manual-migration choice for the published columns (operators rename the key by hand, guided by docs). **Alternative considered:** silently treat `autoPublish` as a fallback for `autoShare` — rejected because it keeps a "publish"-named key alive, the exact confusion this rename removes, and contradicts removing all publish surface. **Alternative considered:** an automated data migration rewriting `autoPublish`→`autoShare` in schema configuration JSON — rejected for parity with the manual published-column migration and to avoid mutating operator-authored schema config implicitly.

### Mixed-change evaluation (ADR-032)
The centre of mass is **code deletion** (PHP/Vue/routes/migrations) → `kind: code`. The RBAC replacement is not a new declarative artifact shipped in `lib/Settings/{app}_register.json`; it is a guard rewrite plus operator-facing migration documentation. So this is not a `config` + `code` envelope in the ADR-032 sense and `kind: code` is honest. However, the change is large (two subsystems). Whether to split SOLR-removal and publishing-removal into two chained changes is a genuine judgment call → recorded under Open Questions / DEFERRED_QUESTIONS. Proceeding as one change keeps the `published`-column removal and its RBAC replacement atomic with the search removal, which share no code and could equally be two PRs.

### Seed data (ADR-001)
No new schemas are introduced — this change only removes and modifies. **No new schemas / no seed data.**

## Risks / Trade-offs

- **[Anonymous read silently breaks for instances relying on the `published` column]** → The migration (drop columns) is paired with operator documentation: any register/schema previously published MUST get a `public`-group `read` RBAC rule. The `deprecate-published-metadata` delta makes this a normative requirement with a runnable example. Mitigation also: ship the migration guide before/with the column drop.
- **[Downstream app coupling (ADR-022)]** → opencatalogi/softwarecatalog may read Register/Schema visibility or call removed routes. Mitigation: grep dependent repos for `getPublished`/`published` on registers/schemas and for the removed routes during apply; flag any consumer before merging the BREAKING removal.
- **[Orphaned controller methods / unrouted methods (ADR-029)]** → Removing routes without removing methods (or vice-versa) fails the route-reachability gate. Mitigation: remove route + method together; run `hydra-gate-route-reachability`.
- **[Orphaned Beheer menu entry (ADR-001)]** → The Solr settings nav item must be removed from the settings UI when `SolrConfiguration.vue` goes. Mitigation: explicit task to clean the menu/section.
- **[Cache-bust]** → Frontend bundle removal needs an `info.xml` `<version>` bump (custom_apps JS is immutable-cached). Mitigation: explicit task.
- **[Lost relevance ranking]** → Solr's BM25/TF-IDF ranking is gone; DB ILIKE has no relevance scoring. Accepted trade-off — no live instance used Solr, so this is theoretical.

## Migration Plan

1. Ship/confirm the RBAC migration documentation (column → `public`-group rule with `$now`/`publicatiedatum`).
2. Deploy the idempotent DB migration: drop `published`/`depublished` columns + indexes on registers/schemas, and drop `indexed_in_solr` + `file_texts_solr_idx`.
3. Operators of instances that relied on the published columns add the `public`-group `read` RBAC rule to the affected registers/schemas (manual, documented).
4. Operators rename any schema/property `autoPublish: true` file-config to `autoShare: true` to keep auto-sharing uploaded files (manual, documented; legacy `autoPublish` stops auto-sharing and logs a deprecation warning until renamed).
5. Remove docker `solr`/zookeeper services + configsets — no data migration needed (the index was a derived copy of the DB).
6. **Rollback**: revert the code; the DB migration is destructive (dropped columns), so rollback after step 2 requires restoring columns from backup. Keep the column-drop migration as the last deploy step so code can be reverted independently before columns are dropped. The `autoPublish`→`autoShare` rename is config-only (no DB migration) and is reverted by reverting the code.

## Open Questions

- Should SOLR-removal and publishing-removal be two chained `kind: code` changes per ADR-032 sizing? (See DEFERRED_QUESTIONS.)
- Is `vectorized`/embedding solely used by removed paths in the current HEAD chunk-table state? (Confirm at apply; provisionally kept.)
- Do existing instances need an automated data migration (not just docs) to convert published-column state into RBAC rules? (Decision: documented manual step.)
