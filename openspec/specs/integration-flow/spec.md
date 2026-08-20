---
status: done
---

# integration-flow Specification

## Purpose
Links Nextcloud Flow (workflowengine) rules to OpenRegister objects, defaulting to schema scope so all objects of a schema trigger the linked rule. The sidebar tab separates NC Flow rules from OR workflow rules and shows recent fire events within a configurable window (default 7 days). Because Flow is admin-gated, the tab and its endpoints are visible only to admins.
## Requirements
### Requirement: Flow Provider Registration

The system SHALL register `FlowProvider` with id='flow', group='workflow', requiredApp='workflowengine', storage='link-table'.

#### Scenario: Provider registered with flow id

- **WHEN** `IntegrationRegistry::getEnabled()` is called with workflowengine installed
- **THEN** the result MUST include the provider with `id='flow'`, `group='workflow'`, `storageStrategy='link-table'`

### Requirement: Schema-Scoped Linking (Default)

Default link scope SHALL be schema (all objects of the schema trigger the linked rule). Per-object linking SHALL be supported but discouraged in UI.

#### Scenario: Default link scope is schema

- **WHEN** a user links a Flow rule without choosing a per-object scope
- **THEN** the system MUST apply the link at schema scope so all objects of the schema trigger the linked rule

### Requirement: Coexistence with OR Workflow Engine

Tab SHALL show two clearly-labelled sections: "NC Flow rules" and "OR workflow rules".

#### Scenario: Tab separates NC Flow and OR workflow rules

- **WHEN** the Flow tab renders
- **THEN** the system MUST show two clearly-labelled sections: "NC Flow rules" and "OR workflow rules"

### Requirement: Recent Events Panel

Tab SHALL display recent fire events for linked rules within a configurable window (default 7 days).

#### Scenario: Recent events shown within window

- **WHEN** the Flow tab renders for linked rules
- **THEN** the system MUST display recent fire events within the configurable window (default 7 days)

### Requirement: Widget Surfaces

`CnFlowCard` SHALL render on the standard four surfaces; the detail-page surface MUST show linked rules and recent events.

#### Scenario: Detail-page surface shows rules and events

- **WHEN** `CnFlowCard` renders with `surface='detail-page'`
- **THEN** the system MUST show the linked rules and recent events

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'flow'` SHALL render rule chip.

#### Scenario: Flow reference property renders rule chip

- **WHEN** `CnDetailGrid` renders a property with `referenceType: 'flow'`
- **THEN** the system MUST render the rule chip for that property

### Requirement: Permission Inheritance

`FlowProvider::requiresPermission()` SHALL return `'admin'` — only admins see flow rules (NC Flow admin-gated).

#### Scenario: Non-admin user does not see Flow tab

- **GIVEN** a non-admin user viewing an object
- **WHEN** `CnObjectSidebar` renders
- **THEN** no Flow tab MUST appear
- **AND** `/api/integrations/flow` MUST return HTTP 403 for the user

