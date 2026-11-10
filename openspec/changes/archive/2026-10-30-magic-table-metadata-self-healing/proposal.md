---
kind: code
depends_on: [object-archive-state]
---

# Proposal: magic-table-metadata-self-healing

## Summary

A magic table that lacks a metadata column (`_archived`, `_frozen`,
`_status`, ...) heals itself the first time it is used, instead of failing
every list of it until an app upgrade runs a repair step.
`occ openregister:tables:reconcile` reports and repairs the same drift, and
the upgrade step, the command and the request path share one helper.

- Ruben decided this on 14 October 2026: fix it in OpenRegister itself, so no
  installation depends on an upgrade having run.
- Dependencies: `object-archive-state`, which introduced `_archived` and
  `_frozen` and the `AddObjectStateColumns` repair step.

## Why

Measured on the development instance on 14 October 2026:

- 38 of 1,712 magic tables lacked `_archived`, `_frozen` and `_status`
  (6 also lacked `_quality`).
- Every object list puts `_archived IS NULL` in its WHERE, so each list of
  those tables failed with `column t._archived does not exist`. Postgres
  logged that on almost every request (a 2.6 GB container log).
- The columns arrived only through the post-migration repair step, which runs
  only when the app version changes. A developer mount moved forward without
  a version change, so it never ran. A table restored from a backup, or made
  by an older code path, is in the same state on any installation.
- `occ openregister:tables:reconcile --apply` reported "No drift": it walked
  only the schemas a register still lists, and 37 of the 38 tables were not
  among them (14 had no register row, 15 no schema row).
- `occ maintenance:repair` took more than 15 minutes in another app's step
  and held the instance in maintenance mode, so it is not a remedy.

## What changes

1. `MagicMetadataColumnHealer`: one class that finds and adds missing metadata
   columns, with a per-process memo and a distributed-cache answer keyed by a
   version hash of the metadata column list.
2. The table-existence check every list, count, facet and cross-table search
   already makes calls the healer, so the first use of a drifted table heals it.
3. A table that cannot be healed is logged once and its lists leave the
   missing column out of the archive and draft predicates, which is exact
   because a table without the column holds no archived or draft row.
4. `occ openregister:tables:reconcile` reports and, with `--apply`, adds
   missing metadata columns on every physical magic table.
5. `AddObjectStateColumns` walks every physical magic table through the same
   healer.
