---
kind: code
depends_on: []
---

# Proposal: platform-capability

## Summary

`OCP\Capabilities\ICapability` is the one answer every Nextcloud client
reads before it does anything: what is installed, what is switched on, and
what the limits are. OpenRegister publishes nothing there, so a client
discovers what the instance supports by trying. This change publishes the
capability block, and lets each claiming app appear in it under its own
name.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`. The tenth of the ten registrations is the capability.
Candidate C-integrations-5 measures the same need from the integrator's
side: "a client reads the instance's own capabilities and limits over the
API before it attempts a call", with forgejo, gitea and nextcloud-deck
driven, and the clause "every integrator currently discovers our upload
limit by hitting it".

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface.

## What openregister implements generically

- One `ICapability` publishing: the app version, the API versions served
  with their status, the deep link patterns registered, the limits an
  integrator needs, and which of the platform integrations of this
  programme are switched on.
- **A block per claiming app**, so a client can ask whether the case app is
  present and what it supports without knowing that OpenRegister is
  underneath it.
- **No secrets and no register names in the unauthenticated part.** The
  capability response is read widely, and it is the wrong place to
  enumerate an instance's data model.

`api-as-a-versioned-surface` specifies the same facts as an API endpoint
for integrators who are not Nextcloud clients. Both read one source, so
the two answers cannot disagree.

## What a leaf app declares

dossiq claims its (register, schema) pairs, which it already does, and
appears in the capability block. It registers no capability class.

## What exists and what is missing

`deep-link-registry` already requires that the registry be discoverable
through `ICapability`, exposing a map of `{registerSlug}::{schemaSlug}` to
URL templates, and its own status section lists that requirement as **not
implemented**. `urn-resource-addressing` names the interface too. So the
requirement is written and unbuilt, which is exactly what the lane found
from the other end.

## Impact

- Extends: `deep-link-registry`.
- Affected code: a capability class and its registration, the deep link
  registry read, the limits source shared with the API capabilities
  endpoint.
- Backwards compatible: nothing reads the block today, so publishing it
  changes no behaviour.
- Size: S.

## Out of scope

- The capabilities endpoint for non-Nextcloud integrators, which
  `api-as-a-versioned-surface` carries over the same source.
