# Design: api-atomic-batch

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| Bulk save | `lib/Controller/BulkController.php` save |
| Bulk writer | `lib/Db/MagicMapper/MagicBulkHandler.php` |

## Approach

1. Wrap the atomic path in `IDBConnection::beginTransaction()`; collect events in a buffer that flushes on commit and is dropped on rollback.

### As built (30 Sep, fixed against the code at HEAD e010e24916)

- `BulkController::writeAtomicBatch()` wraps the ordinary non-streaming save in one transaction. The save path refuses invalid rows one by one and keeps writing; the wrapper commits only when every row wrote, and otherwise rolls back and answers 422 naming the first refused row. A save that throws is rolled back and rethrown to the existing 500 / 422 handlers.
- No event buffer was needed: the bulk save runs with `events: false`, so no object event or webhook leaves the transaction; audit rows it writes roll back with the objects. The buffer in the approach above is therefore not built.
- Nextcloud's connection nests transactions as savepoints, so the per-chunk transactions in `MagicBulkHandler` sit inside the atomic one.
- `ATOMIC_BATCH_LIMIT` = 1000 rows; over it the answer is 413 carrying `limit`.
- `stream` and `partial` do not apply to an atomic batch.
- Known limit: on MySQL a schema whose table does not exist yet is created on first write, and DDL commits implicitly. Send the first object of a new schema outside an atomic batch.

## Declarative or imperative

Imperative request flag.

## Tests

- PHPUnit on a real database connection double that records transaction calls: a batch whose third row is invalid writes nothing and names row 2; events are dispatched only after commit.
- As built: `tests/Unit/Controller/BulkAtomicSaveTest.php` runs a REAL SQLite transaction; the save double writes into a table on the same connection, so a missing rollback leaves countable rows.
