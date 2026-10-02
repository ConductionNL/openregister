# Tasks: names-follow-read-rights

## 1. One rule for names

- [x] 1.1 `MagicMapper::filterReadableUuids()` answers which UUIDs of one table the caller may read, through `applyAccessControlToQuery` with RBAC and multitenancy on, and answers none on any failure (REQ-NFR-01)
- [x] 1.2 `CacheHandler` records a source with every cached name and decides object names through 1.1, organisation names through the organisation scope (REQ-NFR-01)
- [x] 1.3 A cached entry without a source is a miss, in memory and in the distributed cache (REQ-NFR-02)

## 2. Tests

- [x] 2.1 `CacheHandlerNameReadRightsTest`: readable gets the name, unreadable gets nothing (own organisation included), admin unchanged, both caches, legacy entries, organisation names (red on development, green here)
- [x] 2.2 `MagicMapperFilterReadableUuidsTest`: the read rule is applied with both flags and the table's register, chunked, fail closed
