# Reverse-spec controller bundle — settings / observability

## Why

The OpenRegister settings and observability controllers were built ahead of their
specs. This reverse-spec change documents the *observed behavior* of five controller
sub-clusters and attaches each public endpoint to the capability that already owns its
domain. No code is changed; every requirement is `## ADDED Requirements` against an
existing spec (bias-to-extend), and every method carries an `@spec` annotation pointing
at the task that documents it.

Two of the five controllers are genuinely cross-cutting kitchen sinks. Their methods are
split per-method to the owning capability rather than lumped into one bucket — see the
mapping tables below.

## What changes

- **9 capabilities extended** with a total of **17 new requirements** documenting
  observed admin/observability/search controller behavior.
- **92 public controller methods annotated** with `@spec` task references.
- DI helpers (`getObjectService`, `getConfigurationService`), debug-only endpoints with
  no stable contract (`testSchemaMapping`, `debugTypeFiltering`), and the empty
  `VectorSettingsController` are treated as scanner noise and **dropped** (not annotated,
  no requirements).

## Cross-cutting controller — per-method assignment

### `SettingsController` (kitchen sink)

| Method | Capability | Rationale |
|---|---|---|
| `index`, `update`, `load`, `updatePublishingOptions` | production-observability | Aggregate settings read/write admin surface |
| `stats`, `getStatistics` | production-observability | Settings-dashboard warning/total statistics |
| `getDatabaseInfo`, `refreshDatabaseInfo` | production-observability | DB platform + vector-capability introspection |
| `getVersionInfo` | production-observability | App version reporting |
| `rebase` | retention-management | Recomputes object/log deletion times from retention settings |
| `testSetupHandler`, `reindexSpecificCollection`, `getSearchBackend`, `updateSearchBackend` | zoeken-filteren | Search-backend selection & index admin |
| `semanticSearch`, `hybridSearch` | chat-ai | Facade copies of the vector/hybrid search endpoints |
| `getObjectService`, `getConfigurationService` | — (dropped) | DI accessors, not HTTP endpoints |
| `testSchemaMapping`, `debugTypeFiltering` | — (dropped) | Debug endpoints, no stable contract |

### `ConfigurationSettingsController` (kitchen sink)

| Method | Capability | Rationale |
|---|---|---|
| `getRbacSettings`, `updateRbacSettings` | rbac-scopes | RBAC enablement/configuration dials |
| `getOrganisationSettings`, `updateOrganisationSettings`, `getMultitenancySettings`, `updateMultitenancySettings` | tenant-lifecycle | Organisation & multitenancy configuration |
| `getRetentionSettings`, `updateRetentionSettings` | retention-management | Retention policy configuration |
| `getArchivalSettings`, `updateArchivalSettings` | archival-destruction-workflow | Destruction-scheduling/selectielijst configuration |
| `getObjectSettings`, `updateObjectSettings`, `patchObjectSettings` | production-observability | Object-management admin dials |
| `getObjectCollectionFields`, `createMissingObjectFields` | zoeken-filteren | Solr object-collection field synchronization |

## Sub-cluster → capability summary

1. `settings-admin-cross-cutting` (`SettingsController`) → split across
   production-observability / retention-management / zoeken-filteren / chat-ai.
2. `settings-subsystem-admin` (`Settings/*`) → split across production-observability
   (cache/validation/security/object/n8n/apitoken admin) + rbac-scopes / tenant-lifecycle
   / retention-management / archival-destruction-workflow / zoeken-filteren
   (ConfigurationSettingsController sections).
3. `solr-search-admin` (`SolrController` + `Settings/Solr*`) → zoeken-filteren +
   faceting-configuration (facet config endpoints) + chat-ai (vectorize/embedding ops).
4. `observability-health-metrics-heartbeat` (`Health`/`Heartbeat`/`Metrics`) →
   production-observability. Metrics/Health endpoints already covered by existing REQs;
   only the heartbeat keep-alive endpoint is newly documented.
5. `name-cache-warmup` (`NamesController`) → schema-driven-read-coercion.

## Impact

- Specs: 9 capability specs gain `## ADDED Requirements` deltas.
- Code: annotation-only (`@spec` docblock tags); no behavior change.
- Risk: none — documentation of existing behavior.
