# Tasks: records-gallery-view

## 1. Presentation

- [ ] 1.1 `gallery` in the presentation shape and its validation (`coverField` a file or image property, `titleField`, `cardFields`, `cardSize`). Verify: view save tests for a valid gallery and a `coverField` that is a text property.

## 2. Rendering

- [ ] 2.1 Gallery dispatch in `SearchIndex.vue` rendering `CnCardGrid` of `CnObjectCard` with thumbnail covers from the Nextcloud preview service and the schema icon as fallback. Verify: `tests/e2e/gallery-view.spec.ts` saves a gallery view on a schema with images and sees cards with covers.
- [ ] 2.2 Card click opens the record; card menu carries the table's row actions. Verify: same e2e opens a record from a card.
- [ ] 2.3 Gallery option in the view editor with the three field pickers. Verify: same e2e builds the view through the editor.

## 3. Docs

- [ ] 3.1 `docs/` section on the gallery presentation.

Acceptance:
- Table, kanban and calendar views are unchanged.
