---
kind: code
depends_on: [archiving-as-a-process-with-sign-off, data-subject-rights-across-the-instance]
---

# Proposal: anonymising-as-an-archival-outcome

## Summary

The archival vocabulary has three words: keep, keep forever, destroy. A
municipality that wants the case for its statistics and not the person in it
has to choose between keeping the personal data it no longer needs and
destroying a record it still uses. Anonymising is the fourth word, and it
has to be a configured outcome of a result type, not a manual operation
somebody remembers to run.

## The row this closes

### Row 13.32, anonymising as a configured alternative to deletion, chosen per outcome, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 13.25`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.32** | 13.25 | Anonymising as a configured alternative to deletion, chosen per outcome | no | unread |  |
```

- ledger note, verbatim:

> anonymisationAtParts has zero readers, and ArchivalNominationDeriver's whole action vocabulary is bewaren, blijvend_bewaren and vernietigen. There is no anonymise branch to configure.

## What the competitor evidence is

The row is one of the 98 promoted under decision D1, and the corpus states
what its competitor columns hold, verbatim:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here, and the row carries no cross-reference in
the register.

## ADRs

- ADR-031 (schema-declarative business logic): which properties are
  anonymised, and with what, is declared on the schema beside the retention
  annotation. It is not a script per register.
- ADR-003 (openregister, immutable hash-chained audit trail): anonymising is
  an irreversible act on stored data, so it is recorded on the chained trail
  with what it touched and what it left.
- ADR-022 (apps consume OpenRegister abstractions): the retention engine
  owns the outcome vocabulary. A leaf app declares the result type; it does
  not anonymise records itself.
- ADR-005 (security): an anonymised record must not be re-identifiable
  through the trail, the search index or a derived projection, so the act
  reaches all of them or it is not an anonymisation.

## What openregister builds

- A fourth archival action. `anonymiseren` joins keep, keep forever and
  destroy as a nomination the deriver can produce and the destruction list
  can carry. A reviewer answering an entry may answer with it, beside
  destroy, retain and transfer.
- A declared anonymisation profile. A schema declares which properties are
  anonymised and how each is treated: removed, replaced with a fixed value,
  replaced with a stable pseudonym, or generalised to a coarser value such
  as a year or a postcode district. What is left is what the statistics
  need.
- An outcome that chooses it. A result type or an equivalent declared
  outcome names the archival action, so the choice is made once in
  configuration and applied by the retention pass rather than by somebody
  remembering.
- A run that says what it left. Anonymising a record reports the properties
  it changed and the properties it deliberately kept, and the report is
  readable afterwards, because "what is still in there" is the question an
  auditor asks.
- An anonymisation that reaches everything derived. The search index, the
  history projection and any derived copy are updated in the same act. The
  audit trail keeps the fact that the record was anonymised and loses the
  values, which is the one place the general rule about preserving history
  gives way.
- A refusal where it would be a lie. A record under a legal hold, or one
  whose properties the caller may not read, is not anonymised, and the
  refusal names the reason.

## What dossiq consumes

dossiq declares the anonymisation profile per case type and names
`anonymiseren` on the result types that call for it. The register names no
dossiq slug for this row and none exists on dossiq `development`, so the
consuming half is to be specified in dossiq. Beside dossiq: humaniq for
personnel records, learniq, zaakafhandelapp, and anonymiq, which is the
Python ExApp for document anonymisation and is a neighbouring capability
rather than this one.

## Size

M. One word in a vocabulary, one declared profile, one execution path that
has to reach every derived copy.

## The specs this extends

- `specs/retention-management` and the change
  `archiving-as-a-process-with-sign-off` (REQ-APS-001 the nomination,
  REQ-APS-003 whose review answer is destroy, retain or transfer and has no
  fourth answer).
- `specs/archival-annotation-vocabulary` and
  `specs/archival-destruction-workflow`, which carry the three-word action
  vocabulary the ledger note names.
- `specs/gdpr-data-subject-rights`, whose `erase()` already offers a
  `pseudonymise` mode selecting between field-level pseudonymisation and
  whole-object soft delete. That is the execution primitive this change
  declares an archival outcome on top of, which is why it is reused rather
  than rebuilt.
