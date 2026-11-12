# Design: tables-bulk-jobs-and-file-search

No design board draws OpenRegister's Tables page; OpenRegister is not one of the canvas apps.

## D-1: the dialogs are nextcloud-vue's

nextcloud-vue's `index-bulk-edit-and-transitions` design already describes the library half: on mount `CnIndexPage` reads `GET /api/bulk-actions`, the selection strip lists those actions, and a new `CnBulkJobOutcome` panel shows the preview and the result with each row linking to its record. `index-export-follows-the-page` makes the export carry the page's own filters. OpenRegister's Tables page renders `CnIndexPage`, so it turns those options on and passes the register and schema. Two needs are not yet in either nextcloud-vue change and are listed as cross-repo items:

- a query selection ("Select all N matching") that hands `CnIndexPage`'s current query to the job instead of an id list;
- a `searchInFiles` option on `CnIndexPage` that shows the switch, adds `_content_search=true` and renders a "found in {file}" line on a row whose hit came from a chunk.

## D-2: which actions show

The bar shows an action when `GET /api/bulk-actions` lists it and the selection is of one schema. A selection spanning schemas shows only actions that declare themselves schema independent, and the version guard of `bulk-action-jobs` (D-6 there) still refuses at preview. Undo is not in the bar; it is offered on a finished job in the job's outcome panel.

## D-3: where a job lives after the dialog closes

Closing the dialog does not stop a running job. The selection bar shows "1 job running" linking to `/operations`, where the jobs list already is. The outcome download is the same file there and in the dialog.

## D-4: the file name on a content hit

`ContentSearchHandler` maps a chunk hit to its owning object and carries no chunk text onto the row (its disclosure test). The row needs only the source file's name, which the object's own files list already exposes to a reader. The list response adds `@self.matchedFile` (name only) for a row that entered through a chunk. This is the one response change; it carries no text from the file.
