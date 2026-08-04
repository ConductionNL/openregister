## 1. Batch relation preload on the list path

- [ ] 1.1 Pin characterization tests on `GET /api/objects` list/search output including `_extend` (byte-identical expectation).
- [ ] 1.2 Route `ObjectService::findAll()` (`:817-823`) render step through `RenderObject::renderEntities()` (`:3347-3410`), or replicate `collectUuidsForExtend()` + single batch fetch before the per-object formatting loop.
- [ ] 1.3 Keep the Promise-based rendering for the CPU-bound formatting only, not data-fetch.

## 2. Memoize schema-derived values per render pass

- [ ] 2.1 Compute `hasPropertyAuthorization()` / `getPropertiesWithAuthorization()` / `hasComputedProperties()` once per schema and reuse across all objects in the pass (pattern from `QueryHandler.php:221`). Cache on the `Schema` entity or a per-request map.

## 3. Memoize request-invariant auth data

- [ ] 3.1 In `PropertyRbacHandler`, resolve `getUserGroupIds($user)` and `isAdmin()` once per request (nullable memo fields), shared by `checkPropertyAccess()` (`:288`) and `isAdmin()` (`:435`).

## 4. Build validator/schema once per schema

- [ ] 4.1 Cache the cleaned validation schema per schema id+version (invalidate with `SchemaCacheHandler`); stop `json_decode(json_encode())` per object (`ValidateObject.php:866-870`).
- [ ] 4.2 Construct `Validator` + `BsnFormat`/`SemVerFormat`/`Iso8601DateTimeFormat` once per request/schema, not per object (`:1374,1494,1498-1504`).

## 5. `_fields` SQL projection

- [ ] 5.1 In `MagicSearchHandler.php:243`, when `_fields` is present, build the `select()` list from sanitized field columns plus the metadata columns required for hydration; fall back to `t.*` only when `_fields` is empty.

## 6. Verification

- [ ] 6.1 Query-count test: a 20-row `?_extend=rel` list issues O(1) relation fetches, not O(rows).
- [ ] 6.2 Bench: list render on a wide-schema page does schema-derived work once, not per row (assert via spy/counter).
- [ ] 6.3 `_fields=id,name` transfers only those columns (assert generated SQL).
- [ ] 6.4 Output byte-identical to pre-change for the pinned fixtures; opencatalogi/softwarecatalog list flows pass.
- [ ] 6.5 `composer check:strict` passes.

## Acceptance criteria

- List/search relation fetches are O(1) per page, not O(rows).
- Schema-derived booleans and user group/admin lookups are computed once, not per object.
- The validator and format resolvers are not reconstructed per object.
- `_fields` narrows the SQL projection.
