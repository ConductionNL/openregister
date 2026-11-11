# Design: records-change-held-for-approval

Read at openregister development 0ca409ee04.

## D-1: a change gate is a second gate kind in the existing dialect

`ApprovalChainGateListener` subscribes to `ObjectUpdatingEvent` and matches a
transition named by the schema's `x-openregister-approval-chains` entry. A new
`gate.change` form is matched by a sibling listener on `ObjectCreatingEvent` and
`ObjectUpdatingEvent`: it compares the incoming data with the stored object and fires
when a listed property changes (or any property, when the list is empty). The chain
annotation validator (`lib/Service/ApprovalChainAnnotationInstaller.php` and the
schema validator it feeds) accepts the new form and refuses an entry that gates both
a transition and a change.

Exempt callers are declared on the gate (`exempt: [groups]`), so a migration or a
system sync can write without approval when the administrator says so. A system
write with no declared exemption is held like any other.

## D-2: the pending change is a draft

The listener stops the write the way `HookStoppedException` already stops one from a
listener (`lib/Listener/UniqueConstraintListener.php` uses that path), but instead of
an error it hands the incoming delta to `DraftService` (from `records-draft-versions`)
as a draft with key `pending-<n>` and the submitter as creator. The controller turns
that into 202 with `{pendingChange: key}`.

A gated create has no live object. The draft row carries a reserved object uuid and a
null base version; `DraftService::promote()` on such a draft creates the object with
that uuid through `SaveObject`. Because drafts live in their own table, no list,
count, facet or search sees it.

## D-3: the decision runs on the task engine

The chain's template starts a task sequence for the pending change, as it does for a
gated transition today. `TaskSequenceDecisionGuard` enforces separation of duties
against the acting identity and `on_behalf_of`, on by default for an approval, so the
submitter, or a delegate acting for them, is refused with an honest reason.

`ApprovalChainAdvanceListener` handles `TaskSequenceCompletedEvent`. For a change
gate, `onApprove: applyChange` promotes the draft; a rejecting outcome discards it and
stores the rejection comment on the audit entry. Promotion runs full validation, so a
pending change that no longer fits the record is refused and reported to the approver.

## D-4: one pending change per record per gate

A second gated write to a record with an open pending change for the same gate is
refused with 409 naming the open pending change. Two pending changes on one field
would make the second approver approve something the first already overwrote.

## Declarative-vs-imperative decision

Declarative: the gate is part of `x-openregister-approval-chains` on the schema. The
listener and the draft store are platform code shared by every schema.

## Risks

- Held writes break a client that expects 200. Only schemas that declare a change
  gate answer 202, and the gate is opt-in.
- A pending create reserves a uuid another client might guess. The uuid is random
  and reads of it answer 404 until approval.
