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

## As built (2026-09-30)

- The type reads through the existing object-source mechanism instead of a new branch in the read path: `Schema::getObjectSource()` answers `{provider: view, config: {view}, readOnly: true}` for a schema with `x-openregister-view` (an explicit `x-openregister-object-source` wins), and `ViewObjectSourceProvider` serves it. So list, single read and count all go through `ObjectService::paginateObjectSource()` and `GetObject` like any other source, with the schema-level read check first.
- The provider reads the view without the view's own share check (the schema's author chose it) and searches the source table with `_rbac` on, so a reader never sees a source row they could not read directly. A view must name exactly one register and one schema; otherwise the type lists nothing and a warning is logged.
- Facet filters and search terms are applied. A caller filter on a field the view also filters keeps only the values both allow.
- Writes: `ObjectService::saveObject()` and `deleteObject()` throw `ReadOnlyTypeException`, a subclass of `AppendOnlyException`, so every controller catch and the exception trait answer 405; `ObjectsController::create()` gained the same catch (it had none, append-only allows creates).
- `x-openregister-view` joined the list of configuration keys setConfiguration() keeps.
