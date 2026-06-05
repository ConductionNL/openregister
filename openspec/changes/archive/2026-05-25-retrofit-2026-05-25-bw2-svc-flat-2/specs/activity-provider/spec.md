---
retrofit: true
---

# Activity Provider

## Why

The existing IProvider/IFilter requirements cover how OpenRegister
activity events are published and how the NC activity-stream UI filters
them. They do not cover the Tier-2 REST read surface
(`ActivityFilterService`) that the bespoke object-sidebar Activity tab
calls to page through the activity entries linked to a single OR object.
That surface owns its own filter, pagination, and degradation contract,
which this change anchors.

## ADDED Requirements

### Requirement: Tier-2 Object Activity Read Surface
The service MUST resolve NC Activity entries linked to an OR object via the `[or:{objectUuid}]` subject marker, apply optional type / actor / date-range filters, return a bounded cursor-paginated page ordered newest-first, and return an empty result set (never throw) when the NC Activity app is not installed or a query fails.

`ActivityFilterService::getActivityEntries()` MUST match entries by the `[or:{objectUuid}]` marker in the `activity.subject` column, MUST apply the optional exact `type`, exact `actor` (`affecteduser`), and `after` (Unix-timestamp lower bound) filters when supplied, MUST clamp the requested page size into `[1, MAX_LIMIT]` defaulting to `DEFAULT_LIMIT`, MUST page descending by `timestamp` then `activity_id` using a strict-less-than cursor, and MUST return `{ results, total, nextCursor }` where `total` is the filter-set count ignoring the cursor and `nextCursor` is null on the last page. When the Activity app is unavailable the result MUST be `{ results: [], total: 0, nextCursor: null }`, and any DB error MUST be logged and degraded to an empty result rather than propagated.

#### Scenario: Filtered page returned with next cursor
- **GIVEN** an OR object has more linked activity entries than one page
- **WHEN** `getActivityEntries()` is called with a type filter and a page limit
- **THEN** only marker-matched entries of that type MUST be returned, newest-first
- **AND** `nextCursor` MUST carry the oldest returned entry's timestamp so the next call resumes correctly

#### Scenario: Activity app absent degrades to empty
- **GIVEN** the NC Activity app is not installed
- **WHEN** `getActivityEntries()` is called
- **THEN** the result MUST be `{ results: [], total: 0, nextCursor: null }` without throwing
