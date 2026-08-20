---
status: redirect
---
# No-Code App Builder

## Purpose

@e2e exclude redirect stub — no scenarios in OR
This spec is a redirect stub. The canonical specification for the no-code app builder is owned by the root openspec (cross-app capability). This stub exists to preserve the spec slug locally and MUST NOT be treated as authoritative.
## Requirements
### Requirement: Consult the canonical no-code-app-builder spec
Implementers MUST consult the canonical specification owned by the root openspec instead of treating this stub as authoritative.

#### Scenario: Locating the canonical spec
- **WHEN** a developer needs the requirements for the no-code app builder
- **THEN** they MUST refer to the canonical spec in the root openspec
- **AND** they MUST NOT derive normative behavior from this stub

### Requirement: SPA deep-link routes MUST serve the Vue mount template with a client-routing CSP

The system MUST serve every history-mode deep-link route through a single
SPA-mount contract so that all in-app navigation is handled client-side by the
Vue Single Page Application. `UiController` MUST expose one route method per
top-level UI surface (registers, register details, schemas, schema details,
sources, organisation, objects, integrations view, tables, chat, configurations,
deleted, audit trail, search trail, webhooks, webhook logs, entities, entity
details, AVG, reports, report view, endpoints, endpoint logs), and every such
method MUST delegate to a single private `makeSpaResponse()` helper rather than
rendering surface-specific markup. `makeSpaResponse()` MUST return a
`TemplateResponse` for the `index` template with a `ContentSecurityPolicy` whose
`connect-src` permits all domains (`*`) so the frontend can reach the API, and
MUST fall back to the `error` template with HTTP 500 if rendering throws. All
route methods MUST be annotated `@NoAdminRequired` and `@NoCSRFRequired` so deep
links resolve for any authenticated user without a CSRF token.

#### Scenario: Deep-link route returns the SPA index template
- **GIVEN** an authenticated user requests a history-mode route such as `/registers`, `/schemas/{id}`, `/objects`, `/tables`, `/chat`, or `/avg`
- **WHEN** the corresponding `UiController` method runs
- **THEN** it MUST delegate to `makeSpaResponse()`
- **AND** the response MUST be a `TemplateResponse` for the `index` template
- **AND** the response MUST carry a CSP allowing `connect-src '*'`

#### Scenario: All surfaces share one mount helper
- **GIVEN** the 25 history-mode route methods on `UiController`
- **WHEN** each method body is inspected
- **THEN** every method MUST return `makeSpaResponse()` with no surface-specific rendering
- **AND** client-side Vue Router MUST own which view renders based on the URL path

#### Scenario: Render failure falls back to the error template
- **GIVEN** template rendering inside `makeSpaResponse()` throws an exception
- **WHEN** the failure is caught
- **THEN** the response MUST be a `TemplateResponse` for the `error` template carrying the exception message
- **AND** the HTTP status MUST be 500

#### Scenario: Standalone integrations view route for the screenshot harness
- **GIVEN** a request to `/integrations/{register}/{schema}/{objectId}`
- **WHEN** `integrationsView()` runs
- **THEN** it MUST serve the same SPA mount via `makeSpaResponse()` so the harness lands directly on the IntegrationsView Vue route
- **AND** it MUST NOT depend on ObjectDetails sub-resource plugin loading

