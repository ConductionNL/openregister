---
kind: code
depends_on: []
---

# Proposal: platform-comments-entity

## Summary

`OCP\Comments\ICommentsManager` needs an entity collection before anything
can be commented on. OpenRegister registers one collection, `openregister`,
so every object's comments are filed under the platform's name rather than
under the app a user thinks they are in. This change gives each claiming
app its own collection over the same validation and the same storage, so a
comment on a zaak says zaak.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`. The third of the ten missing registrations is the
comments entity. Deck registers its own, which is why a Deck comment is
attributed to Deck everywhere the platform shows comments.

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface.

## What openregister implements generically

- The existing `CommentsEntityListener` keeps handling
  `OCP\Comments\CommentsEntityEvent` and keeps validating that an object
  exists, as `object-interactions` specifies.
- On top of it, an **entity collection per claiming app**, registered from
  the same listener, each validating against the objects of that app's
  claimed pairs. One listener, several collections.
- A **display name and icon per collection**, so a platform surface that
  lists comment sources names the app.
- The existing visibility rule is unchanged: a comment on an object is
  readable exactly by those who may read the object.

## What a leaf app declares

dossiq claims its (register, schema) pairs, which it already does for deep
links, and gets its own comments entity. It registers no listener.

## What exists and what is missing

`object-interactions` specifies notes on objects through
`ICommentsManager`, the registration of `openregister` as an entity type
with a validation closure, and RBAC for interaction operations.
`timeline-entry-visibility` adds internal or public per entry and
`note-edit-history` keeps the previous text. What is missing is one
collection per app: today every comment in the fleet is an `openregister`
comment.

## Impact

- Extends: `object-interactions`.
- Affected code: the comments entity listener and its validation closures,
  the claim lookup.
- Backwards compatible: the `openregister` collection stays registered and
  existing comments keep resolving, because their collection is unchanged.
- Size: S.

## Out of scope

- Migrating existing comments from the `openregister` collection to an
  app's own. Both resolve, and rewriting stored entity names is a data
  migration with no reader benefit.
