# The readable audit list can be narrowed to one register

## Why

humaniq's admin settings show the ten newest audit entries of the humaniq
register (humaniq change `record-history-on-every-detail-page`, REQ-RAH-003).
They read `GET /api/audit-trails/readable`, the list scoped to what the caller
may read. That list takes column filters from the query string, but the shared
parameter reader leaves `register` out of them, so a caller cannot narrow it to
one register and an app's section would show every register's entries.

## What changes

`AuditTrailController::readable()` adds `register` to the column filters when
the parameter is a whole number, the register id. Anything else is ignored, as
before. The filter only narrows: every row still passes the caller's read scope,
and no other endpoint changes.
