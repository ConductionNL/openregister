# Design: modelling-query-backed-type

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| Saved view | `lib/Db/View.php`, `lib/Controller/ViewsController.php` |
| Object read path | `lib/Service/ObjectService.php` searchObjectsPaginated |

## Approach

1. Resolve a view-backed schema in the object read path by substituting the view query and source schema.
2. Refuse writes on a view-backed schema in the save and delete handlers.

## Declarative or imperative

Declarative: `x-openregister-view` on the schema.

## Tests

- PHPUnit: a view-backed schema lists exactly the rows the view query returns; a POST to it answers 405.
