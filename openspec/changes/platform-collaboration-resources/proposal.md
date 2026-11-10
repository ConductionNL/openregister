---
kind: code
depends_on: []
---

# Proposal: platform-collaboration-resources

## Summary

`OCP\Collaboration\Resources\IProvider` is how a record joins a Nextcloud
project: a collection of things people are working on together, drawn from
different apps. A zaak, the folder of its documents and the Talk
conversation about it are one piece of work and three apps. Without a
provider the zaak cannot be in the collection at all. This change makes
any object a collaboration resource.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`: ten platform registrations Deck has and dossiq does
not. The fourth is collaboration resources.

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface, one PR series.

## What openregister implements generically

- One `IProvider` answering for every object: its name, its icon, its
  link, and whether a given user may access it. The access answer is the
  object's own, resolved through the same path as a read.
- **A resource is added and removed by anyone who may update the object**,
  so being in a collection is a fact about the record and not a private
  bookmark.
- **The collection's other members are readable from the object**, so a
  case surface can show the folder and the conversation that belong to it
  without knowing what a folder or a conversation is.
- **A schema declares whether its objects may join collections.** Some
  registers are reference data and have no business in a project.

## What a leaf app declares

dossiq declares which schemas may join collections, and renders the
collection on the case page if it wants to. It registers no provider.

## What exists and what is missing

A search of the openregister openspec tree on 2026-09-14 returns no hits
for `OCP\Collaboration\Resources` in `specs/` or `changes/`. Nothing
implements or specifies it, which is what the lane found. The related work
is `integration-talk`, `files-leaf-save-to-object` and
`object-level-sharing-and-private-scope`, each of which links one app to
an object rather than making the object a member of a collection that
spans apps.

## Impact

- Extends: a new `platform-collaboration-resources` capability.
- Affected code: a provider class and its registration, the access
  resolution, the schema declaration and its validator.
- Backwards compatible: nothing joins a collection until a schema declares
  it may.
- Size: S.

## Out of scope

- The Talk conversation and the Files folder themselves, which their own
  apps provide into the same collection.
