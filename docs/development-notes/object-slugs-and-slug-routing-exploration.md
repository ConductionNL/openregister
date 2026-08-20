# Exploration: object slugs & slug-based routing (OpenRegister + OpenCatalogi)

**Date:** 2026-06-17
**Status:** Exploration only — nothing implemented. Captures findings + the two
changes that would make object-slug URLs work, so they're ready to pick up.

## Questions

1. Does OpenRegister auto-generate `slug`s on objects, or do we generate them?
2. Can an object be fetched via `/{register-slug}/{schema-slug}/{object-slug}`
   (OpenRegister) and `/{catalogue-slug}/{object-slug}` (OpenCatalogi)?

## Findings

### 1. Object slugs are NOT auto-generated

- `ObjectEntity` has a nullable `slug` field (`@self.slug`), documented as
  "unique within register+schema".
- **Nothing populates it.** `->setSlug(` is called **nowhere** in OpenRegister
  `lib/`, and the save path (`Service/Object/SaveObject.php`) has no slug logic.
  There's no slugify helper and no auto-fill from `name`/`title`.
- It CAN be set manually: `ObjectEntity::hydrate()` maps any input key to its
  setter, so including `'slug' => '…'` in the saved object data calls
  `setSlug()` and stores it. Or call `$object->setSlug($slug)` before persisting.
- Uniqueness is documented intent only — not generated, not enforced. A caller
  that sets slugs must ensure uniqueness (and collision handling) itself.

### 2. Slug routing

**OpenRegister — slug-aware end to end (but see caveat).**
- Route `objects#show`: `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}`
  (note the `/api/objects/` prefix — it is NOT a bare `/{register}/{schema}/{id}`).
- `{register}` / `{schema}` → resolved by **slug** (or id/uuid) via
  `resolveRegisterSchemaIds`.
- `{id}` → resolved via `ObjectService::find()`, which matches **id OR uuid OR
  slug** (`orX(id, uuid, slug)`).
- So `…/api/objects/{register-slug}/{schema-slug}/{object-slug}` **resolves** —
  **but only for objects that actually have a slug set** (see finding 1).
  Register/schema slugs are seeded, so those segments work today regardless.

**OpenCatalogi — catalog by slug, object by UUID only.**
- Route `publications#show`: `GET /index.php/apps/opencatalogi/api/{catalogSlug}/{id}`.
- `{catalogSlug}` → by **slug** (`[a-z0-9-]+`, `getCatalogBySlug`).
- `{id}` → resolved **strictly by UUID**: it does `searchObjects` with a
  `@self.uuid` filter (deliberately avoids `find()` because of a `deleted IS
  NULL` condition that trips on objects with `deleted: []`). **Slug is not
  queried.**
- So `/{catalogue-slug}/{object-slug}` does **NOT** work — only
  `/{catalogue-slug}/{object-uuid}`.

## What it takes to support object-slug URLs

1. **OpenRegister — add slug generation** (the lookup already supports slugs):
   - In the `SaveObject` path, when no slug is supplied, slugify a source field
     (e.g. `name`/`title`) and ensure uniqueness **within register+schema**
     (suffix `-2`, `-3`, … on collision). Then
     `/api/objects/{register-slug}/{schema-slug}/{object-slug}` works.
   - Decide: generate always, or only when the schema opts in
     (`configuration.objectNameField` already exists as a related concept).

2. **OpenCatalogi — make `PublicationsController::show()` slug-aware**:
   - Currently filters `@self.uuid = {id}`. Add a slug branch (also filter/try
     `@self.slug`), or switch to the slug-aware `ObjectService::find()` — while
     handling the `deleted IS NULL` quirk the current code is avoiding.
   - Depends on (1): an object needs a slug before it can be looked up by one.

## Source references

OpenRegister:
- `lib/Db/ObjectEntity.php` — `slug` field (~169), `hydrate()` (~818)
- `lib/Service/Object/SaveObject.php` — no slug logic (confirms not generated)
- `lib/Service/ObjectService.php` — "find() supports slugs via orX(id, uuid,
  slug)" (~444, ~504)
- `lib/Controller/ObjectsController.php` — `show()` (~2093):
  `resolveRegisterSchemaIds` + `objectService->find(id: $id, …)`
- `appinfo/routes.php` — `objects#show` `/api/objects/{register}/{schema}/{id}` (~630)

OpenCatalogi:
- `appinfo/routes.php` — `publications#show` `/api/{catalogSlug}/{id}` (~106)
- `lib/Controller/PublicationsController.php` — `show()` (~473) resolves `{id}`
  via `searchObjects` with `@self.uuid` (UUID-only; comment on avoiding `find()`)

## Not decided

- Whether/where to add object-slug generation (always vs schema opt-in).
- Whether OpenCatalogi public URLs should accept slugs at all (SEO-friendly
  publication URLs) — product decision.
