---
kind: code
depends_on: [search-quality-operators-and-facets]
---

# Proposal: search-over-history-and-an-administered-dictionary

## Summary

Search answers what a record is. Two questions it cannot answer are what a
record has ever been, and what a citizen's word means here. A list of every
case that passed through bezwaar at some point cannot be built, and an
administrator cannot teach the search that omgevingsvergunning and
bouwvergunning are the same thing to the person typing in the portal.

## The rows this closes

### Row 9.17, filters over what a case has ever been, not only what it is now, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 9.14`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **9.17** | 9.14 | Filters over what a case has ever been, not only what it is now | no | unread |  |
```

- ledger note, verbatim:

> statusRecord stores every transition as a queryable object with fromStatus, and process mining reads it. There is still no case list filter of the form was ever in status X, and giving this partial credit would double count row 10.4, which already scores that surface.

### Row 9.18, stopwords and synonyms for search, maintained by an administrator, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 9.15`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **9.18** | 9.15 | Stopwords and synonyms for search, maintained by an administrator | no | unread |  |
```

- ledger note, verbatim:

> Nothing lets an administrator teach the search that omgevingsvergunning and bouwvergunning are the same thing to a citizen typing in the portal.

## What the competitor evidence is

Both rows are among the 98 promoted under decision D1. The corpus states
what its competitor columns hold, verbatim:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here, and neither row carries a cross-reference
in the register.

## ADRs

- ADR-007 (openregister, a single built-in search backend): the built-in
  database search is the only backend, and adding one is an ADR-level
  decision. A dictionary is therefore a query-time expansion inside that
  backend, not an analyser configuration on an engine we do not run, and not
  a reason to reintroduce one.
- ADR-031 (schema-declarative business logic): the dictionary is
  administered data in a register, so it is exportable, auditable and
  translatable like any other configuration.
- ADR-009 (openregister, performance invariants): a history predicate is
  answered from an indexed projection, never by scanning the audit trail.
- ADR-022 (apps consume OpenRegister abstractions): the predicate and the
  dictionary live in the platform, so every app's list surface gets them.

## What openregister builds

- A history predicate on the object query. A filter of the form "was ever in
  state X", "was ever assigned to this person" and "changed this property
  between two dates", answered from an indexed projection of the lifecycle
  transitions, evaluated under the same access rules as the current-state
  query.
- An indexed projection to answer it. The transitions are already recorded;
  what is missing is a shape a list query can join. The projection is
  derived, rebuildable, and bounded by the same retention as the trail it
  derives from.
- A dictionary an administrator maintains. Synonym groups and stopwords per
  language, edited in an admin surface, versioned like other configuration,
  and applied at query time so a change takes effect without an index
  rebuild.
- An honest answer about what the dictionary did. A search that was expanded
  reports the terms it expanded to, so a result nobody expected has a
  visible reason.

## What dossiq consumes

dossiq offers the history predicate in its case-list filter bar and declares
the terms citizens use in its portal. The register names no dossiq slug for
either row and none exists on dossiq `development`, so the consuming half is
to be specified in dossiq. Beside dossiq: portaliq for the citizen-facing
search, opencatalogi and stackiq for the catalogue search, every app with a
list surface for the predicate.

## Size

M. One projection with its rebuild, one predicate on the query, one
administered register and its query-time expansion.

## The specs this extends

- `specs/zoeken-filteren`, the object query and its filter grammar, and the
  change `search-quality-operators-and-facets`, whose own "Out of scope"
  section says: "Stopword and synonym administration. The cluster name
  mentions stopwords; no candidate asks for them and ADR-007 leaves the
  analyser to the built-in backend." This row is the candidate that asks.
- `specs/object-lifecycle`, for the transitions the projection derives from.
