---
status: done
---

# frontend-app-bootstrap Specification

## Purpose

@e2e exclude Vue app bootstrap/JS unit patterns — covered by unit tests, not Playwright
TBD - created by archiving change retrofit-2026-05-25-fe-misc. Update Purpose after archive.
## Requirements
### Requirement: REQ-001 — Application data MUST be hot-loaded once at startup

When the app mounts, the bootstrap layer MUST pre-load the essential stores (registers,
schemas, organisations, applications, views, agents, sources, conversations) in parallel
so that modals and views open without per-open API round-trips. The load MUST be
idempotent (skip stores that already hold data) on the normal path and MUST be forceable
on demand (e.g. when switching organisations). A failure in any single store MUST NOT
abort the others or crash the app, and the layer MUST expose whether the essential data
is loaded.

#### Scenario: First mount hot-loads all essential stores

- **GIVEN** a freshly mounted `App` with empty stores
- **WHEN** `mounted()` calls `initializeAppData()`
- **THEN** registers, schemas, organisations, applications, views, agents, sources, and
  conversations SHALL all be requested in parallel
- **AND** the active organisation SHALL be fetched from the session

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

### Requirement: REQ-002 — A cached Nextcloud app install/uninstall client MUST be provided

The app MUST expose a service that installs, force-installs, and uninstalls Nextcloud apps
via the `/index.php/settings/apps/*` endpoints, backed by an in-memory cache of the app
list. The client MUST capture the Nextcloud request token at construction, lazily load and
cache the app list, expose cache invalidation/reload, answer install-state queries from
the cache, skip apps already in the target state, and propagate the HTTP 403
password-confirmation contract to callers.

#### Scenario: Install skips already-installed apps and refreshes the cache

- **GIVEN** a request to install `['calendar', 'contacts']` where `calendar` is active
- **WHEN** `installApp()` runs
- **THEN** only `contacts` SHALL be POSTed to `/settings/apps/enable`
- **AND** the cached app list SHALL be reloaded after the call
- **AND** if every requested app is already installed the call SHALL return `null`

#### Scenario: Install-state queries are answered from the cache

- **GIVEN** an initialised service
- **WHEN** `isAppInstalled(appId)` / `getAppData(appId)` are called
- **THEN** the answer SHALL come from the cached list (loading it first if needed)
- **AND** an unknown `appId` SHALL throw rather than return a falsy result

#### Scenario: Password confirmation is surfaced to the caller

- **GIVEN** an enable/disable request that the server answers with HTTP 403
- **WHEN** the request resolves
- **THEN** a `RequestError` carrying the 403 status and parsed body SHALL be thrown so
  the caller can prompt for password confirmation

### Requirement: REQ-003 — Object file metadata MUST be editable via a typed client

The app MUST expose a typed client for editing the metadata of a file attached to an
object: its labels, and its description/category/labels enrichment. Each operation MUST
target the object-scoped file endpoint and MUST raise on a non-OK HTTP response.

#### Scenario: Labels are replaced via the labels endpoint

- **GIVEN** `updateFileLabels({ registerId, schemaId, objectId, fileId, labels })`
- **WHEN** the client runs
- **THEN** it SHALL `PUT { labels }` to
  `/api/objects/{register}/{schema}/{id}/files/{fileId}/labels`
- **AND** an empty array SHALL clear all labels

#### Scenario: Partial metadata update skips unspecified fields

- **GIVEN** `updateFileMetadata({ ..., description, category, labels })` where some
  fields are `null`
- **WHEN** the client runs
- **THEN** only the non-null fields SHALL be included in the PUT body to
  `/api/objects/{register}/{schema}/{id}/files/{fileId}`
- **AND** an empty string / empty array SHALL explicitly clear that field

#### Scenario: HTTP failure raises

- **GIVEN** any of the file-metadata calls receiving a non-OK response
- **WHEN** the response resolves
- **THEN** the client SHALL throw an `Error` carrying the HTTP status

### Requirement: No shipped view renders a silent-empty stub

A view registered in the app navigation SHALL NOT ship with its data fetch
stubbed out to always render empty while swallowing errors. It SHALL either fetch
real data or present an explicit "coming soon"/unavailable state.

#### Scenario: Templates view shows real data or an explicit empty state

- **WHEN** a user opens the Templates view
- **THEN** it either lists templates from the backend
- **OR** shows an explicit "coming soon" state
- **AND** it does not silently render an empty list with error handling disabled

### Requirement: UI colors use NL Design System CSS variables

User-facing components SHALL use Nextcloud CSS custom properties for colors, not
hardcoded hex values, so theming and dark mode work and WCAG AA contrast holds.

#### Scenario: Recolored views respect the theme

- **WHEN** a user views a recolored component in dark mode
- **THEN** text and borders use theme variables and remain legible (WCAG AA)

### Requirement: User-facing strings are translatable

User-facing strings SHALL be wrapped in `t('openregister', …)`/`n(...)` with
English source keys.

#### Scenario: Cache management strings are translatable

- **WHEN** the Cache Management settings section renders
- **THEN** its headings, labels, and dialog text resolve through the i18n layer

### Requirement: Dialogs are isolated in their own files

`NcDialog`/`NcModal` markup SHALL live in a dedicated file under `src/dialogs/`
(or `src/modals/`), not inline in a parent view.

#### Scenario: Chat and cache dialogs are extracted

- **WHEN** the chat and cache-management dialogs are defined
- **THEN** each lives in its own file under `src/dialogs/`, imported by the parent

