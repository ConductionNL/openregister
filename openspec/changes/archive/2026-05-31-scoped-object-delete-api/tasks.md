# Tasks — scoped-object-delete-api

## 1. Extend the public service API

- [x] 1.1 Add optional `Register|string|int|null $register=null` and `Schema|string|int|null $schema=null` parameters to `ObjectService::deleteObject()` in `lib/Service/ObjectService.php`, slotted between `string $uuid` and `bool $_rbac=true`.
- [x] 1.2 In `deleteObject()`, when `$register` is non-null call `$this->setRegister($register)` and capture the resolved entity in a local; same for `$schema` via `$this->setSchema($schema)`. Keep the existing `currentSchema` derivation when scope is omitted.
- [x] 1.3 Forward both resolved entities to `$this->deleteHandler->deleteObject(register: ..., schema: ..., uuid: $uuid, ...)`.
- [x] 1.4 Update the docblock: keep `@param string $uuid`, document the new `@param Register|string|int|null $register` and `@param Schema|string|int|null $schema` (with the "both or neither" note), add `@deprecated since 1.x — pass $register and $schema to scope the delete; the unscoped form falls back to a cross-table search and can return false positives across magic tables (see #1638)`.

## 2. Scope the delete handler lookup

- [x] 2.1 In `lib/Service/Object/DeleteObject.php::deleteObject()`, when both `$register` and `$schema` are non-null, resolve the object via the scoped path: `$this->objectEntityMapper->find(identifier: $uuid, register: $registerEntity, schema: $schemaEntity, includeDeleted: true, _rbac: $_rbac, _multitenancy: $_multitenancy)` and build a context array equivalent to what `findAcrossAllSources` returns (`object`, `register`, `schema`).
- [x] 2.2 When either is null, keep calling `findAcrossAllSources($uuid, ...)` exactly as today.
- [x] 2.3 Ensure `DoesNotExistException` raised by the scoped path propagates out without any audit-trail row being written — the call site (`ObjectService::deleteObject`) already wraps the legacy `find()` in a try/catch; mirror that for the scoped path so the public-API behaviour stays "row absent ⇒ exception".

## 3. Audit-trail register/schema fidelity

- [x] 3.1 Where the scoped path writes the audit-trail row, ensure the recorded `register` and `schema` are the API-supplied values (numeric IDs from the resolved entities), not the entity-derived values from a cross-table fallback. This is the natural result of (2.1) because the entity comes from the scoped find — verify by reading the audit-write path inside `DeleteObject::delete()` / `DeleteObject::executeIntegrityTransaction()`.

## 4. Fix the adapter

- [x] 4.1 In `lib/Service/ObjectServiceMapperAdapter.php::delete()`, replace `return $this->objectService->deleteObject((string) $id);` with `return $this->objectService->deleteObject(uuid: (string) $id, register: $this->register, schema: $this->schema);`.
- [x] 4.2 Update the method docblock to note that the adapter forwards its bound scope.

## 5. Tests

- [x] 5.1 Add `tests/Unit/Service/Object/DeleteObjectScopedTest.php` (new file) with these unit cases:
    - 5.1.a `deleteObject` with `register=wrong, schema=source` against a UUID that lives only in `register=openconnector, schema=source` → `DoesNotExistException`, row untouched.
    - 5.1.b `deleteObject` with `register=openconnector, schema=source` against the same UUID → succeeds, row deleted.
    - 5.1.c Cross-magic-table UUID collision: two rows with the same UUID in different `(register, schema)`; scoped delete only touches the matching scope.
    - 5.1.d Legacy unscoped `deleteObject($uuid)` (no register/schema) still finds and deletes the row when only one row matches across magic tables.
- [x] 5.2 Add an adapter-level test (`tests/Unit/Service/ObjectServiceMapperAdapterTest.php` — extend if exists, otherwise add) verifying `delete(['id' => $uuid])` forwards the adapter's `(register, schema)` to `ObjectService::deleteObject()`.
- [x] 5.3 Confirm no existing unit test breaks because of the new optional parameters — all current callers in `tests/` use named arguments or the single-arg form, both of which keep working.

## 6. Quality gates

- [x] 6.1 `composer check:strict` clean against the change (PHPCS / PHPMD / Psalm / PHPStan). Fix any pre-existing issues in the touched files following the "fix all issues" policy.
- [x] 6.2 `vendor/bin/phpunit --testsuite=Unit tests/Unit/Service/Object/DeleteObjectScopedTest.php` green.
- [x] 6.3 Full PHPUnit suite green (or at least: no new failures attributable to this change — `git diff --stat` of any baseline files reviewed).

## 7. Documentation

- [x] 7.1 Reference openregister#1638 in the commit message and PR body.
- [x] 7.2 Note in the PR body that openconnector#843, decidesk, launchpad, procest, and pipelinq become candidates for a follow-up migration sweep (out of scope for this PR).
- [x] 7.3 PR labels: `ready-for-code-review`, `ready-for-security-review`.
