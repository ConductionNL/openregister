# Tasks: favourites-and-recent

## 1. Storage

- [x] 1.1 Migration for `openregister_favourites` and
      `openregister_object_views` with (user, object) indexes.
- [x] 1.2 Cascade both on object deletion.

## 2. API

- [x] 2.1 `PUT` and `DELETE .../favourite` endpoints.
- [x] 2.2 Record a view on detail reads, throttled per minute.
- [x] 2.3 `@self.favourite` marker on reads and lists.

## 3. Query

- [x] 3.1 `_favourite=true` and `_recent=true` lenses in the filter parser.

## 4. Tests

- [x] 4.1 Unit tests for the lenses, the throttle and the cascade.
- [x] 4.2 `tests/e2e/ci/favourites-and-recent.spec.ts`: star an object,
      open another, filter by each lens, see the expected rows.
