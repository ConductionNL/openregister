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

### Requirement: Flow Provider Real-Implementation Body

`FlowProvider` MUST replace its prior MarkerLookupTrait stub body with a real implementation backed by `OCA\WorkflowEngine\Manager`. The provider SHALL NOT execute raw SQL against `flow_operations`; all reads SHALL go through the Manager's public methods so upstream schema changes don't silently break OR.

#### Scenario: Provider does not query flow_operations directly

- **GIVEN** the FlowProvider source file
- **WHEN** static analysis inspects the use statements and method bodies
- **THEN** the file MUST NOT import `MarkerLookupTrait`
- **AND** the file MUST NOT contain raw `flow_operations` SQL
- **AND** the file MUST import `OCA\WorkflowEngine\Manager` (or its OCP-public counterpart `OCP\WorkflowEngine\IManager`)

### Requirement: Lazy Manager Resolution

The Manager SHALL be resolved lazily via `OCP\Server::get` inside `getManager()` rather than constructor-injected. Constructor signature `(IDBConnection, IAppManager, IL10N)` MUST be preserved so the shared "greenfield providers" registration block in `lib/AppInfo/Application.php` keeps working without a per-provider override.

#### Scenario: Constructor doesn't touch workflowengine classes

- **GIVEN** the `workflowengine` app is disabled
- **WHEN** the DI container constructs FlowProvider
- **THEN** construction MUST succeed without triggering autoload of any `OCA\WorkflowEngine\*` class
- **AND** the first call to `list()` MUST return `[]` (no exception)

### Requirement: Admin-Scoped Operation Discovery

The provider's `list()` SHALL call `Manager::getAllOperations(new ScopeContext(IManager::SCOPE_ADMIN))` to enumerate admin-scoped flow operations. Per-user operations are out of scope; OR is admin-gated for this leaf.

#### Scenario: Admin scope is used

- **GIVEN** NC Flow has operations in both ADMIN and USER scopes
- **WHEN** `list()` is invoked
- **THEN** the Manager MUST be called with `ScopeContext::SCOPE_ADMIN`
- **AND** USER-scope operations MUST NOT appear in the result

### Requirement: Marker-Based Per-Object Filtering

When admins want a flow rule scoped to a specific OR object they embed the marker `[or:{objectUuid}]` in the operation's `name`. The provider MUST recognise this marker on a substring basis and prefer marker-matched rows when at least one is present; otherwise it MUST return all admin-scoped operations for audit visibility.

#### Scenario: Marker rows present

- **GIVEN** 5 admin-scoped operations of which 2 contain `[or:obj-1]`
- **WHEN** `list(register, schema, 'obj-1')` is invoked
- **THEN** the result MUST contain exactly the 2 marker-matched rows

#### Scenario: No marker rows for the object

- **GIVEN** 3 admin-scoped operations none of which contain `[or:obj-1]`
- **WHEN** `list(register, schema, 'obj-1')` is invoked
- **THEN** the result MUST contain all 3 operations (the audit-view fallback)

### Requirement: Search Filter Support

When the optional `$filters['_search']` key is non-empty, the provider MUST limit the result set to operations whose `name` contains the search term (case-insensitive substring match). The search filter MUST be applied before the marker-row narrowing.

#### Scenario: Search filter excludes non-matching rows

- **GIVEN** operations `["Email to manager", "Slack notify", "Email finance"]`
- **WHEN** `list(register, schema, objectId, ['_search' => 'email'])` is invoked
- **THEN** the result MUST contain the two Email-prefixed rows
- **AND** MUST NOT contain "Slack notify"

### Requirement: SPDX Headers and Annotations

The `FlowProvider.php` file SHALL carry the canonical Conduction SPDX block inside its main docblock (`SPDX-License-Identifier: EUPL-1.2`, `SPDX-FileCopyrightText: 2026 Conduction B.V.`) and a `@spec openspec/changes/integration-flow/tasks.md` annotation per ADR-008.

#### Scenario: SPDX headers present

- **GIVEN** the FlowProvider source file
- **WHEN** the file is scanned for SPDX markers
- **THEN** the docblock MUST contain `SPDX-License-Identifier: EUPL-1.2`
- **AND** the docblock MUST contain `SPDX-FileCopyrightText: 2026 Conduction B.V.`
- **AND** the docblock MUST contain `@spec openspec/changes/integration-flow/tasks.md`

### Requirement: PHPUnit Coverage

`tests/Unit/Service/Integration/Providers/FlowProviderTest.php` SHALL cover the metadata getters, the app-disabled short-circuit, manager-unavailable degrade, `getAllOperations`-throws degrade, marker-row narrowing, schema-scoped fallback, search-filter behaviour, and the three `health()` shapes (`ok` / `unavailable` / `degraded`). The test class SHALL be marked `@group requires-app-workflowengine` so CI environments without the upstream app can skip it cleanly.

#### Scenario: PHPUnit suite includes FlowProviderTest

- **GIVEN** the openregister test suite
- **WHEN** PHPUnit collects tests
- **THEN** `FlowProviderTest` MUST be discovered
- **AND** the suite MUST include at least nine independent test methods covering the listed behaviours
