# Tasks: records-gallery-view

## 1. Presentation

- [x] 1.1 `gallery` in the presentation shape and its validation (`coverField` a file or image property, `titleField`, `cardFields`, `cardSize`). Verify: view save tests for a valid gallery and a `coverField` that is a text property.

## 2. Rendering

- [x] 2.1 Gallery dispatch in `SearchIndex.vue` rendering `CnCardGrid` of `CnObjectCard` with thumbnail covers from the Nextcloud preview service and the schema icon as fallback. Verify: `tests/e2e/gallery-view.spec.ts` saves a gallery view on a schema with images and sees cards with covers.
- [x] 2.2 Card click opens the record; card menu carries the table's row actions. Verify: same e2e opens a record from a card.
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
- 2.1, 2.2 (second pass): `SearchIndex.vue` dispatches `gallery` to the library's `CnCardGrid`
  of `CnObjectCard` inside a wrapper sized by `cardSize`, paged with `CnPagination` over the
  list the table uses. `src/services/galleryCards.js` builds the cards: the cover is a
  Nextcloud preview thumbnail of the cover file (sized by `cardSize`), `@self.image` when the
  view names no cover or the schema's own image field, and nothing otherwise; a cover field
  other than the image field is asked for through `_extend`. The card title is the view's
  `titleField`, the facts are up to four `cardFields`. A card click opens the record like a
  table row; the card menu carries the row actions (edit, merge, copy, delete). Unit-tested
  in `src/services/galleryCards.spec.js` and `src/views/search/SearchIndex.spec.js`. Without a
  cover the card shows no image: `CnObjectCard` draws no schema icon outside the board look.
  The e2e spec (`tests/e2e/gallery-view.spec.ts`) needs a live instance and is not written here.
- 2.3 stays open: the editor's gallery option adds new UI strings, which the l10n parity gate
  requires in all 37 locales.
