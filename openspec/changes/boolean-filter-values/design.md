# Design: boolean-filter-values

## D-1: one normaliser, three builders

`FilterParams::comparableValue(mixed $value, string $propertyType): mixed` is a pure
function. `applyObjectFilters()`, `buildObjectFilterConditionsSql()` and
`MagicFacetHandler`'s object filter call it on the value before they build the
condition. `FilterParams` is already the one place where the filter grammar lives
and already has its static-access exception in `phpmd.xml`, for the same reason:
paths that must reach the same answer call one function by name.

## D-2: `'1'` and `'0'` for a boolean column

PostgreSQL reads `'1'`/`'0'` as boolean input, and MySQL/MariaDB compare a
`TINYINT(1)` with them numerically. `'true'`/`'false'` only works on PostgreSQL.
So a boolean property maps `true`, `false`, `'true'`, `'false'` (case-insensitive)
to `'1'`/`'0'`. Other strings (`'1'`, `'0'`, `'t'`, `'yes'`) pass through as before.

## D-3: other column types

- integer and number: `true` is `1`, `false` is `0`, which stays numeric so the
  column is not cast to text.
- every other type: `'true'` / `'false'`, the JSON spelling of the value.

## D-4: arrays and operator bags

An array value is normalised element by element, and an operator bag key by key,
except `isnull` (read with `FILTER_VALIDATE_BOOLEAN`) and `like` (matched as text).

## D-5: null and unset

Not widened. SQL equality leaves `NULL` out, and so does this change. The decision
is recorded in the spec so a later reader does not "fix" it silently.
