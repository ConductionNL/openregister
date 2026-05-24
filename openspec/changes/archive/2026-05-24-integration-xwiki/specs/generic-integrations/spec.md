---
status: proposed
---

# Integration: XWiki (generic-integrations delta)

## Purpose

Add the XWiki external-knowledge leaf to the umbrella `generic-integrations` spec. XWiki complements the NC-native `integration-collectives` leaf with external-platform support via OpenConnector's `xwiki` source.

**Standards**: XWiki REST API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md), [integration-openproject](../../../integration-openproject/specs/integration-openproject/spec.md), [integration-collectives](../../../integration-collectives/specs/integration-collectives/spec.md)

---

## ADDED Requirements

### Requirement: XWiki Provider Registration

The system SHALL ship an `XwikiProvider` implementing `IntegrationProvider` and SHALL register it with id=`xwiki`, group=`external`, requiredApp=`openconnector`, storageStrategy=`external`, and `getOpenConnectorSource()` returning `'xwiki'`. The provider SHALL appear in `IntegrationRegistry::list()` whenever OpenConnector is installed and enabled.

#### Scenario: Provider visible when OpenConnector is installed

- **GIVEN** OpenConnector is installed and enabled
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the result MUST include a provider with `id='xwiki'`, `group='external'`, `requiredApp='openconnector'`, `storageStrategy='external'`

#### Scenario: Provider hidden when OpenConnector is absent

- **GIVEN** OpenConnector is not installed (or disabled)
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the result MUST NOT include the xwiki provider
- **AND** `GET /api/objects/{register}/{schema}/{id}/integrations/xwiki` MUST return HTTP 503 with `details.cause: 'openconnector-down'`

### Requirement: External Routing via OpenConnector

All CRUD operations SHALL route through `ExternalIntegrationRouter`. The router SHALL resolve the declared OpenConnector source (`xwiki`) and proxy the HTTP call. Auth MAY be HTTP Basic or OAuth2 depending on the source configuration.

#### Scenario: Provider delegates all calls to the router

- **GIVEN** a healthy `xwiki` source row in OpenConnector
- **WHEN** any of `XwikiProvider::list / get / create / update / delete` is invoked
- **THEN** the call MUST be delegated to `ExternalIntegrationRouter::call()` with the `{register, schema, object}` context
- **AND** the provider MUST NOT instantiate an HTTP client, read credentials, or otherwise bypass the router

### Requirement: Flexible Link Input

The link form SHALL accept either a full XWiki URL or a direct `space.page` path. URL parsing SHALL extract the canonical reference via the OpenConnector source's `create` endpoint.

#### Scenario: Paste URL resolves to canonical reference

- **WHEN** a user pastes `https://wiki.example.gov/xwiki/bin/view/Dept/Policy/Privacy`
- **THEN** the system MUST parse it to space=`Dept.Policy`, page=`Privacy`
- **AND** the link MUST be stored with the canonical reference returned by the source

### Requirement: Breadcrumb in Tab

`CnXwikiTab` rows SHALL display the page breadcrumb (ancestor path of the linked XWiki page) above the title so users can disambiguate same-titled pages in different spaces.

#### Scenario: Tab renders breadcrumb ancestor path

- **GIVEN** a linked page with `breadcrumb: ['Wiki', 'Knowledge', 'Policy Manual']` and `title: 'Policy Manual'`
- **WHEN** `CnXwikiTab` renders the row
- **THEN** the breadcrumb element MUST render `Wiki / Knowledge` (the title is dropped as the last breadcrumb element)
- **AND** the title MUST render separately as `Policy Manual`

### Requirement: Text-Only Preview on Detail-Page

`CnXwikiCard` at `surface='detail-page'` SHALL render a text preview of the first linked page's content. The preview SHALL strip all HTML tags and the bodies of `<script>` and `<style>` blocks, SHALL truncate to ~500 characters, and SHALL be bound via text interpolation (never `v-html`). Macros SHALL remain inert text — full rendering lives in XWiki.

