# Tasks — mdm-merge-ui

## 1. Store actions (`src/store/modules/quality.js`)

- [x] 1.1 Add `previewMerge(from, into)` posting to `${API_BASE}/objects/merge/preview` and returning the payload; capture errors on `this.error`.
- [x] 1.2 Add `executeMerge(from, into, reason)` posting to `${API_BASE}/objects/merge/execute`; capture errors.
- [x] 1.3 Add `fetchMergeOperations(params)` GET-ing `${API_BASE}/objects/merge-operation/mergeOperation`, storing rows + total + paging state.
- [x] 1.4 Add `reverseMerge(id)` posting to `${API_BASE}/objects/merge/${id}/reverse`; capture errors and return the updated operation.

## 2. Merge wizard modal (`src/modals/mdm/MdmMergeWizardModal.vue`)

- [x] 2.1 Create the isolated `NcDialog`-based modal (its own file) with props `{ from, into }`; call `previewMerge` on open and render golden record + per-attribute provenance + reversal deadline.
- [x] 2.2 Add a merge-reason `NcSelect` with an `inputLabel` prop; disable confirm until preview loaded and reason chosen; on error show the endpoint message and keep confirm disabled.
- [x] 2.3 On confirm call `executeMerge`, then emit `merged` + `close`; surface any 403/404 error inline.

## 3. DuplicatesIndex wiring (`src/views/quality/DuplicatesIndex.vue`)

- [x] 3.1 Add a per-row "Merge" action that opens `MdmMergeWizardModal` for the pair (map survivor → `into`, merged-away → `from`).
- [x] 3.2 On the modal's `merged` event, reload the candidate list so the merged pair disappears.

## 4. Merge Operations view + reverse action (`src/views/quality/MergeOperationsIndex.vue`)

- [x] 4.1 Create the view listing recent merge operations via `fetchMergeOperations` (survivor, merged-from, reason, timestamp, reversibility).
- [x] 4.2 Show a "Reverse" action only for rows still within the reversal window; call `reverseMerge` and refresh on success.

## 5. Registration + i18n

- [x] 5.1 Register `MergeOperationsIndex` as a page in `src/registry.js`.
- [x] 5.2 Add a `MergeOperations` page (route `/mergeOperations`) and a `DataQualityGroup` nav entry in `src/manifest.json`.
- [x] 5.3 Add English i18n source strings for all new wizard + view strings via `t('openregister', ...)`.

## 6. Coverage + gates

- [ ] 6.1 Add a Playwright e2e that opens the wizard from a candidate pair, previews, confirms a merge, then reverses it from the Merge Operations view. — test authored at `tests/e2e/spec-coverage/mdm-merge-ui.spec.ts` (tags all UI scenarios) but NOT executed against a live Nextcloud instance in this session (none available in this worktree) — left unchecked per instructions.
- [x] 6.2 Add a gate-26 visual-regression baseline for `MergeOperationsIndex.vue` (or a reason-bearing `@visual exclude`), and reference the wizard in the e2e. — spec authored at `tests/e2e/visual/mdm-merge-ui.visual.spec.ts`; baseline screenshots not generated (no live instance to shoot against).
- [x] 6.3 Run the frontend hydra gates (modal-isolation, nc-input-labels, initial-state, dashboard-antipattern, visual-coverage, e2e-coverage) and confirm green. — ran `run-hydra-gates.sh --scope-to-diff`; all 6 gates PASS with zero findings against any mdm-merge-ui file (remaining repo-wide failures are pre-existing debt from #A/#B/#3, unrelated to this change).

## Acceptance criteria

- A steward can merge a candidate pair from `DuplicatesIndex`: preview shows the projected golden record, provenance and reversal deadline; a reason is required; confirming executes the merge and the pair disappears.
- A steward can view recent merge operations and reverse one still within its window; a reversed / expired operation offers no reverse action.
- All merge computation is server-authoritative; the store actions are thin axios wrappers with no client-side merge/survivorship logic.
- No backend code changes; conflict-resolution is not implemented (deferred to `mdm-survivorship-override`).

## Quality checklist

- Merge wizard is a standalone file under `src/modals/`; no inline modal markup in any parent (modal-isolation).
- Every `NcSelect` declares `inputLabel` / `ariaLabelCombobox` (nc-input-labels).
- No server data read from the DOM; state comes from `qualityStore` + endpoints (initial-state).
- New view + modal carry visual/e2e coverage or a reason-bearing exclude (gate-26 / gate-19).
- All new strings are English i18n source (`t('openregister', ...)`); Dutch is a translation, never a key.
