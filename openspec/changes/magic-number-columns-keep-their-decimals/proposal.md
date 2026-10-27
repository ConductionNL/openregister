# Magic number columns keep their decimals

## Why

Every schema `number` property got a `DECIMAL(10,2)` column in its magic table. A value with more than two decimals was rounded by the database without a word: pipelinq's product weight 0.005 kg was stored as 0.01. A value of 10^8 or more did not fit at all. That is silent data loss for every app that stores a measurement, a rate or a coordinate.

## What changes

- A `number` property's column takes its scale from the schema: the decimals of `multipleOf` (0.001 gives scale 3). Without `multipleOf` the column is `DECIMAL(20,6)`. The column always has 14 digits before the point.
- An existing column narrower than that is widened in place on the table's first use in a process, and `openregister:tables:reconcile` reports and repairs it. A column is never narrowed, so no stored value changes.
- A value the column cannot hold (more decimals than its scale, or more digits before the point) is refused on save with a validation error naming the property, on the single-object and the bulk path. It is never rounded.

## Impact

- Reads are unchanged: a `number` value is cast to float on read, so `12.500000` still reads as 12.5.
- `multipleOf: 0.01` (money) keeps scale 2 and gains digits before the point.
