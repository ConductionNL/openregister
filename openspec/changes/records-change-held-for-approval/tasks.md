# Tasks: records-change-held-for-approval

## 1. Declaration

- [x] 1.1 `gate.change` (properties, on, exempt) in the approval-chain dialect, with the refusal for an entry that gates both a transition and a change. Verify: annotation validator tests.

## 2. Holding

- [x] 2.1 Change-gate listener on `ObjectCreatingEvent` and `ObjectUpdatingEvent` storing the delta as a `pending-<n>` draft and stopping the write. Verify: `ChangeGateListenerTest` for a gated field, an ungated field, and an exempt group.
- [x] 2.2 202 answer with the pending change key from `ObjectsController` create, update and patch. Verify: API test that a gated PATCH answers 202 and the object reads unchanged.
- [x] 2.3 Gated create stored with a reserved uuid and no live object. Verify: API test that the uuid answers 404 and the list is unchanged.
- [x] 2.4 409 for a second gated write while one is open. Verify: API test.
      Built in `lib/Listener/ChangeGateListener.php` (registered last on both pre-write
      events, after validation, constraints and quota) and `ObjectsController::heldChangeAnswer()`.
      Unit-tested in `ChangeGateListenerTest` and `ObjectsControllerHeldChangeTest`; the API
      tests named under 2.2-2.4 need a live instance and are not written yet. OPEN for 3.2:
      promoting a pending change must pass this gate (it would be held again).

## 3. Deciding

- [ ] 3.1 Start the chain's task sequence for the pending change; separation of duties refuses the submitter. Verify: test that the submitter's approve answers 403 with the separation-of-duties reason.
- [ ] 3.2 `onApprove: applyChange` promotes the draft; rejection discards it with the comment on the audit entry. Verify: tests for approve, reject, and a promotion refused by validation.

## 4. Interface and docs

- [ ] 4.1 Pending change panel on `ObjectDetails.vue` with the delta, the submitter and a link to the approver's task. Verify: `tests/e2e/change-held-for-approval.spec.ts` changes a bank account, sees it pending, approves as a second user and sees it applied.
- [ ] 4.2 buildiq issue to compile "Require approval before the change" to a change gate (buildiq change, linked here).
- [ ] 4.3 `docs/` page on four-eyes approval of sensitive fields.

Acceptance:
- A rejected change never appears on the record.
- The submitter can never approve their own change.
