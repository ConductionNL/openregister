---
status: proposed
---

# Integration: Forms

## Purpose

Link NC Forms responses and form-mappings to OR objects. Supports ad-hoc response linking and form-mapping for auto-linking future responses.

**Standards**: NC Forms API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## Requirements

### Requirement: Forms Provider Registration

`FormsProvider` registered with id='forms', group='workflow', requiredApp='forms', storage='link-table'.

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

### Requirement: Widget Across Surfaces

`CnFormsCard` renders on all four surfaces with appropriate density per surface.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'forms'` SHALL render `CnFormsCard` at `surface='single-entity'`.

### Requirement: Permission Inheritance

`FormsProvider::requiresPermission()` SHALL return `null`.
