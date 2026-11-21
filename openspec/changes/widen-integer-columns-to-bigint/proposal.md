---
kind: code
---

## Why

An integer property without a `maximum` gets a 32-bit `integer` column, and one whose `maximum` is above 2147483647 gets a `bigint`. A schema that later adds such a maximum keeps its 32-bit column: the table-structure update widens DECIMAL columns only. Every insert of a larger value then fails with "value out of range for type integer".

Found live on integriq: `execution_trace.startedAtUs` and `finishedAtUs` hold microseconds since the epoch (about 1.8e15). Every endpoint call logged "failed to persist execution_trace", so no trace was ever stored. Integriq now declares the maximum, which fixes a fresh install, but an existing install keeps the narrow column without this change.

## What Changes

- The column widener also turns a `smallint` or `integer` column into a `bigint` when its property now maps to `bigint`. It runs on the same explicit paths as the DECIMAL widening: a table-structure update and `openregister:tables:reconcile --apply`.
- A `bigint` column is never narrowed.

## Impact

- `lib/Db/MagicMapper/MagicDecimalWidener.php`
- `tests/Unit/Db/MagicMapper/MagicDecimalWidenerMySqlTest.php`
