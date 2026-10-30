# Proposal: audit-read-events-visible-to

## Why

A `read` audit entry says who opened a record and when. One object's history
(`GET /api/objects/{register}/{schema}/{id}/audit-trails`) and the readable feed
(`/api/audit-trails/readable`) hand a reader every entry of the objects they may
read, reads included. On a case about a family or a health matter, who looked
at it is itself personal data. Ruben decided (23 Oct 2026) that read entries are
shown only to set groups; administrators always see them.

dossiq's case history wants to offer "Ook wie de zaak las". That offer must
follow the server's rule, not a UI-only gate.

## What changes

- A setting `audit.readEventsVisibleTo`: a JSON list of group ids, empty by
  default (only administrators see reads). Listed as security relevant.
- `ReadEventVisibility` decides it: an administrator, or a member of a named
  group, sees reads; no user, an unreadable setting or a failing group lookup
  is "no".
- `ObjectHistoryForReaders` drops read entries for everyone else before paging,
  so `total` counts what the reader is given, and answers
  `readEventsVisible` (true or false). The admin path answers
  `readEventsVisible: true`.
- `ReadableAuditTrailLister` leaves read entries out of the feed for everyone else.

## Behaviour change

Until a group is named, non-admin readers no longer see read entries in a
history or the readable feed. An instance that wants handlers to see them sets
e.g. `occ config:app:set openregister audit.readEventsVisibleTo --value='["dossiq-sensitive"]'`.
