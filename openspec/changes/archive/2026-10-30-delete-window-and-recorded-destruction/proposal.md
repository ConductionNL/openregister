---
kind: code
depends_on: [object-archive-state]
---

# Proposal: delete-window-and-recorded-destruction

## Summary

An accidentally deleted bezwaar is an Archiefwet problem. OpenRegister
already soft-deletes, already computes a purge date and already refuses a
hard delete that was not soft-deleted first. What it does not do is tell
anyone how long they have, destroy the work that hangs off an object when
the object is destroyed, or keep the AVG clock and the Archiefwet clock
apart on the record itself. This change completes the existing soft delete
rather than adding a second state beside it.

## Candidates and cluster

Cluster 39 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Delete, restore and
destroy". Owner openregister, size M, five candidates, four of them
`must`: C-case-core-11 (a matrix hole), C-documents-20,
C-access-and-privacy-14, C-access-and-privacy-50,
C-access-and-privacy-65. Passers: seven, five driven and two documented.
dossiq: one `partial`, four `no`.

## The decision this rests on

**D10, as Ruben decided it: no new recycle state.** The build plan's
option 3 put a recycle state in openregister beside `object-archive-state`
with the guard staying in dossiq. The answer taken is narrower and
cheaper: openregister already has a soft delete with a configurable
retention, so this cluster extends that spec with the stated period and
the recorded destruction. `object-archive-state` stays the state between
open and gone, dossiq's `case-delete-guard` stays as it is, and no third
state is introduced.

The half of D10 that survives unchanged is the separation the decision
insists on: deletion on loss of lawful purpose is not archive retention,
and the specs must not merge them.

## Why

The proving system is vikunja, cited by the case-core lane at
`case-core.tsv:8`: "Settings, Delete your Vikunja account, and the 30-day
purge of soft-deleted tasks (menu-tree.md, code-census.md)". freescout and
plane pass the same candidate, driven. The clause is the reason it is a
`must`: an accidentally deleted bezwaar is an Archiefwet problem.

- **dimpact-zac**, `documents.tsv:33`: "Documents
  (docs/user-manual-features.md)". Deleting a document is allowed only to
  the record manager role. The clause names what we state nowhere:
  destructive acts scoped to one named role.
- **rx-mission**, documented, `access-and-privacy.tsv:53`: "/modules/
  Archiefbeheer". Destroying a case also destroys the process data behind
  it. The candidate note is the sentence to keep: a destroyed case whose
  task history, notes and audit rows survive is not destroyed, and this is
  the only place in the corpus that says so.
- **pinkroccade-izaaksuite**, documented, `access-and-privacy.tsv:52`:
  "/proces-services/domeingericht-archiveren/ (iArchief)". Personal data
  is deleted when its lawful purpose ends, apart from the archive
  retention rule. The clause: the AVG says delete when the purpose ends
  and the Archiefwet says keep for N years, and a product that treats them
  as one rule is wrong in both directions.

**What exists here and does not close it.** `deletion-audit-trail`
requirement 1 soft-deletes by writing `@self.deleted`, excludes the object
from normal queries and offers a trash API with restore. Requirement 2
computes a `purgeDate` from a configurable `objectDeleteRetention` with a
schema-level override. Requirement 4 refuses a hard delete of an object
that was not soft-deleted, and says admin-only access "SHOULD" be
enforced. Requirements 5, 6 and 9 keep a full snapshot, a cascade entry
per object and a per-object entry on a bulk delete.

Three things are missing against the candidates. The period is computed
and never published, so nobody knows how long they have or when it lapses.
The destruction has no declared scope, so what hangs off an object is
governed only by referential integrity, which is a different question from
"is it gone". And the destructive verb is a SHOULD on admin rather than a
named right.

The AVG clock exists in `retention-management` as a daily pass, and its
own text says what it covers: it "operates on the audit-trail's
`processing_activity_id` column, not on `archiefactiedatum`". It erases
audit trail rows. It does not answer the candidate, which is about the
personal data in the object.

## What changes

- **The window is stated, not only computed.** A soft-deleted object
  carries the date it becomes destroyable and the days remaining, on the
  object, in the trash listing and in the refusal a caller gets when they
  try to read it. A restore before that date is one act.
- **Destruction is a second, named act with a named right.** Destroying
  requires the right to destroy, declared in the permission catalogue, not
  a check for administrator. The act records who destroyed what, when and
  under which rule, and the record survives the object.
- **Destruction has a declared scope.** A schema declares what is
  destroyed with the object: its versions, its notes, its files, its
  tasks, its timeline and the audit rows that hold its content. What is
  kept is the evidence that the destruction happened. The scope is
  previewed before the act and reported after it.
- **Two clocks, both visible, neither silent.** An object carries the AVG
  date, from the lawful purpose of its processing activity, and the
  Archiefwet date, from the selectielijst. Each is shown with the rule
  that produced it. Where they disagree, the object is not destroyed and
  the disagreement is reported, because a product that resolves this
  silently is wrong in one direction or the other.
- **A legal hold beats both.** An object under hold is not destroyed by
  either clock, which `retention-management` already says for the archive
  clock and which now holds for the AVG clock too.

## Consumers

- **dossiq**: `case-delete-guard`, unchanged. It refuses a delete that
  would strand something; this change gives it a window to refuse into and
  a destruction to name. The build plan names it as the consuming half.
- **filinq**: the document half of the destruction scope, and the record
  manager right on a document delete.
- **decidiq, humaniq, keepiq, pipelinq**: the same window and the same
  named right over their own objects.

## ADRs

- ADR-005: the destructive act fails closed. A missing right, an unclear
  scope and two disagreeing clocks all refuse.
- ADR-022: the delete window belongs to the object layer, so a leaf app
  guards its own business rule and does not implement a trash.
- ADR-031: the destruction scope is declared on the schema.

## Impact

- Extends `deletion-audit-trail` (the stated window, the named right, the
  declared scope, the two clocks). Sits beside `object-archive-state`,
  which stays the state between open and gone, and beside
  `retention-management`, which keeps the Archiefwet workflow.
- Affected code: `ObjectEntity::delete()` and the deleted metadata,
  `DeletedController`, the trash listing, the permission catalogue, the
  cascade path and the audit trail entries for a destruction.
- Backwards compatible: an instance that declares no destruction scope
  destroys what it destroys today and gains the published window.
- Size: M.

## Out of scope

- A recycle state of its own. D10 as decided says the soft delete carries
  this, and a third state would be a fourth thing to explain.
- Deleting everything you own in one act (C-access-and-privacy-14, a
  `could` with one driven passer). The candidate note calls it a dangerous
  verb to hand a caseworker in a gemeente, and it is a bulk act under
  `bulk-action-jobs` if it is ever wanted.
- The archiving process from nomination to transfer, which is cluster 43
  under decision D7 and its own change.
- dossiq's delete guard, which stays as written.
