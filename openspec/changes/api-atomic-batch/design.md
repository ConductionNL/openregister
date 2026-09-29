# Design: api-atomic-batch

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| Bulk save | `lib/Controller/BulkController.php` save |
| Bulk writer | `lib/Db/MagicMapper/MagicBulkHandler.php` |

## Approach

1. Wrap the atomic path in `IDBConnection::beginTransaction()`; collect events in a buffer that flushes on commit and is dropped on rollback.

## Declarative or imperative

Imperative request flag.

## Tests

- PHPUnit on a real database connection double that records transaction calls: a batch whose third row is invalid writes nothing and names row 2; events are dispatched only after commit.
