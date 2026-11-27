# The boot reads only what the page uses

## What was wrong

Every OpenRegister page load sent reads that nothing on that page used:

- `GET /api/applications`, `GET /api/views` and `GET /api/sources`, hot-loaded
  by `initializeAppData()` on every page. Every consumer of those lists loads
  them itself when it mounts (ApplicationsIndex, SourcesIndex and the search
  sidebar refresh on mount; the configuration modal loads applications when its
  list is empty), so the boot read was always repeated or unused.
- `GET /api/organisations/active`, sent after `GET /api/organisations`
  returned, although the list answers `active` from the same
  `OrganisationService::getActiveOrganisation()` call.
- `GET /api/dashboard`, sent at import by `RegistersSideBar.vue` (a module in
  the main bundle), so on every page instead of only where the sidebar shows.
- `GET /api/tags`, sent on mount by the always-mounted upload modal.

On the sandbox reference stack each PHP request costs ~20-30 ms of php-fpm
bootstrap CPU. Parallel requests from one page queue on that CPU:
`/api/dashboard` took 260-320 ms in the browser against ~35 ms alone.

## What changes

- REQ-001 of `frontend-app-bootstrap`: the boot pre-loads registers, schemas,
  organisations and the active organisation. Lists that only one view or modal
  shows are loaded by that view or modal. The active organisation comes from
  the organisation list answer when the list was read, else from
  `/api/organisations/active`.
- Views and modals load their reads when they are shown or opened, not when
  their module is imported or their always-mounted host mounts.

## Not in this change

- Carrying the remaining boot reads (registers, schemas, organisations) in the
  page as initial state: that moves their cost into the page render and needs
  its own measurement.
