# Tasks: recently-opened-means-opened

## 1. Lookup cause

- [x] 1.1 `WriteCause::LOOKUP` and `WriteCause::asLookup()`, which opens the frame only over `person`
- [x] 1.2 `AuditTrailMapper::findLatestReadsByUser()` counts causes `person` and empty only
- [x] 1.3 Wrap every lookup site of design D2 in `WriteCause::asLookup()`

## 2. Cross-table recent lens

- [x] 2.1 `MagicSearchHandler::buildWhereConditionsSql()` applies `_ids` on the UNION path
- [x] 2.2 `RecentLensOrder`: views, precedence, sort, stamp
- [x] 2.3 `MagicMapper::searchAcrossMultipleTables()` orders, pages and stamps a `_recent` page
- [x] 2.4 `MagicMapper::getGlobalSearchResult()` orders and stamps a `_recent` page
- [x] 2.5 `ObjectsController::crossTableSearch()` surfaces `@self.lenses.recent`

## 3. Tests

- [x] 3.1 `WriteCauseTest`: lookup over person, lookup inside another cause, vocabulary of seven
- [x] 3.2 `AuditTrailMapperReadHistoryTest`: lookup and rule reads excluded, legacy empty cause kept
- [x] 3.3 `RecentLensLookupCallSitesTest`: the cause seen by `find()` from representative callers
- [x] 3.4 `MagicMapperRecentLensTest` and `MagicSearchHandlerIdsSqlTest`: UNION `_ids`, order, paging, `viewedAt`, explicit order wins

## 4. Docs

- [x] 4.1 `docs/features/favourites-and-recent.md`: what counts as opened, cross-table searches
