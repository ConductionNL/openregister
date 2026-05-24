---
status: proposed
---

# Integration: Time Tracker

## Purpose

Link time entries (clients + tasks + hour totals) to OR objects through the registry with a configurable NC time-tracking backend.

**Standards**: NC Time Manager (and compatible time-tracking apps), ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Time Provider Registration

The system SHALL register `TimeProvider` with `id='time-tracker'`, `group='workflow'`, `requiredApp='timemanager'`, and `storage='link-table'`.

#### Scenario: Provider metadata visible in registry

- **WHEN** the integration registry enumerates known providers
- **THEN** the Time provider MUST appear with `id='time-tracker'`, `group='workflow'`, `requiredApp='timemanager'`, and `storage='link-table'`
- **AND** `getIcon()` MUST return `'Clock'`

### Requirement: Configurable Backend

The admin setting `time-tracker.backend` SHALL select which NC time-tracking app provides the underlying storage (default `timemanager`).

#### Scenario: Backend selectable via admin setting

- **GIVEN** an admin opens the OpenRegister settings page
- **WHEN** they choose a different time-tracking app
- **THEN** the `time-tracker.backend` setting MUST be persisted
- **AND** the provider's `requiredApp` resolution MUST reflect the new backend on the next request

### Requirement: Denormalized Object Total

The link table SHALL store a per-object hour total updated on entry write. Dashboard rendering SHALL use this total rather than aggregating entries.

#### Scenario: Dashboard total fetched as single row

- **GIVEN** an object with 120 time entries totalling 47h 30m
- **WHEN** `CnTimeCard` renders with `surface='user-dashboard'`
- **THEN** the card MUST fetch ONE row with the total
- **AND** MUST NOT aggregate individual entries at render time

### Requirement: Reconcile Command

The `occ openregister:time:reconcile` command SHALL recalculate per-object totals from source entries.

#### Scenario: Reconcile repairs drift

- **GIVEN** the per-object total in the link table drifts from the sum of individual entries
- **WHEN** `occ openregister:time:reconcile` runs
- **THEN** the total MUST be recalculated from the backend truth
- **AND** each correction MUST be audit-logged

### Requirement: Widget Across Surfaces

`CnTimeCard` SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`) with surface-appropriate density. The `detail-page` rendering shows a per-user/week breakdown.

#### Scenario: Widget rendered per surface

- **WHEN** the registry resolves the Time widget for a given surface
- **THEN** the resolved component MUST render with surface-appropriate density (totals chip on dashboards; per-user/week breakdown on detail; hours chip on single-entity)

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'time-tracker'` SHALL render an hours chip via `CnTimeCard` at `surface='single-entity'`.

#### Scenario: Schema property with referenceType time-tracker

- **GIVEN** a schema property declares `referenceType: 'time-tracker'`
- **WHEN** `CnFormDialog` or `CnDetailGrid` renders that property
- **THEN** the Time provider's `single-entity` widget MUST be resolved and rendered (with AD-19 fallback to the main `widget`)

### Requirement: Permission Inheritance

`TimeProvider::requiresPermission()` SHALL return `null` so access inherits from the underlying object's RBAC and the backing time-tracking app's own permissions.

#### Scenario: Provider does not declare a custom permission

- **WHEN** the integration registry calls `TimeProvider::requiresPermission()`
- **THEN** the method MUST return `null`
- **AND** access to time entries MUST be governed by object RBAC + Time Manager permissions

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When the configured time-tracking backend is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors. `health()` MUST return the `IntegrationHealth::missingApp('timemanager')`-shaped descriptor when the configured backend is not installed.

#### Scenario: NC Time Manager app uninstalled

- **GIVEN** the NC Time Manager app is not installed
- **WHEN** the registry calls `TimeProvider::list()` or `TimeProvider::health()`
- **THEN** `list()` MUST return `[]` without throwing
- **AND** `health()` MUST return `status='unavailable'` with the documented missing-app message

#### Scenario: Backing classpath missing at runtime

- **GIVEN** the Time Manager app id is reported installed but the `OCA\TimeManager\Db\ClientMapper` class fails to resolve via the server container
- **WHEN** the registry calls `TimeProvider::list()`
- **THEN** the provider MUST swallow the resolution failure and return `[]`
- **AND** MUST NOT propagate a `NotFoundExceptionInterface` or other generic error to the caller
