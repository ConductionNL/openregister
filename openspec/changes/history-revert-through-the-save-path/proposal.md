---
kind: code
depends_on: []
---

# Proposal: history-revert-through-the-save-path

## Summary

A record editor restores an earlier version of a record from its history. The
restored version is checked against the schema as it is now, goes through the
same save as any edit, and is recorded in the audit trail as a revert. When the
restore is refused, the editor gets a plain sentence that says why, never the
server's internal error text.

## Halves this closes

Two merged changes in other repositories restore a version through
OpenRegister's revert route and named what they found there. Neither has a row
in OpenRegister's matrix; the owner moves pass of 28 Sep 2026 handed the
findings here.

- nextcloud-vue `audit-trail-restore-version` (nextcloud-vue `development`
  e487bc8), for buildiq row `data-restore-record`: "It shows fixed sentences
  for 403 and 423, because OpenRegister returns exception text for those", and
  in its findings: "OpenRegister's `revert` route returns exception text in its
  403, 423 and 500 bodies (ADR-005)."
- buildiq `data-restore-record-version` (buildiq `development` 974af86): "One
  thing to confirm there: `RevertHandler` saves through
  `ObjectEntityMapper::update()` (lines 177-181), not the object save path, so
  whether the restored state is validated against the current schema is
  OpenRegister's to state. Also, its `content-versioning` spec names the route
  `/api/revert/{register}/{schema}/{id}` while `routes.php:1453` serves
  `/api/objects/{register}/{schema}/{id}/revert`."

## What changes

- The revert goes through the object save path as an update with a `revert`
  origin: validation against the current schema, the create and update event
  listeners, locks, and one audit entry with action `revert` naming the
  version restored to.
- A restored state that the current schema refuses is not written; the answer
  is 422 with the validation errors.
- The revert route answers fixed sentences for 403, 404, 423 and 500. The
  exception text goes to the log with a request id.
- The `content-versioning` spec names the route that exists.

## Out of scope

- Restoring deleted records. That is `records-restore-with-cascade`.

## Impact

- `lib/Service/Object/RevertHandler.php` (the write at `:177-184`).
- `lib/Controller/RevertController.php` (`:80-125`).
- `openspec/specs/content-versioning/spec.md` (route in two places).
