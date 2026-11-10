---
kind: code
depends_on: [platform-share-provider]
---

# Proposal: platform-cloud-federation-provider

## Summary

`OCP\Federation\ICloudFederationProvider` is how a record reaches somebody
at another organisation's Nextcloud, who signs in there. It is the whole
of ketensamenwerking between a gemeente and an omgevingsdienst without an
account at either. OpenRegister already receives federated shares.
Sending one, and the surface to do it from, is what is missing.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`. The eighth of the ten is the cloud federation
provider, and the consequence the lane names is that a zaak "cannot be
shared with a colleague at the omgevingsdienst". Candidate
C-access-and-privacy-25 measures the same thing: nextcloud-deck,
"lib/Federation/DeckFederationProvider.php, ACL participant type 6", with
dossiq `no` and "zero hits for a federation provider".

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface.

## What openregister implements generically

- A complete `ICloudFederationProvider`: receiving, which already exists as
  `OpenRegisterCloudFederationProvider` with `shareReceived()`, plus
  **sending, accepting, declining and revoking**, so a share is a
  conversation between two instances rather than an inbound event.
- **A remote principal is a principal.** A federated recipient is one more
  principal an object can be shared with, evaluated by the same permission
  layer as a local one, which is what the sharing change already argues.
- **What crosses is declared.** The object's data, its files, its public
  timeline entries or none of those, declared per share, so a
  samenwerkingsverband share does not carry the internal notes with it.
- **Revocation reaches the other side**, and a share the other side
  declines or revokes is reflected here.

## What a leaf app declares

dossiq declares which schemas may be federated and what crosses by
default. It registers no provider.

## What exists and what is missing

The `federation` spec carries confidentiality under every property name it
is stored under, and states that an object-scope share serves its one
object regardless of level. `federation-scope-enforcement` and
`organisation-as-federated-counterparty` are open. The
`object-level-sharing-and-private-scope` proposal records that
`OpenRegisterCloudFederationProvider` implements `ICloudFederationProvider`
with `shareReceived()`, and that `FederatedShare` carries `objectUri`,
`sharedWith`, `permissions` and a `shareToken`. So the inbound half exists
and the record shape exists. What is missing is the outbound half, the
lifecycle around it, and a declaration of what crosses.

## Impact

- Extends: `federation`.
- Affected code: the federation provider's outbound methods, the share
  lifecycle, the declaration and its validator, the payload builder.
- Backwards compatible: inbound federated shares keep working exactly as
  they do.
- Size: M.

## Out of scope

- A link with no account behind it, which `access-by-link-not-by-account`
  owns.
- Moving a case to another legal entity inside one instance, which
  `several-legal-entities-in-one-instance` owns.
