# Design: mdm-merge-relinks-every-reference

Read at openregister development 555af7212, stackiq development d22033a and
hydra ADR-045.

## Context

- `MergeService::executeMerge()` (`lib/Service/Merge/MergeService.php:261`)
  snapshots both records, recomputes the survivor, and relinks in one of two
  modes: `relinkReverseFk()` (`:684`) moves source records whose declared
  `referenceField` names the loser, and `relinkSourceRecords()` (`:647`) moves
  embedded source links. Both come from the schema's `x-openregister-merge`
  configuration. References that no merge configuration declares are not
  touched.
- `reverseMerge()` (`:453`) restores from the snapshot, including
  `reverseFkMoves` (`:472`) and source links (`restoreSourceLink()`, `:892`).
- OpenRegister already answers "who points at this object":
  `objects#used` (`appinfo/routes.php:1190`), backed by the relation index.
- The duplicates page `src/views/quality/DuplicatesIndex.vue` reads the pair
  from `qualityStore.selectedRegister` and `selectedSchema` (`:215-255`) and
  has no route query handling; the API is
  `GET /api/objects/duplicates/{register}/{schema}` (`routes.php:631`).

## D-1: relink from the relation index, not from configuration

`ReferenceRelinker::plan(loserUuid)` asks the relation index for every object
that references the loser, with the field path. For each: a scalar reference
becomes the survivor; an array reference replaces the loser and drops a
resulting duplicate; a relation row is re-pointed. `apply(plan, actor)` patches
each object through the save path with the merging user as actor, so rights,
validation and audit apply. A reference the actor may not update is left and
listed as `skipped` in the merge result.

The configured `relinkReverseFk()` path keeps running first; the relinker
skips moves it already made.

## D-2: the snapshot holds every move

Each applied move is recorded in the snapshot as
`{ object, path, before, after }`. `reverseMerge()` restores a move only when
the field still holds `after`; a later edit wins and is reported.

## D-3: preview

`POST /api/objects/merge/preview` gains `references: [{ schema, count }]`
from `plan()`, so the steward sees what will move before executing.

## D-4: the deep link

`DuplicatesIndex.vue` reads `register` and `schema` from the route query on
mount, resolves slugs through the register and schema stores, and sets the
selection. An unknown value shows the picker with a notice.

## Risks

- A record referenced from thousands of places makes a long merge. The plan is
  capped at 5,000 moves; above it the merge is refused with the count and a
  suggestion to use a bulk job.
