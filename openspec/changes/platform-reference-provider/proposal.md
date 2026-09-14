---
kind: code
depends_on: []
---

# Proposal: platform-reference-provider

## Summary

`OCP\Collaboration\Reference\IReferenceProvider` is what turns a pasted
link into a card. Paste a Deck card into Talk and you see the card; paste
a zaak and you see a bare URL. This change makes any object's link render
as a card in Talk, in a note and anywhere else Nextcloud resolves a
reference, from one provider, with each schema declaring what its card
shows.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`. The second of the ten missing registrations is the
reference provider, and the consequence the lane names is that "a link to
it does not render in Talk". Candidate C-communication-20 measures the
same thing from the other side: nextcloud-deck,
"lib/Reference/CardReferenceProvider.php, BoardReferenceProvider,
CommentReferenceProvider", with odoo and tuleap driven, and the dossiq
note "dossiq registers no reference provider, so a dossiq link pasted in
Talk stays a bare URL".

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface.

## What openregister implements generically

- One `IReferenceProvider` that resolves any object URL the deep link
  registry can parse, for every register and schema, with the reader's own
  access applied: a user who may not read the object gets the bare link
  and no metadata.
- A **card declaration per schema**: the title, up to three summary
  fields, an icon and an optional image property. Without it the card
  shows the object's title and its schema, which is honest and dull.
- The **smart picker** over the same declaration, so a user can insert a
  reference to an object from any Nextcloud text box.

## What a leaf app declares

dossiq declares, per schema, what the card shows. It registers no
provider and writes no resolver.

## What exists and what is missing

`schema-scoped-reference-providers` and
`integration-registry-reference-provider-convergence` carry reference
providers scoped to schemas, and `leaf-reference-provider-convergence` is
open to converge the leaves' own. `mail-smart-picker` carries a picker for
one integration. What is missing is the general case: one provider for
every object, an access-aware resolution, and a declared card shape per
schema rather than per integration.

## Impact

- Extends: `schema-scoped-reference-providers`.
- Affected code: the reference provider registration and resolver, the
  deep link registry's parse, the smart picker source.
- Backwards compatible: an undeclared schema renders a title-and-schema
  card, and an unreadable object renders as a bare link, which is today's
  behaviour.
- Size: S.

## Out of scope

- Creating an object from inside another app's text box
  (C-intake-4), which is the smart picker's create half and belongs with
  the leaf app that owns the schema.
