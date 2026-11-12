---
kind: code
depends_on: []
---

## Why

OpenRegister's survivorship + merge engine resolves a master entity's competing
source records from an **embedded** field: the schema's
`x-openregister-survivorship`/`x-openregister-merge` `sourceLinkField` is read
off the master object's own payload as an array of embedded records or uuid
references.

That does not fit the canonical MDM data model, where source records are
**separate objects that point up at the master** (a reverse foreign key). The
pipelinq `masterEntity` schema declares `sourceLinkField: "sourceRecords"` but
has no such property — its sources are `sourceRecord` objects carrying
`currentMasterEntity` (the master's UUID). Because the master has no embedded
`sourceRecords` array (and `hardValidation` strips one if supplied), the
survivorship recompute, `MergeService::previewMerge`, and the
conflict-resolution modal all resolve **zero** sources and project an **empty**
golden record. The steward-facing merge and conflict chains therefore cannot
render real data (proven by the `mdm-views-route-scoping-e2e` suite, whose
merge/conflict specs skip on exactly this gap).

## What Changes

- The `x-openregister-survivorship` and `x-openregister-merge` annotations gain
  an **optional `sourceLink` block** selecting how sources are resolved:
  - `mode: "embedded"` (default, current behaviour) — read `sourceLinkField`
    off the master payload (embedded records + uuid references).
  - `mode: "reverseFk"` — resolve sources by querying the `sourceSchema` for
    objects whose `referenceField` equals the master's UUID.
- `SurvivorshipRecomputeListener` and `MergeService` resolve sources through a
  single shared resolver honouring the selected mode. In reverse-FK mode they
  query via `ObjectService` instead of reading an embedded array.
- `MergeService` relink/reverse becomes mode-aware: in reverse-FK mode a merge
  **rewrites each losing master's source objects' `referenceField`** to the
  survivor's UUID (and the reversal restores them), instead of merging an
  embedded array on the survivor payload.
- The survivorship + merge annotation validators accept the new `sourceLink`
  shape (and keep `sourceLinkField` required only for embedded mode).
- A source-change recompute trigger: saving/deleting a source object in a
  reverse-FK relationship re-resolves and rematerialises its master's golden
  record, so the master stays correct as its sources change.

Backward compatible: with no `sourceLink` block the engine behaves exactly as
today (embedded mode).

## Capabilities

### New Capabilities
- `mdm-reverse-fk-source-resolution`: reverse-FK source resolution for the MDM
  survivorship/merge engine — resolving a master's competing source records by
  querying a source schema's back-reference field, plus the source-change
  recompute trigger that keeps the master current.

### Modified Capabilities
<!-- None: the existing mdm-survivorship / mdm-merge requirements are unchanged;
     this change adds a new optional resolution mode, captured as new
     requirements under the mdm-reverse-fk-source-resolution capability. The
     embedded-mode behaviour of both engines is preserved byte-for-byte. -->


## Impact

- **Code (openregister):**
  - `lib/Service/Survivorship/SurvivorshipResolver.php` — no signature change;
    already consumes a resolved `sourceRecords` array.
  - New shared `SourceRecordResolver` (or equivalent) used by both callers.
  - `lib/Listener/SurvivorshipRecomputeListener.php` — mode-aware source load.
  - `lib/Service/Merge/MergeService.php` — mode-aware `loadSourceRecords`,
    `relinkSourceRecords`, and reversal (`restoreSourceLink`).
  - `lib/Service/Survivorship/SurvivorshipAnnotationValidator.php` +
    `lib/Service/Merge/MergeAnnotationValidator.php` — accept `sourceLink`.
  - A source-object save/delete listener that recomputes the linked master.
- **Consumers:** pipelinq's `masterEntity` annotation flips to `sourceLink`
  reverse-FK (`sourceSchema: sourceRecord`, `referenceField:
  currentMasterEntity`) in a separate `config` change; no other consumer sets
  `sourceLink`, so all existing embedded configs are unaffected.
- **APIs:** no route/response contract change. `MergeService::previewMerge`
  and the conflict endpoint now return a populated golden record for reverse-FK
  schemas.
- **Depends on:** `mdm-survivorship-engine`, `mdm-merge-engine` (both merged).
