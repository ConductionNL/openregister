---
kind: code
depends_on: []
---

# Proposal: archival-frozen-refuses-delete-and-dates-follow

## Summary

A record that an account manager locked cannot be deleted either, by anyone,
until it is unlocked. And a client's destruction date appears when the client
becomes inactive, moves when that date is corrected, and disappears when it is
cleared, with the client's contact persons following along. Both close gaps
that pipelinq's retention and record-lock changes found in OpenRegister's
archiving.

## Halves this closes

Two halves asked by two merged pipelinq changes (pipelinq `development`
9a5e95c). Neither has a row in OpenRegister's matrix; the owner moves pass of
28 Sep 2026 handed them here.

- pipelinq `platform-record-lock` (design, Context): "The freeze does not
  refuse a delete. `git grep -i frozen` over OpenRegister
  `lib/Service/Object/DeleteObject.php` and `lib/Controller/ObjectsController.php`
  finds nothing. OpenRegister already stops deletes by guard listeners on the
  stoppable `ObjectDeletingEvent` (`lib/AppInfo/Application.php:3359` and :3378
  register two)." Its task 3.1: "Open the OpenRegister issue for 'a frozen
  object refuses deletion' (guard on `ObjectDeletingEvent`)".
- pipelinq `platform-client-retention` (design D3 and D4): "pipelinq depends on
  an OpenRegister change that recalculates, and clears, the destruction date
  when the source date changes under `ander_datumkenmerk` and under the
  relation methods. Until it lands, task 1.3's test fails and the PR says so."
  And: "This needs D3's recalculation to reach a contact when its client
  changes, which the same OpenRegister change must cover."

The third ask in `platform-client-retention` (D6, reading the anonymisation
profile beside the `archive` block) belongs to the open change
`anonymising-as-an-archival-outcome` and is added there, not here.

## What changes

- A guard on `ObjectDeletingEvent` refuses to delete a frozen object, on
  every delete path, with a message naming who froze it and when. Unfreezing
  first is the way to delete it.
- `RetentionService::recalculateArchiveActionDate()` also recalculates under
  `ander_datumkenmerk` when `sourceDateProperty` changed, and under the
  relation methods when `sourceRelation` or the related record's
  `sourceRelationProperty` changed.
- A date that appears after creation sets the destruction date; a date that
  is cleared clears it.
- When a record changes a date that other records' retention reads through a
  relation, those records are recalculated in a background job.

## Out of scope

- A new recycle state or delete window. That is
  `delete-window-and-recorded-destruction`.

## Impact

- New `lib/Listener/FrozenObjectDeleteGuardListener.php`, registered beside
  `WorkingCalendarDeleteGuardListener` and `ConceptDeleteGuardListener`.
- `lib/Service/RetentionService.php` (`recalculateArchiveActionDate()` at
  `:289-360`).
- New `lib/BackgroundJob/RelatedRetentionRecalculationJob.php`.
