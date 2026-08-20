---
status: draft
---

# No-Code App Builder

## Purpose

Extend the no-code-app-builder capability with the OpenRegister-local SPA-mount
contract: the single PHP controller surface (`UiController`) that serves the Vue
Single Page Application into which the manifest-driven, no-code UI loads. The
canonical cross-app no-code builder is owned by the root openspec; this delta
documents only the OR-local mount shell — the PHP entry points that put the SPA
on screen — reverse-specced from the existing implementation.

## ADDED Requirements

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
