---
kind: code
depends_on: [bulk-action-jobs]
---
# Proposal: tables-bulk-jobs-and-file-search

## Summary

Two things the Tables page cannot do although the backend can. A user who selects forty records can delete or copy them, but cannot set a field on all forty, hand them to a handler or export them: those actions exist as previewed, cancellable bulk jobs, reachable only by API and from the operations console. And the page's search box searches record fields only, while Nextcloud's top-bar search already finds a record by words inside its attached files. This change puts both on the Tables page.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | rec-bulk | Select many records and change, delete or export them in one action. | partial |
| openregister | srch-file-content | Find a record by words inside its attached files. | partial |

Delivered changes: `bulk-action-jobs` (all 17 tasks ticked, not archived) and `unified-search-file-content` (the provider passes `_content_search`; its tasks file's status note of 2026-09-18 records what shipped).

## What is there

- Bulk jobs: `GET /api/bulk-actions`, `GET`/`POST /api/bulk-jobs`, `GET /api/bulk-jobs/{id}` and commit, cancel, retry and outcome download (`appinfo/routes.php:1308-1311` and following). Registered actions: Set properties (`lib/BulkAction/SetPropertiesAction.php`), Hand over to a handler (`AssignAction.php`), Apply a rule (`ApplyRuleAction.php`), Export the whole set (`ExportWholeSetAction.php`), Undo a bulk action (`RestorePriorValuesAction.php`). The jobs list lives on `/operations` (`src/views/operations/OperationsConsoleIndex.vue:93`).
- Tables page: mass delete and mass copy (`src/views/search/SearchIndex.vue:476,491,681-688`); mass export switched off (`:676`).
- File content: `QueryHandler` widens a search to chunk hits when `_content_search` is true (`lib/Service/Object/QueryHandler.php:556`), mapped to the owning object, capped at 50 candidates (`lib/Service/Object/ContentSearchHandler.php`). `lib/Search/ObjectsProvider.php:519` passes it for Nextcloud's top-bar search. The Tables page never passes it.

## What changes

- The Tables page's selection bar lists the bulk actions `GET /api/bulk-actions` offers for the selected schema. Choosing one opens nextcloud-vue's bulk job dialog: inputs, a required reason where the action asks for one, the preview with applied, skipped and refused counts, commit, progress, cancel and the outcome download.
- "Select all N matching" turns the selection into a query selection, so an action can cover more than the visible page (bounded by the instance ceiling `bulk-action-jobs` already enforces).
- Export from the selection bar runs `export-whole-set` as a job, for the selection or the whole filtered set.
- The search box gets an "Also search inside files" switch. On, the list query carries `_content_search=true` and a row found through a file says so with the file's name.
- Mass delete and mass copy stay as they are.

## ADRs

- ADR-022: jobs, actions and the content search are OpenRegister's; the page consumes them.
- ADR-005: a job runs under the user's own rights; a content hit never shows a record the user may not read (`unified-search-file-content` task 2).
- hydra ADR-058: no action loads the whole set into the browser.

## Impact

- Extends `bulk-action-jobs` and `zoeken-filteren`.
- Affected code: `src/views/search/SearchIndex.vue`, the object store's list query, and one backend addition: `@self.matchedFile` (the file name only) on a row found through a file, in `lib/Service/Object/ContentSearchHandler.php` (design D-4).
- Depends on nextcloud-vue `index-bulk-edit-and-transitions` and `index-export-follows-the-page` (both specified there, not built), plus a search-in-files option on `CnIndexPage` (cross-repo, design D-1).
- Size: M.

## Out of scope

- Bulk lifecycle transitions: `records-bulk-transition` registers that action; once it is registered the selection bar lists it with no change here.
- File hits as their own result kind and search scopes: `content-search-index`.
