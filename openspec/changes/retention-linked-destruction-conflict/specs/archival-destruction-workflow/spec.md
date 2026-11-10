# archival-destruction-workflow

## ADDED Requirements

### Requirement: A destruction list names the linked records its entries clash with

When a destruction list is created, each entry SHALL carry `linkConflicts`: the linked records, not on the same list, that make destroying the entry's record a conflict. A linked record SHALL be a conflict when it is nominated `bewaren`, has a later archive date, has no archive date yet, is under an active legal hold, or derives its own archive date from the entry's record through a relation-based method. Links SHALL be looked up in both directions, at most 50 each way per entry, with `linkConflictsTruncated` set when more exist. Each conflict SHALL name the linked record's uuid, title, schema, nomination, archive date, the kind of conflict and its direction. The list SHALL carry `linkConflictCount`.

#### Scenario: a case with a permanently kept decision is flagged

- **GIVEN** case `Z-2019-0042` with archive date 2026-09-01 and nomination `vernietigen`, and decision `B-2019-0007`, nominated `bewaren`, that references the case
- **WHEN** the destruction check puts the case on a new list and a records officer calls `GET /api/archival/destruction-lists/{id}`
- **THEN** the case's entry carries one conflict of kind `linked-kept-permanently`, direction `incoming`, naming `B-2019-0007`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/destruction-linked-conflict.spec.ts}

#### Scenario: a sub-case that takes its date from the case is flagged

- **GIVEN** a sub-case whose schema derives its brondatum with method `hoofdzaak` from the case on the list, and which is not on the list itself
- **WHEN** a records officer reads the list
- **THEN** the case's entry carries a conflict of kind `linked-derives-date-from-this` naming the sub-case
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/destruction-linked-conflict.spec.ts}

#### Scenario: records destroyed together do not warn about each other

- **GIVEN** a case and its only linked document on the same destruction list, with the same archive date
- **WHEN** a records officer reads the list
- **THEN** neither entry carries a conflict about the other
- @e2e exclude {specified only; task 1.1 covers it in tests/Unit/Service/Archival/LinkedRetentionConflictFinderTest.php}

### Requirement: Conflicts are current when a reviewer reads them

`GET /api/archival/destruction-lists/{id}` and `GET /api/archival/reviews/pending` SHALL recompute the conflicts of the entries they return and SHALL include the moment of that check as `linkConflictsCheckedAt`. The recomputation SHALL NOT change the stored list.

#### Scenario: a date moved on another list shows up

- **GIVEN** a list created on Monday with no conflict for case `Z-2019-0042`, and on Tuesday a reviewer on another list retains a linked document with a new date in 2035
- **WHEN** the case's reviewer opens their worklist with `GET /api/archival/reviews/pending` on Wednesday
- **THEN** the case's entry shows a `linked-kept-longer` conflict naming the document and its 2035 date
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/destruction-linked-conflict.spec.ts}

### Requirement: Destroying over a conflict needs an explicit acknowledgement

`POST /api/archival/destruction-lists/{id}/entries/{entryId}/decision` with answer `destroy` on an entry that has conflicts SHALL be refused with 422 listing the conflicts unless the request carries `acknowledgeConflicts: true`. The refusal SHALL happen before anything is applied to the record. An acknowledged answer SHALL record `overriddenConflicts` in the list's decision history and their count in the `archival.review_decided` audit row. The approval of the list SHALL record `destroyedOverConflict`, the number of entries destroyed over an acknowledged conflict.

#### Scenario: a reviewer is stopped and shown what they would override

- **GIVEN** case `Z-2019-0042` on a list with a `linked-kept-permanently` conflict, assigned to a records officer
- **WHEN** they post `{"answer": "destroy", "reason": "Termijn verstreken"}` to its decision endpoint
- **THEN** the response is 422 listing the conflict with decision `B-2019-0007`
- **AND** the record is not changed and the list's decision history is unchanged
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/destruction-linked-conflict.spec.ts}

#### Scenario: an acknowledged destroy is recorded with what it overrode

- **GIVEN** the same entry
- **WHEN** the records officer posts `{"answer": "destroy", "reason": "Besluit bevat de zaakgegevens zelf", "acknowledgeConflicts": true}`
- **THEN** the response is 200 and the decision in the history carries `overriddenConflicts` with the conflict with `B-2019-0007`
- **AND** when the list is approved, the approval records `destroyedOverConflict` 1
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/destruction-linked-conflict.spec.ts}

#### Scenario: retaining needs no acknowledgement

- **GIVEN** the same entry
- **WHEN** the records officer answers `retain` with a new date and a reason
- **THEN** the answer is recorded without `acknowledgeConflicts`
- @e2e exclude {specified only; task 3.1 covers it in tests/Unit/Service/Archival/DestructionReviewConflictTest.php}
