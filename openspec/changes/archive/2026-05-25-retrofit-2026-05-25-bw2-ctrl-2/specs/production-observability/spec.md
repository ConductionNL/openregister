---
status: draft
---

# Production Observability

## Purpose

Extend production-observability with per-entity statistics endpoints and the
custom-endpoint delivery-log API. The existing spec covers the Prometheus
metrics endpoint, the health/readiness endpoints, and structured logging, but
has no requirement for the per-register / per-schema statistics surface or for
the delivery-log API on custom endpoints. Reverse-specced from the live
`RegistersController`, `SchemasController`, and `EndpointsController`.

## ADDED Requirements

### Requirement: Per-Entity Statistics and Endpoint Delivery-Log API

The system MUST expose per-entity statistics read endpoints and a
custom-endpoint delivery-log API for operational observability.

`RegistersController::stats` (`GET /api/registers/{id}/stats`) and
`SchemasController::stats` (`GET /api/schemas/{id}/stats`) MUST return an
aggregate statistics envelope for the entity, including object counts (total,
invalid, deleted, locked, and storage size) and audit-log statistics, scoped
to the entity. An unknown id MUST return `404` with an `{error}` body.

`EndpointsController` MUST expose the delivery-log API:

- `logs` (`GET /api/endpoints/{id}/logs`) — the delivery/call log entries for a
  single endpoint;
- `logStats` (`GET /api/endpoints/{id}/logs/stats`) — aggregate statistics over
  that endpoint's delivery log; and
- `allLogs` (`GET /api/endpoints/logs`) — the delivery log across all
  endpoints.

The statistics and log endpoints MUST respect the same RBAC + multi-tenancy
filters as the underlying mappers so they cannot enumerate counts or log
entries across tenants.

#### Scenario: Register statistics envelope
- **GIVEN** a register with a known id containing objects across its schemas
- **WHEN** `GET /api/registers/{id}/stats` is called
- **THEN** the response MUST include object counts (total, invalid, deleted, locked, size) and audit-log statistics scoped to the register
- **AND** an unknown id MUST return HTTP 404 with an `{error}` body

#### Scenario: Schema statistics envelope
- **GIVEN** a schema with a known id
- **WHEN** `GET /api/schemas/{id}/stats` is called
- **THEN** the response MUST include object counts and audit-log statistics scoped to the schema

#### Scenario: Endpoint delivery logs
- **GIVEN** a custom endpoint with delivery-log entries
- **WHEN** `GET /api/endpoints/{id}/logs` is called
- **THEN** the response MUST return that endpoint's delivery-log entries
- **AND** `GET /api/endpoints/logs` MUST return entries across all endpoints
- **AND** `GET /api/endpoints/{id}/logs/stats` MUST return aggregate statistics over the endpoint's log

## Non-Functional Requirements

- **i18n (ADR-007)**: These are operator-facing statistics/log JSON REST
  endpoints. The only app-authored strings are `{error}` diagnostics on unknown
  ids, which are operator copy and exempt from translation. Statistics envelopes
  and delivery-log entries carry counts and recorded call data, not localisable
  UI copy. (ADR-007 n/a.)
- **REST/error contract (ADR-002)**: Follows OpenRegister REST conventions —
  `404` with an `{error}` body for unknown ids and a consistent statistics
  envelope. The statistics and log endpoints MUST respect the same RBAC +
  multi-tenancy filters as the underlying mappers so counts and log entries
  cannot be enumerated across tenants.

## Acceptance Criteria

- [x] `RegistersController::stats`, `SchemasController::stats`, and the `EndpointsController` log verbs carry `@spec production-observability#...` annotations.
- [x] Register/schema `stats` return the aggregate envelope (object counts incl. total/invalid/deleted/locked/size + audit-log stats) scoped to the entity; unknown ids return `404`.
- [x] `logs`/`logStats`/`allLogs` return per-endpoint and cross-endpoint delivery-log data as specified.
- [x] Statistics and log endpoints respect RBAC + multi-tenancy (no cross-tenant enumeration).
- [x] `openspec validate retrofit-2026-05-25-bw2-ctrl-2 --strict` passes.
