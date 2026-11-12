---
kind: code
---

# Proposal: object-tree-read

## Summary

One generic read answers "which objects sit below (or above) this one" along the parent
property a schema already declares, with a level per object, a depth bound, cycle
protection, a node cap, and the caller's RBAC on every node. An organisation's group
(parent company and subsidiaries) is the first consumer: portaliq's "Reikt tot" on the
mandates screen and its case list under a mandate that reaches down (decision 250).

## Why

Decision 250 (Ruben, 15 Oct): an organisation's parent/subsidiary tree is a generic
OpenRegister relation between organisation objects, named by register, schema and field,
and every app reads it the same way.

What exists, measured on development b9e5d8df4:

- **Where organisations live as objects.** OpenRegister's own `Organisation` entity
  is the tenancy concept, not a party. The party objects with a KvK identity are
  dossiq's `kvkCompany` (register `dossiq`, `lib/Settings/register.d/25-brp-kvk.json`,
  identity `kvkNumber`, name `tradeName`, kept current from KvK by integriq's
  `KvkMutatieProvider`). pipelinq's `client` carries no KvK number; dossiq's
  `partnerOrganization` is a chain partner keyed by OIN; OpenRegister's
  `kvk_register.json` is a mock Handelsregister. portaliq has no organisation schema:
  `PortalPartyTreeResolver` reads `portaliq/organisation.parent`, which does not exist.
  No schema in the fleet records an organisation's parent today.
- **How a parent is declared.** Two declarations already exist and both name a
  property that points at the same schema:
  - `x-openregister-hierarchy.parent` (`HierarchyGrantExpander::declarationFor()`),
    which also makes grants on a parent reach its children;
  - `x-openregister-party.parentProperty` (`PartyDefinition::parentProperty()`), the
    party model's organisation parent, already guarded against cycles and depth on
    write by `PartyTreeGuardListener` / `PartyService::assertParentAllowed()`.
- **What reads them.** `HierarchyDescender::childrenOf()` reads one level of children
  from the magic table; `ancestorsOf()` walks up, but only for the hierarchy
  annotation. `RelationGraphService::graph()` walks every relation, typed, without
  levels along one property. Nothing returns the descendants of an object with their
  level, and nothing reads the party parent at all.

## What changes

- `ObjectTreeService::tree()` walks descendants, ancestors or both of one object along
  the declared parent property, reusing `HierarchyDescender::childrenOf()` for each
  level and `MagicMapper::filterReadableUuids()` for read access.
- `GET /api/objects/{register}/{schema}/{id}/tree?direction=&depth=&fields=` exposes it,
  next to the existing `graph` route, resolving the root through `ObjectReadAccess`.
- No new annotation. An organisation tree is declared with
  `x-openregister-party.parentProperty` (no access is inherited); a tree that should
  also carry access uses `x-openregister-hierarchy` (which wins when both are present).

## Consumers

- portaliq: "Reikt tot" on the Machtigingen board, and mandated cases that reach down
  (`PortalPartyTreeResolver`).
- dossiq: `kvkCompany` gains the parent property and the party declaration (its own
  register change, named in tasks).
- Any app with a self-referencing schema (departments, product groups) gets the same
  read; `records-tree-view` stays the one-level browse for lists.

## Impact

- Additive: one service, one route. A schema without a declared parent answers with the
  root alone.
- Size: S.

## Out of scope

- Filling the relation from KvK. The KvK APIs integriq reads (Zoeken, Basisprofiel,
  Vestigingsprofiel, Naamgeving, Mutatieservice) carry no group relations;
  "vestigingen" are branches of one registration, not subsidiaries. KvK's
  "Handelsregister Concernrelaties" (enig aandeelhouder, aansprakelijke, bestuurder)
  is an ordered product, not an API. So the parent is recorded by hand (or by a later
  source) and this change says so rather than faking a feed.
- Trees across schemas.
