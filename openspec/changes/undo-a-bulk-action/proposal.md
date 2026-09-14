---
kind: code
depends_on: [bulk-action-jobs]
---

# Proposal: undo-a-bulk-action

## Summary

A bulk action is the one act in the product whose mistakes scale. Closing a
hundred cases with the wrong filter is a hundred manual corrections, done by
hand, by the person who is already having a bad day. `bulk-action-jobs`
makes the act a recorded job with a preview and a per-member outcome, and
stops there. This change gives the job an inverse.

## The row this closes

### Row 2.41, undo a bulk action, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 2.35`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.41** | 2.35 | Undo a bulk action | no | unread | corpus 2.6 |
```

- ledger note, verbatim:

> BulkStatusTransitionService pairs a preview with an execute and has no inverse and no stored batch record. A mistake on a hundred cases is a hundred manual corrections.

## What the competitor evidence is

The row is one of the 98 promoted under decision D1. The corpus says what
its competitor columns hold, verbatim:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here. The cross-reference the register carries
is `corpus 2.6`, which is the bulk action row itself, not a reading of
another product.

## ADRs

- ADR-031 (schema-declarative business logic): whether an action is
  reversible is declared beside the action, not decided in the job class.
- ADR-022 (apps consume OpenRegister abstractions): the reversal runs in
  OpenRegister over the object model, so every app that has a bulk action
  gets the inverse without writing one.
- ADR-005 (security, per-object authorization): a reversal is a write on
  every member, and each write is authorised for the reverting caller
  rather than inherited from the original actor.

## What openregister builds

- A member outcome that keeps what it needs to go back. Each per-member
  outcome records the properties the action changed with their prior
  values, bounded by a declared size so a job cannot become an archive.
- A reversal that is itself a job. Reversing writes a new job with the
  original as its cause, runs under the same ceiling, the same preview and
  the same per-member reporting, and is subject to the same authorization.
  The audit trail of every member then reads forward: changed, then
  reversed, by whom and why.
- A reversal window and an honest refusal. An action declares whether it is
  reversible and for how long. A member that changed after the original job
  is reported as not reversible and is left alone, because silently
  overwriting somebody else's later edit is a worse outcome than the
  mistake being undone.
- An action that cannot be undone says so before it runs. A destruction or
  a dispatched message is not reversible, and the preview says which of the
  two kinds this job is.

## What dossiq consumes

dossiq renders the reversal beside the job it belongs to and declares which
of its own bulk actions are reversible. No dossiq slug exists for this on
dossiq `development`, so it is to be specified in dossiq. Beside dossiq:
every app with a list surface, notably pipelinq, humaniq, decidiq and
keepiq.

## Size

M. The job record, the inverse, the window and the refusal, over a change
already in flight.

## The spec this extends

`bulk-action-jobs`, requirements REQ-BAJ-001 (the job, its selection and
its per-member outcome) and REQ-BAJ-002 (the preview).
