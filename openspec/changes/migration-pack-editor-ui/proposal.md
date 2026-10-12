---
kind: code
depends_on: []
---

# Proposal: migration-pack-editor-ui

## Summary

Give migration packs a screen. An administrator lists the packs, opens one,
and changes its column mappings: the source column, the target field, whether
the row is required, and the transform (trim, date, lookup table, yes/no
table, join, fixed value). The editor tests the pack against a sample row
before it is saved, and saving gives back exactly what was stored for every
part the editor does not show.

The API gains what such a screen needs and lacked: an envelope body for
create and update, so a pack's own `id` slug can no longer replace the route's
row id; a server-side lock on the slug of a built-in pack; a preview endpoint
that maps sample rows without storing anything; CSRF on every write; and a log
line naming who changed or deleted a pack.

## Why

Packs are stored in OpenRegister (`openregister_migration_packs`, served by
`/api/migration-packs`) and run by its mapping engine, but there is no way to
see or change one except by exporting the JSON, editing it by hand and
importing it again. Apps now ship packs that a municipality is expected to
adjust. stackiq's TOPdesk CMDB import is the first (WOO-588): Gemeente
Rotterdam's administrator must be able to view and edit the column mappings
the import uses, without a developer and without hand-written JSON.

The decision of 2026-10-12 puts all mapping in OpenRegister. Of the three
places a mapping could live there, the migration-pack store is the one the
packs are already written for: the import calls `MappingEngine::mapRow` with
them unchanged, and `PackDefinitionValidator` already checks every transform
they use. A Twig mapping would need an engine rewrite and loses the row and
column in every error; packs stored as register objects would duplicate a
store OpenRegister already has. See design.md D-1.

Building the screen surfaced four defects the API had without one:

- A `PUT /api/migration-packs/{id}` whose body carries the pack's own `id`
  answers 404. Nextcloud merges the JSON body over the route parameters, so
  the slug replaces the row id and the dispatcher casts it to 0.
- The slug of a built-in pack can be changed through update and import. An
  app that finds its pack by slug then silently falls back to its shipped
  file, and its next update seeds a second copy.
- The write routes skip the CSRF check (`NoCSRFRequired`).
- Nothing records who changed a pack, while a pack decides what an import
  writes.

## What changes

- A **Migration packs** page and a pack **editor** page in OpenRegister, with
  a menu entry next to Connections that only administrators see.
- The editor keeps the field mappings as an ordered list, keeps keys it does
  not model, and treats a lookup's "other values" in the three ways the
  engine does: an error, an empty field, or a stored value.
- `POST` and `PUT /api/migration-packs[/{id}]` accept `{"definition": {...}}`;
  on update the route id always wins. The raw body keeps working.
- An update that changes the slug of a built-in pack answers 422
  `builtin-slug-locked`.
- `POST /api/migration-packs/preview` (admin): an unsaved definition and up to
  20 sample rows in, the mapped data and errors per row out.
- Create, update, delete, import and preview require an administrator and a
  CSRF token. Update and delete write an info log line with the user and the
  pack slug.
- Dutch and English for every new string.

## Impact

- **stackiq** reads its TOPdesk packs from this store first and falls back to
  its shipped files (its own change, `cmdb-import-mapping-view`). It depends
  on `MigrationPackService::findByPackSlug()`, `MigrationPack::getDefinitionArray()`,
  `PackDefinitionValidator` and `MappingEngine::mapRow()`; design.md D-9
  records that as a contract.
- **Integriq** is not touched. Its mapping editor is the pattern for this
  screen's layout; no code is shared.
- **API clients** that create or update packs keep working with the raw body.
  A client that posted to the write routes without a CSRF token, outside a
  browser session, must send `OCS-APIRequest: true` or use app passwords with
  basic auth, as for any other CSRF-protected Nextcloud route.
- **opencatalogi, softwarecatalog**: no change; they do not use packs.

## Out of scope

- Per-organisation packs. The store is instance-wide: one mapping per pack
  slug per instance. A shared instance that imports for several
  municipalities gets one mapping for all of them.
- A target-schema field picker. A pack does not say which schema it targets
  (stackiq's targets span three schemas and a Contacts identity), so targets
  stay free text.
- Marking app-owned packs in the `registers#import` pack picker.
- Shipped-version tracking ("is the stored pack still the shipped one?"):
  that belongs to the app that seeds the pack.