#### Scenario: Macros not executed in preview

- **GIVEN** a page containing an XWiki `{{velocity}}` macro and an inline `<script>` tag
- **WHEN** `CnXwikiCard` renders with `surface='detail-page'`
- **THEN** the `<script>` body MUST be removed entirely
- **AND** the macro MUST appear in the preview as inert text (not executed)
- **AND** the preview MUST NOT include any executable HTML markup

### Requirement: Auth Expiry Surfacing

When the OpenConnector source returns an auth-related failure (cause `provider-auth`), `CnXwikiTab` and `CnXwikiCard` SHALL render an explicit reconnect banner. When the source itself is missing, the UI SHALL render a Configure CTA pointing at OpenConnector's source admin page.

#### Scenario: 401 surfaces reconnect banner

- **GIVEN** the OpenConnector source's credentials are invalid (XWiki returns 401)
- **WHEN** `CnXwikiTab` fetches
- **THEN** the controller MUST respond with 503 + `details.cause: 'provider-auth'`
- **AND** the tab MUST render the auth-failure banner with a Reconnect CTA

### Requirement: Widget Surfaces

Per umbrella AD-6/AD-18, `CnXwikiCard` SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`). The `single-entity` surface SHALL render a page-title + breadcrumb chip.

#### Scenario: Single-entity chip renders with title + breadcrumb

- **GIVEN** a property of type `xwiki` whose value resolves to a page with title `Policy Manual` and breadcrumb `['Wiki', 'Knowledge', 'Policy Manual']`
- **WHEN** `CnXwikiCard` renders with `surface='single-entity'` and the matching `value`
- **THEN** the chip MUST contain the title `Policy Manual` and the ancestor path `Wiki / Knowledge`

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'xwiki'` SHALL render a page chip in `CnFormDialog` and `CnDetailGrid` via the registry's `resolveWidget(id, 'single-entity')` path. When the lookup fails, the chip SHALL fall back to a minimal chip showing the raw reference value.

#### Scenario: Fallback chip on lookup failure

- **GIVEN** a property of type `xwiki` with `value: 'Knowledge.PolicyManual'`
- **WHEN** the `single-entity` lookup returns a 503
- **THEN** `CnXwikiCard` MUST render a fallback chip whose text content includes `Knowledge.PolicyManual`
- **AND** the chip MUST carry the `cn-xwiki-card__chip--fallback` modifier class

### Requirement: Permission Inheritance

`XwikiProvider::requiresPermission()` SHALL return `null`. XWiki's own ACLs govern access transitively via the OpenConnector source; the provider MUST NOT enforce its own RBAC layer on top of the object's RBAC.

#### Scenario: No NC-side permission filter beyond object RBAC

- **GIVEN** a user with read access to the OR object
- **WHEN** the user opens the object's XWiki tab
- **THEN** the integration MUST NOT block the tab fetch with an NC-side permission error
- **AND** XWiki's own access checks MUST govern whether the upstream call returns rows

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract (AD-23). When the OpenConnector app is absent, the `xwiki` source is missing, the credentials are invalid, or the upstream XWiki host is unreachable, the provider SHALL surface the documented `ProviderUnavailableException` cause via the controller's 503 envelope rather than leaking generic errors.

#### Scenario: XWiki page moved to a new space

- **GIVEN** a linked page that was moved from `Dept.Policy.Privacy` to `Legal.Privacy` in XWiki
- **WHEN** `CnXwikiTab` renders
- **THEN** the row MUST show the new breadcrumb (XWiki returns a redirect to the new path)
- **AND** the link record's canonical reference SHOULD be updated on the next fetch

#### Scenario: Upstream service down maps to upstream-service-down cause

- **GIVEN** the OpenConnector `xwiki` source is configured but the remote XWiki host is unreachable
- **WHEN** `CnXwikiTab` fetches
- **THEN** the controller MUST respond with 503 + `details.cause: 'upstream-service-down'`
- **AND** the tab MUST render the upstream-unavailable banner with a Retry CTA
