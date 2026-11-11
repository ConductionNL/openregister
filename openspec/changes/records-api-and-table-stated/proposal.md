---
kind: code
---

# Proposal: records-api-and-table-stated

## Summary

Two of the oldest capabilities have no requirement that says them. Every schema is served as a REST collection with list, read, create, replace, patch and delete. The records of a type are browsed in a table whose columns the user picks and sorts. Both are built, so the rows cannot cite a requirement, and a refactor of either has nothing to test against. This change writes both down.

## Rows this closes

| row | capability | built in |
|---|---|---|
| api-rest | Read, create, change and delete records over a REST API generated from the type | `lib/Controller/ObjectsController.php`, `appinfo/routes.php` `objects#index/show/create/update/patch/destroy` |
| rec-table | Browse the records of a type in a table with sortable columns you can choose | `src/views/search/SearchIndex.vue`, `src/sidebars/search/SearchSideBar.vue` |

The other API and search rows already have a requirement that says them; this PR records it as `built.requirement` in `openspec/parity/capabilities.json`.

## What changes

- `objects-crud`: one added requirement for the generated REST collection.
- `zoeken-filteren`: one added requirement for the records table.

## Screens

`openregister/OrObjecten` for the table; the REST API has no screen. See `screens.md`.

## Out of scope

- Kanban, calendar, map and gallery views of the same records (their own rows and changes).
- Editing in a cell (`rec-inline-edit`, REQ-RFCE-002).
