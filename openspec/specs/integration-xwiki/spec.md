---
status: done
---

# integration-xwiki Specification

## Purpose
Links external XWiki pages to OpenRegister objects, routing all CRUD through an OpenConnector `xwiki` source (Basic or OAuth2). The link form accepts a full URL or a space.page path and stores a canonical reference, tab rows show the full wiki/space/page breadcrumb, the detail page renders a macro-stripped text-only preview, and an auth-expiry banner offers a reconnect link. Renders on all four widget surfaces and as a page chip for `xwiki` reference properties; XWiki's own ACLs govern access transitively.

## Requirements

### Requirement: XWiki Provider Registration

`XwikiProvider` SHALL register with id='xwiki', group='external', requiredApp=null, storage='external', `getOpenConnectorSource()='xwiki'`.

#### Scenario: Provider visible in registry

- **WHEN** `IntegrationRegistry::listIds()` is called after app boot
- **THEN** the result MUST include `xwiki`
- **AND** the provider MUST return `storage='external'` and `getOpenConnectorSource()='xwiki'`

### Requirement: External Routing via OpenConnector

All CRUD SHALL route through `ExternalIntegrationRouter`. Auth MAY be Basic or OAuth2 depending on the OpenConnector source config.

#### Scenario: CRUD calls reach XWiki via OpenConnector source

- **GIVEN** an OpenConnector source `xwiki` configured with Basic auth
- **WHEN** the provider issues a `GET /pages` call
- **THEN** `ExternalIntegrationRouter` MUST dispatch through the `xwiki` source
- **AND** the request MUST carry the configured Basic-auth header

### Requirement: Flexible Link Input

Link form SHALL accept either full XWiki URL or direct space.page path. URL parsing SHALL extract canonical reference.

#### Scenario: Paste URL resolves to canonical reference

- **WHEN** user pastes `https://wiki.example.gov/xwiki/bin/view/Dept/Policy/Privacy`
- **THEN** the system MUST parse to space=`Dept.Policy`, page=`Privacy`
- **AND** the link MUST be stored with canonical reference

### Requirement: Breadcrumb in Tab

Tab rows SHALL display full breadcrumb (wiki / space hierarchy / page title), not just title.

#### Scenario: Tab row shows full breadcrumb

- **GIVEN** a linked page `Dept.Policy.Privacy` on wiki `xwiki`
- **WHEN** `CnXwikiTab` renders the row
- **THEN** the row MUST display the breadcrumb `xwiki / Dept / Policy / Privacy`
- **AND** the row MUST NOT collapse to the page title alone

### Requirement: Text-Only Preview on Detail-Page

`CnXwikiCard` at `surface='detail-page'` SHALL render a text preview (first 500 chars of rendered content, macros stripped). Full rendering lives in XWiki.

#### Scenario: Macros not executed in preview

- **GIVEN** a linked page containing XWiki macros (velocity, script)
- **WHEN** preview renders
- **THEN** macro output MUST be stripped to plain text
- **AND** no macro execution MUST occur in the NC context

### Requirement: Auth Expiry Surfacing

When the underlying OpenConnector source returns an auth-expired error, the provider SHALL surface an explicit banner with a reconnect link (same pattern as OpenProject).

#### Scenario: Banner shown when XWiki auth expires

- **GIVEN** the OpenConnector `xwiki` source returns `401 Unauthorized`
- **WHEN** `CnXwikiTab` polls
- **THEN** the tab MUST render an explicit "Reconnect XWiki" banner
- **AND** the banner MUST link to the OpenConnector source configuration page

### Requirement: Widget Surfaces

Per umbrella AD-6/AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); `single-entity` is a page-title + breadcrumb chip.

#### Scenario: Widget renders on each of the four surfaces

- **GIVEN** the user has at least one XWiki link
- **WHEN** the widget is mounted on each of the four surfaces
- **THEN** each surface MUST render an XWiki view appropriate to that surface
- **AND** the `single-entity` surface MUST render a page-title + breadcrumb chip

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'xwiki'` SHALL render page chip.

#### Scenario: Reference property renders a page chip

- **GIVEN** a schema property declared with `referenceType: 'xwiki'`
- **WHEN** the object detail view renders that property
- **THEN** the renderer MUST emit an XWiki page chip showing the page title + breadcrumb

### Requirement: Permission Inheritance

The provider SHALL set `requiresPermission() === null`; XWiki's own ACLs govern transitively via OpenConnector.

#### Scenario: NC does not pre-gate XWiki access

- **GIVEN** a user who lacks the XWiki page permission
- **WHEN** the user opens the XWiki tab
- **THEN** OR MUST NOT block the call at the NC layer
- **AND** the XWiki backend MUST return its native `403` which the provider surfaces verbatim

### Requirement: Object-Independent Free-Text Page Search

OpenRegister SHALL expose an object-independent, paginated, free-text search of
the remote xWiki knowledge base at `GET /api/integrations/xwiki/search`, backed
by `XwikiLinkService::searchPages(?string $query, int $limit, int $offset)`. The
xWiki base URL SHALL be resolved exclusively from the OpenConnector `xwiki`
source via `XwikiProvider` + `ExternalIntegrationRouter` — the caller never
supplies an xWiki URL. The endpoint SHALL be `@NoAdminRequired` (any
authenticated user) and read-only.

On success it SHALL return `{ results, total, limit, offset }` where `results`
are normalised page rows (`{ id, title, space, url, breadcrumb, … }`). `limit`
SHALL be clamped to 1..100 (default 25) and `offset` to >= 0 (default 0). The
query MAY be passed as `q` or `search`; an empty query lists available pages.

When the OpenConnector `xwiki` source is missing/unconfigured, or the upstream
xWiki is unreachable, the endpoint SHALL fail closed — returning
`503 { error, details: { cause } }` (cause one of `openconnector-down`,
`openconnector-source-missing`, `provider-auth`, `upstream-service-down`) — and
SHALL never raise a fatal. The service method SHALL return
`{ unavailable, cause, results: [], total: 0, limit, offset }` in that case.

#### Scenario: free-text search returns paginated hits

- **GIVEN** a configured, enabled OpenConnector `xwiki` source pointing at a
  reachable xWiki
- **WHEN** `GET /api/integrations/xwiki/search?q=passport&limit=10` is called by
  an authenticated user
- **THEN** the response is `200 { results, total, limit: 10, offset: 0 }` with
  normalised page rows matching the query
- @e2e exclude Backend API endpoint resolved through OpenConnector; verified by PHPUnit + Newman, not a browser flow.

#### Scenario: unconfigured source fails closed

- **GIVEN** no OpenConnector `xwiki` source exists (or OpenConnector is disabled)
- **WHEN** `GET /api/integrations/xwiki/search?q=anything` is called
- **THEN** the response is `503 { error, details: { cause } }` with a cause of
  `openconnector-down` or `openconnector-source-missing`, and no fatal/500
- @e2e exclude Backend degradation path; verified by PHPUnit + Newman, not a browser flow.

#### Scenario: limit and offset are clamped

- **GIVEN** the search endpoint
- **WHEN** it is called with `limit=9999` and `offset=-5`
- **THEN** the resolved `limit` is 100 and `offset` is 0 in the response envelope
- @e2e exclude Backend input-clamping; verified by PHPUnit, not a browser flow.
