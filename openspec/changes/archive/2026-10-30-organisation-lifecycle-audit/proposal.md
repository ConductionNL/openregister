# organisation-lifecycle-audit

## Why

An organisation is not a register object, so suspending, reactivating, ending, retaining or archiving one, or editing
its name, description or slug, left nothing on the hash-chained audit trail: the status and its timestamp said what
is, not who did it or when it changed back. dossiq's organisation page (board dossiq/DqTenant) has a History tab that
needs to read exactly that.

## What changes

- `OrganisationLifecycleAuditor` writes one sealed audit-trail entry per change, keyed by the organisation's uuid in
  `object_uuid`, with the acting user: `organisation.status_changed` (`changed.status.old` / `.new`) from
  TenantLifecycleService's suspend, reactivate, deprovision, retain and archive, and `organisation.updated` (the
  changed fields among name, description and slug) from `PUT/PATCH /api/organisations/{uuid}`.
- Fail-soft, as the settings and grant auditors: a failing trail logs at ERROR and never fails the change.
- The admin audit list already filters on `object_uuid` and `action`, so one organisation's history is
  `GET /api/audit-trails?object_uuid={uuid}`.
