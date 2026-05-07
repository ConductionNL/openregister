---
status: proposed
---

# Integration: Analytics

## Purpose

Link NC Analytics reports to OR objects/schemas with embedded chart rendering via apexcharts.

**Standards**: NC Analytics API, apexcharts, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

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

Per umbrella AD-6/AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); `single-entity` includes a sparkline.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'analytics'` SHALL render report-title chip + sparkline.

### Requirement: Permission Inheritance

`requiresPermission() === null`; Analytics ACLs govern.

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying report in NC Analytics is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Analytics report returns no data

- **GIVEN** a linked Analytics report whose dataset has zero rows
- **WHEN** `CnAnalyticsCard` renders
- **THEN** an empty-state chart MUST be displayed with "No data" labeling
- **AND** the refresh action MUST remain available

#### Scenario: Analytics app version mismatch

- **GIVEN** NC Analytics returns a chart config version OR does not recognise
- **WHEN** `CnAnalyticsCard` attempts to render
- **THEN** it MUST fall back to a link-out "Open in Analytics" affordance (no broken chart)
