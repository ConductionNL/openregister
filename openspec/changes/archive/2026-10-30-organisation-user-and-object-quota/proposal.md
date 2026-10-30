# Proposal: organisation-user-and-object-quota

## Why

dossiq's organisation page (board DqTenant) draws a quota of "Zaken 1.240 van 5.000" and "Gebruikers 24 van 50". An
OpenRegister organisation limits storage, bandwidth and requests; it has no limit on its members or its objects, and its
usage counts members but not objects.

## What changes

- An organisation gains `userQuota`, `objectQuota` and `objectQuotaSchema` (the slug of the schema the object quota
  counts; none counts every schema). NULL is no limit, as for the other quotas. Migration
  `Version1Date20261023090000` adds the three nullable columns.
- `quota` answers `users`, `objects` and `objectsSchema`; `usage.objects` counts the organisation's live objects of that
  schema (`MagicMapper::countObjectsByOrganisation`), only for an organisation with an object quota, null when it could
  not be counted.
- The organisation update endpoint accepts the three fields; an example set may declare `users`, `objects` and
  `objectsSchema` in an organisation's quota. The Zuiddrecht set gives Gemeente Waterland 50 users and 5,000 cases.

Generic: any app names the schema it counts; nothing in OpenRegister knows about cases. Enforcement of the new limits is
not part of this change.
