# Calculations Annotation

## Problem
View-side templates and PHP services routinely combine fields into derived display values: decidesk's `propertyItems()` ternaries, `<X>Detail.vue` view models combining first/last names, computed booleans like "is overdue", time-difference fields like "days from created to completed". These are pure functions of stored properties and have no business in app code.

## Proposed Solution
Add the `x-openregister-calculations` schema annotation. A calculation is a typed declaration with an expression in a small DSL (concat / if / arithmetic / comparison / date diff). Two storage modes:

- `materialise: true` — evaluated at save-time by an `ObjectCreatingEvent` / `ObjectUpdatingEvent` listener; persisted as a stored field; can be targeted by `x-openregister-aggregations` (sibling change `aggregations-annotation`).
- `materialise: false` — evaluated at response-render time when `_include=calculations` is set; not persisted.

Reuses the placeholder resolver shipped in `aggregations-annotation` (so `$now` / `$currentUser` work the same way). Cycle detection at schema-save time prevents `a → b → a` chains.
