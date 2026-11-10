# Design: search-value-or-empty-filter

Read at openregister development 555af7212.

## Context

- `MagicSearchHandler::COMPARISON_OPERATORS` is
  `['gte', 'lte', 'gt', 'lt', 'in', 'notIn', 'ne', 'isnull']`
  (`lib/Db/MagicMapper/MagicSearchHandler.php:92`). `buildSearchQuery()`
  turns `?status_in[]=new` into `status => ['in' => ['new']]`, which is why a
  suffixed operator works only when it is in that list (the finding of the
  open change `isnull-filter-operator`, whose tasks are done).
- The operators of one property become separate conditions joined with AND
  (`:1535-1556` for the raw condition path; `isnull` at `:1546`). The
  QueryBuilder path for reference and array columns uses `orX()` inside one
  multi-value `in` (`:2560-2640`) but has no null branch.
- So `setting_in[]=X` and `setting_isnull=true` together match nothing: a row
  cannot be both.

## D-1: one operator, one parenthesised OR

`inOrEmpty` joins `COMPARISON_OPERATORS`. For a scalar column it emits
`(col IN (:values) OR col IS NULL OR col = '')`. For an array (JSON) column it
emits the existing any-of containment, OR `col IS NULL`, OR the column equals
an empty array. The values are bound parameters, as in `in`.

## D-2: counts and facets follow

Counts and facets reuse the same condition builders, so they need no second
implementation; the test asserts a count and a facet with the operator.

## D-3: the metadata columns

`@self` metadata filters (`metadataNullConditionsSql()`, `:1820`) accept the
operator for nullable metadata columns too, for example
`@self.organisation_inOrEmpty[]=`.

## Risks

- A client that sends `inOrEmpty` with an empty list gets only the empty rows.
  That is the literal meaning and is documented.
