---
kind: code
depends_on: []
---
# Proposal: read-history-on-audit-trail

## Summary

"Recently opened" kept its own table, `openregister_object_views`, with its own throttle, cap and cleanup. The audit trail already records every audited read with the reader and the moment. Ruben ruled on 9 October 2026, reviewing the dossiq dashboard: one generic abstraction in OpenRegister on top of logging, and the separate table goes.

This change adds `ReadHistoryService` as the one place a read of an object is registered. It writes the audit trail's `read` row and the AVG processing-log entry, each through its own existing storage. The `_recent` lens reads the reader's history back from the audit trail, and every returned object carries `@self.viewedAt`. With the audit trail switched off the lens is empty and the response says why.

## Why

- Two records of the same fact drift. The view table throttled to one row a minute, the audit trail did not, and the two could disagree about when someone last opened a case.
- The view table needed its own cap (100), its own trim and its own cleanup listener. The audit trail already has retention.
- dossiq's dashboard tile wants "opened today" and "opened yesterday". That needs the moment per object, on the list response.

## What changes

- New `lib/Service/Interaction/ReadHistoryService.php`: `registerAuditRead()`, `registerProcessingRead()`, `resolveRecentLens()`.
- `GetObject::find()` writes its `read` row through `registerAuditRead()`. Behaviour is unchanged: same gate on `retention.auditTrailsEnabled`, same per-call `$_audit` opt-out, same row.
- `ObjectService::logProcessingRead()` writes the AVG entry through `registerProcessingRead()`, which delegates to `ProcessingLogService` unchanged.
- New `AuditTrailMapper::findLatestReadsByUser()`: per user, distinct objects, latest read first, capped at 100.
- `_recent=true` resolves the history at the edge (`SearchQueryHandler`) onto `_ids`, orders the page by it, and sets `@self.viewedAt` (ISO 8601) on each object. The response carries `@self.lenses.recent = {"available": bool, "reason": string|null}`.
- Migration `Version1Date20261009100000` adds index `or_audit_user_read_hist` on `openregister_audit_trails (user, action, object_uuid, created)`.
- Migration `Version1Date20261009100100` drops `openregister_object_views`.
- Removed: `ViewHistoryService`, `ObjectView`, `ObjectViewMapper`, the `recordObjectView()` call in `ObjectsController::show()`, and the view half of `FavouritePruneListener`.

## Out of scope

- Favourites (`ObjectFavourite`, `_favourite`) stay as they are.
- The AVG processing log keeps its table, retention and readers. See design D2.

## Related

- openregister#4507 fixes the missing table prefix in the old `applyRecencyOrder()` subquery. This change removes that subquery: the order is a `CASE` over bound parameters and names no table.
- dossiq's recent tile consumes `@self.viewedAt` by that exact key.
