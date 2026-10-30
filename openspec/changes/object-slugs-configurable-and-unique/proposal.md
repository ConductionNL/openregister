---
kind: code
---

## Why

Apps need object slugs they can put in a URL and trust a year later. OpenCatalogi's
stable WOO publication links (ConductionNL/opencatalogi#1592) cannot be built on what
OpenRegister does today. Measured on `development` (2.1.42-unstable, NC 35, magic-table
storage), with `configuration.objectSlugField: "title"` written straight into the
database because the API refuses to keep it:

| Step | Slug today |
|---|---|
| Create A "Hello World" | `hello-world` |
| Create B "Hello World" | `hello-world` (duplicate of A) |
| Update A, title renamed | `hello-world-renamed` (the slug follows the rename) |
| GET `…/hello-world` after the rename | returns B, without any error |
| Bulk save 2 × "Bulk Item" | both `bulk-item` |

Three defects cause this:

1. **`objectSlugField` is dropped on schema save and import.**
   `Schema::validateConfigurationArray()` has no entry for it, so slugs cannot be
   switched on through the API, the schema editor or a configuration import.
2. **Slugs are not unique and not stable.** Two objects with the same source value get
   the same slug, and every save regenerates the slug, so a rename moves the link to a
   different object.
3. **The collision handling is dead code.** `SaveObject::setDefaultValues()` writes
   `<slug>-<time()>` into the object *data* (where the magic mapper parks it in
   `@self.relations.slug` and logs a `Discarding … slug` warning on every save), and
   `MetadataHydrationHandler::hydrateObjectMetadata()` then overwrites the entity slug
   with the plain value.

## What Changes

- **Schema configuration:** `objectSlugField` (string) and a new `objectSlugSuffix`
  (boolean, default `false`) are accepted and persisted by schema save and by
  configuration import.
- **Unique within register + schema:** for a schema with `objectSlugField`, a slug is
  held by at most one live object in its register + schema. A new slug that collides is
  **not** written: the object is saved without a slug (the save does not fail) and a
  warning names the register, schema, slug and both object uuids. With
  `objectSlugSuffix: true` the colliding slug gets a UTC datetime suffix instead.
- **Frozen once set:** a later save never overwrites an object's slug, even when the
  source field is renamed. Only an object without a slug gets one at save time, so an
  object that lost a collision gets the slug on its next save once the holder is gone.
- **Client-supplied slugs** (`@self.slug` / `slug`) stay allowed for integrations and
  follow the same uniqueness and freeze rules; they are slugified like a generated slug
  (`Zaak_2026-0042` is stored as `zaak-2026-0042`, a value that slugifies to nothing counts as not sent).
- **Bulk parity:** `SaveObjects` applies exactly the same rules as a single save,
  including two objects in the same batch with the same source value.
- **Ambiguous lookup is an error:** a slug lookup inside one register + schema that
  matches more than one object (pre-existing duplicates) answers HTTP 409 with a JSON
  body, and logs both uuids. It no longer surfaces as an HTML 500.
- **`occ openregister:backfill-slugs <register> <schema>`:** fills missing slugs for
  existing objects, idempotent. A set slug is never rewritten, also not after the slug
  field is switched (Robert Zondervan, 2026-10-23: no rewrite flag). `--dry-run` reports
  without writing. Existing duplicates are logged and never silently changed.
- **Schema editor:** the configuration tab gets an "Object slug field" dropdown over the
  schema's properties and an "Append a date-time suffix on collision" switch.
- **Clean-up:** `setDefaultValues()` stops injecting `slug` into object data, and the
  uncalled `MetadataHydrationHandler::generateSlug()` fallback chain and the
  now-unused `SaveObject::generateSlug()` / `createSlug()` are removed. One slugify
  routine remains.
- **Behaviour changes, only for schemas that set `objectSlugField`:** a rename no
  longer changes the slug (GW1). A collision leaves the new object without a slug
  instead of duplicating it (GW2). A lookup on a duplicated slug answers 409 instead of
  an unhandled error (GW3). The implicit `-<time()>` suffix, which never took effect,
  becomes the opt-in `objectSlugSuffix` (GW4).
- **Not breaking:** schemas without `objectSlugField` behave exactly as before. No
  migration, no unique index, and existing slugs, duplicates included, are left as they
  are.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `object-lifecycle`: object-slug generation, uniqueness, freezing, bulk parity,
  ambiguous slug lookup, the backfill command, and the schema configuration keys that
  switch them on. The read-endpoint requirement gains the ambiguous-slug response.

## Impact

- **Code (PHP):** `lib/Db/Schema.php` (configuration allowlist),
  `lib/Service/Object/SaveObject.php`,
  `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`,
  `lib/Service/Object/SaveObjects.php`, `lib/Db/MagicMapper/MagicBulkHandler.php`,
  `lib/Db/MagicMapper.php`, `lib/Controller/ObjectsController.php`, a new
  `lib/Service/Object/SaveObject/ObjectSlugHandler.php`, a new
  `lib/Command/BackfillObjectSlugsCommand.php` registered in `appinfo/info.xml`.
- **Code (frontend):** the schema configuration tab is rendered by
  `CnSchemaFormDialog` / `CnSchemaConfigurationTab` from `@conduction/nextcloud-vue`,
  so the two controls land there. OpenRegister then bumps the dependency and types the
  keys in `src/entities/schema/schema.ts`.
- **Data:** none written on upgrade. The backfill is an explicit operator command.
- **API:** `GET /api/objects/{register}/{schema}/{slug}` on a duplicated slug answers
  409 JSON. Every other response shape is unchanged.
- **Dependent apps:** OpenCatalogi (#1592) builds its catalogue-scoped resolve and its
  per-environment backfill on top of this change. Softwarecatalog and other consumers
  are only affected if their schemas set `objectSlugField`.
- **Not addressed here:** uniqueness across a catalogue (#1592), the HTML 404 page on an
  unknown slug, and register-less slug lookups across tables.
