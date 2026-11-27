# frontend-app-bootstrap

## MODIFIED Requirements

### Requirement: REQ-001 — Application data MUST be hot-loaded once at startup

When the app mounts, the bootstrap layer MUST pre-load the stores the app shell
and most pages read (registers, schemas, organisations) in parallel, and set the
active organisation. A list that only one view or modal shows (applications,
views, sources) MUST be loaded by that view or modal when it is shown, not by
the boot. The active organisation MUST be taken from the organisation list
answer when the boot just read the list (it carries `active`, from the same
session lookup), and fetched from `/api/organisations/active` otherwise. The
load MUST be idempotent (skip stores that already hold data) on the normal path
and MUST be forceable on demand (e.g. when switching organisations). A failure
in any single store MUST NOT abort the others or crash the app, and the layer
MUST expose whether the essential data is loaded.

#### Scenario: First mount hot-loads the shell's stores

- **GIVEN** a freshly mounted `App` with empty stores
- **WHEN** `mounted()` calls `initializeAppData()`
- **THEN** registers, schemas and organisations SHALL be requested in parallel
- **AND** applications, views and sources SHALL NOT be requested
- **AND** the active organisation SHALL be set from the organisation list answer,
  without a request to `/api/organisations/active`

#### Scenario: The list does not answer the active organisation

- **GIVEN** organisations already loaded, or a list answer without `active`
- **WHEN** `initializeAppData()` runs with no active organisation set
- **THEN** the active organisation SHALL be fetched from `/api/organisations/active`

#### Scenario: Already-loaded stores are not re-fetched on the normal path

- **GIVEN** stores that already hold data
- **WHEN** `initializeAppData()` runs again
- **THEN** each already-populated store SHALL be skipped rather than re-fetched

#### Scenario: Force reload always refreshes

- **GIVEN** populated stores
- **WHEN** `reloadAppData()` is called (e.g. on organisation switch)
- **THEN** every store SHALL be refreshed regardless of current state

#### Scenario: A single store failure does not abort startup

- **GIVEN** one store load that rejects
- **WHEN** `initializeAppData()` runs
- **THEN** the error SHALL be logged and the remaining loads SHALL still complete
- **AND** `isAppDataLoaded()` SHALL report readiness based on the core entities present

## ADDED Requirements

### Requirement: REQ-004 — A view or modal loads its reads when it is shown

A component that is part of the main bundle or is always mounted (a sidebar, a
modal host's child) MUST NOT send a read at import or on mount when it is not
shown. It MUST send the read when it is created as a shown view or when its
modal opens.

#### Scenario: The upload modal is closed

- **GIVEN** an OpenRegister page with the upload modal closed
- **WHEN** the page loads
- **THEN** no request to `/api/tags` SHALL be sent
- **AND** opening the upload modal SHALL load the tags

#### Scenario: A page without the registers sidebar

- **GIVEN** an OpenRegister page other than Registers
- **WHEN** the page loads
- **THEN** no request to `/api/dashboard` SHALL be sent
