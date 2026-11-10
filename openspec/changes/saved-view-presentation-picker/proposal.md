---
kind: code
depends_on: []
---
# Proposal: saved-view-presentation-picker

## Summary

A user who saves or edits a view on the Tables page picks how it shows: as a table, as a kanban board grouped by a status field, or as a calendar by a date field. The backend has stored and validated that choice since `object-views-kanban-calendar`, and the Tables page already draws a kanban or a calendar when a view asks for one. No screen lets anyone ask. This change adds the picker.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | rec-kanban | See records as cards on a board, grouped by a status field. | partial |
| openregister | rec-calendar | See records on a calendar by one of their dates. | partial |

Both rows were re-rated in spec round part 2 (7 Oct 2026) from building to specified: the delivered change `object-views-kanban-calendar` (archived as `2026-07-25-object-views-kanban-calendar`) shipped the backend and the renderer, not the editor.

## What is there

- `lib/Db/View.php:164` stores `presentation`; `getPresentationFormatted()` (`:428-449`) defaults it to `table`.
- `lib/Service/ViewService.php:210` validates `groupByField` and `dateField` against the view's schema and refuses a presentation that cannot render (`saved-search-views` REQ-VIEW-PRES-01).
- `GET /api/views/{id}/kanban` and `/calendar` (`appinfo/routes.php:1820-1821`, `lib/Controller/ViewsController.php:916,978`) serve the columns and the dated objects; `src/store/modules/views.js:497,542` fetches them.
- `src/views/search/SearchIndex.vue:229` dispatches on `presentation.viewType` to nextcloud-vue `CnObjectKanban` (`:730`) and `CnObjectCalendar` (`:747`).

## What is missing

- `src/modals/view/EditView.vue` and the save form in `src/sidebars/search/SearchSideBar.vue` send no `presentation`. A view gets a kanban or calendar presentation only through the API.
- The Tables page offers no way to switch the open view between its presentations.

## What changes

- The save form and the edit modal carry a presentation section: a view type choice (Table, Board, Calendar) and, per type, the field pickers the backend validates (`groupByField`, optional `cardFields` and `columnOrder` for a board; `dateField` and optional `endDateField` for a calendar). The pickers offer only properties of the view's schema that can serve that role.
- A refusal from the view save (REQ-VIEW-PRES-01) is shown on the field it names and the form stays open.
- The Tables page header shows the open view's presentation and lets its owner switch it, which saves the view.
- The picker is a nextcloud-vue component, so a leaf app's `CnSaveViewDialog` offers the same choice (REQ-VIEW-PRES-05). OpenRegister wires it.

## Consumers

Every leaf app that saves views through nextcloud-vue: dossiq and pipelinq (boards by status), planninq and decidiq (calendars by date).

## ADRs

- ADR-022: the presentation is OpenRegister's view entity; the app only edits it.
- `saved-search-views` REQ-VIEW-PRES-05: presentation components are shared nextcloud-vue components.
- ADR-004: the edit modal stays its own file under `src/modals/`.

## Impact

- Extends `saved-search-views`.
- Affected code: `src/modals/view/EditView.vue`, `src/sidebars/search/SearchSideBar.vue`, `src/views/search/SearchIndex.vue`, `src/store/modules/views.js` (save payload). No backend change.
- Depends on nextcloud-vue shipping the presentation picker (cross-repo, see design D-1).
- Backwards compatible: a view saved without a presentation stays a table.
- Size: S.

## Out of scope

- Gallery and tree presentations: `records-gallery-view` and `records-tree-view` add their options to the same picker.
- Timeline.
