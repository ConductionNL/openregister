# Design: retention-linked-destruction-conflict

Read at openregister development c53dd0685c.

## D-1: what counts as a link

Two directions, both from data Open Register already keeps:

- **Outgoing.** The records this entry points at: the uuids in the entry object's `relations` column (`lib/Db/ObjectEntity.php:1050`, read with `getRelations()`).
- **Incoming.** The records that point at this entry. The schemas whose properties can reference the entry's schema are read from the schema definitions once per list. For each such schema, one `MagicMapper::findByRelationBatchInSchema()` call (`lib/Db/MagicMapper.php:8424-8440`) finds every record in that schema's table that references any entry uuid on the list, using the `_relations` index. That is one query per referencing schema per list, never one per entry and never a scan of every table (`findByRelation()`, `:8140`, walks all tables and is not used here).

Per entry at most 50 outgoing and 50 incoming links are examined. An entry with more carries `linkConflictsTruncated: true`, so a reviewer knows the list of conflicts is partial rather than reading it as complete.

## D-2: when a link is a conflict

`lib/Service/Archival/LinkedRetentionConflictFinder.php` compares the entry record E (on the list, to be destroyed) with each linked record L that is not itself on the same list. L's retention is read from its `retention` block, the same block `RetentionService` writes (`archiefnominatie` at `lib/Service/RetentionService.php:158-166`, `archiefactiedatum` beside it). A conflict has one of these kinds:

| kind | when |
|---|---|
| `linked-kept-permanently` | L's `archiefnominatie` is `bewaren`. |
| `linked-kept-longer` | L's `archiefactiedatum` is later than E's. |
| `linked-date-unknown` | L has no `archiefactiedatum` yet, so nobody can say it may go first. |
| `linked-on-hold` | L has an active legal hold, read with `LegalHoldService::hasActiveHoldFromRetention()` (`lib/Service/Archival/LegalHoldService.php:225`). |
| `linked-derives-date-from-this` | L's schema derives its brondatum through one of the relation methods (`lib/Service/Archival/ArchiveActionDateCalculator.php:108-114`) and its `sourceRelation` property points at E (`:267-280` reads the same keys). After E is destroyed, L's date cannot be recomputed. |

Direction is recorded on each conflict (`incoming` or `outgoing`), because a kept record pointing at a destroyed one dangles, while a destroyed record pointing at a kept one does not. Both are reported; the incoming ones are listed first.

A conflict entry reads:

```json
{"kind": "linked-kept-permanently", "direction": "incoming",
 "uuid": "...", "title": "Besluit kapvergunning", "schema": 14,
 "archiefnominatie": "bewaren", "archiefactiedatum": null}
```

The lookup runs without RBAC and multitenancy, as the retention pass does, because retention is an obligation of the instance. The title shown is the linked record's `name`, as `createDestructionList()` uses for its own entries (`lib/Service/RetentionService.php:863`).

## D-3: computed at creation, refreshed at review

`RetentionService::createDestructionList()` (`lib/Service/RetentionService.php:826-886`) calls the finder once for the whole list and adds `linkConflicts` and `linkConflictsTruncated` to each entry, plus `linkConflictCount` on the list.

A date can move between creation and review: a reviewer on another list may retain L with a new date. So the archival controller's list read (`GET /api/archival/destruction-lists/{id}`, `appinfo/routes.php:2015`) and the reviewer's worklist (`GET /api/archival/reviews/pending`, `:2028`, served through `DestructionReviewService::pendingEntries()`, `lib/Service/Archival/DestructionReviewService.php:299`) recompute the conflicts for the entries they return and include `linkConflictsCheckedAt`. The recomputation is not saved on a read; the stored value is what the list looked like when it was made.

## D-4: destroying over a conflict is a stated decision

`POST /api/archival/destruction-lists/{id}/entries/{entryId}/decision` (`appinfo/routes.php:2027`) takes a new optional `acknowledgeConflicts` boolean. The check has to run before anything happens to the record: `ArchivalController::recordDecision()` applies the answer through `$this->outcomes->apply()` first and writes the history second (`lib/Controller/ArchivalController.php:799-815`). So a new `DestructionReviewService::assertConflictsAcknowledged()` is called at the top of `recordDecision()`, before `apply()`. For a `destroy` answer it recomputes the entry's conflicts:

- no conflicts: nothing changes;
- conflicts and no `acknowledgeConflicts: true`: it throws `LinkConflictsNotAcknowledgedException`, which the controller maps to 422 with the conflicts in the body, so the reviewer sees what they would override. It is a separate exception because the controller maps `InvalidArgumentException` to 400 (`:816-820`) and this is not a malformed request;
- conflicts and `acknowledgeConflicts: true`: `recordAnswer()` (`lib/Service/Archival/DestructionReviewService.php:236-285`) adds `overriddenConflicts`, the conflicts as they stood at that moment, to the decision it appends to the list's `decisions` history.

The existing rule that every answer carries a reason (`:453-455`) is what makes the acknowledgement a sentence and not a checkbox. The `archival.review_decided` audit row the controller writes (`lib/Controller/ArchivalController.php:834-842`) gains the count of overridden conflicts. `retain` and `transfer` need no acknowledgement: they do not destroy anything.

## D-5: the approval says what it approves

`DestructionService::approveList()` (`lib/Service/Archival/DestructionService.php:215`) adds `destroyedOverConflict`, the count of entries whose decision carries `overriddenConflicts`, to the approval it records. An approver who signs a list with seven overridden conflicts signs a number they can see.

## Declarative-vs-imperative decision

Declarative inputs, imperative check. The rule reads what schemas already declare (the archival configuration with `afleidingswijze`, `sourceRelation` and `sourceRelationProperty`) and what retention already stored on each record (`archiefnominatie`, `archiefactiedatum`, holds). There is nothing new for a schema author to declare: the conflict is a comparison between existing declarations, so it lives in one service.

## Risks

- **Performance.** One batched query per referencing schema per list, and a cap of 50 links each way per entry (D-1). A list read recomputes only the entries it returns.
- **Security.** The lookup ignores RBAC to see every link, but conflicts appear only inside a destruction list the caller may already read, and a conflict names a linked record's title, schema and dates, nothing else of its content.
- **False alarms.** A linked record kept longer is not always a problem, which is why this warns and asks for a reason rather than blocking.
