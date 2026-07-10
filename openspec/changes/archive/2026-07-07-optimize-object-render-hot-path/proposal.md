---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

The object list/search read path — the most-called endpoint in the fleet —
recomputes per *object* work that is a function of the *schema* or *request*, and
bypasses a batch-preload optimization that already exists in the codebase. Each
issue is individually cheap but sits inside the per-object loop, so cost is
O(objects × N) (ADR-009 Rules 1–2).

1. **List render bypasses batch relation-preload → N+1 (CRITICAL).**
   `ObjectService::findAll()` (`lib/Service/ObjectService.php:817-823`) renders
   via `renderObjectsAsync()` (`:939-975`), a per-row `renderHandler->renderEntity()`
   loop with no preloaded relation cache; `renderEntity()`'s relation resolution
   (`RenderObject.php:435-458`) falls through to `objectCacheService->getObject($id)`
   — one query per related UUID per row on a miss. Meanwhile
   `RenderObject::renderEntities()` (`:3347-3410`) already does exactly the right
   thing: "Batch preload ALL related objects BEFORE rendering... prevents N+1",
   collecting UUIDs via `collectUuidsForExtend()` for a single fetch. `findAll()`
   just doesn't route through it. At default page size a single `?_extend=` list
   turns 1 query into 20–50+.

2. **Schema-derived booleans recomputed per object (HIGH).**
   `Schema::hasPropertyAuthorization()` (`lib/Db/Schema.php:566-582`) and
   `getPropertiesWithAuthorization()` (`:615-633`) full-scan the property list;
   called per rendered entity from `RenderObject.php:1636,1648`. Same for
   `ComputedFieldHandler::hasComputedProperties()`
   (`SaveObject/ComputedFieldHandler.php:428-439`) at `RenderObject.php:1617`.
   For a 200-row list on a 40-property schema that is ~8,000 redundant scans.
   The correct memo pattern already exists at `QueryHandler.php:221`.

3. **Per-object group lookups (HIGH).** `PropertyRbacHandler::checkPropertyAccess()`
   (`lib/Service/PropertyRbacHandler.php:288`) calls `getUserGroupIds($user)` per
   guarded property per object; `isAdmin()` (`:435`) repeats it — ~600 calls for
   100 objects × 5 guarded properties, for a value that cannot change in a request.

4. **Validator rebuilt per object (HIGH).** `ValidateObject::cleanSchemaForValidation()`
   (`lib/Service/Object/ValidateObject.php:866-870`) does
   `json_decode(json_encode($schema))` per object; `new Validator()` (`:1374,1494`)
   and `new BsnFormat()/SemVerFormat()/Iso8601DateTimeFormat()` (`:1498-1504`) are
   constructed per call. Bulk import pays O(objects) for O(schemas) work.

5. **`SELECT t.*` ignores `_fields` (LOW-MED).**
   `MagicSearchHandler.php:243` always selects `t.*`; `_fields` projection is
   applied in PHP after full hydration (`RenderObject.php:1537,1589,3364`). A
   `_fields=id,name` autocomplete still transfers/decodes every property column.

## What Changes

- Route `ObjectService::findAll()`'s render step through
  `RenderObject::renderEntities()` (or replicate its `collectUuidsForExtend()` +
  batch preload) so relations are fetched once per page, not per row.
- Memoize schema-derived booleans (`hasPropertyAuthorization`,
  `getPropertiesWithAuthorization`, `hasComputedProperties`) once per schema per
  render pass, reusing the `QueryHandler:221` pattern.
- Memoize the current user's group ids + admin status once per request in
  `PropertyRbacHandler`.
- Cache the cleaned validation schema per schema id+version and construct the
  `Validator` + format resolvers once per request/schema, not per object.
- Push `_fields` (via `sanitizeColumnName()`, always including metadata columns
  needed for hydration) into the `select()` list when present.

## Impact

- Affected: `lib/Service/ObjectService.php`, `lib/Service/Object/RenderObject.php`,
  `lib/Service/PropertyRbacHandler.php`, `lib/Service/Object/ValidateObject.php`,
  `lib/Db/MagicMapper/MagicSearchHandler.php`, `lib/Db/Schema.php` (memo slot).
- Pure performance; no API contract change (except `_fields` becoming a real
  projection). Behaviour-preserving.
- Risk: the render-path reroute is the highest-value but also highest-blast-radius
  change — pin characterization tests on list/search output (incl. `_extend`)
  before and after; verify opencatalogi/softwarecatalog list flows byte-identical.
