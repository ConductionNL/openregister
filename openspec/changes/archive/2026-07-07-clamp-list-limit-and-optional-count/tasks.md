## 1. Clamp page size

- [ ] 1.1 Apply a hard max to the effective limit centrally in `QueryHandler` (`:344`) / `getConfig()`: `$limit = min(max(0, $limit), self::MAX_PAGE_SIZE)` with `MAX_PAGE_SIZE` (e.g. 1000).
- [ ] 1.2 Confirm `ObjectsController.php:655` and any other list entry inherit the clamp (clamp at the shared layer, not per-controller).

## 2. Optional count

- [ ] 2.1 In `QueryHandler` (`:367-386`), when `_count=false`/`_noTotal` is present, skip building/executing `$countQuery` and return `total: null`.
- [ ] 2.2 Document the flag in the API/OpenAPI spec.

## 3. Verification

- [ ] 3.1 Test: `?_limit=1000000` returns at most `MAX_PAGE_SIZE` rows.
- [ ] 3.2 Test: `?_count=false` issues no COUNT query (assert via query log) and returns `total: null`.
- [ ] 3.3 Test: default behaviour (no flag) unchanged — total present, default page size 20.
- [ ] 3.4 `composer check:strict` passes.

## Acceptance criteria

- No list request can load more than `MAX_PAGE_SIZE` rows.
- Clients can opt out of the total-count query.
- Default list behaviour is unchanged.
