# Tasks: insert-race-follow-ups

- [x] 1.1 `MagicMapper::insert()` announces `ObjectUpdatedEvent` (stored row as old) when the insert replaced a row, else `ObjectCreatedEvent` as before.
- [x] 1.2 `ObjectService::checkSavePermissions()` judges a stored row hidden from the caller by the update rule against that row (RBAC on).
- [x] 1.3 `ImportHandler` inserts a seed object once.
- [x] 1.4 Tests for each, failing first.
