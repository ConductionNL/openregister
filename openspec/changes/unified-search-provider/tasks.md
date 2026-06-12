# Tasks: Unified Search Provider

## Phase 1 — Access-control contract (RBAC + tenant + published)

- [ ] 1.1 Pin the delegation contract in `lib/Search/ObjectsProvider.php::search()`: `searchObjectsPaginated()` is always called with `_rbac: true` and `_multitenancy: true`; add a class-level PHPDoc block documenting that the provider performs NO second access filter and relies on the pipeline (link the spec requirement).
- [ ] 1.2 Verify (and where missing, implement in the search pipeline) the published-predicate behaviour for unified search: an authenticated user WITHOUT an explicit RBAC grant on a schema sees objects whose `@self.published` is set and not expired (`depublished` unset/future); soft-deleted objects are never returned. Add the regression assertions to `tests/Unit/Search/ObjectsProviderTest.php` with a mocked `ObjectService`, plus pipeline-level coverage where the predicate actually lives.
- [ ] 1.3 Add Newman requests to a new `tests/integration/openregister-unified-search.postman_collection.json` driving `/ocs/v2.php/search/providers/openregister_objects/search?term=...` as (a) an RBAC-granted user, (b) a user from another organisation (zero cross-tenant hits), (c) an ungranted user against a published object (hit) and an unpublished object (no hit). Wire the collection into `tests/newman/run-all.sh::DOMAIN_ORDER` after `crud`.

## Phase 2 — Schema-level opt-out (`searchable`)

- [ ] 2.1 Make the unified-search path honour `Schema::$searchable`: resolve the set of `searchable = false` schema IDs once per request (request-scoped cache in the provider or a `SchemaMapper::findNonSearchableIds()` helper) and pass it into `searchObjectsPaginated()` as a schema exclusion; verify the exclusion is applied inside the query (not post-filtering a page, which would leak counts/pagination gaps).
- [ ] 2.2 When the `schema` custom filter explicitly targets a `searchable = false` schema, return an empty result set (opt-out wins over explicit filter). Unit-test both directions.
- [ ] 2.3 Confirm `SchemasController` + the schema edit modal already round-trip `searchable` (column from `Version1Date20250929120000`); update the schema docs (`docs/`) to state the flag now governs Nextcloud unified search exposure. No migration needed.

## Phase 3 — Per-app labeling + deep links

- [ ] 3.1 Extend `lib/Dto/DeepLinkRegistration.php` with an optional `?string $displayName = null` constructor parameter + getter; extend `DeepLinkRegistryService::register()` and `DeepLinkRegistrationEvent::register()` with the same optional trailing parameter (backward compatible — pipelinq/procest listeners keep working unchanged).
- [ ] 3.2 Add `DeepLinkRegistryService::resolveDisplayName(int $registerId, int $schemaId): ?string` using the existing lazy ID→slug maps; returns `displayName ?? appId` for claimed pairs, `null` for unclaimed pairs.
- [ ] 3.3 In `ObjectsProvider`, build the entry subline as `{App display name} · {Register title} · {Schema title}` for claimed pairs and `Open Register · {Register title} · {Schema title}` for unclaimed pairs (reuse the existing `resolveRegisterName`/`resolveSchemaName` caches); keep using `resolveIcon()` for the entry icon and pass `rounded: true` on `SearchResultEntry` so app icons render as badges.
- [ ] 3.4 Keep URL resolution as-is (deep-link template first, `openregister.objects.show` fallback) and add unit tests for the claimed/unclaimed/mixed-result labeling matrix.

## Phase 4 — Excerpts

- [ ] 4.1 Add `ObjectsProvider::buildExcerpt(array $object, string $term): string` — first case-insensitive match of the term across top-level scalar string values (schema property order, `@self` skipped), ±60 chars context with ellipses, matched substring verbatim; fall back to `summary` → truncated `description` → `''`.
- [ ] 4.2 Append the excerpt to the subline (`… — {excerpt}`) and drop the current `Updated: {date}` suffix from `buildDescription()` (the match context is more useful than the timestamp; keep date in the fallback-only path). Unit-test: match in second field, match only in numeric field (fallback), no term (filter-only browse → summary fallback), multibyte content.

## Phase 5 — Pagination

- [ ] 5.1 Replace the `@todo: implement pagination`: read `ISearchQuery::getLimit()` (capped at 25) and the cursor (`getCursor()`, integer offset as string) into `_limit`/`_offset`; return `SearchResult::paginated(name, entries, $offset + $limit)` when a full page came back, `SearchResult::complete()` otherwise.
- [ ] 5.2 Unit-test: first page full → paginated with cursor 25; second page short → complete; empty result → complete. Newman: two-page walk over a fixture register with >25 objects asserting no duplicate/missing UUIDs across pages.

## Phase 6 — Spec, docs, fleet scope note

- [ ] 6.1 Write `specs/unified-search-provider/spec.md` (this change's delta) and the `deep-link-registry` delta for `displayName`; on archive, sync into `openspec/specs/`.
- [ ] 6.2 Update `docs/` (search documentation page) describing the central-provider decision, the `searchable` flag, labeling, and pagination; cross-link `deep-link-registry`.
- [ ] 6.3 Fleet follow-up (tracked here, executed per app): close/decline the suggested per-app search-provider changes in **pipelinq**, **procest**, and **planix** referencing this change; verify pipelinq + procest deep-link listeners pass a `displayName`, and open a small planix change adding its (currently missing) `DeepLinkRegistrationListener` so planix objects get labeled deep links instead of its own provider.
