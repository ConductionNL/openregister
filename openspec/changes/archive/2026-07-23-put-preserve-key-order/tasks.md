# Tasks: put-preserve-key-order

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Fix the write path

- [x] 1.1 Trace the object save/update path and locate where JSON object-typed property keys are reordered; neutralise it so submitted key order is preserved (iterate submitted keys on default-merge, no `ksort`/canonicalisation on object values) (REQ-OBJ-KO-01)
  - Absent-key defaults are appended after submitted keys, never interleaved to reorder existing ones.
  - **Finding (2026-07-23):** traced the full write path (`ObjectsController::update` → `ObjectService::saveObject` → `SaveObject::prepareObjectForUpdate`/`prepareObjectForCreation` → `MagicMapper::prepareObjectDataForTable`/`rowToObjectEntity`). No PHP-layer reordering site exists — `setDefaultValues()` already does `array_merge($data, $renderedDefaults, $constantValues)` (submitted keys first, defaults appended), and no step applies `ksort` or rebuilds an object-typed value from schema-declared property order. The storage-layer half of #1720 (PostgreSQL JSONB hashing keys) was already closed by `MagicMapper`'s `json_ordered` column-type fix (commit 11576838a, PR #1726, merged to development 2026-05-23) — object-typed schema properties get a verbatim-order JSON column instead of JSONB. No further code change was required; see the round-trip tests added under task 2 for verification.

- [x] 1.2 Ensure the read serializer re-encodes object-typed properties in stored order (no read-side re-keying) (REQ-OBJ-KO-01)
  - Confirmed: `MagicMapper::rowToObjectEntity()` decodes with a bare `json_decode($value, true)` — no re-keying step exists on read.

## 2. Verification

- [x] 2.1 Round-trip test: prepare an object with a known key order into an object-keyed property through the real `SaveObject` write path, then reverse the keys and re-prepare, asserting the exact (reversed) key sequence survives (drag-reorder simulation); also pins the pure encode/decode symmetry `MagicMapper` performs on the storage side (REQ-OBJ-KO-01)
  - Added `tests/Unit/Service/Object/SaveObjectKeyOrderPreserveTest.php` (4 tests, run via `docker run --rm -v $PWD:/app -w /app nextcloud:34.0.0-apache php vendor/bin/phpunit`). All PASS. Complements the existing `MagicMapperKeyOrderColumnTypeTest.php` (9 tests, also passing) from the prior #1720 fix.

- [x] 2.2 PUT-semantic guard: assert a non-changed sibling field survives the write while key order is being preserved (REQ-OBJ-KO-01)
  - Covered by `testDragReorderSurvivesThePreparedUpdate()` — asserts the untouched `name` sibling property survives alongside the reordered `mapping` property.

Acceptance criteria:
- A drag-reorder of an object-keyed property persists across save and re-read.
- No object-typed property keys are reordered by validate/store/serialize.
- PUT-semantic carry-forward of unchanged fields is unaffected.
