---
kind: code
depends_on: [records-draft-versions]
---

# Proposal: records-change-held-for-approval

## Summary

A functional administrator marks fields of a record type as sensitive, such as a
bank account number on a supplier. When someone changes one of them, the change does
not take effect. It waits as a pending change until a second person approves it, and
the person who made the change cannot approve it themselves. A rejected change is
never stored on the record. The same holds for a new record on a type that requires
approval before it exists.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| buildiq | acc-four-eyes-data-change | Require a second person to approve a change to sensitive master data before it takes effect | partial |
| buildiq | logic-approval-before-save | Hold a submitted record until it is approved, so a rejected submission is never stored | no |

Both rows are in buildiq's matrix, owned here because built.owner is
ConductionNL/openregister.

**acc-four-eyes-data-change.** Demand: tender,
https://www.tenderned.nl/aankondigingen/overzicht/310787 (VGGM wens W10). No
competitor is rated yes. The matrix note: "approval follows the write; the row asks
for the change to wait for the second person".

**logic-approval-before-save.** Demand: changelog,
https://github.com/nocobase/nocobase/releases/tag/v1.9.0. No competitor is rated yes.
On its own this row would have been deferred (a changelog row only, logic area). It is
in this change because holding a new record is the same pending-change store as
holding an edit.

## Why

Approvals run after the fact. buildiq's "Require approval" compiles to
`x-openregister-approval-chains` (buildiq `lib/Service/AutomationCompilerService.php:783-795`)
and fires on a trigger after the record is written. In Open Register the only thing an
approval chain can hold back is a lifecycle transition: `ApprovalChainGateListener`
(`lib/Listener/ApprovalChainGateListener.php`) refuses a transition with
`approval-chain-pending` until the object's approval sequence completes, and
`ApprovalChainAdvanceListener` performs the transition on approval. A change to a
field is never held, and a new record is always stored.

The pieces to hold one exist. `records-draft-versions` (this pass) keeps a delta
beside the live object and promotes it. Task sequences carry separation of duties,
on by default for an approval (`lib/Service/Task/TaskSequenceDecisionGuard.php`), so
the submitter cannot decide their own change.

## What changes

- An `x-openregister-approval-chains` entry may gate a change instead of a
  transition: `gate: {change: {properties: [...], on: ["update", "create"]}}`.
  An empty property list means any change.
- A write that touches a gated property, by a user who is not exempt, is stored as a
  pending change (a draft with key `pending-<n>`) and answered with 202 and the
  pending change's key. The live record is unchanged.
- A gated create is stored as a pending change with no live object. Nothing is
  returned by reads, lists or search until it is approved.
- The chain's approval sequence starts for the pending change. On approval the draft
  is promoted through the normal save path; on rejection it is discarded with the
  reason. Either way the audit trail keeps both the request and the decision.
- The object page shows a pending change with what it changes, who asked, and the
  approver's task. The approver decides in the task inbox they already use.

## Consumers

- buildiq compiles its "Require approval" option to a change gate instead of an
  after-write trigger when the maker asks for it before the change.
- shillinq (supplier bank details), humaniq (salary data), dossiq (sensitive master
  data on a case type) declare the gate on their schema.

## ADRs

- hydra ADR-031: the gate is declared on the schema in the existing approval-chain
  dialect.
- hydra ADR-098 (fleet workflow convergence) and ADR-065: the decision runs on the
  one task engine, as the consolidated approval chains already do.
- hydra ADR-005: the submitter cannot approve their own change; the gate fails closed
  when the chain cannot be provisioned, as `ApprovalChainGateListener` does today.
- openregister ADR-003: request, decision and application are audit facts.

## Impact

- Extends `approval-workflow`.
- Affected code: the approval-chain annotation validator, a change-gate listener on
  `ObjectCreatingEvent` and `ObjectUpdatingEvent` beside `ApprovalChainGateListener`,
  `ApprovalChainAdvanceListener` (`onApprove: applyChange`), `DraftService`,
  `ObjectsController` (202 answer), `src/views/object/ObjectDetails.vue`.
- Backwards compatible: chains that gate a transition behave as today.
- Size: M.

## Out of scope

- Approving a change to a file's content.
- Several approvers in parallel or in sequence beyond what the chain dialect already
  offers; the change reuses the dialect as it is.
