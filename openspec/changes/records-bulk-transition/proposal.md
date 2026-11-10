---
kind: code
depends_on: [bulk-action-jobs]
---

# Proposal: records-bulk-transition

## Summary

A case handler selects forty requests on a list and moves them all to
"in behandeling" in one act. Each record goes through the same lifecycle move
a single record does: its guards, its required inputs, its actions and its
audit entry. The job shows which records moved, which were skipped because
they were already there, and which were refused and why.

## Halves this closes

This is the OpenRegister half of nextcloud-vue's merged change
`index-bulk-edit-and-transitions` (nextcloud-vue `development` e487bc8). It
has no row in OpenRegister's matrix; the owner moves pass of 28 Sep 2026 handed
it here after the OpenRegister lane had finished. Nextcloud-vue writes:
"The OpenRegister half of this change is two more: `set-field` (one property,
one value, validated per object like a save) and `transition` (one lifecycle
action, guarded per object like `POST /api/objects/{id}/transition`). Listed
for the openregister lane."

The `set-field` half already exists: `openregister:set-properties`
(`lib/BulkAction/SetPropertiesAction.php`, shipped by #3742 under the open
change `bulk-action-jobs`) writes the same properties on every selected object
through `patchObject()`, the save path. Nextcloud-vue's sentence "Its registry
holds two actions today" was read before that shipped. Only `transition` is
written here.

The rows behind nextcloud-vue's change are opencatalogi `pub-bulk` and buildiq
`data-bulk-edit`.

## What changes

- A bulk action `openregister:transition` with parameters `action` (the
  lifecycle action) and `data` (its inputs, the same for every object).
- Each object goes through `TransitionEngine::transition()` as the job's
  actor, so guards, inputs, lifecycle actions and audit behave exactly as for
  a single move.
- An object already in the target state is `skipped`; an object whose
  lifecycle refuses the move is `failed` with the engine's message.
- The preview (commit false) reports per object whether the move is available
  to the actor, using the same check as `GET /api/objects/{id}/available-actions`.

## Out of scope

- Different inputs per object.
- Undo of a bulk transition. A lifecycle move is not reversed by writing old
  values back; `undo-a-bulk-action` does not cover it.

## Impact

- New `lib/BulkAction/TransitionAction.php`, registered in
  `lib/Listener/BulkActionRegistrationListener.php`.
- `lib/Service/Lifecycle/TransitionEngine.php` (an explicit actor).
