---
kind: code
depends_on: [dedup-check-before-create]
---

# Proposal: duplicate-merge-and-dismissed-pairs

## Summary

Finding two records of the same person is one capability. Merging them so a
human decides which value survives, and remembering the pairs a human has
already ruled out, are two more, and without them a deduplication screen is
offered once and never opened again. This change adds the per-field merge
choice, the dismissed pair, and the soft uniqueness alert an administrator
declares on a single field.

## The rows this closes

### Row 5.14, party deduplication and merge with per-field selection, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 5.14`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **5.14** | 5.14 | Party deduplication and merge with per-field selection | no | unread | discovery D-freescout-41 |
```

- ledger note, verbatim:

> Row 2.24 asks about duplicate cases. Nothing finds two records of the same person, and nothing merges them field by field with a refusal when the merger cannot see every domain involved.

### Row 5.15, mark a reviewed pair as not a duplicate, so it stops being offered, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 5.15`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **5.15** | 5.15 | Mark a reviewed pair as not a duplicate, so it stops being offered | no | unread | discovery D-freescout-41 |
```

- ledger note, verbatim:

> Without this a deduplication screen offers the same false pair every week until people stop reading it. It is what makes row 5.14 usable rather than a second capability on top of it.

### Row 11.42, alert when a nominated field's value already exists on another record, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.34`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.42** | 11.34 | Alert when a nominated field's value already exists on another record | no | unread | discovery D-request-tracker-17 |
```

- ledger note, verbatim:

> Nothing lets an administrator nominate a field as effectively unique and get a warning on save. A second case on the same KvK number is found later or never.

## What the competitor evidence is

All three rows are among the 98 promoted under decision D1, and for those
the corpus is explicit about what its competitor columns hold. Quoted from
`procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

So no competitor claim is made here. The cross-reference column carries the
discovery findings `D-freescout-41` for 5.14 and 5.15 and
`D-request-tracker-17` for 11.42, and those are the only external pointers
the register holds for them.

## ADRs

- ADR-012 (deduplication of capability, company-wide): before proposing a
  new capability, search OpenRegister for overlap and justify the addition
  against what exists. The overlap here is real and named below:
  `specs/mdm-merge` already merges, and this change adds the human choice
  on top of it rather than a second merge path.
- ADR-031 (schema-declarative business logic): the nomination of a field as
  effectively unique is a schema annotation, not a branch in a controller.
- ADR-022 (apps consume OpenRegister abstractions): a leaf app declares
  which fields it nominates and renders the screens. It does not carry its
  own matcher, its own merge or its own dismissal store.
- ADR-023 (action-level authorization): merging two records and dismissing a
  pair are actions, gated by a declared group rather than by an `isAdmin()`
  check.

## What openregister builds

- A merge that takes a per-field decision. `specs/mdm-merge` computes the
  survivor with `SurvivorshipResolver` and offers no choice; the preview
  gains an explicit field selection and execution applies exactly what the
  preview was approved with.
- A refusal when the merger cannot see everything being merged. The preview
  already rejects a pair the caller cannot read; a merge of records carrying
  properties the caller cannot read is refused too, rather than quietly
  merging a domain out of sight.
- A dismissed pair. Two objects are recorded as reviewed and not the same,
  by whom and why, and the pair stops being offered by the scorer until the
  data behind the judgement changes.
- A soft uniqueness alert. A property is nominated as effectively unique,
  and a save whose value already exists elsewhere returns the record that
  holds it. It is a warning by default, on create and on update, which is
  the half `dedup-check-before-create` leaves open: that change declares
  `onCreate` only.

## What dossiq consumes

dossiq calls the check, renders the side-by-side merge screen and the
dismissal action, and declares which fields it nominates (KvK number, BSN,
e-mail address). No dossiq slug exists for this on dossiq `development`, so
it is to be specified in dossiq. Beside dossiq: humaniq (an applicant
entered twice), pipelinq (a lead), opencatalogi (a publication).

## Size

M. A handful of tasks over two existing specs, one of them the merge
service that already has preview, execute and reverse.

## The specs this extends

- `specs/mdm-merge`, requirements "Entity-type-agnostic merge preview" and
  "Atomic reversible merge execution".
- `specs/duplicate-detection`, requirement "Declarative duplicate detection
  over a register/schema", and the change `dedup-check-before-create` which
  adds the create-time check this one widens to every save.
