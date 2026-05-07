---
status: proposed
---

# Integration: Time Tracker

## Purpose

Link time entries to OR objects through the registry with configurable NC time-tracking backend.

**Standards**: NC Time Manager (and compatible time-tracking apps), ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Time Provider Registration

`TimeProvider` registered with id='time-tracker', group='workflow', requiredApp=(configurable), storage='link-table'.

### Requirement: Configurable Backend

Admin setting `time-tracker.backend` SHALL select which NC time-tracking app provides the underlying storage (default `timemanager`).

### Requirement: Denormalized Object Total

Link table SHALL store per-object hour total updated on entry write. Dashboard rendering SHALL use this total rather than aggregating entries.

#### Scenario: Dashboard total fetched as single row

- **GIVEN** an object with 120 time entries totalling 47h 30m
- **WHEN** `CnTimeCard` renders with `surface='user-dashboard'`
- **THEN** the card MUST fetch ONE row with the total
- **AND** MUST NOT aggregate individual entries at render time

### Requirement: Reconcile Command

`occ openregister:time:reconcile` SHALL recalculate totals from source entries.

### Requirement: Widget Surfaces

Per umbrella AD-6/AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); the `detail-page` rendering shows a per-user/week breakdown.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'time-tracker'` SHALL render hours chip.

### Requirement: Permission Inheritance

`requiresPermission() === null`; backend app ACLs govern.

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying time entry in the configured Time backend is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Reconcile repairs drift

- **GIVEN** the per-object total in the link table drifts from the sum of individual entries
- **WHEN** `occ openregister:time:reconcile` runs
- **THEN** the total MUST be recalculated from the backend truth
- **AND** each correction MUST be audit-logged
