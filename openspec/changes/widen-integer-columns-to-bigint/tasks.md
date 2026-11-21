# Tasks: widen-integer-columns-to-bigint

- [x] 1.1 `MagicDecimalWidener::widenings()` widens a live `smallint`/`integer` column to `BIGINT` when the required column is `bigint`.
- [x] 1.2 PHPUnit: an integer column becomes bigint, a column that stays integer is left alone, a bigint column is never narrowed.
- [x] 1.3 Live: on a NC 34.0.6 instance with integriq, `openregister:tables:reconcile --apply` turned `execution_trace.started_at_us` and `finished_at_us` into `bigint`, after which endpoint calls stored their traces.
