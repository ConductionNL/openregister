---
status: redirect
change: built-in-dashboards
---

# Built-in Dashboards

## Purpose

This specification is a redirect stub. The canonical specification for built-in dashboards lives in the root openspec (cross-app pattern) and defines the dashboard UI layer, widget vocabulary, and operator experience.

OpenRegister integrates with built-in dashboards by providing aggregation data via the `rapportage-bi-export` spec. This stub exists to preserve the spec slug locally and MUST NOT be treated as authoritative for dashboard requirements.

## Requirements

### Requirement: Consult the canonical built-in-dashboards spec

Implementers MUST consult the canonical `built-in-dashboards` specification owned by the root openspec instead of deriving normative behavior from this redirect stub.

#### Scenario: Locating canonical widget definitions
- **WHEN** a developer needs to understand available dashboard widget types
- **THEN** they MUST refer to the canonical `built-in-dashboards` spec in root openspec
- **AND** they MUST NOT invent new widget types locally

#### Scenario: Configuring operator dashboard UX
- **WHEN** an OpenRegister operator configures a new dashboard
- **THEN** the canonical spec defines the configuration schema and rendering behavior
- **AND** OpenRegister's role is to supply the aggregation data via `rapportage-bi-export`

## Related Specifications

- **rapportage-bi-export** (this app): Provides chart data API and dashboard widget integration
- **built-in-dashboards** (root openspec): Canonical UI layer and widget definitions
