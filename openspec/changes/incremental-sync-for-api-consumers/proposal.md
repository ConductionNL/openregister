---
kind: code
depends_on: [delete-window-and-recorded-destruction]
---

# Proposal: incremental-sync-for-api-consumers

## Summary

An API consumer that mirrors a register needs two answers on every run:
what changed since the last run, and what was deleted since the last run.
OpenRegister answers the first and not the second. This change makes a
deletion visible to a changed-since read, lets the deleted list be read
from a point in time, reports objects destroyed after their bin window,
and hands the consumer a server-side watermark to start the next run from.

## Rows covered

- `int-incremental-sync` (added to this repo's `openspec/parity/capabilities.json`
  in the spec round of 7 Oct 2026): "Fetch only what changed since your
  last sync through the API, including a list of deleted items."
- planninq row `int-incremental-sync` names OpenRegister as the owner
  ("Sibling-owed: openregister owns this capability, not planninq").
  planninq links this change as `openregister/incremental-sync-for-api-consumers`.

## Why

planninq's untis reader proposed the row: a timetable system pulls lessons
and rooms on a schedule and must drop the ones that were removed. Any app
that syncs OpenRegister objects into another system has the same need.

What works today, read on development (e3c2954612):

- A changed-since read works through the `@self` metadata filter.
  `_updated` is a filterable metadata column
  (`lib/Db/MagicMapper/MagicSearchHandler.php:146`) and `gte` is among the
  comparison operators (`:94`).
- `_includeDeleted=true` returns soft-deleted rows (`:483`).

What does not:

- **A soft delete leaves `updated` alone.** `DeleteObject` writes the
  `deleted` block (`lib/Service/Object/DeleteObject.php:334-361`) and never
  sets `updated`. A changed-since read with `_includeDeleted=true`
  therefore misses every object deleted after the consumer's last run,
  unless it happened to change in the same window.
- **`GET /api/deleted` ignores filters.** `DeletedController::index`
  passes only `limit` and `offset` to
  `findDeletedAcrossAllMagicTables` (`lib/Controller/DeletedController.php`).
  A consumer cannot ask for one register, one schema, or deletions after a
  date.
- **A destroyed object leaves no trace a sync can read.** After the bin
  window the row is gone. Its destruction is an audit-trail entry
  (`DestructionScope::DESTRUCTION_ACTION`), readable only per object id.
  A consumer that last ran before the destruction never learns of it.
- **The consumer's clock decides the next window.** Nothing in the
  response says which server time the read covers, so a consumer with a
  skewed clock, or a write landing during the read, drops changes.

## What changes

- A soft delete and a restore set the object's `updated` to the time of
  the act.
- `GET /api/deleted` accepts `register`, `schema` and `deletedSince`,
  applied before pagination and before the count.
- `GET /api/deleted` with `deletedSince` and `_includeDestroyed=true` also
  lists objects destroyed since then, as tombstones read from the audit
  trail, under the same read scope as the destruction record endpoint.
- Object list responses carry a `syncWatermark`: the server time taken
  before the query ran. The consumer passes it as `since` next time.

## What does not change

- The object list keeps excluding deleted rows by default (deletion-audit-trail
  Requirement 12).
- No new endpoint and no new table. The tombstones are the audit trail's
  own destruction entries (ADR-003: the audit trail is the record).
- The bin window, restore and destruction rules of
  `delete-window-and-recorded-destruction` stay as they are.

## Open questions

None for this change. The open OpenRegister questions with Ruben
(`for-ruben/openregister-gate23-gap-questions.md` Q1, Q2, Q4, Q6, Q9) do
not touch the sync read.
