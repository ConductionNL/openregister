# Tasks: api-upsert-on-a-declared-key

## 1. Handler

- [x] 1.1 Add `lib/Service/Object/UpsertOnKeyHandler.php`: resolve the named `refuse` constraint (legacy `unique` included), read the key values, call `MatchResolver::resolve()`, and decide create, update or refuse. Verify: `tests/Unit/Service/Object/UpsertOnKeyHandlerTest.php` covers zero, one and two matches, a `report` constraint, an unknown name and a missing key value.
- [x] 1.2 Take and release the per-key lock through `ILockingProvider` around the lookup and the save (design D-4), and turn `MatchLookupFailedException` into a 503 with nothing written (D-3). Verify: `UpsertOnKeyHandlerTest` asserts the lock path is a hash, the lock is released when the save throws, and a failed lookup never reaches `saveObject()`.

## 2. Controller

- [x] 2.1 Read `_upsertOn` in `ObjectsController::create()` from the raw request; refuse it for an anonymous caller (401) and together with `_failIfExists` (400); map the outcomes to 201, 200, 400, 403, 409 and 503, dropping `conflictingObject` on the unseen-holder 409 (design D-5). Verify: `tests/Unit/Controller/ObjectsControllerUpsertOnKeyTest.php`; a Newman collection `tests/integration/openregister-upsert-on-key.postman_collection.json` (under tests/integration because that is the directory CI's newman job runs; registered in tests/newman/run-all.sh) asserts 201 then 200 for the same key and 409 for a duplicated key.
- [ ] 2.2 (open: needs a live instance under concurrent load; the lock path and release are unit-tested in 1.2) Prove the race is closed. Verify: `tests/Integration/UpsertOnKeyConcurrencyTest.php` fires 12 concurrent calls with one key and finds exactly one record; one call answers 201 and every other call answers 200, or 503 with Retry-After (Ruben, 5 Oct, DECISIONS row 66: the short wait stays; the live pass of 5 Oct saw one record, 1x201 + 6x200 + 5x503 with Retry-After: 5). That the 503 carries Retry-After through the controller: `ObjectsControllerUpsertOnKeyTest::testAKeyAnotherCallHoldsIs503WithRetryAfter`.

## 3. Generated document

- [x] 3.1 Document `_upsertOn` with the schema's `refuse` constraint names as `enum`, and the added statuses, in `OasService::createPostOperation()`. Verify: `tests/Unit/Service/OasServiceUpsertParameterTest.php`; `GET /api/registers/{id}/oas` for a schema with constraint `zaaksleutel` lists it.

## 4. Docs and end-to-end test

- [x] 4.1 Document the upsert, the key rule and every status in `docs/api/objects.md` with a curl example using `<API_KEY>`. Verify: `npm run build` in `docs/` succeeds (left to the docs CI build; no local production build).
- [ ] 4.2 (spec written, ticked once it has run green in CI) Add `tests/e2e/ci/upsert-on-key.spec.ts`: a signed-in integration creates then updates by key, an anonymous call is refused, and a duplicated key is refused with its matches. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- A POST without `_upsertOn` behaves exactly as before, proven by the existing create tests passing unchanged.
- No response on the upsert path names a uuid the caller cannot read.
