# Design: records-form-and-cell-editors

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| Record form editor choice | `src/modals/object/ViewObject.vue:2997` getPropertyInputComponent |
| Language value editor (unused) | `src/components/i18n/TranslationFieldEditor.vue` |
| Records list | `src/views/search/SearchIndex.vue` (row click opens the modal) |
| Save path | PATCH `/api/objects/{register}/{schema}/{id}` (ObjectsController) |

## Approach

1. Extend `getPropertyInputComponent()` with an enum branch (NcSelect with `inputLabel`), a file branch and a translatable branch that mounts `TranslationFieldEditor`.
2. Add an editable cell component to the records table, shown only when the row carries update rights in `@self`; it PATCHes one field.

## Declarative or imperative

Imperative UI only; the property declaration already carries everything the editors need.

## Tests

- vitest for the editor choice per property shape (enum, file, translatable, plain).
- vitest for the editable cell: saves one field, shows the server refusal, hidden without update rights.
