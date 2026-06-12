---
status: proposed
---

# Integration: Time Tracker

## Purpose

Link time entries to OR objects through the registry with configurable NC time-tracking backend.

**Standards**: NC Time Manager (and compatible time-tracking apps), ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## Requirements

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

Standard four; detail-page shows per-user/week breakdown.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'time-tracker'` SHALL render hours chip.

### Requirement: Permission Inheritance

`requiresPermission() === null`; backend app ACLs govern.
