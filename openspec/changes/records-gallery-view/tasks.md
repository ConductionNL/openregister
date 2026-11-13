# Tasks: records-gallery-view

## 1. Presentation

- [x] 1.1 `gallery` in the presentation shape and its validation (`coverField` a file or image property, `titleField`, `cardFields`, `cardSize`). Verify: view save tests for a valid gallery and a `coverField` that is a text property.

## 2. Rendering

- [ ] 2.1 Gallery dispatch in `SearchIndex.vue` rendering `CnCardGrid` of `CnObjectCard` with thumbnail covers from the Nextcloud preview service and the schema icon as fallback. Verify: `tests/e2e/gallery-view.spec.ts` saves a gallery view on a schema with images and sees cards with covers.
- [ ] 2.2 Card click opens the record; card menu carries the table's row actions. Verify: same e2e opens a record from a card.
- [ ] 2.3 Gallery option in the view editor with the three field pickers. Verify: same e2e builds the view through the editor.

## 3. Docs

- [x] 3.1 `docs/` section on the gallery presentation.

Acceptance:
- Table, kanban and calendar views are unchanged.

## Build notes

- 1.1: `gallery` is validated at view save (`ViewService::validateGalleryPresentation()`):
  `titleField` required, `coverField` optional and refused when it is not a file, a list of
  files or a string of `format: image`, every `cardFields` entry a property of the view's
  schema, and `cardSize` one of small, medium, large. Unit-tested in
  `tests/Unit/Service/ViewServiceGalleryTest.php` (12 cases, mutation-checked).
  `coverField` defaulting to the schema's `objectImageField` is a render-time default
  (D-2), so validation treats an absent `coverField` as valid rather than filling it in.
- 3.1: `docs/features/views.md` gains a Presentation section naming all four presentations
  and documenting the gallery's fields and refusals (the doc had no presentation section
  at all before).
- 2.1, 2.2 and 2.3 are left open: the gallery rendering in `src/views/search/SearchIndex.vue`
  needs the library's `CnCardGrid`/`CnObjectCard` and the Playwright run on a live instance,
  neither verifiable in this build.
