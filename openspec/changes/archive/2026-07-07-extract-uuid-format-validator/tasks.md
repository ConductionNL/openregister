## 1. Create the shared UUID validator

- [ ] 1.1 Add `lib/Formats/UuidFormat.php` implementing `Opis\JsonSchema\Format` plus a static `isValid(string): bool` helper. Support the canonical 8-4-4-4-12 form and the documented prefixed (`[a-z]+-uuid`) and 32-hex variants as explicit, named options — not accidental drift.
- [ ] 1.2 Register it for JSON-Schema `format: uuid` where object properties use it.

## 2. Route call sites through it

- [ ] 2.1 Replace the inline UUID regex at each site with a `UuidFormat` call, using the Edit tool per file (no sed/awk/scripted rewrites): `ObjectsController.php:4172`, `UrnService.php:277`, `SchemaService.php:378`, `ObjectService.php:2968`, `RenderObject.php:423,491,3264`, `SaveObject.php:475,581,778,788,899,1850,1861,1988,1999,2446,2456`, `SaveObjects.php:2414,2419`, `RelationCascadeHandler.php:104,191,349,359,395,405`, `GraphQL/Scalar/UuidType.php:56`, `PerformanceHandler.php:354,364`, `UtilityHandler.php:78,88`, `AnnotationNotificationDispatcher.php:2087`, `MagicFacetHandler.php:698,1249,2193`, `ValidateObject.php:233,326,480,748,1184`, `ObjectReferenceProvider.php:445`, `ImportService.php:1397`.
- [ ] 2.2 Where a site used a prefixed/32-hex variant, select the matching `UuidFormat` option so behaviour is preserved.

## 3. Fix BsnFormat

- [ ] 3.1 In `BsnFormat.php:42-66`, reject inputs whose digit count is > 9 before padding; reject the all-zero BSN (`000000000`). Keep the elfproef for valid 9-digit input.

## 4. DateTimeNormalizer + slug helper

- [ ] 4.1 Route `ProcessingLogController::optionalDateParam()` (`:359`) through `DateTimeNormalizer` instead of `new DateTime($value)`.
- [ ] 4.2 Extract a shared slug helper; have `RegisterMapper::cleanObject()` (`:589-601`) and `SchemaMapper::generateSlug()` (`:1183-1189`) both call it.

## 5. Verification

- [ ] 5.1 Unit tests for `UuidFormat`: canonical valid/invalid, each supported variant, rejection of malformed.
- [ ] 5.2 Unit tests for `BsnFormat`: `000000000` rejected, over-length rejected, a known-valid BSN still passes.
- [ ] 5.3 Grep asserts no inline UUID regex literal remains in `lib/` outside `UuidFormat`.
- [ ] 5.4 `composer check:strict` passes; opencatalogi/softwarecatalog object flows unaffected.

## Acceptance criteria

- Exactly one UUID validator exists; all call sites use it.
- `BsnFormat` rejects the all-zero and over-length sentinels.
- No `new DateTime($userValue)` on request input outside `DateTimeNormalizer`.
- Slug generation is shared, not duplicated across the two mappers.
