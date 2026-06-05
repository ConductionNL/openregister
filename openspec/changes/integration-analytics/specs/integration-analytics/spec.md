---
status: proposed
---

# Integration: Analytics

## Purpose

Link NC Analytics reports to OR objects/schemas with embedded chart rendering via apexcharts.

**Standards**: NC Analytics API, apexcharts, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## Requirements

### Requirement: Analytics Provider Registration

`AnalyticsProvider` registered with id='analytics', group='workflow', requiredApp='analytics', storage='link-table'.

### Requirement: Embedded Chart Rendering via Shared Library

Charts SHALL render via apexcharts (the existing shared dep via `@conduction/nextcloud-vue`) consuming Analytics' chart config. Analytics chart logic SHALL NOT be re-implemented.

### Requirement: Differential Refresh Rates

Dashboard surfaces SHALL auto-refresh every 5 minutes. Detail-page and single-entity surfaces SHALL refresh only on user action.

#### Scenario: Dashboard chart refreshes automatically

- **GIVEN** a linked Analytics report displayed on `user-dashboard`
- **WHEN** 5 minutes pass since last fetch
- **THEN** the chart data MUST be re-fetched without user interaction

#### Scenario: Detail-page chart does not auto-refresh

- **GIVEN** a linked Analytics report displayed on `detail-page`
- **WHEN** 5 minutes pass
- **THEN** the chart data MUST NOT be re-fetched unless user clicks refresh or re-enters the route

### Requirement: Widget Surfaces

Standard four; single-entity includes sparkline.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'analytics'` SHALL render report-title chip + sparkline.

### Requirement: Permission Inheritance

`requiresPermission() === null`; Analytics ACLs govern.
