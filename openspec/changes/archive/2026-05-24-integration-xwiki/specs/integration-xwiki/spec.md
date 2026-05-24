---
status: proposed
---

# Integration: XWiki

## Purpose

Link XWiki pages to OR objects through external routing. Complements `integration-collectives` (NC-native wiki) with external-platform support.

**Standards**: XWiki REST API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md), [integration-openproject](../../../integration-openproject/specs/integration-openproject/spec.md), [integration-collectives](../../../integration-collectives/specs/integration-collectives/spec.md)

---

## ADDED Requirements

### Requirement: XWiki Provider Registration

The system SHALL ship an `XwikiProvider` implementing `IntegrationProvider` and registered with id=`xwiki`, group=`external`, requiredApp=`openconnector`, storageStrategy=`external`, and `getOpenConnectorSource()` returning `'xwiki'`. The provider SHALL appear in `IntegrationRegistry::list()` whenever OpenConnector is installed and enabled.

#### Scenario: Provider visible when OpenConnector is installed

- **GIVEN** OpenConnector is installed and enabled
- **WHEN** `IntegrationRegistry::list()` is called
- **THEN** the returned list MUST include an entry with `id === 'xwiki'`
- **AND** that entry MUST report `group === 'external'` and `storageStrategy === 'external'`

#### Scenario: Provider hidden when OpenConnector is absent

- **GIVEN** OpenConnector is not installed (or disabled)
- **WHEN** `XwikiProvider::isEnabled()` is called
- **THEN** it MUST return `false`
- **AND** `XwikiProvider::health()` MUST surface `status: 'unavailable'` with a missing-app message rather than throwing

### Requirement: External Routing via OpenConnector

All CRUD operations on linked XWiki pages SHALL route through `ExternalIntegrationRouter`. The router SHALL resolve the declared OpenConnector source (`xwiki`) and proxy the HTTP call. Auth MAY be HTTP Basic or OAuth2 depending on the source configuration.

#### Scenario: Provider holds no HTTP client and no credentials

- **GIVEN** an `XwikiProvider` instance
- **WHEN** any of `list / get / create / update / delete` is invoked
- **THEN** the call MUST be delegated to `ExternalIntegrationRouter::call()` with the `{register, schema, object}` context
- **AND** the provider MUST NOT instantiate an HTTP client, read credentials, or otherwise bypass the router

### Requirement: Flexible Link Input

The link form SHALL accept either a full XWiki URL or a direct `space.page` path. URL parsing SHALL extract the canonical reference via the OpenConnector source's `create` endpoint.

#### Scenario: Paste URL resolves to canonical reference

- **WHEN** a user pastes `https://wiki.example.gov/xwiki/bin/view/Dept/Policy/Privacy`
- **THEN** the system MUST parse it to space=`Dept.Policy`, page=`Privacy`
- **AND** the link MUST be stored with the canonical reference returned by the source

#### Scenario: Paste space.page path stores canonical reference

- **WHEN** a user pastes `Dept.Policy.Privacy`
- **THEN** the system MUST treat the input as a canonical reference and store it without HTTP-URL parsing

### Requirement: Breadcrumb in Tab

`CnXwikiTab` rows SHALL display the full breadcrumb (wiki / space hierarchy / page title) so users can disambiguate pages with the same title across different spaces. The breadcrumb element shown above the title SHALL be the ancestor path only (the last breadcrumb element is the title and SHALL be dropped from the breadcrumb label).

#### Scenario: Breadcrumb renders ancestor path

- **GIVEN** a linked page with `breadcrumb: ['Wiki', 'Knowledge', 'Policy Manual']` and `title: 'Policy Manual'`
- **WHEN** `CnXwikiTab` renders the row
- **THEN** the title MUST render as `Policy Manual`
- **AND** the breadcrumb element MUST render `Wiki / Knowledge` (the title is dropped)

### Requirement: Text-Only Preview on Detail-Page

