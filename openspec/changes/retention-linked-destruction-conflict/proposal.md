---
kind: code
---

# Proposal: retention-linked-destruction-conflict

## Summary

A records officer reviewing a destruction list sees, per entry, when destroying that record would clash with the records linked to it. Examples: a decision that must be kept permanently still points at the case on the list, a sub-case takes its archive date from the case on the list, or a linked record is under a legal hold. The warning names each linked record, its archive date or nomination, and why it clashes. A reviewer can still answer "destroy", but then says why in so many words, and that reason is in the list's decision history. Nothing is destroyed or kept automatically because of the warning.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | ret-linked-destroy-conflict | Warn when a record's destruction date conflicts with that of the records linked to it. | no |

**ret-linked-destroy-conflict** (openregister's matrix)

- Demand: tender, https://www.tenderned.nl/aankondigingen/overzicht/419447 (the row's origin).
- Competitor yes cells: none in the packet.

## Why

A record's dates can follow a linked record, but nothing compares them when one of them is about to be destroyed.

- A record's archive date can be derived from a linked record: `ArchiveActionDateCalculator` handles the relation-based methods `gerelateerde_zaak`, `hoofdzaak`, `ingangsdatum_besluit`, `vervaldatum_besluit` and `zaakobject` (`lib/Service/Archival/ArchiveActionDateCalculator.php:108-114`) through `brondatumFromRelation()` (`:267-306`), which reads the date off the related record. Once that record is destroyed the derivation has nothing to read.
- The destruction list is built by `RetentionService::createDestructionList()` (`lib/Service/RetentionService.php:826-886`), called from `DestructionCheckJob` (`lib/BackgroundJob/DestructionCheckJob.php:139`). Each entry carries the record's own `archiefactiedatum` and classification (`:854-873`) and nothing about its links.
- The review answers destroy, retain or transfer through `DestructionReviewService::recordAnswer()` (`lib/Service/Archival/DestructionReviewService.php:236-285`), which checks the answer, the reason and the reviewer (`:442-470`) but not the links.
- The only place linked records meet retention is at execution: `ReferentialIntegrityService::partitionRetainedTargets()` (`lib/Service/Object/ReferentialIntegrityService.php:303-341`) asks `ArchivalRetentionGuard::cascadeRefusal()` (`lib/Service/Archival/ArchivalRetentionGuard.php:228-235`) and keeps a retained child out of a cascade, whose wording says "Its parent is gone, this record stays" (`:137-141`). That is the conflict discovered after the fact, with the parent already gone.

## What changes

- For each entry on a destruction list, Open Register looks up the records linked to it, both the records it points at and the records that point at it, within a bound.
- A link is a conflict when the linked record is not on the same list and is kept longer: it has nomination `bewaren`, a later archive date, no archive date yet, or an active legal hold. A link is also a conflict when the linked record derives its own archive date from this record.
- The conflicts are stored on the entry as `linkConflicts` when the list is created, and recomputed when the list or an entry is read for review, so a date moved since is reflected.
- `GET /api/archival/destruction-lists/{id}`, its entries and `GET /api/archival/reviews/pending` carry the conflicts and a count per list.
- A "destroy" answer on an entry with conflicts needs `acknowledgeConflicts: true` and a reason that is recorded with the conflicts it overrode. Without it the answer is refused with 422 naming the conflicts.
- Approving a list reports how many entries were destroyed over an acknowledged conflict.

## Consumers

- filinq and dossiq consume the archiving process (`archiving-as-a-process-with-sign-off`, "filinq and dossiq consume it") and render its review; they show `linkConflicts` beside each entry. Open Register ships no review page of its own today (a search of `src/` for `destruction-lists` finds none), so this change delivers the API and its contract.

## ADRs

- openregister decision 2026-09-19 (archiefactiedatum is Open Register's): the dates compared are the ones Open Register computes.
- openregister ADR-003 (immutable audit trail): an acknowledged override is part of the list's decision history.
- openregister ADR-009 (performance invariants) and hydra ADR-058 (bounded queries): links are looked up once per referencing schema per list, not per entry, with a cap per entry.
- openregister ADR-002 (organisation tenancy): the lookup runs without RBAC, as retention does, and the review endpoints keep their existing access rules, so a reviewer sees conflicts only in lists they may already read.
- hydra ADR-031 (declarative business logic): the conflict rule reads the declared archival configuration and nomination; no per-schema code.

## Impact

- Extends the capability `archival-destruction-workflow`.
- Affected code: a new `lib/Service/Archival/LinkedRetentionConflictFinder.php`, `lib/Service/RetentionService.php` (`createDestructionList()`), `lib/Service/Archival/DestructionReviewService.php` (`recordAnswer()`, `guardAnswer()`, `pendingEntries()`), `lib/Service/Archival/DestructionService.php` (`approveList()` summary), the archival controller's list, entry and decision actions.
- Backwards compatible. Entries without conflicts look as today apart from an empty `linkConflicts`. A "destroy" answer on an entry without conflicts is unchanged.
- Size: M.

## Out of scope

- Moving a linked record's date or adding it to the list automatically. A person decides; the warning informs.
- The AVG and Archiefwet clocks on one record. `delete-window-and-recorded-destruction` (REQ-DWD-004) reports that conflict.
- A review page. The consuming apps render the review; this change is the data and the rule.
