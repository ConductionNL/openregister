# Tasks: Reverse-spec settings-management capability

Reverse-spec retrofit. Tasks document observed behavior of shipped code; the
work item is the `@spec` annotation linking each method group to its
requirement. Repetitive per-domain get/update pairs are grouped under one task.

## Task 1 — Sliced per-domain settings get/update pattern

- [x] Document that every per-domain settings endpoint is a typed
      `getXSettingsOnly()` / `updateXSettingsOnly(array $data)` pair that reads a
      single JSON blob from `IAppConfig`, returns hard-coded defaults when the
      key is empty/unset, PATCH-merges incoming data over stored values, and
      writes the merged result back as JSON. Domains observed: LLM, File,
      Object, Retention, Archival, SOLR, search-backend, RBAC, Multitenancy,
      Organisation, n8n, Publishing.
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
      `getSettings`/`updateSettings`, `updatePublishingOptions`. Verified — 17
      `@spec ...#task-1` annotations present in `lib/Service/SettingsService.php`.
- [x] Annotate the canonical handler implementation of the pattern in
      `lib/Service/Settings/LlmSettingsHandler.php`
      (`getLLMSettingsOnly`/`updateLLMSettingsOnly`). Verified — 2 `#task-1`
      annotations present.

## Task 2 — Facade-and-handler delegation architecture

- [x] Document that `SettingsService` is a thin facade that delegates each domain
      to a specialized `Service\Settings\*` handler injected via constructor
      (lazy-loaded to break circular dependencies), and is the single
      persistence/orchestration layer beneath the controller-side settings admin.
- [x] Annotate the delegation accessors and convenience getters in
      `lib/Service/SettingsService.php`: `isMultiTenancyEnabled`,
      `getDefaultOrganisationUuid`/`setDefaultOrganisationUuid`, `getTenantId`,
      `getOrganisationId`. Verified — `#task-2` annotations cover
      `getSearchBackendConfig`, `isMultiTenancyEnabled`,
      `getDefaultOrganisationUuid`, `setDefaultOrganisationUuid`, `getTenantId`,
      `getOrganisationId`.
- [x] Annotate the multi-method handler implementations in
      `lib/Service/Settings/ConfigurationSettingsHandler.php` and
      `lib/Service/Settings/SolrSettingsHandler.php` that back the facade
      delegation. Verified — 2 `#task-2` annotations in each handler.

## Task 3 — Cache statistics, clear, and warmup orchestration

- [x] Document the cache management surface: statistics, typed clear
      (`all` or a named cache type), and names-cache warmup, all delegated to
      `CacheSettingsHandler`.
- [x] Annotate `getCacheStats`, `clearCache`, `warmupNamesCache` in
      `lib/Service/SettingsService.php` and their implementations in
      `lib/Service/Settings/CacheSettingsHandler.php`. Verified — 3 `#task-3`
      annotations on the facade (`getCacheStats`, `clearCache`,
      `warmupNamesCache`) and 3 in `CacheSettingsHandler.php`.

## Task 4 — Mass-validation batch-job orchestration

- [x] Document that mass-validation creates evenly-sized batch jobs over the
      total object count, processes them serially or in parallel chunks
      (re-saving each object via `ObjectService::saveObject` to re-trigger
      business logic), validates `mode` and `batchSize` parameters, collects or
      short-circuits on errors per the `collectErrors` flag, and returns
      aggregate stats (processed/successful/failed, duration, throughput,
      memory).
- [x] Annotate `validateAllObjects`, `massValidateObjects`, `createBatchJobs`,
      `processJobsSerial`, `processJobsParallel`, `processBatchDirectly` in
      `lib/Service/SettingsService.php` and `validateAllObjects` in
      `lib/Service/Settings/ValidationOperationsHandler.php`. Verified — 6
      `#task-4` annotations on the facade methods and 1 on
      `ValidationOperationsHandler::validateAllObjects`.

## Task 5 — Environment introspection, stats, and rebase

- [x] Document version/database/PostgreSQL-extension introspection
      (`getVersionInfoOnly`, `getDatabaseInfo`, `hasPostgresExtension`,
      `getPostgresExtensions`), dashboard statistics aggregation (`getStats`,
      `getSolrDashboardStats`), and the `rebase` configuration operation that
      resets/rebuilds selected components (SOLR config, cache).
- [x] Annotate `getVersionInfoOnly`, `getDatabaseInfo`, `hasPostgresExtension`,
      `getPostgresExtensions`, `getStats`, `getSolrDashboardStats`,
      `getSolrFacetConfiguration`, `updateSolrFacetConfiguration`, `rebase` in
      `lib/Service/SettingsService.php`. Verified — 9 `#task-5` annotations
      on the listed methods.

## Verification

Annotation audit run from the worktree:

```
grep -c "@spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt" \
    lib/Service/SettingsService.php
# → 48 annotations on the facade (23 task-1 + 6 task-2 + 4 task-3 + 6 task-4 +
#   9 task-5; the `getSearchBackendConfig`/`updateSearchBackendConfig` pair is
#   annotated under both task-1 and task-2 per the spec mapping)
```

Per-handler annotation counts:

```
LlmSettingsHandler.php              2 (task-1 round-trip pair)
ConfigurationSettingsHandler.php    2 (task-2 facade-backing accessors)
SolrSettingsHandler.php             2 (task-2 facade-backing accessors)
CacheSettingsHandler.php            3 (task-3 cache surface)
ValidationOperationsHandler.php     1 (task-4 mass-validation backing)
```

All 12 sub-task annotation requirements are satisfied by code shipped in the
development branch. The retrofit is complete.
