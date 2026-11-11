# Tasks: tables-bulk-jobs-and-file-search

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Cross-repo prerequisite

- [ ] 1.1 nextcloud-vue builds `index-bulk-edit-and-transitions` and `index-export-follows-the-page`, plus the query selection and the `searchInFiles` option of design D-1. Bump `@conduction/nextcloud-vue` to that release.

## 2. Tables page (`src/views/search/SearchIndex.vue`)

- [ ] 2.1 Turn on the bulk actions from `GET /api/bulk-actions` and the job dialog for the selected schema (D-2); "1 job running" link to `/operations` (D-3). Verify: unit test that the bar lists the registry's actions for one schema.
- [ ] 2.2 Turn the mass export on, through the export action as a job (`showMassExport` true, `:676`). Verify: same unit test file.
- [ ] 2.3 `tests/e2e/ci/tables-bulk-jobs.spec.ts`: select forty, Set properties, read the preview with skips, commit, watch progress, download the outcome; select all matching and export.
- [ ] 2.4 `searchInFiles` on; `lib/Service/Object/ContentSearchHandler.php` adds `@self.matchedFile` (name only) for a chunk-entered row (D-4), unit test beside `ContentSearchHandlerTest` asserting the name is there and no chunk text. `tests/e2e/ci/tables-search-in-files.spec.ts`: a word only in an attached text file is found with the switch on and not with it off.

## 3. Close

- [ ] 3.1 `docs/`: "Change many records at once" and "Search inside files".
- [ ] 3.2 `@spec` tags; `openspec validate tables-bulk-jobs-and-file-search --strict`.
- [ ] 3.3 Set rows `rec-bulk` and `srch-file-content` to built once 2.3 and 2.4 pass.
