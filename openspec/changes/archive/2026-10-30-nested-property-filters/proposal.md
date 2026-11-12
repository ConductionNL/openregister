---
kind: code
depends_on: []
---

# Proposal: nested-property-filters

## Why

A filter cannot name a field inside an object property. shillinq keeps an
invoice's reminder state in an object property, `ARInvoice.dunning`
(`currentStage`, `nextDunningDate`, ...), and its receivables board counts the
invoices in a reminder round with `?dunning.currentStage[isnull]=false`. That
count is always 0.

Measured on 2026-10-16 on the dev instance (PostgreSQL, openregister
`development` at b08b9fe188), over three ARInvoice objects whose `dunning` is
empty, as user pieter:

| query | total |
|---|---|
| `_limit=1` | 1 |
| `dunning.currentStage[isnull]=true` | 0 |
| `dunning.currentStage[isnull]=false` | 0 |

The first filter should match the invoice and the second should not. Neither
does.

## Cause, in the code

PHP turns `dunning.currentStage` into `dunning_currentStage`, and
`SearchQueryHandler::buildSearchQuery()` splits that back into
`['dunning' => ['currentStage' => ...]]`. `MagicSearchHandler::applyObjectFilters()`
then reads the value of an object property as a list of UUIDs
(`applyJsonObjectFilter`). A PHP caller passing `['dunning.currentStage' => 2]`
gets an unknown property instead, which adds `1 = 0`. The raw UNION path
(`buildObjectFilterConditionsSql`) behaves the same way.

## What Changes

- `NestedPathFilter` takes the filters on a path inside an object property out of the filter map, in both spellings: a dotted key, or the nested map the request layer rebuilds. A path can be any depth.
- The path is read from the JSON column: `#>>` on PostgreSQL, `JSON_UNQUOTE(JSON_EXTRACT())` on MariaDB and MySQL.
- The comparison uses the text form: equality, a list, `in`, `notIn`, `ne`, `isnull` and `like`. `gt`, `gte`, `lt` and `lte` compare as numbers when the operand is numeric.
- Both the QueryBuilder path and the raw UNION path use it.
- A path segment that is not a plain name matches nothing and is reported as an ignored filter. An encrypted object property is refused, the same as its column filter.
- A filter on the object property itself keeps its current meaning: a UUID, a list, or an operator bag.

## Impact

- Affected code: `lib/Db/MagicMapper/NestedPathFilter.php` (new), `lib/Db/MagicMapper/MagicSearchHandler.php`.
- No schema, migration or API shape changes. A query that answered an empty page now answers the matching objects.
