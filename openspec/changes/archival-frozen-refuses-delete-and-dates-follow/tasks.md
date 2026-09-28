# Tasks: archival-frozen-refuses-delete-and-dates-follow

## 1. Frozen guard

- [ ] 1.1 `FrozenObjectDeleteGuardListener` on `ObjectDeletingEvent`, registered beside the other delete guards. Verify: `tests/Unit/Listener/FrozenObjectDeleteGuardListenerTest.php` with a real `ObjectDeletingEvent` for a frozen and an unfrozen object, and a cascade meeting a frozen child.

## 2. Dates

- [ ] 2.1 Recalculation under `ander_datumkenmerk` and the relation methods, nomination filled from `defaultNominatie`, clearing with an audit entry. Verify: `RetentionServiceTest` for a date set after creation, a corrected date, a cleared date, and a changed `sourceRelation`.
- [ ] 2.2 `RelatedRetentionRecalculationJob`, queued from the save path, deduplicated, batched. Verify: `tests/Unit/BackgroundJob/RelatedRetentionRecalculationJobTest.php` where a client's `relationshipEndedAt` change recalculates two contact persons.

## 3. Proof and docs

- [ ] 3.1 Newman: freeze a record, try `DELETE` (refused), unfreeze, delete (done).
- [ ] 3.2 Newman: set a client's end date, read its and its contact's destruction dates, clear it, read them again.
- [ ] 3.3 Document both in `docs/` beside archiving and freezing.

Acceptance:
- No delete path removes a frozen object.
- A destruction date always follows its source date, including through a relation.
