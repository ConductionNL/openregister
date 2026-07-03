---
kind: code
depends_on: []
---

## Why

ADR-045 makes OpenRegister the owner of the MDM / data-governance surface,
including the steward **actions** that turn scored objects into governed master
data. Follow-on #B already landed the reversible-merge backend in OR
(`MergeService` + `MergeController` exposing `POST /api/objects/merge/preview`,
`/execute`, `/{id}/reverse`, and `ObjectsMergedEvent`). Follow-on #3 landed the
read-only steward surface (Data Quality, Duplicate Candidates, Master-entity /
golden-record views). What is still missing is the **action UI**: today a steward
can *see* a candidate duplicate pair but cannot *merge* it from OpenRegister —
the merge wizard still lives only in pipelinq. This change (#C) generalises
pipelinq's merge wizard into OR so the merge/reverse actions live where ADR-045
says they belong, letting pipelinq drop its app-local copy in #D.

## What Changes

- Add a **merge wizard modal** (`src/modals/mdm/MdmMergeWizardModal.vue`, its own
  file per the modal-isolation gate) launched from `DuplicatesIndex` for a
  selected candidate pair. It calls `merge#preview`, shows the post-merge golden
  record, per-attribute provenance and the reversal deadline, collects a merge
  reason, then calls `merge#execute` and refreshes the candidate list.
- Add a **reverse-merge action** surfaced in a new **Merge Operations** list view
  (`src/views/quality/MergeOperationsIndex.vue`) that lists recent
  `mergeOperation` audit rows and offers "Reverse" while the operation is still
  within its reversal window, calling `merge#{id}/reverse`.
- Add store actions to `qualityStore` (`src/store/modules/quality.js`):
  `previewMerge`, `executeMerge`, `fetchMergeOperations`, `reverseMerge` — thin
  `@nextcloud/axios` wrappers over the #B endpoints (no new backend code).
- Register the new view + modal in `src/registry.js`, add the manifest page +
  navigation entry in `src/manifest.json`, and add English i18n source strings.
- Add gate-26 visual-coverage + a Playwright e2e for the new view and modal.
- **No backend changes.** Conflict-resolution (manual per-attribute survivorship
  override) is explicitly **out of scope** — see DEFERRED_QUESTIONS; OR has no
  stored per-object override primitive today (`SurvivorshipResolver` auto-resolves
  from trust tiers), so a real override needs a backend follow-on
  (`mdm-survivorship-override`), not a UI-only shim.

## Capabilities

### New Capabilities

- `mdm-merge-ui`: The steward-facing merge action surface in OpenRegister — a
  reversible-merge wizard launched from the duplicate-candidates view and a
  merge-operations list with an in-window reverse action, both consuming the
  existing #B merge endpoints. Covers preview/confirm/execute/reverse UX, store
  actions, reason capture, reversal-window gating, i18n and visual/e2e coverage.

### Modified Capabilities

<!-- None. #B's backend behaviour and #3's read-only views are unchanged; this
     change only adds new frontend capability. No existing spec's requirements
     change. -->

## Impact

- **Frontend (new):** `src/modals/mdm/MdmMergeWizardModal.vue`,
  `src/views/quality/MergeOperationsIndex.vue`.
- **Frontend (modified):** `src/views/quality/DuplicatesIndex.vue` (launch
  wizard from a pair), `src/store/modules/quality.js` (merge actions),
  `src/registry.js`, `src/manifest.json`, `src/l10n/` (English source strings),
  `tests/e2e/` (+ `tests/e2e/visual/` baseline).
- **Backend:** none consumed beyond the already-merged #B endpoints
  (`merge#preview`, `merge#execute`, `merge#reverse`) and the `mergeOperation`
  audit rows they persist; a read of merge operations reuses OR's generic object
  read surface for the merge register/schema.
- **Downstream:** unblocks pipelinq #D (delete app-local
  `MdmMergeWizardModal.vue` and deep-link to OR instead).
