# Retrofit — tenant-lifecycle

Describes observed behavior of 4 methods under `tenant-lifecycle` as 1 new REQ + 1 backfilled existing REQ annotation. Code already exists — this change retroactively specifies it.

## Affected code units
- lib/BackgroundJob/TenantDeprovisionJob.php::run (REQ-003 backfill)
- lib/Service/TenantLifecycleService.php::isValidEnvironment (REQ-005 new)
- lib/Service/TenantLifecycleService.php::isValidPromotionOrder (REQ-005 new)
- lib/Service/TenantLifecycleService.php::validateTransition (REQ-001 existing, task-73)

## Approach
- `isValidEnvironment`: checks env ∈ OTAP_ORDER constant map — returns bool, no side effects
- `isValidPromotionOrder`: compares OTAP_ORDER[source] < OTAP_ORDER[target] — returns bool, unknown envs get order -1 (automatically fail)
- `TenantDeprovisionJob::run`: hourly job, queries deprovisioning orgs, calls archive() on each, logs result
- `validateTransition`: checks STATE_TRANSITIONS map, throws Exception (HTTP 409) on invalid transition

## Observed gap (REQ-003)
The spec says: "all objects belonging to the Organisation MUST be soft-deleted" during deprovisioning.
The actual job only calls `tenantLifecycleService->archive()` which transitions status to `archived` but does NOT soft-delete objects. Object soft-deletion is commented as deferred to "the purge job after retention period expires." This diverges from the REQ-003 scenario. Not fixing — surfacing for reviewer attention.

Source: openspec/coverage-report.md generated 2026-04-23. See retrofit playbook.
