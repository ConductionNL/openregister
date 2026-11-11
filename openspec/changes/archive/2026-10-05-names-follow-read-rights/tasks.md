# Tasks: names-follow-read-rights

## 1. One rule for names

- [x] 1.1 `MagicMapper::filterReadableUuids()` answers which UUIDs of one table the caller may read, through `applyAccessControlToQuery` with RBAC and multitenancy on, and answers none on any failure (REQ-NFR-01)
- [x] 1.2 `CacheHandler` records a source with every cached name and decides object names through 1.1, organisation names through the organisation scope (REQ-NFR-01)
- [x] 1.3 A cached entry without a source is a miss, in memory and in the distributed cache (REQ-NFR-02)

## 2. Tests

- [x] 2.1 `CacheHandlerNameReadRightsTest`: readable gets the name, unreadable gets nothing (own organisation included), admin unchanged, both caches, legacy entries, organisation names (red on development, green here)
- [x] 2.2 `MagicMapperFilterReadableUuidsTest`: the read rule is applied with both flags and the table's register, chunked, fail closed

## 3. System reads in a web request

- [x] 3.1 `MagicRbacHandler::isTrustedSystemCaller()`: userless on the command line, or inside `runAsSystem()`; never a logged-in user, never a forced-anonymous evaluation. Used by `applyRbacFilters()`; `buildRbacConditionsSql()` takes the runAsSystem arm only (REQ-NFR-03)
- [x] 3.2 `MagicOrganizationHandler::isSystemContext()` takes the same runAsSystem arm (REQ-NFR-03)
- [x] 3.3 `SystemOperationReadScopeTest` (red on development, green here)

## 4. Related schemas

- [x] 4.1 `SchemaMapper::getRelated()` looks up and scans schemas with multitenancy off; `SchemaMapperGetRelatedTenancyTest` (REQ-NFR-04)
