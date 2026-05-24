---
status: proposed
---

# Integration: Forms

## Purpose

Link NC Forms responses and form-mappings to OR objects. Supports ad-hoc response linking and form-mapping for auto-linking future responses.

**Standards**: NC Forms API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Forms Provider Registration

The system SHALL register `FormsProvider` with `id='forms'`, `group='workflow'`, `requiredApp='forms'`, and `storage='link-table'`.

#### Scenario: Provider metadata visible in registry

- **WHEN** the integration registry enumerates known providers
- **THEN** the Forms provider MUST appear with `id='forms'`, `group='workflow'`, `requiredApp='forms'`, and `storage='link-table'`
- **AND** `getIcon()` MUST return `'ClipboardText'`

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

#### Scenario: User opens a linked response

- **WHEN** a user opens a linked response in the OR registry surface
- **THEN** the response viewer MUST display the question + answer pairs without edit affordances
- **AND** the response MUST link out to NC Forms for any modifications

### Requirement: Widget Across Surfaces

`CnFormsCard` SHALL render on all four surfaces with appropriate density per surface.

#### Scenario: Widget rendered per surface

- **WHEN** the registry resolves the Forms widget for a given surface
- **THEN** the resolved component MUST render with surface-appropriate density per surface (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`)

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'forms'` SHALL render `CnFormsCard` at `surface='single-entity'`.

#### Scenario: Schema property with referenceType forms

- **GIVEN** a schema property declares `referenceType: 'forms'`
- **WHEN** `CnFormDialog` or `CnDetailGrid` renders that property
- **THEN** the Forms provider's `single-entity` widget MUST be resolved and rendered (with AD-19 fallback to the main `widget`)

### Requirement: Permission Inheritance

`FormsProvider::requiresPermission()` SHALL return `null`.

#### Scenario: Provider does not declare a custom permission

- **WHEN** the integration registry calls `FormsProvider::requiresPermission()`
- **THEN** the method MUST return `null`
- **AND** access MUST inherit from the underlying object's RBAC + NC Forms' own permissions
