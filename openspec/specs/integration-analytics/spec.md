---
status: done
---

# integration-analytics Specification

## Purpose
Links Nextcloud Analytics reports to OpenRegister objects and embeds their charts, rendering through the shared apexcharts dependency from the Analytics chart config rather than re-implementing chart logic. Dashboard surfaces auto-refresh every five minutes while detail-page and single-entity surfaces refresh only on user action, and the single-entity surface and `referenceType: 'analytics'` properties add a sparkline. Access control defers to Analytics' own ACLs.
## Requirements
### Requirement: Analytics Provider Registration

The system SHALL register `AnalyticsProvider` with id='analytics', group='workflow', requiredApp='analytics', storage='link-table'.

#### Scenario: Analytics provider is registered

- **WHEN** the integration registry is enumerated
- **THEN** the system MUST include a provider with id='analytics', group='workflow', requiredApp='analytics', and storage='link-table'

### Requirement: Embedded Chart Rendering via Shared Library

Charts SHALL render via apexcharts (the existing shared dep via `@conduction/nextcloud-vue`) consuming Analytics' chart config. Analytics chart logic SHALL NOT be re-implemented.

#### Scenario: Charts render via apexcharts

- **WHEN** a linked Analytics report is displayed
- **THEN** the system MUST render the chart via apexcharts consuming Analytics' chart config
- **AND** the system MUST NOT re-implement Analytics chart logic

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

The system SHALL render the standard four surfaces; the single-entity surface MUST include a sparkline.

#### Scenario: Single-entity surface includes a sparkline

- **WHEN** the Analytics widget renders on the single-entity surface
- **THEN** the system MUST include a sparkline

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'analytics'` SHALL render report-title chip + sparkline.

#### Scenario: Analytics reference renders chip and sparkline

- **WHEN** a schema property declares `referenceType: 'analytics'`
- **THEN** the system MUST render a report-title chip and a sparkline

### Requirement: Permission Inheritance

The provider SHALL declare `requiresPermission() === null`; Analytics ACLs govern access.

#### Scenario: Analytics ACLs govern access

- **WHEN** a user accesses the analytics integration
- **THEN** the system MUST defer access control to Analytics' own ACLs

