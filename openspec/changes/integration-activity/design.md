# Design: Integration — Activity

> Umbrella decisions apply.

## Approach

Unique among integrations — no link table. `ActivityFeedService` queries NC Activity per-render filtered by the object's related entities (linked files, tasks, comments, users). Returns a merged feed.

## Architecture Decisions

### AD-1: New storage strategy `query-time`

**Decision**: Add `'query-time'` to the umbrella's storage strategy enum (`magic-column | link-table | external | query-time`). Activity is the only one using it in this wave.

**Why**: Activity events aren't owned by OR — they live in NC Activity. A link table would add redundant state and staleness risk. Query-time filtering of the real source is correct.

**Trade-off**: Per-render query cost. Mitigated by reasonable pagination + backend caching at NC Activity layer.

### AD-2: Activity is read-only; `create`/`update`/`delete` throw NotImplemented

**Decision**: Provider's CRUD methods throw for mutations. Only `list` and `get` are functional.

**Why**: OR never creates/edits/deletes activity events — NC Activity owns that. Throwing makes the constraint explicit at the contract level.

## Files Affected

### Umbrella modification
- `IntegrationProvider::getStorageStrategy()` docblock gains `'query-time'` option (covered in umbrella tasks.md — this leaf triggers a micro-update to umbrella enum docs)

### Backend (new)
- `ActivityFeedService`, `ActivityController`, `ActivityProvider`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnActivityTab/*`, `CnActivityCard/*`, `src/integrations/builtin/activity.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Activity query slow on very busy objects | Server-side pagination + activity-type filter chips |
| Feed noise drowns the signal | Filter chips for event types (files, comments, tasks) + saved filter preferences |
| Overlap with OR audit trail confuses users | Clear labelling: Activity tab = "what happened in NC around this"; Audit tab = "immutable OR event log" |
