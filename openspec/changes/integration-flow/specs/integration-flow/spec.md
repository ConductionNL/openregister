---
status: proposed
---

# Integration: Flow

## Purpose

Link NC Flow (workflowengine) rules to schemas/objects and surface recent fire events through the registry.

**Standards**: NC workflowengine, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## Requirements

### Requirement: Flow Provider Registration

`FlowProvider` registered with id='flow', group='workflow', requiredApp='workflowengine', storage='link-table'.

### Requirement: Schema-Scoped Linking (Default)

Default link scope SHALL be schema (all objects of the schema trigger the linked rule). Per-object linking SHALL be supported but discouraged in UI.

### Requirement: Coexistence with OR Workflow Engine

Tab SHALL show two clearly-labelled sections: "NC Flow rules" and "OR workflow rules".

### Requirement: Recent Events Panel

Tab SHALL display recent fire events for linked rules within a configurable window (default 7 days).

### Requirement: Widget Surfaces

Standard four; detail-page shows linked rules + recent events.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'flow'` SHALL render rule chip.

### Requirement: Permission Inheritance

`FlowProvider::requiresPermission()` SHALL return `'admin'` — only admins see flow rules (NC Flow admin-gated).

#### Scenario: Non-admin user does not see Flow tab

- **GIVEN** a non-admin user viewing an object
- **WHEN** `CnObjectSidebar` renders
- **THEN** no Flow tab MUST appear
- **AND** `/api/integrations/flow` MUST return HTTP 403 for the user
