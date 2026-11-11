# Design: records-gallery-view

Read at openregister development 0ca409ee04.

## D-1: gallery is one more presentation

`View::getPresentationFormatted()` (`lib/Db/View.php:428-446`) defaults a missing
presentation to `table`. The view save validation that checks `groupByField` and
`dateField` against the schema (REQ-VIEW-PRES-01) gains a `gallery` branch that
checks `coverField`, `titleField` and each `cardFields` entry, and refuses a
`coverField` that is not a file or image property. `cardSize` is `small`, `medium` or
`large`.

## D-2: no new endpoint

Kanban and calendar need board and range derivation, which is why
`ViewPresentationService` exists (`lib/Service/ViewPresentationService.php`). A
gallery is a paged list, so it uses the objects list the table already calls, with
the view's filters and sort. The cover comes from the rendered object: when
`coverField` equals the schema's `objectImageField`, `@self.image` is already set
(`RenderObject.php:1481-1495`); otherwise the list asks for `_extend` of that field so
the file's `downloadUrl` is present.

## D-3: rendering

`presentationType()` in `src/views/search/SearchIndex.vue` returns `gallery`, and the
page renders nextcloud-vue's `CnCardGrid` of `CnObjectCard`, each with the cover, the
title field and up to four card fields. Clicking a card opens the record like a table
row. The same row actions (copy, delete) sit in the card's menu.

## Declarative-vs-imperative decision

Declarative: the gallery is a view's declared presentation. No code per schema.

## Risks

- Images slow a page of 50 cards: thumbnails come from Nextcloud's preview service
  for the file, not the full file.
- A cover a viewer may not read: the file access check applies, and the card shows
  the schema icon instead.
