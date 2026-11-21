# Tasks: records-copy-with-links

## 1. Service and route

- [ ] 1.1 `CopyObject::copy()` with payload building, create through `SaveObject`, and the three link kinds under the caller's rights, in one transaction for data. Verify: `tests/Unit/Service/Object/CopyObjectTest.php` for each kind, a refused link, and a rollback when the create fails.
      > 2026-11-13, partial: `CopyPayload` builds the create body (the source's fields plus `overrides`, never `id`, `uuid`, `@self`, `_` keys or `x-openregister-generated` properties), and the copy is created through the normal create path under the caller's rights (`CopyPayloadTest`). The three link kinds and the transaction around them are still to build; until then a request with `include` is refused with 422.
- [ ] 1.2 Files copied after commit with per-file outcomes. Verify: the same test with a fake file service that fails one file.
- [ ] 1.3 `ObjectsController::copy()` and the route beside `objects#move`, answering 201 with the link report; 403 without `create`. Verify: `ObjectsControllerTest` and a Newman case.
      > 2026-11-13, partial: `objects#copy` sits beside `objects#move`. `ObjectsController::copy()` returns 401 with no session, 404 when the source cannot be read, 403 without `create`, 422 for `include` or `_upsertOn`, and 201 with `{object, links}`. A refused create answers as the create path does (`ObjectsControllerCopyTest`). The Newman case is still open.

## 2. Proof and docs

- [ ] 2.1 Add `tests/e2e/ci/copy-with-links.spec.ts`: copy an entry that has two relation rows and sits in one array reference; assert both rows and the reference on the copy.
- [ ] 2.2 Document the copy endpoint in `docs/` beside move, including what is never copied.

Acceptance:
- The source object is never changed by a copy.
- A copy has its own uuid, audit trail and versions from its first save.
