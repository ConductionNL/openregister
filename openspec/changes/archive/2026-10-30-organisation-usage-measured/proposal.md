# Proposal: organisation-usage-measured

## Why

An organisation's `usage` block reported `storage: 0`, `bandwidth: 0` and
`requests: 0` for every organisation, always. A 0 reads as "nothing used", so
a quota card drawn from it told every tenant they used nothing. Only `users`
and `groups` were real.

## What Changes

- `GET /api/organisations/{uuid}` measures usage through a new
  `OrganisationUsageService`:
  - `users`, `groups`: the organisation's members and groups (unchanged).
  - `storage`: the summed file cache size of the folders of the
    organisation's live objects across all magic tables; null when it cannot
    be read.
  - `requests`: this hour's counter of the quota middleware. The middleware
    only counts for an organisation with a request quota, and only with APCu,
    so it is null otherwise.
  - `bandwidth`: null. The middleware counts JSON response bodies only, which
    is not bandwidth.
- Everywhere else the entity is serialised (lists, search, active),
  `storage`, `bandwidth` and `requests` are null instead of 0: the entity
  cannot measure them.
- `MagicMapper::findFolderIdsByOrganisation()` and
  `FileMapper::sumSizesByFileIds()`.

Measuring runs only after the existing access check in `show`.
