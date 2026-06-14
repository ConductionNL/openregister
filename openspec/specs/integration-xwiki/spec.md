---
status: done
---

# integration-xwiki Specification

## Purpose
TBD - created by archiving change integration-xwiki. Update Purpose after archive.
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

