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
