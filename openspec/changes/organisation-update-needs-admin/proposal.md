# organisation-update-needs-admin

## Why

`PUT/PATCH /api/organisations/{uuid}` let any member of an organisation rename it or change its description: the only
check was membership, and only a change of who holds `grant_manage` asked for more. Ruben decided that changing an
organisation needs a Nextcloud administrator or the organisation's own administrator; members only read it.

## What changes

- `OrganisationController::update` (and `patch`, its alias) checks `OrganisationService::isOrganisationAdmin()` (a
  Nextcloud administrator, or the organisation's owner) right after the access check and before the organisation or the
  request is read for writing. A member gets 403 with "Only an administrator or an administrator of this organisation
  may change it. Members can read it."
- The `grant_manage` guard inside update is gone: every change now needs that role, so it could no longer be reached.
