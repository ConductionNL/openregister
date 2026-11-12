# Tasks: read-history-on-audit-trail

## 1. Read registration

- [x] 1.1 Add `ReadHistoryService` with `registerAuditRead()`, `registerProcessingRead()` and `resolveRecentLens()`
- [x] 1.2 `GetObject::find()` writes its `read` row through `registerAuditRead()`
- [x] 1.3 `ObjectService::logProcessingRead()` writes through `registerProcessingRead()`, ProcessingLog storage unchanged

## 2. Read history

- [x] 2.1 `AuditTrailMapper::findLatestReadsByUser()`: distinct objects, latest read first, capped
- [x] 2.2 Migration `Version1Date20261009100000`: index `or_audit_user_read_hist (user, action, object_uuid, created)`

## 3. The `_recent` lens

- [x] 3.1 `SearchQueryHandler` resolves the history onto `_ids`, `_recentViews` and `_recentLens`, stripping caller-supplied copies
- [x] 3.2 `MagicSearchHandler` orders by the history (bound `CASE`, no table name) and sets `@self.viewedAt`
- [x] 3.3 `ObjectEntity` carries transient `viewedAt` in `@self`
- [x] 3.4 Both list builders surface `@self.lenses.recent`

## 4. Remove the view table

- [x] 4.1 Remove `ViewHistoryService`, `ObjectView`, `ObjectViewMapper` and the `recordObjectView()` call
- [x] 4.2 `FavouritePruneListener` no longer prunes views
- [x] 4.3 Migration `Version1Date20261009100100` drops `openregister_object_views`
- [x] 4.4 Update `docs/features/favourites-and-recent.md`

## 5. Tests

- [x] 5.1 `ReadHistoryServiceTest`: audit row, `_audit: false`, audit off, unreadable setting, processing log with audit off, lens states
- [x] 5.2 `AuditTrailMapperReadHistoryTest`: the query as SQL on the migrated table
- [x] 5.3 `ReadRegistrationWiringTest`: GetObject, ObjectService, ordering and `viewedAt` asserted from the callers
- [x] 5.4 `SearchQueryHandlerPersonalLensesTest`: history to `_ids`, audit off, intersection, forged keys stripped
- [x] 5.5 Migration tests for both migrations
- [x] 5.6 e2e `favourites-and-recent.spec.ts` asserts `@self.viewedAt` and `@self.lenses.recent`
