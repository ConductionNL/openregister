# Tasks: object-watchers

## 1. Storage and service

- [x] 1.1 Migration: `openregister_watchers` (user, object uuid, register, schema, created) with a unique index on (user, object).
- [x] 1.2 `WatcherService`: watch, unwatch, list, add and remove another user (`manage`), cleanup on delete.

## 2. API and markers

- [ ] 2.1 Routes: `PUT`/`DELETE .../watch`, `GET .../watchers`, `PUT`/`DELETE .../watchers/{userId}`.
- [ ] 2.2 `@self.watching` and `@self.watcherCount` in `RenderObject`; `_watching=true` lens in the query parser.

## 3. Notifications

- [ ] 3.1 `{"watchers": true}` accepted by `NotificationAnnotationValidator`; resolved by the recipient resolver with the read check and list healing.

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/object-watchers.spec.ts`: watch an object, change it, see the notification, unwatch.
- [ ] 4.2 Unit tests for the service, the lens, the resolver and the validator; Newman for the routes.
