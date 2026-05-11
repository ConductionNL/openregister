# Tasks — Aggregations Annotation

- [ ] 1.1 Add `x-openregister-aggregations` schema-save validation to `SchemaService` — every filter/field/groupBy field exists, every operator known, every placeholder known, every metric in v1 vocabulary, no two aggregations share a name.
- [ ] 1.2 Create `lib/Service/Search/PlaceholderResolver.php` — resolves `$now`, `$startOfDay`/`$startOfWeek`/`$startOfMonth`/`$startOfYear`, `$currentUser`, with offset arithmetic (`$now-7d`, `$startOfMonth-1`). Extract this as a shared service so the parallel calculations-annotation change can reuse it.
- [ ] 1.3 Create `lib/Service/Aggregation/AggregationCompiler.php` — translates an aggregation spec + the existing filter compiler's output into a backend-specific aggregate query.
- [ ] 1.4 Add `aggregate(string $metric, ?string $field, array $query, ?array $groupBy): array` to `SearchBackendInterface`. Implement in `PostgresSearchBackend` (using `COUNT/SUM/AVG/MIN/MAX/COUNT(DISTINCT)` + `GROUP BY` / `date_trunc(<bucket>, field)`), `SolrSearchBackend` (facets + stats), `ElasticsearchBackend` (`aggs` clauses with `terms` / `date_histogram`).
- [ ] 1.5 Create `lib/Controller/AggregationController.php` — `aggregate(string $name): JSONResponse`. Loads schema, looks up aggregations[name] (404 if missing), resolves placeholders, dispatches to backend, formats response. `#[NoAdminRequired]`.
- [ ] 1.6 Register route `GET /api/objects/aggregations/{name}` in `appinfo/routes.php`.
- [ ] 1.7 Extend the existing search endpoint to accept `_aggregate=name1,name2` — return the paginated `results` AND `aggregations: { name: <value-or-groups> }` in one response.
- [ ] 1.8 Cache layer — reuse the existing `findObjects` cache; key = `(register, schema, name, resolved-placeholders-hash, rbac-scope-hash)`, TTL 60s. Headers `X-OR-Cache: hit|miss`.
- [ ] 1.9 Create `lib/EventListener/AggregationInvalidationListener.php` — subscribes to `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` / `ObjectTransitionedEvent`; evicts cache for the affected `(register, schema)`.
- [ ] 1.10 Unit tests: validator (every rule, pass + fail), placeholder resolver (every placeholder + offset), compiler (every backend, every metric, groupBy + bucket).
- [ ] 1.11 Integration test: declare aggregations on a test schema; create / update / delete objects; verify counts respond correctly; verify cache invalidation fires.
- [ ] 1.12 Doc: `docs/annotations/x-openregister-aggregations.md` + a worked example mirroring decidesk's ActionItem aggregations.
- [ ] 1.13 Update `openspec/platform-capabilities.md` with the `x-openregister-aggregations` row.
