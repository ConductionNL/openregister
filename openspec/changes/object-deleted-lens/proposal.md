# Proposal: a deleted lens on the object list

## Why

A soft-deleted object can only be listed through `/api/deleted`, which scans the magic table of every schema of every app and pages over the merged result. A consumer that wants the deleted rows of ONE schema (dossiq's Deleted cases page) pays for the whole instance: 20 to 32 seconds as admin on the Zuiddrecht example set, and a page cut that drops the rows it wanted behind other apps' deletions. The object list already has the lens shape for this question, `_archived` (absent, `true`, `any`), and `_includeDeleted=true` only answers "both".

## What changes

- `_deleted` on the object list, the count and the facets, shaped like `_archived`: absent or false is the working set, `true` is the deleted rows alone, `any` is both. An unrecognised value never widens the list.
- `_includeDeleted=true` keeps meaning both, so no caller changes. When both are sent, `_deleted` wins.
- `_deleted` is a reserved parameter, never read as a filter on a property named `deleted`.
- The objects endpoint returns the deleted rows the lens asks for, with their `@self.deleted` marker, scoped by the same RBAC and organisation rules as the working set.

## Not changed

Who may read a row, the trash endpoints (`/api/deleted`), restore and purge.
