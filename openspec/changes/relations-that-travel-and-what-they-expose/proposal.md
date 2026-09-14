---
kind: code
depends_on: [relation-types-with-inverses, party-roles-beyond-the-requester]
---

# Proposal: relations-that-travel-and-what-they-expose

## Summary

A declared relation is worth more than a line on a detail page. Three things
follow from it and none of them exists: walking it to answer who else is
affected, stating it between two parties rather than between a record and a
party, and saying which fields it hands over when it crosses a domain
boundary. This change adds the walk with a prune, the party-to-party
relationship type, and the field set a link type exposes.

## The rows this closes

### Row 2.48, affected parties and objects derived by walking declared relations, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 2.42`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.48** | 2.42 | Affected parties and objects derived by walking declared relations | no | unread |  |
```

- ledger note, verbatim:

> Row 2.26 declares typed links. Nothing walks them to answer who else is affected, and nothing lets you prune a branch of that walk.

### Row 5.16, relationship type with a reciprocal label and a party type at each end, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 5.16`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **5.16** | 5.16 | Relationship type with a reciprocal label and a party type at each end | no | unread |  |
```

- ledger note, verbatim:

> Row 5.1 gives a party a role on a case. Nothing relates two parties to each other, so guardian and ward, or employer and employee, cannot be stated.

### Row 13.36, link type declaring which fields of the linked record it exposes, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 13.29`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.36** | 13.29 | Link type declaring which fields of the linked record it exposes | no | unread |  |
```

- ledger note, verbatim:

> Row 2.26 declares typed links. A link is all or nothing, so relating a Wmo case to a Jeugdwet case exposes everything or nothing rather than the two fields that were meant.

## What the competitor evidence is

All three rows are among the 98 promoted under decision D1, and the corpus
states, verbatim, what its competitor columns hold:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here, and none of the three rows carries a
cross-reference in the register.

## ADRs

- ADR-031 (schema-declarative business logic): a relation type, the fields
  it exposes and whether it may be walked are declared on the schema.
- ADR-022 (apps consume OpenRegister abstractions): the graph, the party
  relationship and the exposure rule live in the platform. A leaf app
  declares its types and renders the answer.
- ADR-005 (security): the walk is evaluated for the caller, never as the
  system, and a link that exposes a field set never widens what the reader
  may already see outside that link.
- ADR-010 (openregister, permission verbs): the exposure declared by a link
  type is a narrowing of read, not a new verb.

## What openregister builds

- An affected-set answer over the relation graph. Given a root object,
  return the objects and the parties reachable along the relation types the
  caller names, at a bounded depth, each with the path that reached it.
  `relation-types-with-inverses` REQ-RTI-005 already returns a bounded,
  typed, directed graph; this adds the part the row asks for on top of it:
  the answer is filtered to the affected kinds, it names the parties, and
  the caller prunes a branch.
- A prune that is recorded, not just applied. A branch excluded from the
  answer is reported as excluded with the relation type that was cut, so a
  notification list has a visible reason for who is not on it.
- A relation type between two parties. A relationship names its two ends by
  party kind, carries a label and a reciprocal label, and runs for a period.
  Guardian and ward, employer and employee, and gemachtigde and principal
  are then statements in the register rather than conventions in prose.
- A link type that declares its field set. A relation type MAY name the
  properties of the far record that the relation exposes. A reader holding
  no other access to the far record sees exactly those properties, and the
  rest reads as withheld rather than as absent.

## What dossiq consumes

dossiq declares its relation types and its party relationship types per case
type, renders the affected list on the case, and consumes the exposure
declaration for the cross-domain links between a Wmo and a Jeugdwet case.
The build plan names `relation-types-with-inverses` as the vehicle for the
first half and no dossiq slug exists for these three on dossiq
`development`, so the dossiq half is to be specified in dossiq. Beside
dossiq: decidiq, keepiq, pipelinq and stackiq for the graph, humaniq for
the party relationship.

## Size

L. A new mechanism on each of the three counts, sharing one traversal.

## The specs this extends

- `specs/referential-integrity`, through the change
  `relation-types-with-inverses` (REQ-RTI-001, REQ-RTI-002, REQ-RTI-005).
- `specs/row-field-level-security`, which already narrows a read by
  property. The exposure a link declares is evaluated beside it.
- `party-model`, through the change `party-roles-beyond-the-requester`
  (REQ-PRM-001), which gives a party a typed role on an object and stops
  short of a party holding a relationship to another party.
