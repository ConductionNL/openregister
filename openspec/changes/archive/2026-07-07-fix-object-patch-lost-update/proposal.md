---
kind: fix
depends_on: []
---

## Why

The object PATCH path is a read-modify-write with no concurrency control
(`lib/Service/ObjectServiceMapperAdapter.php:189-215`):

```php
if ($patch === true) {
    $existing = $this->objectService->find(id: (string) $id, ...);
    $object = array_merge($existing->getObject(), $object);
}
return $this->objectService->saveObject(object: array_merge($object, ['id' => (string) $id]), ...);
```

Between `find()` and `saveObject()` there is no lock, no version check, and no
etag. Two concurrent PATCH calls on the same object race: the second writer
merges its changes onto the state it read *before* the first writer committed,
then overwrites — silently discarding the first writer's fields it did not
itself touch. This is a classic lost-update, and for a system-of-record data
platform it means concurrent edits (two users, or a user + an automation)
silently lose data with no error surfaced.

OpenRegister already has a `LockHandler` and objects carry version metadata, so
the primitives to fix this exist; the PATCH adapter simply does not use them.

## What Changes

- Add optimistic concurrency to the PATCH path: capture the object's version (or
  a content hash) at `find()` time and pass an expected-version assertion into
  `saveObject()`. If the row changed since the read, reject with HTTP 409
  Conflict instead of overwriting.
- Honour a client-supplied `If-Match`/etag when present, so API clients can opt
  into conditional updates explicitly.
- Alternatively (or additionally) take a short row lock via `LockHandler` for the
  read-merge-write span where optimistic retry is undesirable.

## Impact

- Affected: `lib/Service/ObjectServiceMapperAdapter.php`, `ObjectService::saveObject()`
  (accept/verify an expected-version parameter), possibly `ObjectsController`
  (surface 409 + `If-Match`).
- Behavioural change: concurrent conflicting PATCHes now yield a 409 instead of
  a silent overwrite — clients must handle/retry. Document in the API spec.
- Risk: low; non-concurrent PATCHes are unaffected. Ensure the version check is
  cheap (single indexed column) to avoid a perf regression on the hot path.
