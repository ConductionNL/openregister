# Design: magic-table-metadata-self-healing

## D-1 Where the check runs

`MagicTableHandler::existsTableForRegisterSchema()`. Every path that queries a
magic table asks it first: the single-table list, the count, the facets, the
UNION search and count, and the sequential cross-table search. When the
answer is "exists", the handler calls `MagicMetadataColumnHealer::ensure()`
with the table name.

It is not in `boot()` and it is not a sweep. ADR-106 forbids per-request work
that grows with the number of features; this check is per table, on first
use, and is answered from memory after that (D-2). ADR-106 rule 4 names a
cron job as the fallback for a repair step that may not have run; a cron job
would leave every list of a drifted table failing until it ran, which is the
failure this change removes. A cron job can still be added beside this
without changing it.

## D-2 Cost

1. Per process: a static memo, table name to the columns it still lacks. After
   the first call for a table, `ensure()` is an array lookup.
2. Across requests: the distributed cache (`openregister_magic_metadata`)
   holds "complete" for `HEALTHY_TTL` (one hour) under a key that includes a
   version hash of the metadata column names and types. Declaring a new
   metadata column changes the hash, so every table is checked again once.
3. Cold cache: one `information_schema.columns` query for that table.

`MagicMapper::dropTable()` forgets the table, so a table recreated under the
same name is checked again.

## D-3 Concurrency

Two requests may add the same column. On PostgreSQL the statement is
`ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, so both succeed. Elsewhere a
failed ALTER is followed by a fresh read of the columns; a column that is now
present counts as added, no warning.

On MySQL and MariaDB a DDL statement commits an open transaction. When a
transaction is open there, the healer does not alter the table in that
request; the search handler leaves the column out (D-4) and the next request
heals it.

## D-4 Failure

A column that cannot be added (no ALTER permission, a lock timeout) makes the
table "degraded" for `FAILURE_TTL` (15 minutes) in the distributed cache. The
warning is logged once when the table becomes degraded, not per request, and
names the remedy. While degraded, `missingFor()` answers the missing columns
and the search handler drops `_archived IS NULL` and the draft predicate for
that table (and answers no rows for the archived-only and drafts-only lenses).
That is exact: a table without `_archived` holds no archived row. A primary
key column cannot be retrofitted and is reported as failed.

## D-5 One helper, three callers

The request path, `openregister:tables:reconcile` and the
`AddObjectStateColumns` repair step all call the healer, through
`MagicMapper::inspectMetadataColumns()` and `healMetadataColumns()`. The
column list itself stays in `MagicMapper::getMetadataColumns()`, exposed as
`getMetadataColumnDefinitions()`. The command and the repair step walk the
physical tables (`MagicMapper::listMagicTableNames()`) rather than the
register to schema links, which is what made the earlier reconcile blind to
orphaned tables.
