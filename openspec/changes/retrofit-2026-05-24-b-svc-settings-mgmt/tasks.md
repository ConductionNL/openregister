# Tasks: Reverse-spec settings-management capability

Reverse-spec retrofit. Tasks document observed behavior of shipped code; the
work item is the `@spec` annotation linking each method group to its
requirement. Repetitive per-domain get/update pairs are grouped under one task.

## Task 1 — Sliced per-domain settings get/update pattern

- [ ] Document that every per-domain settings endpoint is a typed
- [x] Document that every per-domain settings endpoint is a typed
      `getXSettingsOnly()` / `updateXSettingsOnly(array $data)` pair that reads a
      single JSON blob from `IAppConfig`, returns hard-coded defaults when the
      key is empty/unset, PATCH-merges incoming data over stored values, and
      writes the merged result back as JSON. Domains observed: LLM, File,
      Object, Retention, Archival, SOLR, search-backend, RBAC, Multitenancy,
      Organisation, n8n, Publishing.
- [ ] Annotate the facade delegation pairs in `lib/Service/SettingsService.php`:
- [x] Annotate the facade delegation pairs in `lib/Service/SettingsService.php`:
      `getLLMSettingsOnly`/`updateLLMSettingsOnly`,
      `getFileSettingsOnly`/`updateFileSettingsOnly`,
      `getObjectSettingsOnly`/`updateObjectSettingsOnly`,
      `getRetentionSettingsOnly`/`updateRetentionSettingsOnly`,
      `getArchivalSettingsOnly`/`updateArchivalSettingsOnly`,
      `getSolrSettings`/`getSolrSettingsOnly`/`updateSolrSettingsOnly`,
      `getSearchBackendConfig`/`updateSearchBackendConfig`,
      `getRbacSettingsOnly`/`updateRbacSettingsOnly`,
      `getMultitenancySettingsOnly`/`updateMultitenancySettingsOnly`,
      `getOrganisationSettingsOnly`/`updateOrganisationSettingsOnly`,
      `getSettings`/`updateSettings`, `updatePublishingOptions`.
- [ ] Annotate the canonical handler implementation of the pattern in
- [x] Annotate the canonical handler implementation of the pattern in
      `lib/Service/Settings/LlmSettingsHandler.php`
      (`getLLMSettingsOnly`/`updateLLMSettingsOnly`).

## Task 2 — Facade-and-handler delegation architecture

- [ ] Document that `SettingsService` is a thin facade that delegates each domain
      to a specialized `Service\Settings\*` handler injected via constructor
      (lazy-loaded to break circular dependencies), and is the single
      persistence/orchestration layer beneath the controller-side settings admin.
- [ ] Annotate the delegation accessors and convenience getters in
      `lib/Service/SettingsService.php`: `isMultiTenancyEnabled`,
      `getDefaultOrganisationUuid`/`setDefaultOrganisationUuid`, `getTenantId`,
      `getOrganisationId`.
- [ ] Annotate the multi-method handler implementations in
- [x] Document that `SettingsService` is a thin facade that delegates each domain
      to a specialized `Service\Settings\*` handler injected via constructor
      (lazy-loaded to break circular dependencies), and is the single
      persistence/orchestration layer beneath the controller-side settings admin.
- [x] Annotate the delegation accessors and convenience getters in
      `lib/Service/SettingsService.php`: `isMultiTenancyEnabled`,
      `getDefaultOrganisationUuid`/`setDefaultOrganisationUuid`, `getTenantId`,
      `getOrganisationId`.
- [x] Annotate the multi-method handler implementations in
      `lib/Service/Settings/ConfigurationSettingsHandler.php` and
      `lib/Service/Settings/SolrSettingsHandler.php` that back the facade
      delegation.

## Task 3 — Cache statistics, clear, and warmup orchestration

- [ ] Document the cache management surface: statistics, typed clear
      (`all` or a named cache type), and names-cache warmup, all delegated to
      `CacheSettingsHandler`.
- [ ] Annotate `getCacheStats`, `clearCache`, `warmupNamesCache` in
- [x] Document the cache management surface: statistics, typed clear
      (`all` or a named cache type), and names-cache warmup, all delegated to
      `CacheSettingsHandler`.
- [x] Annotate `getCacheStats`, `clearCache`, `warmupNamesCache` in
      `lib/Service/SettingsService.php` and their implementations in
      `lib/Service/Settings/CacheSettingsHandler.php`.

## Task 4 — Mass-validation batch-job orchestration

- [ ] Document that mass-validation creates evenly-sized batch jobs over the
- [x] Document that mass-validation creates evenly-sized batch jobs over the
      total object count, processes them serially or in parallel chunks
      (re-saving each object via `ObjectService::saveObject` to re-trigger
      business logic), validates `mode` and `batchSize` parameters, collects or
      short-circuits on errors per the `collectErrors` flag, and returns
      aggregate stats (processed/successful/failed, duration, throughput,
      memory).
- [ ] Annotate `validateAllObjects`, `massValidateObjects`, `createBatchJobs`,
- [x] Annotate `validateAllObjects`, `massValidateObjects`, `createBatchJobs`,
      `processJobsSerial`, `processJobsParallel`, `processBatchDirectly` in
      `lib/Service/SettingsService.php` and `validateAllObjects` in
      `lib/Service/Settings/ValidationOperationsHandler.php`.

## Task 5 — Environment introspection, stats, and rebase

- [ ] Document version/database/PostgreSQL-extension introspection
- [x] Document version/database/PostgreSQL-extension introspection
      (`getVersionInfoOnly`, `getDatabaseInfo`, `hasPostgresExtension`,
      `getPostgresExtensions`), dashboard statistics aggregation (`getStats`,
      `getSolrDashboardStats`), and the `rebase` configuration operation that
      resets/rebuilds selected components (SOLR config, cache).
- [ ] Annotate `getVersionInfoOnly`, `getDatabaseInfo`, `hasPostgresExtension`,
- [x] Annotate `getVersionInfoOnly`, `getDatabaseInfo`, `hasPostgresExtension`,
      `getPostgresExtensions`, `getStats`, `getSolrDashboardStats`,
      `getSolrFacetConfiguration`, `updateSolrFacetConfiguration`, `rebase` in
      `lib/Service/SettingsService.php`.
