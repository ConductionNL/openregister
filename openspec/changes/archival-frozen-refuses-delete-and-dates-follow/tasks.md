# Tasks: archival-frozen-refuses-delete-and-dates-follow

## 1. Frozen guard

- [x] 1.1 `FrozenObjectDeleteGuardListener` on `ObjectDeletingEvent`, registered beside the other delete guards. Verify: `tests/Unit/Listener/FrozenObjectDeleteGuardListenerTest.php` with a real `ObjectDeletingEvent` for a frozen and an unfrozen object, and a cascade meeting a frozen child.

## 2. Dates

- [x] 2.1 Recalculation under `ander_datumkenmerk` and the relation methods, nomination filled from `defaultNominatie`, clearing with an audit entry. Verify: `RetentionServiceTest` for a date set after creation, a corrected date, a cleared date, and a changed `sourceRelation`.
- [x] 2.2 `RelatedRetentionRecalculationJob`, queued from the save path, deduplicated, batched. Verify: `tests/Unit/BackgroundJob/RelatedRetentionRecalculationJobTest.php` where a client's `relationshipEndedAt` change recalculates two contact persons.

## 3. Proof and docs

- [x] 3.1 Newman: freeze a record, try `DELETE` (refused), unfreeze, delete (done). Collection written: `tests/integration/openregister-frozen-delete.postman_collection.json`; tick after its first live pass.
- [x] 3.2 Newman: set a client's end date, read its and its contact's destruction dates, clear it, read them again. Collection written: `tests/integration/openregister-dates-follow.postman_collection.json`; tick after its first live pass.
  > 2026-11-25, sandbox Nextcloud 34 on PostgreSQL 16 (main 55f2aa3e). 3.1: `openregister-frozen-delete` 11 requests,
  > 10 assertions, 0 failed. 3.2: `openregister-dates-follow` with background jobs in AJAX mode (as its description
  > asks) failed on main: the contact never followed. A web or AJAX cron request runs the job with no user and is not
  > a trusted caller, so the relation lookup kept only rows without an organisation and the contact (which has one)
  > was skipped without a word; `occ`/system cron did follow. Fixed in the same PR: the job recalculates inside
  > `SystemOperationContext::run()`. With it, 47 requests, 47 assertions, 0 failed.
- [x] 3.3 Document both in `docs/` beside archiving and freezing.

Acceptance:
- No delete path removes a frozen object.
- A destruction date always follows its source date, including through a relation.
