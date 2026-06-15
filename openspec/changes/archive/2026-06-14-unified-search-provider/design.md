# Design: Unified Search Provider

## Key decision — central, not per leaf app

Product-owner decision: Nextcloud unified search over register objects
is provided **once**, by OpenRegister, for the whole fleet. Leaf apps
do not implement `OCP\Search\IProvider`. Rationale:

- RBAC + multitenancy + published predicate live in OR's
  `searchObjectsPaginated()`; a per-app provider would have to
  re-derive the same filters against the same magic tables (drift =
  IDOR risk, see hydra-gate-no-admin-idor lineage).
- NC renders one collapsible section per provider; 15 fleet apps would
  produce 15 sections, most empty for any given query. One OR section
  with per-entry app labeling scales.
- Apps already have a declaration channel: the deep-link registry.
  Reusing it means a leaf app participates in unified search with the
  listener it (in pipelinq's and procest's case) already ships.

## Reuse analysis

- `lib/Search/ObjectsProvider.php` — existing `IFilteringProvider`
  registered via `Application::register()`
  (`$context->registerSearchProvider(ObjectsProvider::class)`); this
  change hardens it in place, no new provider class.
- `OCA\OpenRegister\Service\ObjectService::searchObjectsPaginated()` —
  the only query path the provider uses; called with
  `_rbac: true, _multitenancy: true` and (new) `_published: true`
  semantics pinned by spec. Supports `_limit`/`_offset` already —
  cursor pagination maps onto these.
- `OCA\OpenRegister\Service\DeepLinkRegistryService` +
  `OCA\OpenRegister\Dto\DeepLinkRegistration` +
  `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` — existing
  boot-time declaration mechanism (in-memory, slug-keyed, lazy
  ID→slug maps). Extended with `displayName`.
- `OCA\OpenRegister\Db\Schema::$searchable` — existing boolean column
  (migration `Version1Date20250929120000`, default `true`), already
  round-tripped by `SchemasController`. The provider path starts
  consuming it; no migration needed.
- `SchemaMapper` / `RegisterMapper` — existing name-resolution caching
  in the provider (`resolveSchemaName` / `resolveRegisterName`) is
  kept for subline labels.
- `OCP\Search\SearchResult::paginated()` / `SearchResultEntry`
  (`rounded: true` flag) — standard NC pagination + avatar-style
  icons; no custom UI.

## Provider behaviour shape

```text
search(IUser $user, ISearchQuery $query): SearchResult
  1. Build $searchQuery from term/since/until/register/schema filters
     (existing logic).
  2. Add searchable-schema constraint:
       exclude schemas where searchable = false
     (pushed into searchObjectsPaginated as a schema-ID exclusion list
      resolved once per request via SchemaMapper, cached).
  3. _limit  = min(SearchQuery::getLimit(), 25)
     _offset = (int) $query->getCursor()        // cursor = offset
  4. $results = objectService->searchObjectsPaginated(
       query: $searchQuery, _rbac: true, _multitenancy: true);
     // published predicate enforced inside the pipeline; spec pins
     // the observable contract, provider adds no second filter.
  5. Per result entry:
       url      = deepLinkRegistry->resolveUrl(...) ?? objects.show
       icon     = deepLinkRegistry->resolveIcon(...) ?? icon-openregister
       label    = deepLinkRegistry->resolveDisplayName(...) ?? 'Open Register'
       title    = name|title|uuid (existing)
       subline  = "{label} · {Register} · {Schema} — {excerpt}"
  6. return count == limit
       ? SearchResult::paginated(name, entries, $offset + $limit)
       : SearchResult::complete(name, entries);
```

## Excerpt strategy

`buildExcerpt(array $object, string $term): string`

1. Walk the rendered object's top-level scalar string values in schema
   property order (skip `@self`, skip non-strings).
2. First case-insensitive occurrence of `$term` wins; return ±60 chars
   around the match, ellipsised, with the matched substring left
   verbatim (NC's search UI does not support rich-text highlighting in
   sublines; "highlighting" = the matched term appears verbatim in the
   excerpt so the UI's own term-match styling can latch on).
3. No string match (numeric/relational hit): fall back to existing
   `summary` → truncated `description` → empty.

Excerpts are derived from the same rendered object the user is allowed
to read — never from raw rows — so row/field-level security
(`row-field-level-security`) keeps applying to excerpt content for
free.

## DeepLinkRegistration extension

```php
new DeepLinkRegistration(
    appId: 'pipelinq',
    registerSlug: 'pipelinq',
    schemaSlug: 'client',
    urlTemplate: '/apps/pipelinq/#/clients/{uuid}',
    icon: null,
    displayName: 'Pipelinq',   // NEW, optional, default null
);
```

- `DeepLinkRegistryService::resolveDisplayName(int $registerId, int
  $schemaId): ?string` — same lazy slug-map resolution as
  `resolveUrl()`/`resolveIcon()`; returns `displayName ?? appId` for a
  claimed pair, `null` for unclaimed.
- `DeepLinkRegistrationEvent::register()` gains the optional trailing
  `?string $displayName = null` parameter — existing listeners
  (pipelinq, procest) keep working unchanged.

## Risks / notes

- **Schema-exclusion list size**: one `SchemaMapper` query per search
  request (request-cached); acceptable — searches are interactive and
  the schemas table is small.
- **Published predicate is pipeline-owned**: the provider deliberately
  does NOT re-filter. The spec's wrong-user scenarios are the
  regression net; the unit tests assert the delegation flags, the
  Newman tests assert the observable behaviour through
  `/ocs/v2.php/search/providers/openregister_objects/search`.
- **Cursor format**: plain integer offset serialised as string —
  matches what NC core providers (files, contacts) do; no opaque
  cursor needed since results are offset-stable enough for interactive
  search.
