---
kind: code
depends_on: []
---

# Proposal: records-copy-with-links

## Summary

A catalogue editor starts a new application entry by copying an existing one,
and the copy arrives with the links that make it useful: its relation rows,
its place in the lists that point at the original, and its files, as far as
the editor chooses. OpenRegister makes the copy in one act, and answers which
links came along and which were refused and why.

## Halves this closes

This is the OpenRegister half of nextcloud-vue's merged change
`index-copy-with-relations` (nextcloud-vue `development` e487bc8), which covers
stackiq row `land-copy-entry`. It has no row in OpenRegister's matrix; the
owner moves pass of 28 Sep 2026 handed it here. Nextcloud-vue writes:
"OpenRegister has no copy operation. It moves an object and keeps its uuid
(`objects#move`, `appinfo/routes.php:1238-1243` at `555af72`), and its comment
there says why a copy is a different act. The OpenRegister half is
`POST /api/objects/{register}/{schema}/{id}/copy` taking the overrides and the
link kinds to take along, answering the new object and a per-link outcome.
Listed for the openregister lane."

Stackiq's row `land-copy-entry` has a changelog demand row (GLPI 11.0.0) and
two competitors rated `yes` (SAP LeanIX: "A cloned fact sheet includes ...",
GLPI), quoted in the nextcloud-vue proposal.

## What changes

- `POST /api/objects/{register}/{schema}/{id}/copy` with `overrides` (field
  values for the new object) and `include`, a subset of `relationRows`,
  `incoming` and `files`.
- The new object is created through the normal save path with a new uuid, so
  validation, defaults, generated identifiers and audit apply.
- `relationRows` re-creates the source's relation rows on the copy.
  `incoming` adds the copy beside the source in every array-valued reference
  that points at the source. `files` copies the attached files.
- Each link the caller may not write is reported as not copied with the
  reason, and does not stop the copy.
- The whole act runs in one transaction for the object and its relation rows;
  files are copied after commit and reported per file.

## Out of scope

- Copying single-valued incoming references (that would move the link away
  from the source).
- A copy across registers or schemas. That is `objects#move` territory.

## Impact

- New `lib/Service/Object/CopyObject.php` beside `MoveObject.php`.
- `lib/Controller/ObjectsController.php` (`copy()`), route in
  `appinfo/routes.php` beside `objects#move`.
- Reuses `objects#used` (`:1190`), `objectRelations#index` and `#addLink`
  (`:1214-1215`) and `files#copy` (`:1466`) logic through their services.
