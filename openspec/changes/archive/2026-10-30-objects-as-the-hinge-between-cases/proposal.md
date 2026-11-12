---
kind: code
depends_on: [party-roles-beyond-the-requester]
---

# Proposal: objects-as-the-hinge-between-cases

## Summary

A melding, an inspection and a permit application on the same address are
one object's history. OpenRegister hangs objects off cases rather than the
other way round, so nobody can open an address and read what happened
there. This change gives any object the reverse view of everything that
references it, adds a property that reads a referenced object's field live
instead of copying it, and carries geographic features up from the objects
and parties a record points at, each saying where it came from.

## Candidates and cluster

Cluster 47 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The object register as
the hinge between cases". Owner openregister, size L, depends on CT-6,
seven candidates: C-case-core-12, -16, -24, -25, -37, -43 and C-intake-24.
One is a `must`: C-case-core-16. No matrix hole. Passers: 6, four driven
and two documented. dossiq rates `partial` on four and `no` on three.

## The decisions this rests on

**D6, relevance-led promotion.** C-case-core-16 is a `must` with one
driven passer and one documented, and enters on relevance.

**D21, documented candidates admitted and labelled.** C-case-core-25
(mozard) and C-case-core-43 (atabix) have no driven passer. The first is
in scope as documented; the second is recorded, not built.

**CT-6 is buildiq's half.** The layout per case type is a buildiq change
under D16. This change owns the data behind those surfaces, not the page.

## Why

The proving system is opencase, cited by the case core lane at
`case-core.tsv:14`: "Estate search and detail (EstateDetail.md)". A real
world object is a first-class record with its own cases hanging off it.
That is C-case-core-16, a `must`, with mozard documented on the same
shape. The candidate clause states the inversion plainly: "our objects
hang off cases rather than the other way round".

The rest:

- **A field that shows a referenced record's own field, live**
  (C-case-core-12): otobo, "Dynamic field, Reference and Lens
  (Driver/Reference.pm family, Driver/Lens.pm)". The besluit's datum shown
  on the bezwaar without copying it. dossiq: "OpenRegister relations
  exist; a lens onto a related object's field not found".
- **An external object type gets its own list page and search fields**
  (C-case-core-24): valtimo, "Admin > Objecten (Admin-Objecten.md)".
  Reusing the case list machinery for objects is what makes objects usable
  rather than merely stored.
- **An object's current status read at a glance across its cases**
  (C-case-core-25, documented): mozard,
  "/functionaliteiten/objectregistratie".
- **Map features inherited from the parties and objects, each saying where
  it came from** (C-case-core-37, `could`): xxllnc-zaken, "Geo
  (geo-location/spec.md)".
- **An import location as an administered object** (C-intake-24):
  opencase, "Admin settings (code-census.md)". dossiq: "ours is one
  mailbox in a settings screen and this is a list of sources".

**What exists here and does not close it.** `linked-entity-types`
specifies `linkedTypes` on a schema, `Nc*` property types, metadata
columns on magic and entity tables, read-time enrichment through
`_extend`, a generic metadata API for ad-hoc linking and reverse lookups
across tables. `referential-integrity` carries `inversedBy` and
`writeBack` and the `uses` and `used` traversal.
`relation-types-with-inverses` is open for the inverse label.
`admin-list-views` and `saved-search-views` carry list surfaces.
`geo-metadata-kaart` carries geographic metadata, and carries an inherited
defect with it: its main spec holds a `## ADDED Requirements` delta header
at line 14, which truncates the parsed `## Requirements` section and makes
`openspec archive` refuse any delta against it. The geographic requirement
here targets `linked-entity-types` instead, and the defect is reported
rather than fixed, because it is on a line this change does not touch. So the links exist and
can be traversed, and a list surface exists. What is missing is the
reading: no surface treats an object as the root and its referencing
records as its history, no property renders a referenced value without
copying it, and no geographic feature says which relation it came from.

## What changes

- **Any object reads the records that reference it, grouped and
  summarised.** One query answers which objects point at this one, grouped
  by schema, each with its title, its status and when it last changed. An
  address then has a history without any schema being taught about
  addresses.
- **A lens property reads a referenced object's field live.** A property
  declares a reference property and the property to read through it. The
  value is resolved at read, is never stored, and is read-only. When the
  referenced object changes, the lens changes with it.
- **A lens fails visibly, not silently.** An unreadable referenced object
  renders as withheld rather than as empty, because empty reads as "there
  is no besluit" and withheld reads as "you may not see it".
- **Every schema gets the same list surface.** A schema declares its list
  columns and its search fields, and the generic list surface renders them,
  so an object type is as usable as a case list with no work per type.
- **Geographic features are inherited, with provenance.** A record's map
  features may be collected from the objects and parties it references.
  Each feature names the relation it came from, and a feature the record
  holds itself outranks an inherited one.
- **An intake source is an administered object.** A watched folder, a
  mailbox or an endpoint is a row in a register rather than a field in a
  settings screen, so there can be more than one and each can be switched
  off.

## Consumers

- **dossiq**: declares which object types a case type may reference, and
  gets the address page with its cases without building one.
- **opencatalogi and stackiq**: the reverse view is what a publication's
  own history looks like.
- **filinq**: intake sources as objects, one per watched folder.
- **integriq**: the same, one per endpoint, and the lens is how a
  registry-backed value is displayed without being copied under CT-5.

## ADRs

- ADR-022: one relation model in the object layer, consumed by every leaf
  app.
- ADR-031: the lens, the list columns and the geographic inheritance are
  declared on the schema.
- ADR-005: a lens over an unreadable object renders as withheld, never as
  absent.
- ADR-009: the reverse view is one bounded query per group, never a walk
  over relations.

## Impact

- Extends: `linked-entity-types` with the reverse view, the lens, the list
  declaration and the inherited geographic features.
- Affected code: the reverse lookup service, the read-time enrichment
  path, the schema validator, the list surface's column resolution, the
  geographic collector.
- Backwards compatible: a schema that declares no lens, no list columns and
  no geographic inheritance behaves as today.
- Size: L.

## Out of scope

- The page layout per case type, which is CT-6 and buildiq's under D16.
- The status, location, depreciation and value of an organisation's assets
  (C-case-core-43, documented, atabix). Outside municipal casework, and
  recorded because it is in a published catalogue.
- The inverse label on a relation, which `relation-types-with-inverses`
  carries.
