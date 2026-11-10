# Retrofit — service bundle: top-level service facades (chunk 2)

Reverse-specs a chunk of uncovered top-level `lib/Service/*.php` service
facades flagged by the OpenRegister backend coverage scan. The batch
(`/tmp/or-scan/bw2-svc-flat-2.json`) lists 112 uncovered public methods
across 22 service files. Code already exists in production — this change
retroactively anchors the genuinely facade-owned behavior to spec and
tags every boilerplate facade delegation with `@spec exclude` per
ADR-003.

## Why

The scanner flagged 112 `missing @spec` methods. Most are facade
plumbing — constructors, one-line delegations to already-specced
handlers, trivial getters/setters, and degraded-path mapper wrappers —
which ADR-003 covers with `@spec exclude <reason>`. A minority encode
genuinely novel behavior that no existing requirement captures. Those
either map onto an existing owning capability (annotated in-place
against that capability's spec) or, where no capability owns them, are
captured here as new requirements (≤5 total).

## What Changes

Five new requirements, each in its owning capability:

- **activity-provider** — the Tier-2 filtered + cursor-paginated read
  surface over NC Activity entries linked to an OR object
  (`ActivityFilterService::getActivityEntries`). The existing IFilter/
  IProvider REQs cover the activity-stream UI, not this REST data read.
- **generic-integrations** — the Tier-2 entity link-service contract
  shared by the xWiki / Talk / OpenProject / Bookmark / Collective link
  services: link-existing, create-and-link, unlink-without-remote-delete,
  list-with-stale-cache-refresh, and picker-with-graceful-degradation.
- **search-index** — adaptive post-import Solr warmup scheduling
  (`ImportService::scheduleSolrWarmup` / `scheduleSmartSolrWarmup` /
  `getRecommendedWarmupMode`): import completion schedules a background
  index warmup whose mode + object cap scale with the import size.
- **audit-trail-immutable** — the LogService audit-trail access surface:
  object-scoped log retrieval with soft-deleted register/schema
  tolerance, multi-format export, and admin-only deletion.
- **text-extraction** (new capability) — the file→chunk extraction
  *orchestration* lifecycle (`TextExtractionService`): extract a
  file/object into chunks with modified-since re-extraction detection,
  discover untracked files, drain a pending queue, retry failures, and
  report stats. This sits above the per-source handler contract in the
  sibling `text-extraction-sources` cap (bw-svc-mid2) and below
  `vector-embeddings`; the two text-extraction caps are complementary
  layers, not duplicates.

Everything else is tagged `@spec exclude` or annotated against an
existing capability spec / change.

## Annotation against existing capabilities (no new REQ)

These methods encode behavior already owned by a committed capability;
they are annotated in-place against that capability rather than re-spec'd:

- `LanguageService::parseAcceptLanguageHeader`, `resolveLanguageForRegister`
  → `register-i18n` (Accept-Language negotiation + per-register fallback chain).
- `OperatorEvaluator::valueMatchesOperator`,
  `PropertyRbacHandler::canReadProperty` / `canUpdateProperty` /
  `filterReadableProperties` / `getUnauthorizedProperties`
  → `row-field-level-security` (FLS property authorization + MongoDB-style
  operator matching with SQL three-valued-logic parity).
- `MetricsService::recordMetric`, `cleanOldMetrics`
  → `production-observability` (metric write path + retention pruning).
- `WebhookService::interceptRequest`
  → `webhook-payload-mapping` (pre-event request-interception webhooks).
- `ExportService::buildTemplateCsv`, `buildTemplateSpreadsheet`
  → `data-import-export` (downloadable per-schema import templates).
- `ImportService::softDeleteByImportJobId`
  → `data-import-export` (import rollback on critical failure).
- `AvgComplianceService::runAllChecks`
  → `avg-verwerkingsregister` (automated PII compliance smell aggregate).
- `DashboardService::recalculateLogSizes`, `recalculateAllSizes`,
  `RegisterService::getSchemaObjectCounts`
  → `built-in-dashboards` (size recalculation + object-count metrics;
  annotated against the existing `b-svc-compute-profile-org` change).
- `OrganisationService::getOrganisationForNewEntity`, `joinOrganisation`,
  `getUserOrganisationStats`, `getOrganisationSettingsOnly`,
  `getDefaultOrganisationUuid`
  → multi-tenancy organisation feature (existing `b-svc-compute-profile-org`
  change).
- `RegisterService::createFromArray`, `updateFromArray`
  → `file-actions` REQ-004 (register folder provisioning on register CRUD).
- `FileService` file CRUD / folder / tagging methods (`getFiles`,
  `getFilesForEntity`, `getFileById`, `saveFile`, `updateFile`,
  `deleteFile`, `createEntityFolder`, `createFolder`,
  `createObjectFolderWithoutUpdate`, `attachTagsToFile`)
  → `file-actions` REQ-001/004/005.
- `TextExtractionService::extractFile` / `extractObject` / `chunkDocument` /
  `discoverUntrackedFiles` / `extractPendingFiles` / `retryFailedExtractions` /
  `getStats` → the new `text-extraction` capability ("File and Object
  Chunk-Extraction Lifecycle" below).

## Source

`/tmp/or-scan/bw2-svc-flat-2.json` (112 methods, 22 files). Behavior
described here is what the code does today, not aspirational.
