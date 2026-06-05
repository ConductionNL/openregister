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

Per umbrella AD-6/AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); the `detail-page` rendering shows linked rules + recent events.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'flow'` SHALL render rule chip.

### Requirement: Admin-Gated Permission Semantics

`FlowProvider::requiresPermission()` SHALL return the literal string `'admin'`. In OR's `AuthorizationService` mapping, `'admin'` resolves to **"the current user is a member of the Nextcloud admin group"** (i.e. `IGroupManager::isAdmin($userId) === true`). It is NOT an OR-internal role string and NOT a per-object permission.

The Flow integration is hidden by **two independent gates**:

1. **App gate** (`isEnabled()` / `getRequiredApp()`): hides the integration if `workflowengine` is disabled at the NC instance level. When `workflowengine` is disabled, the integration is filtered out at stage 1 of the visibility filter for all users — admins included.
2. **Permission gate** (`requiresPermission(): 'admin'`): when `workflowengine` is enabled, the integration is filtered out at stage 1 for non-admin users. Admins see it.

The two gates are independent — disabling `workflowengine` hides the tab even from admins; enabling it exposes the tab to admins only.

#### Scenario: workflowengine disabled — tab hidden from everyone

- **GIVEN** the NC `workflowengine` app is disabled instance-wide
- **WHEN** `CnObjectSidebar` renders for any user (admin or not)
- **THEN** no Flow tab MUST appear
- **AND** `/api/integrations/flow` MUST return HTTP 404 (integration not registered, distinct from 403)

#### Scenario: workflowengine enabled, non-admin user — tab hidden via permission gate

- **GIVEN** `workflowengine` is enabled
- **AND** the current user is not in the NC admin group
- **WHEN** `CnObjectSidebar` renders
- **THEN** no Flow tab MUST appear
- **AND** `/api/integrations/flow` MUST return HTTP 403 for the user

#### Scenario: workflowengine enabled, admin user — tab visible

- **GIVEN** `workflowengine` is enabled
- **AND** the current user is a member of the NC admin group
- **WHEN** `CnObjectSidebar` renders for an object whose schema lists `flow` in `linkedTypes`
- **THEN** the Flow tab MUST appear

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying flow rule in NC Flow (workflowengine) is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Flow rule deleted while linked

- **GIVEN** a flow rule link whose underlying rule was deleted in NC Flow admin
- **WHEN** `CnFlowTab` renders
- **THEN** the row MUST render a "Rule deleted" placeholder with the former rule name (from cache)
- **AND** the "recent events" panel MUST continue to show historical fires from the NC event log
