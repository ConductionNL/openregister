---
kind: refactor
depends_on: []
adr: openspec/architecture/adr-008-shared-format-validators.md
---

## Why

Per ADR-008 (and company ADR-011), each validation rule should have exactly one
implementation in `lib/Formats/`. Three violations exist at HEAD:

1. **UUID regex duplicated 35+ times.** The literal
   `'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'` is
   copy-pasted across controllers, services, save/render handlers, GraphQL
   scalar, and facet handler (e.g. `ObjectsController.php:4172`,
   `UrnService.php:277`, `SchemaService.php:378`, `RenderObject.php:423,491,3264`,
   `SaveObject.php:475,581,778,…`, `GraphQL/Scalar/UuidType.php:56`,
   `MagicMapper/MagicFacetHandler.php:698,1249,2193`, and ~20 more). Some
   variants allow prefixed or 32-hex forms with different rules — a real drift
   risk, since a fix to one copy does not propagate. There is **no** `UuidFormat`
   in `lib/Formats/` despite that being the designated home.

2. **BSN elfproef accepts all-zero / over-length input.**
   `BsnFormat.php:42-66` left-pads with `str_pad(..., STR_PAD_LEFT)` and never
   checks length, so `"000000000"` yields control `0`, `0 % 11 === 0` → passes
   as a "valid" BSN, and an over-9-digit numeric string is validated on a
   miscalculated checksum.

3. **DateTimeNormalizer bypass.** `ProcessingLogController::optionalDateParam()`
   (`:359`) calls `new DateTime($value)` directly on a request parameter, in
   violation of the `DateTimeNormalizer` contract that forbids exactly this.

## What Changes

- Add `lib/Formats/UuidFormat.php` (an `Opis\JsonSchema\Format` + a static
  helper) as the single UUID validator, covering the canonical form and the
  documented prefixed/32-hex variants explicitly. Route all 35+ call sites
  through it (Edit each — no scripted rewrites, per project rule).
- Fix `BsnFormat`: reject input longer than 9 digits before padding, and reject
  the all-zero BSN sentinel.
- Route `ProcessingLogController::optionalDateParam()` through `DateTimeNormalizer`.
- Extract a shared slug helper so `RegisterMapper::cleanObject()` and
  `SchemaMapper::generateSlug()` stop duplicating the same sequence.

## Impact

- Affected: new `lib/Formats/UuidFormat.php`, ~35 call sites across
  `lib/Controller/`, `lib/Service/`, `lib/Db/`; `lib/Formats/BsnFormat.php`;
  `lib/Controller/ProcessingLogController.php`; a shared slug helper +
  `RegisterMapper`/`SchemaMapper`.
- Behavioural change: the BSN fix rejects inputs that previously passed — verify
  no legitimate value relied on the bug (none should; all-zero/over-length are
  invalid by definition).
- Risk: the UUID sweep is mechanical but large; do it with Edit per file and
  keep a test asserting each variant the old copies accepted still validates.
