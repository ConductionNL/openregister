# Proposal: isnull-filter-operator

## Problem

`openspec/specs/zoeken-filteren` has advertised `?afgehandeld_op_isnull=true` since
2026-03. **It has never worked.** Not "worked incorrectly" — the filter contributed no
condition at all, so the caller got an unrelated result set with no error and no log.

The mistake is two layers deep, and the top layer is a decoy.

**The decoy.** `SearchQueryHandler::cleanQuery()` contains a `_isnull` branch that reads

```php
$newParameters[$base] = 'IS NOT NULL';
if ($value === true) { $newParameters[$base] = 'IS NULL'; }
```

`=== true` cannot match a query string, which only ever delivers `"true"`. That looks
like the bug. It is not, because **`cleanQuery()` has zero production callers.** Nothing
in `lib/` invokes it; only tests and the spec name it. Fixing that comparison changes
nothing observable, which is how this was nearly shipped as a one-line fix.

**The real layer.** What actually normalises a suffixed parameter is
`SearchQueryHandler::buildSearchQuery()`, whose underscore-to-nested reconstruction turns
`?status_in[]=new` into `status => ['in' => ['new']]`. That is why `_in`, `_notIn`, `_ne`,
`_gte` and friends work: `MagicSearchHandler::COMPARISON_OPERATORS` happens to contain
those names. `isnull` was absent from that list, so `?assignee_isnull=true` became a
nested `['isnull' => 'true']` bag that no condition builder inspected, and the filter was
dropped.

Measured on a live instance over 13 tickets, 11 of them unassigned:

| query | rows |
|---|---|
| `?assignee_isnull=true` | 0 |
| `?assignee_isnull=false` | 0 |
| `?assignee=IS%20NULL` (the sentinel) | 11 |
| `?assignee=IS%20NOT%20NULL` | 2 |

Both spellings returning 0 is the tell: a working-but-inverted operator would have
returned 2 for one of them.

**The same shape hides a second dead parameter.** `?ordering=-title` is documented in the
same spec and was also implemented only in `cleanQuery()`. Over HTTP `ordering` is read as
a filter on a property no schema declares, which adds `1 = 0`: `?ordering=title` returns
**0 of 13 rows**. The documented legacy sort does not sort, it empties the result.

## Solution

Implement the operator where operators actually live, and delete the decoy.

1. **`isnull` joins `COMPARISON_OPERATORS`**, so a bag containing only `isnull` is
   recognised as an operator bag. Without this it would fall through to the historical
   bare-list branch and become `IN ('true')` — matching nothing, and looking like a
   correct empty page.
2. **All four condition builders handle it**: the QueryBuilder and raw-SQL UNION paths,
   for object fields and for `@self` metadata. Two implementations of one filter language
   is exactly where a fix lands on one side only and behaviour starts depending on which
   query shape the caller hit.
3. **The value is coerced with `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.** Raw truthiness
   would make the string `"false"` mean true, which is worse than the bug being fixed.
4. **`cleanQuery()` is deleted** along with its tests. It is a second, dead filter language
   that made the spec look implemented.
5. **The spec is corrected** to describe the real mechanism, name the exhaustive operator
   list, and record that `?ordering=` is unsupported.

The literal `?field=IS NULL` sentinel is untouched and remains the spelling the fleet's
queue pages use, since it works on instances that do not yet carry this change.

## Impact

- **`?field_isnull=true` starts filtering.** Any caller that had adapted to the broken
  behaviour would change — none can have, because the parameter returned an unfiltered
  result rather than a usable one.
- **`cleanQuery()` is gone from the public surface of `SearchQueryHandler`.** A fleet-wide
  grep finds no caller outside this repo's own copies.
- **No schema, migration or API-shape change.**
