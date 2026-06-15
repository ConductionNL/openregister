# settings-management Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-b-svc-settings-mgmt. Update Purpose after archive.
## Requirements
### Requirement: Per-domain settings MUST follow a sliced typed get/update pattern persisted as JSON in IAppConfig
Each configuration domain MUST expose a typed retrieval method
(`getXSettingsOnly()` form) and a typed update method
(`updateXSettingsOnly(array $data)` form). The retrieval method MUST read a
single JSON-encoded blob from `IAppConfig` under the `openregister` app and the
domain's key, MUST return a complete defaults structure when the stored value is
empty or unset, and MUST backfill any individually missing keys with their
defaults for backward compatibility. The update method MUST apply PATCH
semantics — merging the incoming partial data over the currently stored values
so that omitted keys retain their existing value — and MUST persist the merged
result back as a JSON string. The domains covered by this pattern are: LLM,
File, Object, Retention, Archival, SOLR, search backend, RBAC, Multitenancy,
Organisation, n8n, and Publishing.

#### Scenario: Retrieve an unconfigured domain returns defaults
- **GIVEN** the `llm` app-config key is empty or unset
- **WHEN** `getLLMSettingsOnly()` is called
- **THEN** a complete default structure MUST be returned (e.g. `enabled` is
  `false`, provider sub-configs present with their default URLs/models, and
  `vectorConfig` defaulting to `backend: 'php'`, `solrField: '_embedding_'`)
- **AND** no value MUST be written to `IAppConfig` as a side effect of reading

#### Scenario: Update applies PATCH-merge over stored values
- **GIVEN** a domain already has stored settings in `IAppConfig`
- **WHEN** `updateXSettingsOnly($data)` is called with only a subset of keys
- **THEN** the supplied keys MUST overwrite their stored counterparts
- **AND** keys absent from `$data` MUST retain their previously stored values
- **AND** the merged result MUST be JSON-encoded and written back under the
  domain's app-config key
- **AND** the merged settings MUST be returned to the caller

#### Scenario: All enumerated domains expose the get/update pair
- **GIVEN** the settings facade
- **THEN** each of LLM, File, Object, Retention, Archival, SOLR, search backend,
  RBAC, Multitenancy, Organisation, n8n, and Publishing MUST be reachable through
  its typed getter and (where mutable) its typed updater

### Requirement: SettingsService MUST act as a thin facade delegating each domain to a specialized handler
`SettingsService` MUST be the single public entry point for settings persistence
and MUST delegate each domain to a dedicated `Service\Settings\*` handler
(LLM, File, Object/Retention, Cache, SOLR, Configuration, search backend,
validation). Handlers MAY be lazy-loaded through the app container to break
circular dependencies during dependency-injection bootstrap. The facade MUST NOT
embed domain business logic of the testing/using kind (connection testing,
embedding generation, chat execution, searching) — those belong to the
respective business-logic services.

#### Scenario: Facade delegates a domain call to its handler
- **GIVEN** a configured `SettingsService` with its handlers injected
- **WHEN** `updateSolrSettingsOnly($data)` is called
- **THEN** the call MUST be forwarded to `SolrSettingsHandler::updateSolrSettingsOnly`
- **AND** the handler's result MUST be returned unchanged

#### Scenario: Bootstrap-critical reads avoid uninitialized handlers
- **GIVEN** the search-backend configuration is needed during DI bootstrap
- **WHEN** `getSearchBackendConfig()` is called before its handler is fully wired
- **THEN** the facade MUST read the value directly from `IAppConfig` and return a
  safe default (`active: 'solr'`, `available: ['solr','elasticsearch']`) on
  empty or error rather than dereferencing an uninitialized handler

### Requirement: The capability MUST orchestrate cache statistics, clearing, and warmup
The facade MUST expose cache management delegated to `CacheSettingsHandler`:
gathering cache statistics, clearing a named cache type or all caches, and
warming the names cache. Clearing MUST accept an optional cache type and MUST
default to clearing all caches when none is supplied.

#### Scenario: Clear cache with no type clears everything
- **WHEN** `clearCache()` is called with no argument
- **THEN** the handler MUST be invoked with cache type `'all'`
- **AND** a result array describing the clear operation MUST be returned

#### Scenario: Cache statistics are aggregated into dashboard stats
- **WHEN** `getStats()` is called
- **THEN** the returned array MUST include a `cache` section sourced from
  `getCacheStats()`
- **AND** a failure to gather cache stats MUST be captured as an `error` entry
  rather than aborting the whole stats response

### Requirement: Mass validation MUST orchestrate batch jobs over all objects with serial or parallel processing
Mass validation MUST validate the `mode` parameter (only `serial` or `parallel`)
and the `batchSize` parameter (between 1 and 5000), MUST partition the total
object count (optionally capped by `maxObjects`) into evenly-sized batch jobs,
and MUST re-save each object through `ObjectService::saveObject` to re-trigger
business logic. In serial mode batches are processed sequentially; in parallel
mode they are processed in fixed-size chunks. The `collectErrors` flag MUST
control whether processing collects all errors or short-circuits on the first
failure. The operation MUST return aggregate statistics including processed,
successful, and failed counts, duration, throughput, and memory usage.

#### Scenario: Invalid mode is rejected
- **WHEN** `massValidateObjects(mode: 'turbo')` is called
- **THEN** an `InvalidArgumentException` MUST be thrown before any object is
  processed

#### Scenario: collectErrors short-circuit behavior
- **GIVEN** an object fails to re-save during serial processing
- **WHEN** `collectErrors` is `false`
- **THEN** processing of the current batch MUST stop at the first failure

#### Scenario: collectErrors accumulates all failures
- **GIVEN** multiple objects fail to re-save during processing
- **WHEN** `collectErrors` is `true`
- **THEN** processing MUST continue past each failure
- **AND** every failure MUST be accumulated into the `errors` array

#### Scenario: Results carry aggregate statistics
- **WHEN** a mass-validation run completes
- **THEN** the result MUST include `stats` with `total_objects`,
  `processed_objects`, `successful_saves`, `failed_saves`, `duration_seconds`,
  `batches_processed`, and `objects_per_second`
- **AND** a `memory_usage` section with start/end/peak figures

### Requirement: The capability MUST expose environment introspection and configuration rebase
The facade MUST expose read-only environment introspection — application
version info, cached database information, and PostgreSQL extension presence —
and a `rebase` operation that resets or rebuilds selected configuration
components (SOLR configuration, cache). Introspection MUST degrade gracefully
when data is unavailable (e.g. return `null`/empty rather than throwing), and
`rebase` MUST report per-component outcomes and overall success.

#### Scenario: PostgreSQL extension check on a non-Postgres database
- **GIVEN** the cached database info reports a non-PostgreSQL engine or is absent
- **WHEN** `hasPostgresExtension('vector')` is called
- **THEN** it MUST return `false`
- **AND** `getPostgresExtensions()` MUST return an empty array

#### Scenario: Rebase a selected component
- **WHEN** `rebase(['components' => ['cache']])` is called
- **THEN** the cache MUST be cleared
- **AND** the result MUST report `success: true` with a `rebased.cache` entry
  and a `timestamp`

#### Scenario: Rebase failure is reported, not thrown
- **GIVEN** a rebase sub-operation raises an exception
- **WHEN** `rebase()` is called
- **THEN** the result MUST report `success: false` with an `error` of
  `'Rebase failed'` and the exception message rather than propagating the throw

