---
kind: code
depends_on: [object-level-sharing-and-private-scope]
---

# Proposal: platform-share-provider

## Summary

`OCP\Share\IShareProvider` is the interface behind every share Nextcloud
knows about: shared with you, share management, expiry, and the platform's
own audit of who shared what. An object shared through OpenRegister's own
mechanism is invisible to all of it. This change registers a share
provider so an object share is a Nextcloud share.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`. The sixth of the ten is the share provider, and the
consequence the lane names is that a zaak "cannot be shared with a
colleague at the omgevingsdienst".

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface.

## What openregister implements generically

- One `IShareProvider` registered with `OCP\Share\IManager`, so an object
  share appears in shared with you, is manageable from the platform's own
  surfaces, and carries the platform's expiry and note fields.
- **The provider is a face on the existing model, not a second store.**
  `object-level-sharing-and-private-scope` owns what an object share is;
  this change makes the platform able to see it. The proposal of that
  change already argues the same point: federation is a share type and not
  a parallel system.
- **Permissions map explicitly.** The platform's read, update and share
  bits map onto the object verbs, and a bit with no meaning for an object
  is refused rather than silently ignored.
- **A schema declares whether its objects are shareable this way.**

## What a leaf app declares

dossiq declares which schemas are shareable and renders nothing of its
own. The sharing surface is the platform's.

## What exists and what is missing

`object-level-sharing-and-private-scope` specifies an invitation naming a
user or a group on one object, a private scope, and a schema-agnostic
primitive, and its own design weighs registering an `IShareProvider` as
one of two options. `federation` specifies that an object-scope share
serves its one object regardless of level, and records that OpenRegister
already receives federated shares through
`OpenRegisterCloudFederationProvider`. What is missing is the registration
itself: until it exists, an object share is invisible to the platform.

## Impact

- Extends: a new `platform-share-provider` capability, over
  `object-level-sharing-and-private-scope`.
- Affected code: a provider class and its registration, the permission bit
  mapping, the schema declaration.
- Backwards compatible: existing object shares keep working through their
  own path and become visible through the provider.
- Size: M.

## Out of scope

- What an object share means, which
  `object-level-sharing-and-private-scope` owns.
- A link with no account behind it, which `access-by-link-not-by-account`
  owns.
