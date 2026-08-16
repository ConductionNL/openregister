---
status: done
---

# integration-cospend Specification

## Purpose
Links Cospend projects and bills to OpenRegister objects, each link typed as either a project or a bill, and renders amount chips and totals across the standard surfaces. Totals aggregate only within a single currency, with mixed-currency sets shown as per-currency totals side by side. Access inherits from object RBAC plus Cospend's own ACLs.
## Requirements
### Requirement: Cospend Provider Registration

The system SHALL register `CospendProvider` with id='cospend', group='workflow', requiredApp='cospend', storage='link-table'.

#### Scenario: Provider registered with cospend id

- **WHEN** `IntegrationRegistry::getEnabled()` is called with Cospend installed
- **THEN** the result MUST include the provider with `id='cospend'`, `group='workflow'`, `storageStrategy='link-table'`

### Requirement: Project or Bill Link Types

Link rows SHALL have a `link_type` of `project` or `bill`, not both hybrid.

#### Scenario: Link row carries a single link type

- **WHEN** a Cospend link row is created
- **THEN** the system MUST set `link_type` to either `project` or `bill`, never both

### Requirement: Same-Currency Aggregation Only

Totals SHALL aggregate only bills in the same currency. Mixed-currency sets SHALL render per-currency totals side by side.

#### Scenario: Mixed-currency set renders per-currency totals

- **WHEN** linked bills span more than one currency
- **THEN** the system MUST aggregate totals only within each currency and render per-currency totals side by side

### Requirement: Widget Surfaces

`CnCospendCard` SHALL render on the standard four surfaces; the single-entity surface MUST render an amount chip.

#### Scenario: Single-entity surface renders amount chip

- **WHEN** `CnCospendCard` renders with `surface='single-entity'`
- **THEN** the system MUST render an amount chip

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'cospend'` SHALL render amount chip.

#### Scenario: Cospend reference property renders amount chip

- **WHEN** `CnDetailGrid` renders a property with `referenceType: 'cospend'`
- **THEN** the system MUST render the amount chip for that property

### Requirement: Permission Inheritance

`CospendProvider::requiresPermission()` SHALL return `null`; Cospend ACLs apply.

#### Scenario: Provider declares no extra permission

- **WHEN** `CospendProvider::requiresPermission()` is called
- **THEN** the system MUST return `null` so access inherits from object RBAC and Cospend ACLs

