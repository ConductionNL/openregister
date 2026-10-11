# Tasks: sequential-cross-table-search-orders-like-union

## 1. Tests first

- [x] 1.1 A test that runs the same multi-schema query on the sequential path and through the UNION path's merge, page by page, and expects the same uuids in the same order: property order, metadata order, default order, score order, an unknown metadata key.
- [x] 1.2 The recent lens orders the sequential path by the read history.

## 2. The sequential path

- [x] 2.1 Sort the merged entities on `buildUnionOrderKeys()` with `sortUnionRows()` before the page is cut.
- [x] 2.2 Ask every table for its rows in the resolved order, closed by the uuid tiebreaker.
- [x] 2.3 Update the paging test from #4522 to the uuid default order.
