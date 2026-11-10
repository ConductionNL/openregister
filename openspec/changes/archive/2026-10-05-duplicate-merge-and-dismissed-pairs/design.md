# Design: duplicate-merge-and-dismissed-pairs

## D-1: the choice is data, not a second merge path

`MergeService::previewMerge()` already computes a survivor. Rather than a
second execution path for a human-decided merge, the preview returns the
resolver's proposal per property and accepts a decision map back. Execution
takes that map and refuses a map that does not match the preview it was
approved with, so the reviewed screen and the write cannot disagree.

## D-2: a dismissal is an object, not a flag on a pair

The dismissed pair is a record in a register: the two uuids in a canonical
order, the actor, the reason, the moment, and a fingerprint of the values
that were compared. The scorer excludes a pair whose fingerprint still
matches, and offers it again when it does not, so a dismissal survives a
false start and expires when the data behind it changes. A flag on one of
the two objects would be lost the moment either is merged.

## D-3: soft uniqueness is a warning, not a constraint

A database unique index refuses. What the row asks for is an alert on save
that names the record already holding the value, with the writer free to
continue. It is therefore the dedup check narrowed to one property and one
exact match, reusing the same scorer and the same cap, and it runs on
update as well as on create. A schema that wants a refusal declares
`onCreate: "block"` from `dedup-check-before-create`; the two annotations
sit beside each other and mean different things on purpose.

## D-4: reuse analysis (ADR-012)

- `MergeService` (preview, execute, reverse, reversal window): reused, one
  parameter added to preview and one to execute.
- `SurvivorshipResolver`: reused unchanged as the proposer.
- The dedup scorer and its candidate cap: reused for the soft-unique check.
- `ObjectService`: every write still goes through it, so RBAC, tenancy and
  the audit trail are unchanged.
- No overlap found with `duplicate-detection`'s rule set, which scores
  stored pairs and does not decide anything.

## D-5: kind

Code, in OpenRegister. Consuming apps declare and render.
