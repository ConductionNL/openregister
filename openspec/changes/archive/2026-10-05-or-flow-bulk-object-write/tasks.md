# Tasks — or-flow-bulk-object-write

- [x] 1.1 `bulk` in `configKeys()`; `validateBulkKeys()` save-time guards
      (boolean strictness, create/upsert only, no `onConflict: fail`, upsert
      requires `replace: true` + single uuid match).
- [x] 1.2 `writeBulk()`: cap against page size up front, client-side row
      uuids, one `saveObjects(validation: true, events: false)` inside
      `runAs($owner)`, categorised-result indexing by uuid, loud failure on
      rejected rows.
- [x] 1.3 `configForm()` — register/schema selects (`optionsFrom`), labelled
      fields for every scalar key; `fields`/`match` stay on the JSON pane.
- [x] 1.4 Unit tests: vocabulary pin, bulk guard rejections, one-call
      assertion with row shapes, upsert id resolution, rejected-row failure,
      cap refusal.
