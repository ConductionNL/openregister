## ADDED Requirements

### Requirement: REQ-ABN-002 AggregationRunner MUST dispatch Postgres-native then PHP fallback

The runner MUST attempt backends in the order: Postgres-native fast path, then PHP fallback. There is no external (`SearchBackendInterface`, Solr or Elasticsearch) dispatch tier: the external backend abstraction is removed.

The Postgres-native fast path is tried first via
`tryNativeAggregation()`. When it returns `null` (unsupported query
shape / non-Postgres engine for non-date-bucket paths / table not
found), the runner MUST fall back to the PHP runner.

The PHP fallback MUST hydrate at most `PHP_FALLBACK_ROW_CAP = 10000`
rows. When the source table has more matching rows than the cap,
the response MUST carry `truncated: true`; the native path MUST set
`truncated: false` (full-set evaluation). Every response envelope
MUST carry a `backend` field with one of `"postgres"` /
`"php-fallback"`.

#### Scenario: Native path returns a result and attributes postgres

- **GIVEN** a `byStatus` aggregation whose filter shape the native path can translate
- **WHEN** the runner executes it
- **THEN** the response MUST carry `backend: "postgres"`
- **AND** `truncated` MUST be `false`
- **AND** no external search backend MUST be consulted

#### Scenario: PHP fallback over the row cap surfaces truncated

- **GIVEN** the native path returns `null` (e.g. a filter shape it can't
  translate) and the matching row set exceeds 10 000 rows
- **WHEN** the PHP fallback hydrates the row set
- **THEN** the runner MUST cap the hydrate at 10 000 rows
- **AND** the response MUST carry `truncated: true`
- **AND** the response MUST carry `backend: "php-fallback"`

#### Scenario: Backend attribution always populated

- **GIVEN** any aggregation request that reaches a backend
- **WHEN** the response is rendered
- **THEN** the JSON body MUST include a top-level `backend` field
- **AND** the value MUST be one of `"postgres"` or `"php-fallback"`
- **AND** a fall-through path (the native path returns `null` and the
  PHP runner answers) MUST attribute the actual backend that produced
  the result, not the originally-targeted one

## REMOVED Requirements

### Requirement: REQ-ABN-002 AggregationRunner MUST dispatch external then Postgres-native then PHP fallback with non-fatal external errors
**Reason**: The external dispatch tier (`SearchBackendInterface`, Solr, Elasticsearch) is removed, so the external-throws scenario and the `solr`/`elasticsearch`/`external` attribution values no longer apply.
**Migration**: Replaced by "REQ-ABN-002 AggregationRunner MUST dispatch Postgres-native then PHP fallback", which keeps the row cap, `truncated` flag and backend attribution with `postgres` and `php-fallback` as the only values.
