---
kind: code
---

# Proposal: Nextcloud entities as virtual schemas + inheritance-aware semantic providers

## Problem

ADR-048 resolves a `referenceSemanticType` URI to any installed schema whose
implemented-types contain it. But (1) the richest providers of common types are
**Nextcloud itself** — User→Person, Group→Organization, Contact→Person,
Event→Event, File→DigitalDocument — and none are OR schemas, so they can't be
referenced; OR ships **no** native `organisation` schema at all, so leaf apps
duplicate one with no canonical home. (2) OR's `allOf` schema inheritance merges
a parent's properties but NOT its semantic marker, so a schema extending a
"Person" schema is invisible to a `schema.org/Person` reference. (3) There is no
"which NC entity is which schema.org type" knowledge anywhere.

## Proposed Change

Additive, reusing the existing object-source-provider mechanism — no new
resolution machinery (per hydra ADR-049).

1. **Inheritance-aware implemented-types.** Make a schema's implemented types the
   union of its own markers and all `allOf` ancestors' markers (walk the chain
   with the existing circular-ref guard). One change in the implemented-types
   computation; a schema without `allOf` is unchanged.
2. **NC-entity semantic seed map.** A hardcoded `NcEntitySemanticMap` (User→
   `schema:Person`/`user-directory-source`, Group→`schema:Organization`/
   `group-source`, plus rows for contacts/calendar/files/deck/talk/tasks as their
   providers land) that a Repair/seed step materialises into virtual registers +
   schemas. Data, not a resolution branch.
3. **Always-available Directory register.** Seed a virtual register
   (`application: openregister`, always enabled) with `nc-user`
   (`x-schema-org: schema:Person`) and `nc-group` (`x-schema-org:
   schema:Organization`), each bound via `x-openregister-object-source.provider`
   to a new read-only `UserDirectoryObjectSourceProvider` /
   `GroupObjectSourceProvider` (wrapping `IUserManager` / `IGroupManager`, shaped
   like `CalDavVtodoObjectSourceProvider`). Result: every instance has a Person
   and Organization provider with no third-party app.

### Scope

**In scope**: the `allOf` implemented-types union; the seed map + Directory
register/schemas; the two directory object-source providers; DI/boot
registration; tests; live verification that a `schema.org/Organization` reference
resolves to `nc-group` and its picker lists live groups.

**Out of scope**: Contacts/Calendar/Files/Deck/Talk object-source providers
(follow-on, one per PR, reusing the Integration Registry read code); physical
consolidation of leaf `organization`/`Payee` duplicates (deferred behind the now-
stable URI); any write path to virtual objects (they are read-only).

## Impact

- **OpenRegister**: additive — one method change (`getImplementedTypes` allOf
  walk), a seed constant + Repair/seed step, two `ObjectSourceProvider` classes,
  DI registration. No change to `SemanticTypeResolver` or the read-path
  delegation.
- **Consuming apps / frontend**: none required — a virtual schema is a normal
  register+schema, so the ADR-048 resolver and the object picker work unchanged.
- **Security (ADR-005)**: providers are read-only and user-scoped via the source
  managers (denied == absent), following the CalDAV reference provider.

## Dependencies

- hydra **ADR-049** (records the decision) + ADR-048, ADR-019, ADR-022, ADR-011.
- Existing OR: `object-source-providers` (mechanism), `cross-app-semantic-references`
  (resolver), `SchemaMapper::resolveAllOf` (inheritance).
