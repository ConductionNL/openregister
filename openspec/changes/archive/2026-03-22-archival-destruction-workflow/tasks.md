## 1. Service Layer Foundation

- [x] 1.1 Create `lib/Service/Archival/ArchiefactiedatumCalculator.php` with `calculate()` method supporting afleidingswijzen: `afgehandeld`, `eigenschap`, `termijn` — each deriving brondatum + bewaartermijn to produce archiefactiedatum
- [x] 1.2 Create `lib/Service/Archival/LegalHoldService.php` with methods: `placeHold()`, `releaseHold()`, `hasActiveHold()`, `bulkPlaceHold()` — storing holds in `retention.legalHold` on ObjectEntity
- [x] 1.3 Create `lib/Service/Archival/DestructionService.php` with methods: `findEligibleObjects()`, `createDestructionList()`, `approveList()`, `rejectList()`, `executeDestruction()`, `generateCertificate()`

## 2. Background Jobs

- [x] 2.1 Create `lib/BackgroundJob/DestructionCheckJob.php` extending `TimedJob` — queries for objects past archiefactiedatum with archiefnominatie=vernietigen, excludes legal holds and objects already on lists, calls `DestructionService::createDestructionList()`
- [x] 2.2 Create `lib/BackgroundJob/DestructionExecutionJob.php` extending `QueuedJob` — processes approved destruction lists in batches of 100, handles cascade destruction, file cleanup, re-checks legal holds before each deletion
- [x] 2.3 Create `lib/BackgroundJob/BulkLegalHoldJob.php` extending `QueuedJob` — applies legal holds to all objects in a schema when bulk hold is requested
- [x] 2.4 Register all three background jobs in `lib/AppInfo/Application.php` via `IJobList`

## 3. API Controller and Routes

- [x] 3.1 Create `lib/Controller/ArchivalController.php` with endpoints: GET/destruction-lists, GET/destruction-lists/{id}, POST/destruction-lists/{id}/approve, POST/destruction-lists/{id}/reject, POST/legal-holds, DELETE/legal-holds/{id}, GET/legal-holds, GET/certificates
- [x] 3.2 Add routes to `appinfo/routes.php` under `/api/archival/` prefix
- [x] 3.3 Add archivist role authorization check — return HTTP 403 for unauthorized users on all archival endpoints

## 4. Integration with Existing Infrastructure

- [x] 4.1 Add archival metadata hook in `SaveObject` pipeline — when an object in an archive-configured schema is saved and `afleidingswijze` source property changes, call `ArchiefactiedatumCalculator::calculate()` to recalculate archiefactiedatum
- [x] 4.2 Extend `DeleteObject` to support `permanent: true` flag for physical deletion (bypass soft-delete), used by `DestructionExecutionJob`
- [x] 4.3 Add legal hold check in `DestructionService` pre-flight validation — scan all objects and their cascade targets for active legal holds before execution
- [x] 4.4 Add WOO-published flag detection — check if object has been published via WOO and add `woo_gepubliceerd` label to destruction list entries

## 5. Notification Integration

- [x] 5.1 Add `INotification` for new destruction lists — notify archivist role users when DestructionCheckJob generates a new list
- [x] 5.2 Add `INotification` for legal hold exclusions — notify archivist when objects are auto-excluded from destruction due to legal holds
- [x] 5.3 Add `INotification` for cascade halt — notify archivist when destruction is blocked by legal hold on child object

## 6. Destruction Certificate

- [x] 6.1 Implement `DestructionService::generateCertificate()` — create immutable register object with destruction date, approvers, object counts by schema/selectielijst, selectielijst reference, Archiefwet compliance statement
- [x] 6.2 Ensure certificate objects cannot be edited or deleted — add protection check in SaveObject and DeleteObject for certificate schema objects

## 7. Testing and Verification

- [x] 7.1 Write unit tests for `ArchiefactiedatumCalculator` covering all three afleidingswijzen and recalculation on property change
- [x] 7.2 Write unit tests for `LegalHoldService` covering place, release, bulk hold, and hasActiveHold checks
- [x] 7.3 Write unit tests for `DestructionService` covering list creation, approval (full/partial/reject), dual-approval workflow, and certificate generation
- [x] 7.4 Write integration tests for `DestructionCheckJob` and `DestructionExecutionJob` with mock data
- [x] 7.5 Test with opencatalogi and softwarecatalog to verify no regressions on existing object operations
