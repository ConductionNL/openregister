## MODIFIED Requirements

### Requirement: REQ-ABN-002 AggregationRunner MUST dispatch Postgres-native then PHP fallback
The runner MUST attempt backends in the order: Postgres-native fast path, then PHP fallback. There is no external (`SearchBackendInterface`/Solr/Elasticsearch) dispatch tier — the external backend abstraction is removed.

The Postgres-native fast path is tried first via `tryNativeAggregation()`. When it returns `null` (unsupported query shape / non-Postgres engine for non-date-bucket paths / table not found), the runner MUST fall back to the PHP runner.

The PHP fallback MUST hydrate at most `PHP_FALLBACK_ROW_CAP = 10000` rows. When the source table has more matching rows than the cap, the response MUST carry `truncated: true`; the native path MUST set `truncated: false` (full-set evaluation). Every response envelope MUST carry a `backend` field with one of `"postgres"` / `"php-fallback"`.

#### Scenario: Native path returns a result and attributes postgres
- **GIVEN** a `byStatus` aggregation whose filter shape the native path can translate
- **WHEN** the runner executes it
- **THEN** the response MUST carry `backend: "postgres"`
- **AND** `truncated` MUST be `false`

#### Scenario: PHP fallback over the row cap surfaces truncated
- **GIVEN** the native path returns `null` (e.g. a filter shape it can't translate) and the matching row set exceeds 10 000 rows
- **WHEN** the PHP fallback hydrates the row set
- **THEN** the runner MUST cap the hydrate at 10 000 rows
- **AND** the response MUST carry `truncated: true` and `backend: "php-fallback"`
