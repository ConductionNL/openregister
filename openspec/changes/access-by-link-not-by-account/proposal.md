---
kind: code
depends_on: [object-level-sharing-and-private-scope]
---

# Proposal: access-by-link-not-by-account

## Summary

A bezwaarmaker, an externe adviseur and an architect all need one dossier
and none of them should have an account. Five driven systems open a record
at a link with declared limits on what the holder may do. OpenRegister
shares objects with principals and publishes nothing at a link. This
change adds a publication link with a random anchor, a declared capability
set per link, an optional password, an expiry, revocation, and an audit
entry for every use.

## Candidates and cluster

Cluster 64 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Access by link rather
than by account". Owner openregister, size M, depends on portal identity,
four candidates: C-communication-7, C-access-and-privacy-5,
C-access-and-privacy-25 and C-access-and-privacy-83. Highest relevance
`should`, no `must`, no matrix hole. Passers: 5, all five driven. dossiq
rates `partial` on one and `no` on three.

Ledger row the candidate notes name: 13.35.

## The decisions this rests on

**D8, option 3 as taken.** Both: an account with DigiD or eHerkenning
behind it, and a case number plus an e-mail with a one-time link, chosen
per case type. The link half is the smaller one and unblocks eight
candidates, and it is what this change builds. The account half is
portaliq's `portal-identity-space`.

**D6, relevance-led promotion.** No member is a `must`, so the cluster
enters on relevance and on D8 having landed.

**D1, the dossiq-only rows.** Row 13.35 is renumbered centrally.

## Why

The proving system is huly, cited by the access lane at
`access-and-privacy.tsv:29`: "Settings > Members, Guests, plugins/guest
PublicLink + Restrictions". A link that opens a record, or signs a party
in, with declared limits on what it may do, with freescout driven on the
same shape.

The rest:

- **A case, a document or a saved list published at a link that opens
  without an account** (C-communication-7): plane, "Public board,
  db/models/deploy_board.py:19 DeployBoard with a random anchor,
  TYPE_CHOICES project, issue, module, cycle, page, view, intake". Actieve
  openbaarmaking under the Woo is exactly this act, and a besluitenlijst
  published from the case system rather than retyped into the website is
  the difference between current and correct.
- **What a reader without an account may do is chosen per publication**
  (C-access-and-privacy-83): plane, "deploy_board.py:33-39
  is_comments_enabled, is_reactions_enabled, is_votes_enabled,
  is_activity_enabled, is_disabled", and vikunja. dossiq's lane names
  exactly what exists: "createTokenShare mints a read-only track your case
  token with an expiry and no password and no write".
- **A record shared with somebody at another organisation's instance, who
  signs in there** (C-access-and-privacy-25): nextcloud-deck,
  "lib/Federation/DeckFederationProvider.php, ACL participant type 6".
  Ketensamenwerking with the omgevingsdienst without an account at either
  end. That is the cloud federation provider, and it is
  `platform-cloud-federation-provider` under D9.

**What exists here and does not close it.**
`object-level-sharing-and-private-scope` shares one object with a named
user or group and adds a private scope, and its own proposal records that
OpenRegister already receives federated shares through
`OpenRegisterCloudFederationProvider`, with `FederatedShare` carrying
`objectUri`, `sharedWith`, `permissions` and a `shareToken`. The
`federation` spec states that an object-scope share serves its one object
regardless of level. `self-folder-access-control` keeps folder binds
default-deny. So sharing with a principal is specified, and the token
exists inside federation. What is absent is a share whose holder is
nobody: no publication link, no capability set on one, no password, and no
record of who used it.

## What changes

- **A publication link opens one object, one view or one file.** The link
  carries a random anchor that cannot be guessed from the object's
  identifier, and it resolves a principal that is the link itself rather
  than any user.
- **The link declares what its holder may do.** Read, read and comment,
  read and upload, or nothing while it is switched off. The default is read
  only, and anything the link does not declare is refused.
- **A link may carry a password and must carry an expiry.** A forwarded
  link is otherwise an open door, and a link without an end date is a door
  nobody closes. Both are checked at use, not at render.
- **A link is revoked, and revocation is immediate.** A revoked or expired
  link answers 404, never a reduced page, because a reduced page tells the
  holder the record exists.
- **Every use is on the audit trail.** The link, the act, the time and the
  address, so a publication that turns out to have been wrong can be
  reconstructed.
- **The link's reads obey the object's own rules.** A public link cannot
  reveal a field the schema hides, a timeline entry marked internal, or a
  file the object does not carry.

## Consumers

- **dossiq**: `CaseSharingService` mints publication links with declared
  capabilities instead of one read-only token shape.
- **portaliq**: the one-time link half of D8's answer, before the account
  half lands.
- **opencatalogi**: actieve openbaarmaking of a besluitenlijst as a link
  from the record, not a retyped page.
- **filinq**: a document shared with an adviser who has no account.

## ADRs

- ADR-005: the link resolves a principal and fails closed. An expired,
  revoked or password-failing link answers 404.
- ADR-046: the link is a subject-scoped reader, so it sees what that
  subject may see and nothing wider.
- ADR-022: one link primitive in the object layer, consumed by every leaf
  app.
- ADR-003: every use of a link is an audit fact on the chain.

## Impact

- Extends: a new `public-access-links` capability, beside
  `object-level-sharing-and-private-scope` which keeps sharing with named
  principals.
- Affected code: the share token resolution, the object read path's
  principal resolution, the field and timeline visibility filters, the
  audit writer.
- Backwards compatible: nothing is published until a link is minted, and an
  instance that mints none behaves as today.
- Size: M.

## Out of scope

- Sharing with a principal at another instance, which is
  `platform-cloud-federation-provider` under D9 and rides the federation
  provider that already exists.
- Portal identity with DigiD or eHerkenning behind it, which is portaliq's
  `portal-identity-space` under D8.
- What the citizen may write on their own case, which is cluster 48 and
  portaliq's under D16.
