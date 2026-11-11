# Design: read-history-on-audit-trail

## Context

Three read records existed side by side:

| Record | Written by | Gate | Storage |
|---|---|---|---|
| Audit `read` row | `GetObject::find()` | `retention.auditTrailsEnabled` and per-call `$_audit` | `openregister_audit_trails`, hash-chained |
| View row | `ObjectsController::show()` via `ViewHistoryService` | logged-in caller, 60 s throttle | `openregister_object_views`, one row per (user, object), trimmed to 100 |
| AVG processing-log entry | `ObjectService::find()` via `ProcessingLogService` | schema opt-in `x-openregister-processing.logReads` | `openregister_processing_log`, append-only |

The view row repeated what the audit row already said, for every detail open with the audit trail on.

## D1 · One read registration, `ReadHistoryService`

Every read of an object is registered through `ReadHistoryService`. It has two write methods, one per record, and one read method for the `_recent` lens.

The two write methods are called at the points that called the old writers, not merged into one call. The audit row is written in `GetObject::find()`, which GraphQL and `QueryHandler` also reach directly. The AVG entry is written in `ObjectService::find()` after the permission check. Moving either write would change what is recorded: the audit trail would lose GraphQL reads, or the AVG log would start recording reads that were refused. Neither is this change's call to make.

## D2 · The AVG processing log keeps its own storage

Ruling 2 asked whether ProcessingLog can be unified into the audit trail without weakening it. It cannot, on four counts:

1. **It cannot be switched off.** The audit trail follows `retention.auditTrailsEnabled`. AVG art. 30 and art. 5(2) require the log of every processing of personal data whatever an administrator sets for the audit trail. Stored in the audit trail, it would switch off with it.
2. **Internal loads still count.** A caller passing `_audit: false` skips the audit row. The AVG hook ignores that flag on purpose: an internal load of personal data is still a processing.
3. **Retention differs.** Audit rows are tombstoned on `expires` (payload and `user` blanked). The processing log is pruned by `ProcessingLogMapper::deleteCreatedBefore()` on its own term. One table cannot hold both terms without one of them losing.
4. **Readers differ.** The processing log is read through `ProcessingLogController`, admin or FG group only, and per data subject. The audit trail is readable within a caller's own scope (`audit-trail-immutable`). Merging would widen who can read the AVG log.

So the processing log keeps its table, columns, opt-in, attribution, retention and readers. It is written through `ReadHistoryService::registerProcessingRead()`, which delegates to `ProcessingLogService::logRead()` and `flush()` unchanged. Nothing it records changes.

**The processing log is never read for the `_recent` lens.** It exists for accountability. Reading it to drive a convenience feature would be a use outside its purpose (doelbinding, AVG art. 5(1)(b)).

## D3 · The `_recent` lens reads the audit trail

`AuditTrailMapper::findLatestReadsByUser()` runs:

```sql
SELECT object_uuid, MAX(created) AS last_read
FROM *PREFIX*openregister_audit_trails
WHERE user = :uid AND action = 'read' AND object_uuid IS NOT NULL
GROUP BY object_uuid
ORDER BY last_read DESC
LIMIT 100
```

The new index `or_audit_user_read_hist (user, action, object_uuid, created)` answers it from the index alone. Built through the query builder, so the table prefix applies.

`SearchQueryHandler` resolves the history once, at the edge, the same way the watching lens works:

- the uuids land on `_ids` (intersected with any `_ids` already there, keeping history order), so every search path honours the lens, the cross-table UNION included, and RBAC is still applied by the object query;
- `_recentViews` (uuid => ISO 8601) carries the order and the moments to `MagicSearchHandler`, which orders the page by a `CASE` over bound parameters and sets `@self.viewedAt`;
- `_recentLens` carries the report the response surfaces as `@self.lenses.recent`.

The three internal keys are stripped from the incoming request first, so a caller cannot hand in another user's history or forge `viewedAt`.

The cap of 100 is distinct objects in the history, before RBAC. An object deleted or no longer readable still takes a slot, so a page can show fewer than 100. That is accepted: the cap bounds the query, it was never a promise of length.

## D4 · Response shape

Per object, on a `_recent=true` page only:

```json
{ "@self": { "id": "…", "viewedAt": "2026-10-09T10:15:00+00:00" } }
```

Response level, whenever `_recent=true` was asked:

```json
{ "@self": { "lenses": { "recent": { "available": true, "reason": null } } } }
```

`available: false` comes with one of three reasons:

| reason | when |
|---|---|
| `audit-trail-disabled` | `retention.auditTrailsEnabled` is false |
| `anonymous` | no logged-in user |
| `read-history-unavailable` | the history query failed, or the service could not be resolved |

`available: false` always comes with an empty page. With the audit trail off there is no shadow log (ruling 1).

`@self.lenses` sits next to the existing response-level reports `@self.history` and `@self.dictionary`.

## D5 · No write throttle

The old 60 second throttle lived in `ViewHistoryService` and existed because the view table refreshed one row. The audit trail writes one `read` row per audited GET, and did so before this change. This change adds no write: it removes one (the view row). Throttling audit rows would drop legally relevant records, so the abstraction does not throttle. The history collapses repeated reads at query time with `GROUP BY`.

## D6 · Dropping the view table

The rows are not migrated. Every view row recorded a detail GET that also wrote an audit `read` row while the audit trail was on, so the history survives. While the audit trail was off, the old table held views the new lens will not show, by ruling 1.

`FavouritePruneListener` no longer clears views. Audit rows are a legal record with their own retention and must not be deleted with the object. A purged object no longer matches the object query, so it leaves the lens on its own.

## Risks

- **Semantics widen slightly.** The old view was written by the detail route only. The audit `read` row is written by every audited `find()`, including service code that reads an object in the caller's session without `_audit: false`. Such an object now shows as recently opened. Those callers should pass `_audit: false` anyway, since the same read pollutes the audit trail.
- **Heavy readers.** A uid with millions of read rows (a sync account) makes the `GROUP BY` scan its whole index range. Interactive users do not reach that volume; if one does, a lookback window is the next step.
- **Cross-table searches** get the restriction through `_ids`, but not the recency order or `viewedAt`: those are applied per table in `MagicSearchHandler::searchObjects()`, and the cross-table paths assemble rows elsewhere.