`CnXwikiCard` at `surface='detail-page'` SHALL render a text preview of the first linked page's content. The preview SHALL strip all HTML tags and the bodies of `<script>` and `<style>` blocks, SHALL truncate to ~500 characters, and SHALL render the result through text interpolation only (never `v-html`). Macro markup (e.g. `{{velocity}}…`) SHALL remain inert text. Full rendering lives in XWiki and is reached via the "Open in XWiki" link.

#### Scenario: Macros not executed in preview

- **GIVEN** a linked page containing XWiki macros (velocity, script) and inline `<script>` markup
- **WHEN** the detail-page preview renders
- **THEN** the `<script>` body MUST be removed entirely
- **AND** all remaining HTML tags MUST be stripped to plain text
- **AND** the result MUST be bound via text interpolation (no macro execution and no script execution in the NC context)

### Requirement: Auth Expiry Surfacing

When the OpenConnector source returns an auth-related failure (cause `provider-auth`), `CnXwikiTab` and `CnXwikiCard` SHALL render an explicit "XWiki returned 401 — check the OpenConnector source credentials" banner with a Reconnect CTA pointing at OpenConnector's source admin page. When the source itself is missing (cause `openconnector-source-missing` or `openconnector-down`), the UI SHALL instead render a "Configure XWiki connection" CTA.

#### Scenario: 401 from upstream surfaces reconnect banner

- **GIVEN** the OpenConnector source's credentials are invalid (XWiki returns 401)
- **WHEN** `CnXwikiTab` fetches
- **THEN** the controller MUST respond with 503 + `details.cause: 'provider-auth'`
- **AND** the tab MUST render the auth-failure banner with a Reconnect CTA

#### Scenario: Missing source surfaces configure banner

- **GIVEN** OpenConnector is installed but no `xwiki` source row exists
- **WHEN** `CnXwikiTab` fetches
- **THEN** the controller MUST respond with 503 + `details.cause: 'openconnector-source-missing'`
- **AND** the tab MUST render the unconfigured banner with a Configure CTA

### Requirement: Widget Surfaces

Per umbrella AD-6/AD-18, `CnXwikiCard` SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`). The dashboard surfaces SHALL show a count headline + most-recent linked page + an auth-status badge; the `single-entity` surface SHALL render a page-title + breadcrumb chip.

#### Scenario: Dashboard surface renders count + auth badge

- **GIVEN** an object with two linked XWiki pages and a healthy OpenConnector source
- **WHEN** `CnXwikiCard` renders with `surface='user-dashboard'`
- **THEN** the headline MUST read `2 linked pages`
- **AND** an auth-status badge MUST render with label `Configured` and class `cn-xwiki-card__auth-badge--configured`

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'xwiki'` SHALL render a page chip in `CnFormDialog` and `CnDetailGrid` via the registry's `resolveWidget(id, 'single-entity')` path. When the lookup fails, the chip SHALL fall back to a minimal chip showing the raw reference value rather than disappearing.

#### Scenario: Reference chip falls back on lookup failure

- **GIVEN** a property of type `xwiki` with `value: 'Knowledge.PolicyManual'`
- **WHEN** the `single-entity` lookup returns a 503
- **THEN** `CnXwikiCard` MUST render a fallback chip whose text content includes `Knowledge.PolicyManual`
- **AND** the chip MUST carry the `cn-xwiki-card__chip--fallback` modifier class

### Requirement: Permission Inheritance

`XwikiProvider::requiresPermission()` SHALL return `null`; XWiki's own ACLs govern access transitively via the OpenConnector source. The provider MUST NOT enforce its own RBAC layer on top.

#### Scenario: No NC-side permission check beyond object RBAC

- **GIVEN** a user who can read an OR object but not the linked XWiki page
- **WHEN** the user opens the object's XWiki tab
- **THEN** the integration MUST NOT block the tab fetch with an NC-side permission error
- **AND** the row's "Open in XWiki" link MUST surface XWiki's own permission denial when followed (XWiki returns 403 to the upstream call, which surfaces as a `provider-auth` or empty row)
