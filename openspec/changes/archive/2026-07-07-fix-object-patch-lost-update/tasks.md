## 1. Optimistic concurrency on PATCH

- [ ] 1.1 In `ObjectServiceMapperAdapter.php:189-215`, capture `$existing`'s version (or content hash) at `find()` time.
- [ ] 1.2 Pass an expected-version assertion into `ObjectService::saveObject()`; if the persisted row's version no longer matches, throw a conflict (HTTP 409) rather than overwriting.
- [ ] 1.3 Extend `saveObject()` to accept and verify the expected version at the mapper boundary (compare-and-set), returning the conflict signal.

## 2. Conditional-request support

- [ ] 2.1 In `ObjectsController` PATCH, read an `If-Match` header when present and thread it as the expected version; emit the current etag/version on responses.
- [ ] 2.2 Map the conflict to a 409 with a body indicating the current version.

## 3. Verification

- [ ] 3.1 Concurrency test: two PATCHes read the same version; the first commits; the second gets 409 and does NOT clobber the first's untouched fields.
- [ ] 3.2 Test: a PATCH with a stale `If-Match` gets 409; with a current one, succeeds.
- [ ] 3.3 Perf check: the version comparison adds no unindexed query on the save hot path.
- [ ] 3.4 `composer check:strict` passes.

## Acceptance criteria

- Concurrent conflicting PATCHes cannot silently lose data; the loser gets 409.
- Non-conflicting PATCHes behave exactly as before.
- The API exposes a version/etag so clients can do conditional updates.
