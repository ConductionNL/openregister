---
kind: code
depends_on: []
---

# Proposal: boolean-filter-values

## Why

A property filter whose value is a PHP boolean returns no rows. A filter with the
same value as a string returns them.

Found live on 2026-10-10 on a throwaway instance (PostgreSQL 16, Nextcloud 34,
openregister `development` at `ae4084d72a`). dossiq's work queue asks for the open
cases of a person with `'statusHiddenInLists' => false, 'isDraft' => false`
(`lib/Service/WorkQueueService.php:502-506` on dossiq `development`). Over 12 cases
that all hold `false` in both columns, `searchObjects()` gave:

| filter | rows |
|---|---|
| `assignee` only | 6 |
| `+ statusHiddenInLists => false` | 0 |
| `+ isDraft => false` | 0 |
| both as bool `false` | 0 |
| both as string `'false'` | 6 |

So `/api/work-queue` answers `{"items":[]}`, the queue shows no urgency pills, and
the urgency sort falls back to newest first.

## Cause, in the code

The condition builders turn a filter value into SQL with `(string)$value`
(raw SQL path, `MagicSearchHandler::buildObjectFilterConditionsSql()`) or bind it
as a string parameter (QueryBuilder path, `applyObjectFilters()`, and
`MagicFacetHandler`). PHP turns `false` into `''` and `true` into `'1'`.
PostgreSQL refuses `boolean = ''` with `SQLSTATE[22P02] invalid input syntax for
type boolean: ""`. The mapper catches that, logs "Failed to search register+schema
table" and returns an empty result. The caller sees zero rows, not an error.

On MySQL and MariaDB a boolean column is a `TINYINT(1)`. There `''` and `'true'`
both compare as `0`, so `false` happened to work and the string `'true'` matched the
`false` rows.

## What changes

- A filter value that is a PHP boolean filters exactly like its string form, on
  every condition builder: equality, `in`, `notIn`, `ne`, and the facet counts.
- On a boolean property the values `true`, `false`, `'true'` and `'false'` (any
  case) are bound as `'1'` and `'0'`, which PostgreSQL, MySQL and MariaDB all read
  as the boolean. On a numeric property a PHP boolean becomes `1` or `0`. On any
  other property it becomes `'true'` or `'false'`.
- The normalisation lives in one place, `FilterParams::comparableValue()`, so the
  three builders cannot disagree again.

## What does not change

- **A null or missing value matches neither `true` nor `false`.** `isDraft=false`
  returns the rows that hold `false`, not the rows where `isDraft` was never set.
  This is what the string form `'false'` already did, so it keeps the existing
  answer and widens nothing. A caller that means "false or unset" fills the value
  (dossiq's seed and repair step do, finding B3) or, once it lands, uses
  `inOrEmpty` from `search-value-or-empty-filter`.
- A filter value of PHP `null` keeps its current meaning. `_isnull` and the
  `IS NULL` sentinel stay the way to ask for a missing value.
- The `isnull` and `like` operator values are not touched: they are read by their
  own rules.

## Impact

- `lib/Support/FilterParams.php`, `lib/Db/MagicMapper/MagicSearchHandler.php`,
  `lib/Db/MagicMapper/MagicFacetHandler.php`.
- Fleet callers that pass a PHP boolean start getting their rows. They are listed
  in the PR body.
