---
kind: code
depends_on: []
---

# Proposal: platform-search-provider

## Summary

`OCP\Search\IProvider` is the interface that puts a record in the
Nextcloud search bar. OpenRegister implements one for every object.
Results carry the owning app's icon only when that app claimed a deep
link, and no leaf app can be filtered to on its own. This change gives
each leaf app its own provider identity over the one implementation, and
lets a schema declare what a result says.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`: "Deck registers fifteen platform integration points
in its Application class and dossiq registers only dashboard widgets, a
notifier and event listeners through the shared OpenRegister bootstrap.
The lane grepped each registration name across procest/lib/ and the shared
bootstrap and found ten missing". The first of the ten is the search
provider, and the consequence the lane names is that "a zaak does not
appear in the Nextcloud search bar".

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface, one PR series. The decision's own
line: "None of the ten is a feature to design: each is an interface the
platform publishes and a class that implements it." Nothing blocks it,
which is the point.

The lane is explicit that this is not a matrix row and is not proposed as
one, "because both products are Nextcloud apps so the comparison is not
available to any other system in the corpus".

## What openregister implements generically

- `ObjectsProvider` stays the one implementation of `OCP\Search\IProvider`
  for every register object, as `unified-search-provider` specifies:
  RBAC and tenancy honoured inside the query, the `searchable` flag per
  schema, chunked pipeline queries, per-app labelling, deep-linked result
  URLs, an excerpt subline and a cursor.
- On top of that, a **provider identity per claiming app**. An app that
  claims a (register, schema) pair gets its own provider id, its own name
  and its own order in the search bar, so a user can search that app
  alone. The query path underneath is the same one.
- A **result declaration on the schema**: which property is the title,
  which is the subline, and which date orders it. Without it the provider
  guesses, and a guess reads as a bug in whichever app owns the schema.

## What a leaf app declares

dossiq declares, per schema, that its registers belong to it and what a
result should say. It registers no provider, writes no query and holds no
index.

## What exists and what is missing

`unified-search-provider` carries the provider, the access rules, the
chunking and the labelling. What it does not carry: a provider id per
claiming app, so the search bar cannot be narrowed to one app, and a
declared title, subline and ordering date, so every app gets the
provider's guess.

## Impact

- Extends: `unified-search-provider`.
- Affected code: the provider registration, the deep link registry's claim
  lookup, the result builder.
- Backwards compatible: an unclaimed pair keeps the OpenRegister identity
  and today's subline.
- Size: S.

## Out of scope

- Full text over file content, which `unified-search-file-content` and
  `content-search-index` carry.
- Searching timeline entries, which `timeline-entries-are-records` carries.
