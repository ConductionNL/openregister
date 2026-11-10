---
kind: code
---

# Proposal: records-gallery-view

## Summary

A caseworker saves a view that shows records as a gallery of cards, each with a
cover image, a title and a few chosen fields. It suits records people recognise by
a picture: buildings, assets, products, locations. The gallery is a fourth
presentation of a saved view, beside table, kanban and calendar, and uses the same
filters, sorting and sharing.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | rec-gallery | See records as a gallery of cards with a cover image | no |

The row is in Open Register's own matrix, in its core area (records). No demand row.
Competitors rated yes:

- directus (source read at v12.4.1, not driven): "directus:app/src/layouts/cards/index.ts:22
  cards layout with an image source field".
- nocodb (source read at 2026.09.0, not driven): "nocodb:packages/nocodb/src/controllers/galleries.controller.ts:39
  create gallery view with cover image field; nocodb:packages/nc-gui/components/smartsheet/Gallery.vue".

## Why

A saved view declares its presentation (`lib/Db/View.php:155-164`): `viewType` is
`table`, `kanban` or `calendar` (`saved-search-views` REQ-VIEW-PRES-01), and the search
page dispatches on it (`presentationType()` in `src/views/search/SearchIndex.vue`).
The change that added kanban and calendar, `object-views-kanban-calendar`, names
gallery as an explicit phase-two follow-up, and no change has taken it up. The matrix
evidence: "SearchIndex.vue presentations are table/kanban/calendar only (:211)".

The image is already there. A schema can name an image property in
`configuration.objectImageField`, and `RenderObject` resolves it into the object's
image (`lib/Service/Object/RenderObject.php:1481-1495`).

## What changes

- `viewType` accepts `gallery` with `gallery: {coverField, titleField, cardFields,
  cardSize}`. `coverField` defaults to the schema's `objectImageField`. The view save
  refuses a field that is not a property of the view's schema, as it does for kanban.
- The search page renders a gallery view as a card grid: cover image, title, up to
  four chosen fields, the same pagination and the same row actions as the table.
- A record without an image shows the schema icon in its place.
- The view editor offers Gallery as a presentation with pickers for the three fields.

## Consumers

- stackiq (applications with a logo), buildiq and decidiq (locations, meeting rooms),
  learniq (courses with a cover), through the nextcloud-vue card grid every app
  already ships.

## ADRs

- `saved-search-views` REQ-VIEW-PRES-05: presentation components are shared and
  wired, not owned by Open Register. The card grid is nextcloud-vue's.
- hydra ADR-058: the gallery pages like the table; no view loads every record.
- hydra ADR-054 (public surface hardening): cover images are served through the same
  file access checks as the object's files.

## Impact

- Extends `saved-search-views`.
- Affected code: `lib/Db/View.php` (presentation shape), the presentation validation
  in the view save path, `src/views/search/SearchIndex.vue`, the view editor,
  nextcloud-vue `CnCardGrid` and `CnObjectCard`.
- Backwards compatible: existing views keep their presentation.
- Size: S.

## Out of scope

- A timeline presentation, the other phase-two item of `object-views-kanban-calendar`.
- Image cropping and focal points.
