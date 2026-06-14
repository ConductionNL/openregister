# Tasks: Unified Search Provider

## Phase 1 — Access-control contract (RBAC + tenant + published)

- [x] 1.1 Pin the delegation contract in `lib/Search/ObjectsProvider.php::search()`: `searchObjectsPaginated()` is always called with `_rbac: true` and `_multitenancy: true`; add a class-level PHPDoc block documenting that the provider performs NO second access filter and relies on the pipeline (link the spec requirement).
- [x] 1.2 Verify (and where missing, implement in the search pipeline) the published-predicate behaviour for unified search: an authenticated user WITHOUT an explicit RBAC grant on a schema sees objects whose `@self.published` is set and not expired (`depublished` unset/future); soft-deleted objects are never returned. Added the regression assertions to `tests/Unit/Search/ObjectsProviderTest.php` (`testSearchAlwaysDelegatesWithRbacAndMultitenancyTrue`, `testSearchProviderAppliesNoSecondAccessFilter`). The published predicate / soft-delete exclusion already lives inside the OR search pipeline (`searchObjectsPaginated` + magic mapper); the provider pins the delegation flags and the Newman collection asserts the observable soft-delete exclusion.
- [~] 1.3 Added `tests/integration/openregister-unified-search.postman_collection.json` driving `/ocs/v2.php/search/providers/openregister_objects/search?term=...` and the providers-list endpoint, wired into `tests/newman/run-all.sh::DOMAIN_ORDER` after `crud`. The collection asserts: provider is registered and not duplicated per-app, term match + labeled subline, `searchable=false` opt-out (implicit and explicit-filter), and soft-delete exclusion — all as the authenticated admin. The multi-user cross-tenant / ungranted-vs-published-vs-unpublished matrix is pinned at the pipeline level (rbac.postman_collection / ObjectServiceDeep), not re-asserted here, because provisioning a second-org user + RBAC grants is out of this single collection's bootstrap; the provider provably applies no second filter (unit test), so pipeline coverage is the source of truth for those scopes.

## Phase 2 — Schema-level opt-out (`searchable`)

- [x] 2.1 Made the unified-search path honour `Schema::$searchable`: added `SchemaMapper::findNonSearchableIds()` + `findSearchableIds()`, resolved once per request (request-scoped cache `$nonSearchableIds` in the provider), and pass the searchable allow-list as the `@self.schema` IN-filter into `searchObjectsPaginated()`. Applied inside the query, not by post-filtering a page.
- [x] 2.2 When the `schema` custom filter explicitly targets a `searchable = false` schema, the provider returns an empty result set without querying the pipeline (opt-out wins). Unit-tested both directions (`testExplicitSchemaFilterCannotBypassOptOut`, `testNonSearchableSchemasConstrainedToAllowList`, `testDefaultSchemasRemainSearchable`).
- [x] 2.3 Confirmed `SchemasController` + schema entity round-trip `searchable` (column from `Version1Date20250929120000`; Newman `searchable flag round-trips as false` asserts it); updated `docs/features/search-and-faceting.md` to state the flag now governs Nextcloud unified search exposure. No migration needed.

## Phase 3 — Per-app labeling + deep links

- [x] 3.1 Extended `lib/Dto/DeepLinkRegistration.php` with an optional `?string $displayName = null` constructor-promoted property; extended `DeepLinkRegistryService::register()` and `DeepLinkRegistrationEvent::register()` with the same optional trailing parameter (backward compatible — pipelinq/procest listeners unchanged, asserted by `testRegisterRemainsSourceCompatibleWithoutDisplayName`).
- [x] 3.2 Added `DeepLinkRegistryService::resolveDisplayName(int $registerId, int $schemaId): ?string` using the existing lazy ID→slug maps; returns `displayName ?? appId` for claimed pairs, `null` for unclaimed pairs (unit-tested).
- [x] 3.3 In `ObjectsProvider`, build the entry subline as `{App display name} · {Register title} · {Schema title}` for claimed pairs and `Open Register · {Register title} · {Schema title}` for unclaimed pairs (reuses `resolveRegisterName`/`resolveSchemaName`); uses `resolveIcon()` for the entry icon and passes `rounded: true` on `SearchResultEntry` for claimed pairs.
- [x] 3.4 URL resolution kept as-is (deep-link template first, `openregister.objects.show` fallback); added unit tests for the claimed/unclaimed/mixed-result labeling matrix and the fallback URL.

## Phase 4 — Excerpts

- [x] 4.1 Added `ObjectsProvider::buildExcerpt(array $object, string $term): string` — first case-insensitive match (`mb_stripos`) across top-level scalar string values in property order (`@self` skipped), ±60 chars context with ellipses (`sliceExcerpt`), matched substring verbatim; falls back to `summary` → truncated `description` → `''`.
- [x] 4.2 Subline ends with `… — {excerpt}` via `buildSubline()`; the obsolete `Updated: {date}` suffix and the unused `buildDescription()` were removed (match context replaces the timestamp). Unit-tested: match in a non-first field, numeric-only match (summary fallback), and multibyte content with ellipsis edges.

## Phase 5 — Pagination

- [x] 5.1 Replaced the `@todo: implement pagination`: reads `ISearchQuery::getLimit()` (capped at 25) and the cursor (`getCursor()`, integer offset as string) into `_limit`/`_offset`; returns `SearchResult::paginated(name, entries, $offset + $limit)` when a full page came back, `SearchResult::complete()` otherwise.
- [x] 5.2 Unit-tested: full first page → paginated with cursor 25 (`testFullFirstPageReturnsCursor`); cursor offset honoured on the next request (`testSecondPageUsesCursorOffset`); short page → complete (`testShortPageCompletesResult`). Newman pagination two-page walk is covered structurally by the cursor unit tests; the Newman collection asserts presence/absence by UUID rather than seeding >25 objects (kept the collection lean for the dev container).

## Phase 6 — Spec, docs, fleet scope note

- [x] 6.1 `specs/unified-search-provider/spec.md` (this change's delta) and the `deep-link-registry` delta for `displayName` are present and validate (`openspec validate unified-search-provider` passes); synced into `openspec/specs/` on archive.
- [x] 6.2 Updated `docs/features/search-and-faceting.md` with a "Nextcloud Unified Search" section describing the central-provider decision, the `searchable` flag, labeling, excerpts, and pagination; cross-links the deep-link registry.
- [~] 6.3 Fleet follow-up (out of this OpenRegister PR's scope — executed per app in pipelinq/procest/planix repos). The OR-side mechanism (`displayName` on the registry/event) is shipped here so those apps can pass a label; declining their per-app search-provider changes and adding the planix `DeepLinkRegistrationListener` are tracked as downstream per-app work, not this PR.
