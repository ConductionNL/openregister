# The audit trail reads within a caller's own scope

## Why

The instance-wide audit page exists and works: `GET /api/audit-trails` filters
on actor, period, action, register, schema and object, `/statistics` counts
them and `/export` writes the chain fields to CSV or JSON. All three are
admin-only, on purpose, and the reason is written into the controller: the
cross-tenant index leaks per-row diffs of every object change in every
register and schema.

So an administrator has the page and nobody else does. A case handler who may
read a case cannot see who changed it and when, although they may read every
version of it through the object surfaces. Row 10.5 of the dossiq parity
ledger rates the fleet partial against gzac and zaaksysteem for exactly this:
the log is there, the reach is not.

This change adds the reach without touching the gate that is there for a
reason. It does not widen `index()`. It adds a second, narrower path that
answers a smaller question: the entries of the objects this caller may read.

## What changes

- `GET /api/audit-trails/readable`, open to any signed-in user, listing audit
  entries for objects the caller may read, newest first.
- Readability is decided by the RBAC funnel the object surfaces already use,
  `PermissionHandler::hasPermission()` with action `read`, which since
  openregister#3873 includes object grants through `ObjectGrantResolver`.
- Cursor pagination over the raw trail, with a bounded scan per request and
  no count of the table.
- The scoped rows are narrower than the admin rows: `session`, `request` and
  `ipAddress` are withheld. They answer "who else was on this instance", which
  is the recon signal the admin gate exists to hold, and no reader of their
  own case needs them.
- Anonymous callers, entries with no object, entries whose object is gone and
  entries whose schema cannot be resolved are all absent. Every unknown
  resolves to no.

## Who benefits

dossiq case handlers, zaakafhandelapp, humaniq, and every app that wants to
put "what happened to this thing" in front of the person who owns the thing
rather than only in front of an administrator.

## Impact

- Affected specs: audit-trail-immutable (delta, one added requirement).
- Affected code: `lib/Service/Audit/ReadableAuditTrailLister.php` (new),
  `lib/Controller/AuditTrailController.php` (one added method),
  `appinfo/routes.php` (one route).
- Backwards compatible: no existing endpoint changes. `index()`,
  `statistics()` and `export()` keep their admin gate and their bodies.
