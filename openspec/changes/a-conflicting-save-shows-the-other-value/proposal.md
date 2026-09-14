---
kind: code
depends_on: []
---

# Proposal: a-conflicting-save-shows-the-other-value

## Summary

Two people edit the same record and the second save is refused. That is
already specified for PATCH. What is not specified is the only part the
second person cares about: what the other one wrote. A 409 that says
"version mismatch" sends them back to reload, compare by eye and retype.
This change puts the conflicting values in the refusal and applies the
assertion to every write, not only to PATCH.

## The row this closes

### Row 2.50, conflicting save refused with the other person's value shown, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 2.44`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.50** | 2.44 | Conflicting save refused with the other person's value shown | no | unread | corpus 2.27 |
```

- ledger note, verbatim:

> No If-Match, ETag or expected version exists on any write, so the last save wins silently. conflictRecord is declared in the offline sync fragment and has no reader. Row 2.27 answers the same problem with a lock, which is the heavier of the two designs.

## What the competitor evidence is

The row is one of the 98 promoted under decision D1. Quoted verbatim from
the corpus batch file:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here. The cross-reference is `corpus 2.27`, the
lock row, which the ledger note itself calls the heavier of the two designs
and which is answered in this repository by `run-scoped-object-locking`.

## ADRs

- ADR-002 (API): the conflict is an HTTP status with a body that explains
  itself, not a bespoke error envelope.
- ADR-005 (security): the conflicting values are filtered for the caller.
  A refusal is not a route to a property somebody may not read.
- ADR-022 (apps consume OpenRegister abstractions): the conflict shape is
  the platform's, so every app's form reads the same body.

## What openregister builds

- The conflicting values in the 409. The refusal names, per property, the
  value the caller sent, the value the caller read, and the value now
  stored, so a form can show the two side by side and offer a choice.
- Every write asserts, not only PATCH. `specs/objects-crud` specifies
  optimistic concurrency on PATCH. A full replace made with a stale read
  overwrites silently, which is the same defect with a different verb.
- A conflict the caller may read. A property the caller may not read is
  reported as conflicting without its value, so the refusal cannot be used
  to read a field that field-level security refuses.
- The refusal is recorded. A refused write leaves an audit entry naming
  both versions, so a support question about a lost edit has an answer.

## What dossiq consumes

dossiq renders the side-by-side choice on the case form and stops treating
409 as a generic failure. The register's `dossiq_half` for the neighbouring
lock row names `edit-lock-on-the-case-page`; this row has no dossiq slug on
dossiq `development`, so it is to be specified in dossiq. Beside dossiq:
every app with an edit form, and nextcloud-vue for the component that
renders the two values.

## Size

S. One response shape, one assertion widened, one audit entry.

## The spec this extends

`specs/objects-crud`, requirement "Partial object updates are protected
against lost updates", and its scenario "Conditional update via If-Match".
