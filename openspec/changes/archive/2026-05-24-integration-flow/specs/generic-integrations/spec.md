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

`FlowProvider` SHALL be registered as a tagged `IntegrationProvider` with id `'flow'`, group `'workflow'`, requiredApp `'workflowengine'`, and storage strategy `'link-table'`. The provider MUST extend `AbstractIntegrationProvider` and resolve `OCA\WorkflowEngine\Manager` lazily so the class is loadable on instances where the `workflowengine` app is disabled.

#### Scenario: Provider self-reports its metadata

- **GIVEN** the container has booted with the Flow integration registered
- **WHEN** the registry calls `getId()`, `getRequiredApp()`, `getStorageStrategy()`
- **THEN** the provider MUST return `'flow'`, `'workflowengine'`, `'link-table'` respectively

#### Scenario: Provider loads when workflowengine is disabled

- **GIVEN** the `workflowengine` app is not installed on the instance
- **WHEN** the DI container builds the provider
- **THEN** construction MUST succeed (no class resolution at constructor time)
- **AND** `isEnabled()` MUST return `false`

### Requirement: Schema-Scoped Linking (Default)

Default link scope SHALL be schema (all objects of the schema trigger the linked rule). Per-object linking SHALL be supported but discouraged in UI. Provider `list()` MUST therefore return admin-scoped operations for the object's owning schema by default and narrow to per-object-marked rows only when at least one `[or:{objectUuid}]` marker is present.

#### Scenario: Admin-scoped operations exist without any marker rows

- **GIVEN** NC Flow has 3 admin-scoped operations and none carry the `[or:{objectUuid}]` marker
- **WHEN** `list(register, schema, objectId)` is invoked
- **THEN** the provider MUST return all 3 operations (schema-scoped default)

#### Scenario: At least one marker row exists for the object

- **GIVEN** NC Flow has 5 admin-scoped operations, 2 of which contain `[or:obj-1]` in their name
- **WHEN** `list(register, schema, 'obj-1')` is invoked
- **THEN** the provider MUST return only the 2 marker-matched rows

### Requirement: Coexistence with OR Workflow Engine

The Flow tab SHALL display two clearly-labelled sections: `"NC Flow rules"` (from `workflowengine`) and `"OR workflow rules"` (from OR's own engine). The two systems MUST NOT be conflated in a single list; each row MUST disclose its origin.

#### Scenario: Both engines have operations for the schema

- **GIVEN** the schema has 2 NC Flow rules and 1 OR workflow rule
- **WHEN** the Flow tab renders
- **THEN** users MUST see two labelled sections with 2 rules in the NC Flow section and 1 rule in the OR section

### Requirement: Recent Events Panel

The Flow tab SHALL display recent fire events for linked rules within a configurable window (default 7 days). When the event log is unavailable the panel MUST degrade to a "no recent events" empty state rather than throw.

#### Scenario: Event log unavailable

- **GIVEN** NC's event log is misconfigured or empty
- **WHEN** the Recent Events panel renders
- **THEN** the panel MUST show an empty state with no error toast

### Requirement: Widget Surfaces

Per umbrella AD-6/AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`). The `detail-page` surface MUST show linked rules plus the recent-events panel.

#### Scenario: Detail-page widget renders both panes

- **GIVEN** an object detail page with the Flow widget enabled
- **WHEN** the widget mounts
- **THEN** the linked-rules pane and the recent-events pane MUST both appear

### Requirement: Reference-Property Auto-Rendering

A schema property with `referenceType: 'flow'` SHALL auto-render as a rule chip in `CnDetailGrid` / `CnFormDialog`, fetched via the provider's `get()` method.

#### Scenario: Schema property references a flow rule

- **GIVEN** a schema property `automation` with `referenceType: 'flow'`
- **WHEN** `CnDetailGrid` renders the property value
- **THEN** the rule chip MUST display the operation name and an "Open in NC settings" link

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

The provider SHALL conform to the umbrella's Error-Handling Contract. When the `workflowengine` app is disabled, the `OCA\WorkflowEngine\Manager` cannot be resolved, or `getAllOperations()` throws, the provider SHALL return the empty array from `list()` and report a structured shape from `health()` rather than letting the exception propagate.

#### Scenario: workflowengine app disabled

- **GIVEN** the `workflowengine` app is not installed
- **WHEN** `list()` is invoked
- **THEN** the method MUST return `[]`
- **AND** `health()` MUST return `['status' => 'unavailable', 'authStatus' => 'configured', 'message' => <non-null>]`

#### Scenario: Manager resolution fails despite the app being installed

- **GIVEN** the `workflowengine` app is installed
- **AND** `OCP\Server::get(OCA\WorkflowEngine\Manager::class)` throws (missing constructor deps in a non-standard bootstrap)
- **WHEN** `list()` is invoked
- **THEN** the method MUST return `[]`
- **AND** `health()` MUST return `['status' => 'degraded', 'authStatus' => 'configured', 'message' => <mentions "Manager">]`

#### Scenario: getAllOperations throws on DB drift

- **GIVEN** `workflowengine` is installed
- **AND** the Manager is resolvable
- **AND** `getAllOperations()` throws `RuntimeException` due to schema drift
- **WHEN** `list()` is invoked
- **THEN** the method MUST return `[]` (exception swallowed; no re-throw)

#### Scenario: Flow rule deleted while linked

- **GIVEN** a flow rule link whose underlying rule was deleted in NC Flow admin
- **WHEN** `CnFlowTab` renders
- **THEN** the row MUST render a "Rule deleted" placeholder with the former rule name (from cache)
- **AND** the "recent events" panel MUST continue to show historical fires from the NC event log
