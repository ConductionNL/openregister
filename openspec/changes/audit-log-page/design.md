# Design: audit log page

## D-1: one query, six filters, index-backed

The instance-wide list reads `openregister_audit_trails` with filters on
user, created range, action, register, schema and object uuid, each backed
by the indexes enhanced-audit-trail added. Full-text on the change summary
uses the existing database full-text path. The query is bounded by cursor
pagination; no count of the whole table is computed on page load.

## D-2: RBAC by object, admin by role

A non-admin's list is the join of the trail with the objects the user may
read, so the same predicate as object lists. An admin reads the whole trail.
Entries of deleted objects remain visible to admins only.

## D-3: export is the existing export, filtered

CSV and JSON export call the compliance export with the same filter set, so
`hash` and `previousHash` are in the file and a reader can verify the chain
offline. Export is a background job past 10,000 rows, with a notification
carrying the download.

## D-4: a leaf surface

The audit leaf gains an `index` surface. A manifest places it as a page or
links it as a card, so a fleet app has an audit page with no code.

## D-5: kind

Code, in OpenRegister. Consuming apps declare the page in config.
