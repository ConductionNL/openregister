## MODIFIED Requirements

### Requirement: Fuzzy search with pg_trgm integration

The system MUST support optional fuzzy (typo-tolerant) search when the `_fuzzy=true` parameter is explicitly set AND the PostgreSQL `pg_trgm` extension is available. Fuzzy search MUST use the `similarity()` function on the `_name` column with a threshold of `0.1`. When fuzzy search is active, a `_relevance` score column MUST be available for sorting. Every magic table's `_name` column MUST additionally be backed by a `pg_trgm` GIN index (`USING GIN (_name gin_trgm_ops)`), created automatically at table-creation and sync time whenever the platform is PostgreSQL and the `pg_trgm` extension is available — this makes both the `_fuzzy=true` `similarity()` path and the always-on `ILIKE`-based `_name` matching in ordinary (non-fuzzy) full-text search index-backed rather than a sequential scan. The query logic (`MagicSearchHandler.buildSearchConditionSql()` / `applyFullTextSearch()`) is unchanged by the index's presence — only its execution plan improves.

#### Scenario: Fuzzy search enabled with pg_trgm
- **GIVEN** PostgreSQL database with `pg_trgm` extension installed
- **AND** an object with `_name = "Geluidsoverlast"`
- **WHEN** the user searches with `?_search=Geluidoverlast&_fuzzy=true` (missing 's')
- **THEN** the system MUST add `similarity(_name::text, 'Geluidoverlast') > 0.1` to the OR conditions
- **AND** the object MUST appear in results despite the typo

#### Scenario: Relevance score in results
- **GIVEN** fuzzy search is enabled
- **WHEN** search results are returned
- **THEN** each result MUST include a `_relevance` field computed as `ROUND(similarity(_name::text, searchTerm) * 100)::integer`
- **AND** results MUST be sortable by `_relevance DESC` via `?_order={"_relevance":"DESC"}`

#### Scenario: Fuzzy search disabled by default
- **GIVEN** a search request without `_fuzzy=true`
- **WHEN** `MagicSearchHandler.isFuzzySearchEnabled()` is called
- **THEN** it MUST return `false` regardless of pg_trgm availability
- **AND** only ILIKE-based search MUST be performed (approximately 13% faster than fuzzy)

#### Scenario: Fuzzy search gracefully degrades without pg_trgm
- **GIVEN** a MariaDB or PostgreSQL database WITHOUT `pg_trgm` extension
- **WHEN** the user searches with `?_search=test&_fuzzy=true`
- **THEN** `hasPgTrgmExtension()` MUST return `false` (cached for request lifetime)
- **AND** the search MUST fall back to ILIKE-only matching without error

#### Scenario: `_name` search is index-backed on PostgreSQL with pg_trgm
- **GIVEN** a magic table with 80,000+ rows, PostgreSQL, and `pg_trgm` installed
- **WHEN** `?_search=<rare term>` is executed against `_name`
- **THEN** the query plan MUST use the `{table}_name_trgm_idx` GIN index (not a sequential scan)
- **AND** this MUST hold whether or not `_fuzzy=true` is set, since the index accelerates both the plain `ILIKE` and the `similarity()` conditions on `_name`

#### Scenario: Index absent gracefully degrades to unindexed matching
- **GIVEN** PostgreSQL without `pg_trgm`, or MariaDB/SQLite
- **WHEN** a magic table is created or synced
- **THEN** no `_name` trgm index MUST be created (no error, informative log only)
- **AND** `_search`/`_fuzzy=true` queries MUST continue to return correct results via the existing unindexed path — only the performance characteristic changes, not correctness

## ADDED Requirements

### Requirement: Schema properties MAY opt into indexed fuzzy/substring search via a `searchable` flag

The system MUST recognise a `searchable: true` boolean on schema string properties, structurally identical in shape to the existing `facetable` flag. When present, and when the platform is PostgreSQL with the `pg_trgm` extension available, the system MUST create a `pg_trgm` GIN index on that property's magic-table column (`CREATE INDEX IF NOT EXISTS {table}_{column}_trgm_idx ON {table} USING GIN ({column} gin_trgm_ops)`) at table-creation time, and MUST retrofit the index when an existing table's schema is synced to add the flag. Index creation MUST be tolerant of failure (index already exists, or the column's type is incompatible with `gin_trgm_ops`) — a failure MUST be logged and MUST NOT abort table creation/sync. On non-PostgreSQL platforms, or when `pg_trgm` is unavailable, the flag MUST be silently accepted (no index, no error) — schema definitions remain portable across database platforms.

#### Scenario: Property marked searchable gets a trgm index at table creation
- **GIVEN** a schema property `title` (type `string`) has `"searchable": true`
- **AND** the platform is PostgreSQL with `pg_trgm` installed
- **WHEN** `MagicMapper::createTableForRegisterSchema()` runs for that schema
- **THEN** a GIN index `{table}_title_trgm_idx` MUST be created on the `title` column using `gin_trgm_ops`

#### Scenario: Existing table retrofits the index when the flag is added later
- **GIVEN** a magic table already exists for a schema without `searchable` on `title`
- **AND** the schema is updated to add `"searchable": true` to `title`
- **WHEN** `MagicTableHandler::syncTableForRegisterSchema()` runs
- **THEN** the `{table}_title_trgm_idx` GIN index MUST be created on the existing table (mirroring how a newly-added `facetable` property gets its btree index retrofitted)

#### Scenario: searchable on a non-string property degrades without failing the sync
- **GIVEN** a schema property `amount` (type `number`) is incorrectly marked `"searchable": true`
- **WHEN** the table-creation/sync index-creation loop reaches that property
- **THEN** the `CREATE INDEX ... USING GIN (amount gin_trgm_ops)` attempt MUST be wrapped in a try/catch
- **AND** a failure MUST be logged as a warning, not thrown
- **AND** the rest of the table creation/sync MUST complete successfully

#### Scenario: searchable flag is a portable no-op on MariaDB/SQLite
- **GIVEN** a schema property has `"searchable": true` and the active platform is MariaDB
- **WHEN** the magic table is created
- **THEN** no trgm index MUST be created (the `gin_trgm_ops` operator class doesn't exist on MariaDB)
- **AND** table creation MUST succeed without error
- **AND** `_search` over that property MUST continue to work via the existing `ILIKE`/`CAST` path already used on non-Postgres platforms
