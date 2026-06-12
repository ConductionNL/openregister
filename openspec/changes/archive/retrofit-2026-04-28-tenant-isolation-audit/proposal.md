# Retrofit — tenant-isolation-audit

Maps 3 methods from the `tenant-isolation-audit` bucket to existing REQs in other capabilities. No new REQs drafted — behaviors are fully covered by specs already in place.

## Affected code units
- lib/BackgroundJob/TenantPurgeJob.php::run (tenant-lifecycle#REQ-003 — purge scenario)
- lib/BackgroundJob/TenantUsageSyncJob.php::run (tenant-quotas#REQ-004 — usage counter persistence)
- lib/Middleware/TenantQuotaMiddleware.php::checkRequestQuota (tenant-quotas#REQ-001 via task-75)

## Approach
- `TenantPurgeJob::run`: daily job; queries archived organisations; purges those past configurable retention window (default 90 days); deletes usage records + org entity. Implements the "purge after retention" scenario of tenant-lifecycle#REQ-003.
- `TenantUsageSyncJob::run`: every 5 minutes; reads APCu hourly-bucket counters per active org; upserts to `openregister_tenant_usage` table. Implements tenant-quotas#REQ-004.
- `TenantQuotaMiddleware::checkRequestQuota`: private; reads APCu counter, applies OTAP environment multiplier, throws `TenantQuotaExceededException` when exceeded. Covered by existing class-level task-75 annotation (tenant-quotas#REQ-001).

## Notes
- Scanner placed these under `tenant-isolation-audit` due to proximity to tenant management code; actual behaviors belong to `tenant-lifecycle` and `tenant-quotas`.
- `checkRequestQuota` is private; class-level annotation already present (task-75). Method-level annotation added for completeness.

Source: openspec/coverage-report.md generated 2026-04-23. See retrofit playbook.
