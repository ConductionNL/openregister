---
status: done
---

# integration-forms Specification

## Purpose
TBD - created by archiving change integration-forms. Update Purpose after archive.
## Requirements
### Requirement: Forms Provider Registration

The system SHALL register `FormsProvider` with id='forms', group='workflow', requiredApp='forms', storage='link-table'.

#### Scenario: Provider registered with forms id

- **WHEN** `IntegrationRegistry::getEnabled()` is called with Forms installed
- **THEN** the result MUST include the provider with `id='forms'`, `group='workflow'`, `storageStrategy='link-table'`

### Requirement: Two Link Modes

The integration SHALL support (a) linking individual responses and (b) form-mapping for auto-linking future responses.

#### Scenario: Individual response link

- **WHEN** user picks an existing response and clicks "Link"
- **THEN** `openregister_form_links` row type `response_link` MUST be created
- **AND** the response MUST appear in the tab

#### Scenario: Form-mapping auto-link

- **GIVEN** a form-mapping exists for form F on schema S with object-selector O
- **WHEN** a user submits a response to form F
- **THEN** a post-submit hook MUST resolve the object via selector O
- **AND** link the response to that object automatically

### Requirement: Read-Only Response View

Response rendering SHALL be read-only. Editing delegates to NC Forms.

#### Scenario: Response view is read-only

- **WHEN** a linked response is rendered in the tab
- **THEN** the system MUST render it read-only and delegate editing to NC Forms

### Requirement: Widget Across Surfaces

`CnFormsCard` SHALL render on all four surfaces with appropriate density per surface.

#### Scenario: Card renders per-surface density

- **WHEN** `CnFormsCard` renders on any of the four surfaces
- **THEN** the system MUST render with the density appropriate to that surface

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'forms'` SHALL render `CnFormsCard` at `surface='single-entity'`.

#### Scenario: Forms reference property renders card chip

- **WHEN** `CnDetailGrid` renders a property with `referenceType: 'forms'`
- **THEN** the system MUST render `CnFormsCard` at `surface='single-entity'` for that property

### Requirement: Permission Inheritance

`FormsProvider::requiresPermission()` SHALL return `null`.

#### Scenario: Provider declares no extra permission

- **WHEN** `FormsProvider::requiresPermission()` is called
- **THEN** the system MUST return `null` so access inherits from object RBAC and Forms app access

