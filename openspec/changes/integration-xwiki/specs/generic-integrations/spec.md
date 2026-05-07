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

`XwikiProvider` registered with id='xwiki', group='external', requiredApp=null, storage='external', `getOpenConnectorSource()='xwiki'`.

### Requirement: External Routing via OpenConnector

All CRUD SHALL route through `ExternalIntegrationRouter`. Auth MAY be Basic or OAuth2 depending on the OpenConnector source config.

### Requirement: Flexible Link Input

Link form SHALL accept either full XWiki URL or direct space.page path. URL parsing SHALL extract canonical reference.

#### Scenario: Paste URL resolves to canonical reference

- **WHEN** user pastes `https://wiki.example.gov/xwiki/bin/view/Dept/Policy/Privacy`
- **THEN** the system MUST parse to space=`Dept.Policy`, page=`Privacy`
- **AND** the link MUST be stored with canonical reference

### Requirement: Breadcrumb in Tab

Tab rows SHALL display full breadcrumb (wiki / space hierarchy / page title), not just title.

### Requirement: Text-Only Preview on Detail-Page

`CnXwikiCard` at `surface='detail-page'` SHALL render a text preview (first 500 chars of rendered content, macros stripped). Full rendering lives in XWiki.

#### Scenario: Macros not executed in preview

- **GIVEN** a linked page containing XWiki macros (velocity, script)
- **WHEN** preview renders
- **THEN** macro output MUST be stripped to plain text
- **AND** no macro execution MUST occur in the NC context

### Requirement: Auth Expiry Surfacing

Same as OpenProject — explicit banner + reconnect link.

### Requirement: Widget Surfaces

Per umbrella AD-6/AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); `single-entity` is a page-title + breadcrumb chip.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'xwiki'` SHALL render page chip.

### Requirement: Permission Inheritance

`requiresPermission() === null`; XWiki's own ACLs govern transitively via OpenConnector.

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying wiki page in XWiki (via OpenConnector) is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: XWiki page moved to a new space

- **GIVEN** a linked page that was moved from `Dept.Policy.Privacy` to `Legal.Privacy` in XWiki
- **WHEN** `CnXwikiTab` renders
- **THEN** the row MUST show the new breadcrumb (XWiki returns redirect to new path)
- **AND** the link record's canonical reference SHOULD be updated on next fetch

#### Scenario: XWiki macro output sanitisation

- **GIVEN** a page containing an XWiki `{{velocity}}` macro
- **WHEN** `CnXwikiCard` renders with `surface='detail-page'`
- **THEN** the macro MUST be stripped (not executed) in the preview text
- **AND** the preview MUST NOT include `<script>` or other executable markup from the XWiki renderer output
