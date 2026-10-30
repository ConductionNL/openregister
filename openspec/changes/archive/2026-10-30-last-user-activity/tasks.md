# Tasks: last-user-activity

## 1. Code

- [x] 1.1 `ObjectEntity::$lastUserActivity`, serialised as `@self.lastUserActivity`.
- [x] 1.2 `_last_user_activity` column, written from `@self`, never defaulted to now, indexed on new tables.
- [x] 1.3 Read back on both hydration paths (`MagicStatisticsHandler`, `MagicSearchHandler`).
- [x] 1.4 Filterable through `METADATA_COLUMNS`; sortable through a sanitised `@self.` sort key.
- [x] 1.5 `UserActivityStamper`, called from `SaveObject` on create and update.

## 2. Tests and docs

- [x] 2.1 `tests/Unit/Service/Object/UserActivityStamperTest.php`: web request, CLI, cron, no user, system scope, every non-person cause.
- [x] 2.2 `tests/Unit/Db/LastUserActivityMetadataTest.php`: filter on both paths, sort, read back on both paths.
- [x] 2.3 `tests/Unit/Service/MagicMapperTest.php`: written as carried, never defaulted.
- [x] 2.4 `docs/Features/search.md`.
