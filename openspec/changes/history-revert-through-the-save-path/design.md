# Design: history-revert-through-the-save-path

Read at openregister development 555af7212.

## Context

- `RevertController::revert()` (`lib/Controller/RevertController.php:80-125`)
  answers `['error' => $e->getMessage()]` with 403 for
  `NotAuthorizedException`, 423 for `LockedException` and 500 for any other
  `Exception` (`:117-121`). ADR-005 forbids exception text in responses.
- `RevertHandler::revert()` checks `update` rights and the lock
  (`lib/Service/Object/RevertHandler.php:150-167`), rebuilds the old state with
  `AuditTrailMapper::revertObject()` (`:170-174`), writes it with
  `ObjectEntityMapper::update()` (`:177-181`) and dispatches
  `ObjectRevertedEvent` (`:184`).
- `ObjectEntityMapper::update()` is the table write, not the save path, so no
  validation, no `ObjectUpdatingEvent` listener (dependent values, coded
  values, required-when) and no audit entry of the save path run.
- Only the flow trigger and webhook listeners hear `ObjectRevertedEvent`
  (`lib/AppInfo/Application.php:3058`, `:3579`). So the `content-versioning`
  spec's "the audit trail MUST record action `revert`" (`:152`) is not met by
  this path.
- The route is `/api/objects/{register}/{schema}/{id}/revert`
  (`appinfo/routes.php:1453`); the spec says `/api/revert/{register}/{schema}/{id}`
  (`openspec/specs/content-versioning/spec.md:149`, `:489`).

## D-1: revert is an update with an origin

`RevertHandler` hands the rebuilt object data to `SaveObject` as an update of
the same uuid, with a save context `origin: revert` and `revertedTo` (the
version, audit id or time). The save path validates against the current
schema, dispatches the updating and updated events, applies the lock check and
writes the audit entry; the audit writer uses action `revert` and records
`revertedToVersion` when the origin says so. `ObjectRevertedEvent` is still
dispatched after the save, so flow triggers and webhooks keep firing.

## D-2: a state the current schema refuses is not restored

When validation fails, the handler throws the save path's validation
exception and nothing is written. The controller answers 422 with the
validation errors, the same shape an edit gets. The editor can see which
field changed meaning since that version.

## D-3: fixed answers

The controller maps: `NotAuthorizedException` to 403 "You may not restore
this record.", `DoesNotExistException` to 404 "Record not found.",
`LockedException` to 423 "This record is locked by someone else.", and any
other exception to 500 "The record could not be restored." with a request id,
logging the exception with the same id.

## D-4: the spec names the real route

Both places in `content-versioning/spec.md` change to
`/api/objects/{register}/{schema}/{id}/revert`, through a MODIFIED delta in a
later archive, and the scenario in this change uses the real route now.
