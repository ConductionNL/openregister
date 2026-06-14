# Retrofit — archival-destruction-workflow

Describes observed behavior of 2 methods under `archival-destruction-workflow` as 1 new REQ. Code already exists — this change retroactively specifies it.

## Affected code units
- lib/BackgroundJob/DestructionCheckJob.php::run (pre-notification step)
- lib/BackgroundJob/DestructionCheckJob.php::sendObjectNotification

## Additional methods annotated with existing REQs
- lib/Controller/ArchivalController.php::createLegalHold (REQ-006)
- lib/Controller/ArchivalController.php::releaseLegalHold (REQ-006)
- lib/Service/Archival/LegalHoldService.php::placeHold (REQ-006)
- lib/Service/Archival/LegalHoldService.php::releaseHold (REQ-006)
- lib/Service/Archival/LegalHoldService.php::hasActiveHold (REQ-006)
- lib/Service/Archival/LegalHoldService.php::hasActiveHoldFromRetention (REQ-006)
- lib/Service/Archival/LegalHoldService.php::bulkPlaceHold (REQ-006)
- lib/Service/ArchivalService.php::calculateArchivalDate (REQ-007)
- lib/Service/ArchivalService.php::findObjectsDueForDestruction (REQ-001)

## Approach
- Observed: DestructionCheckJob::run sends per-object notifications to archivaris group for objects within a configurable lead window (default 30 days) before archiefactiedatum. Deduplication via app config `retention_notified_objects`. Legal hold exclusion. Subject varies by archiefnominatie.
- This behavior is DISTINCT from the list-creation notification in REQ-001 (which fires when a destruction list is created). Pre-destruction notifications fire N days before the deadline.
- REQ-009 added to spec; remaining 18 Bucket 2a methods annotated with existing REQ refs.

Source: openspec/coverage-report.md generated 2026-04-23. See retrofit playbook.
