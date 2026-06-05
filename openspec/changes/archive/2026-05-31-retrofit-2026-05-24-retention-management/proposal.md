# Retrofit — retention-management

Describes observed behavior of 7 methods across 4 files under `retention-management` as 3 new REQs. Code already exists — this change retroactively specifies it. The remaining 11 methods in the original batch were either DROPped because they implement requirements already present in `openspec/specs/retention-management/spec.md` (private helpers behind public REQ-bearing methods) or were FPs from a prior triage pass against other capabilities.

## Affected code units

- `lib/Service/AvgRetentionService.php` — `__construct`, `runRetentionPass`, `processActivity` (private), `computeCutoff` (private), `findOverdueObjectsForActivity` (private), `erasePastRetention` (private), `loadCandidate` (private)
- `lib/BackgroundJob/AvgRetentionJob.php` — `__construct`, `run` (protected)
- `lib/BackgroundJob/RealtimeEventRetentionJob.php` — `__construct`, `run` (protected)
- `lib/Service/Settings/ObjectRetentionHandler.php` — `getVersionInfoOnly`, `convertToBoolean` (private)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes.
- Draft REQs that match behavior (not aspirational).
- DROPs documented in the Notes section below.

## REQ map

| REQ | Methods |
|-----|---------|
| REQ-AVG-RETENTION | `AvgRetentionService::__construct/runRetentionPass/processActivity/computeCutoff/findOverdueObjectsForActivity/erasePastRetention/loadCandidate`, `AvgRetentionJob::__construct/run` |
| REQ-REALTIME-EVENT-RETENTION | `RealtimeEventRetentionJob::__construct/run` |
| REQ-RETENTION-SETTINGS-VERSION-AND-BOOLEANS | `ObjectRetentionHandler::getVersionInfoOnly/convertToBoolean` |

## Notes — DROPs

The original batch JSON contained 11 additional methods that this retrofit does NOT annotate:

- `RetentionService::determineBrondatum` (private) — helper of `calculateArchiefactiedatum`; already covered by the existing _"The system MUST calculate archiefactiedatum using configurable afleidingswijzen"_ requirement (Scenarios "Calculate from afgehandeld" / "Calculate from eigenschap" / "Calculate from termijn").
- `RetentionService::validateNotImmutable` (public) — powers the existing _"Destroyed objects cannot be modified"_ and _"Transferred objects become read-only"_ Scenarios under the MDTO retention metadata requirement.
- `RetentionService::extractSelectielijstBron` (private) — helper of `generateDestructionCertificate`; covered by the existing _"The system MUST generate destruction certificates"_ requirement.
- `RetentionController::checkDualApprovalRequired` (private) — helper invoked from the approve endpoint; covered by the existing _"Two-step approval for sensitive schemas"_ Scenario under the multi-step approval workflow requirement.
- `src/views/settings/sections/RetentionConfiguration.vue::showRebaseDialog` — trivial UI dispatch (one-liner that delegates to the settings store); no distinct backend behavior to specify.

DROPs from earlier coverage triage that landed in this batch (FP carry-overs, not actually retention-management new behavior):

- `AvgRetentionJob::__construct/run`, `AvgRetentionService::runRetentionPass/processActivity/computeCutoff/findOverdueObjectsForActivity/erasePastRetention/loadCandidate` were earlier triaged as DROPs of `archival-destruction-workflow#REQ-001/REQ-003/REQ-006/REQ-008` and `actions#REQ-001`. They WERE correctly DROPped from those capabilities — they belong to retention-management AVG/GDPR enforcement, NOT to Archiefwet destruction-list workflow nor actions CRUD. They are picked up here as a new dedicated REQ.

Source: `/tmp/or-scan/rspec-cluster-retention-management.json` (capability `retention-management`, 18 methods, 7 files).
