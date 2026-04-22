---
status: proposed
---

# Integration: Flow

## Purpose

Link NC Flow (workflowengine) rules to schemas/objects and surface recent fire events through the registry.

**Standards**: NC workflowengine, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

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

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying flow rule in NC Flow (workflowengine) is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Flow rule deleted while linked

- **GIVEN** a flow rule link whose underlying rule was deleted in NC Flow admin
- **WHEN** `CnFlowTab` renders
- **THEN** the row MUST render a "Rule deleted" placeholder with the former rule name (from cache)
- **AND** the "recent events" panel MUST continue to show historical fires from the NC event log
