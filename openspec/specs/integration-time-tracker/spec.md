---
status: done
---

# integration-time-tracker Specification

## Purpose
Links time-tracking entries to OpenRegister objects via a configurable Nextcloud backend app (default `timemanager`), storing a denormalized per-object hour total that dashboards read in a single row instead of aggregating entries at render time. Renders across the standard widget surfaces (with a per-user/week breakdown on the detail page) and an hours chip for `time-tracker` reference properties, with an `occ openregister:time:reconcile` command to recalculate totals from source entries. The backend app's ACLs govern visibility.
## Requirements
### Requirement: Time Provider Registration

The system SHALL register `TimeProvider` with id='time-tracker', group='workflow', requiredApp=(configurable), storage='link-table'.

#### Scenario: Provider registered

- **WHEN** the integration registry is built
- **THEN** the system MUST register `TimeProvider` with id='time-tracker', group='workflow', requiredApp=(configurable), storage='link-table'

### Requirement: Configurable Backend

Admin setting `time-tracker.backend` SHALL select which NC time-tracking app provides the underlying storage (default `timemanager`).

#### Scenario: Backend selected by admin setting

- **WHEN** the admin setting `time-tracker.backend` is set
- **THEN** the system MUST use the selected NC time-tracking app for underlying storage (default `timemanager`)

### Requirement: Denormalized Object Total

Link table SHALL store per-object hour total updated on entry write. Dashboard rendering SHALL use this total rather than aggregating entries.

#### Scenario: Dashboard total fetched as single row

- **GIVEN** an object with 120 time entries totalling 47h 30m
- **WHEN** `CnTimeCard` renders with `surface='user-dashboard'`
- **THEN** the card MUST fetch ONE row with the total
- **AND** MUST NOT aggregate individual entries at render time

### Requirement: Reconcile Command

`occ openregister:time:reconcile` SHALL recalculate totals from source entries.

#### Scenario: Reconcile recalculates totals

- **WHEN** `occ openregister:time:reconcile` is run
- **THEN** the system MUST recalculate per-object totals from source entries

### Requirement: Widget Surfaces

The system SHALL render the standard four surfaces; the detail-page SHALL show a per-user/week breakdown.

#### Scenario: Surfaces rendered

- **WHEN** the Time Tracker integration renders
- **THEN** the system MUST provide the standard four surfaces, with the detail-page showing a per-user/week breakdown

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'time-tracker'` SHALL render hours chip.

#### Scenario: Reference property renders hours chip

- **WHEN** a property with `referenceType: 'time-tracker'` is rendered
- **THEN** the system MUST render the hours chip

### Requirement: Permission Inheritance

The system SHALL expose `requiresPermission() === null`; backend app ACLs govern.

#### Scenario: Permission inherited from backend

- **WHEN** `requiresPermission()` is evaluated for the Time Tracker provider
- **THEN** it MUST return `null` so that the backend app ACLs govern

