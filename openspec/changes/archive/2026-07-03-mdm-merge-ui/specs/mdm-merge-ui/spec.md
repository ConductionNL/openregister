## ADDED Requirements

### Requirement: Steward launches a merge wizard from a duplicate candidate pair

The Duplicate Candidates view SHALL let a steward launch a merge wizard for a
single candidate pair. The wizard SHALL be an isolated modal component under
`src/modals/` (one modal per file) and SHALL be opened with the pair's two
object identifiers, mapping the surviving object to the merge `into` and the
merged-away object to `from`.

#### Scenario: Merge action is offered per candidate pair

- **WHEN** the Duplicate Candidates view renders a candidate pair with a register
  and schema selected
- **THEN** a "Merge" action is shown for that pair
- **AND** activating it opens the merge wizard modal with the pair's two object
  identifiers pre-selected as `from` and `into`

#### Scenario: Wizard is a standalone modal, not inline markup

- **WHEN** the merge wizard is implemented
- **THEN** its `NcDialog`/`NcModal` markup lives in its own file under `src/modals/`
- **AND** `DuplicatesIndex.vue` imports and renders it rather than declaring the
  dialog markup inline

<!-- @e2e exclude Structural/static-layout requirement (file placement + import wiring), verified by the modal-isolation gate and code review, not runtime UI behaviour. -->

### Requirement: Merge wizard previews the post-merge golden record before executing

On open, the wizard SHALL call the merge preview endpoint
(`POST /apps/openregister/api/objects/merge/preview`) with `{ from, into }` and
SHALL display the previewed `postMergeGoldenRecord`, per-attribute
`attributeProvenance`, and the `reversalDeadline` returned by the endpoint. The
preview SHALL be side-effect-free: no object or merge operation is written until
the steward confirms.

#### Scenario: Preview renders the projected survivor

- **WHEN** the wizard opens for a valid pair
- **THEN** it requests a merge preview for `{ from, into }`
- **AND** it displays the post-merge golden record fields, each field's winning
  source from `attributeProvenance`, and the reversal deadline
- **AND** no merge is executed yet

#### Scenario: Preview failure surfaces an error and blocks confirmation

- **WHEN** the preview request returns a 403 or 404 error
- **THEN** the wizard shows the returned error message
- **AND** the confirm/execute control remains disabled

### Requirement: Merge wizard captures a reason and executes an auditable merge

The wizard SHALL require the steward to supply a merge reason before executing.
On confirm it SHALL call the execute endpoint
(`POST /apps/openregister/api/objects/merge/execute`) with `{ from, into, reason }`,
and on success SHALL close and refresh the Duplicate Candidates list so the
merged pair no longer appears. Any reason `NcSelect` SHALL carry an `inputLabel`
(or `ariaLabelCombobox`) for accessibility.

#### Scenario: Confirming a merge executes it and refreshes candidates

- **WHEN** the steward enters a reason and confirms
- **THEN** the wizard posts `{ from, into, reason }` to the execute endpoint
- **AND** on success it closes and the Duplicate Candidates list is reloaded

#### Scenario: Reason is mandatory

- **WHEN** the reason field is empty
- **THEN** the confirm/execute control is disabled

#### Scenario: Reason selector is accessibly labelled

- **WHEN** the reason is chosen via an `NcSelect`
- **THEN** that `NcSelect` declares an `inputLabel` (or `ariaLabelCombobox`) prop

### Requirement: Steward reviews recent merge operations in a dedicated view

OpenRegister SHALL provide a Merge Operations view that lists recent
`mergeOperation` audit rows for the merge register/schema, read through the
generic object read surface
(`GET /apps/openregister/api/objects/{register}/{schema}`). Each row SHALL show
at least the survivor, the merged-away object(s), the reason, the merge
timestamp, and whether the operation is still reversible.

#### Scenario: Merge operations list renders audit rows

- **WHEN** the steward opens the Merge Operations view
- **THEN** it lists recent merge operations with survivor, merged-from,
  reason, timestamp, and reversibility state

#### Scenario: View is registered and navigable

- **WHEN** the app manifest and registry are built
- **THEN** the Merge Operations view is a registered page with a navigation entry
  under the data-quality group

### Requirement: Steward reverses a merge within its reversal window

The Merge Operations view SHALL offer a "Reverse" action only for operations
that are still reversible (within their reversal window). The action SHALL call
the reverse endpoint (`POST /apps/openregister/api/objects/merge/{id}/reverse`)
and on success SHALL refresh the list so the operation is shown as no longer
reversible.

#### Scenario: Reverse offered only within the window

- **WHEN** a merge operation is still within its reversal window
- **THEN** a "Reverse" action is shown for it
- **AND** for an operation whose window has closed or that was already reversed,
  no "Reverse" action is shown

#### Scenario: Reversing restores the objects and updates the row

- **WHEN** the steward reverses a reversible operation
- **THEN** the view posts to the reverse endpoint for that operation id
- **AND** on success the list is refreshed and the operation is shown as reversed
  / no longer reversible

### Requirement: Merge store actions wrap the merge endpoints without new backend logic

`qualityStore` SHALL expose `previewMerge(from, into)`, `executeMerge(from, into,
reason)`, `fetchMergeOperations(params)`, and `reverseMerge(id)` as thin
`@nextcloud/axios` wrappers over the existing merge and generic-object endpoints.
These actions SHALL add no merge, survivorship, or conflict-resolution logic
client-side — all merge computation stays server-authoritative in OpenRegister.

#### Scenario: Store actions delegate to the OR endpoints

- **WHEN** `previewMerge`, `executeMerge`, or `reverseMerge` is invoked
- **THEN** it issues the corresponding request to the OpenRegister merge endpoint
  and returns the server response
- **AND** it performs no survivorship or conflict resolution in the browser

<!-- @e2e exclude Store-layer request/response contract, covered by Jest unit tests in src/store/modules/quality.spec.js (previewMerge/executeMerge/reverseMerge describe blocks) per the UI-e2e/API-unit test-layer split; not a Playwright UI scenario. -->

#### Scenario: Errors are captured on the store

- **WHEN** a merge action request fails
- **THEN** the store records the returned error message and the caller can react
  to it

<!-- @e2e exclude Store-layer error-capture contract, covered by the "records an error on failure" Jest unit tests in src/store/modules/quality.spec.js for previewMerge/executeMerge/fetchMergeOperations/reverseMerge; not a Playwright UI scenario. -->

### Requirement: New merge surface carries visual and e2e coverage

The new Merge Operations view and the merge wizard modal SHALL each be covered by
a visual-regression baseline or an e2e workflow test (satisfying the gate-26
visual-coverage requirement for new views/major UI), or carry a reason-bearing
`@visual exclude` / `@e2e exclude` annotation.

#### Scenario: New view and modal are proven by tests

- **WHEN** the change is submitted
- **THEN** the Merge Operations view and the merge wizard modal are each
  referenced by a visual-regression spec or an e2e test, or annotated with a
  reason-bearing exclude

<!-- @e2e exclude Meta-requirement about coverage itself (satisfied by tests/e2e/visual/mdm-merge-ui.visual.spec.ts + tests/e2e/spec-coverage/mdm-merge-ui.spec.ts existing and tagging the other scenarios in this file); not itself a distinct runtime UI behaviour to drive. -->
