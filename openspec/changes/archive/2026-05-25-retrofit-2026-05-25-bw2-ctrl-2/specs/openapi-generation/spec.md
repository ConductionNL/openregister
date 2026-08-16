---
status: draft
---

# OpenAPI Generation

## Purpose

Extend openapi-generation with the schema-authoring sub-resources and the
registry meta-entity *operational* endpoints that the shared resource-CRUD
requirement (added by `retrofit-2026-05-24-b-ctrl-registry-views` task-1)
explicitly defers to "their own capability specs". Reverse-specced from the
live `SchemasController`, `RegistersController`, `EndpointsController`, and
`MappingsController`.

## ADDED Requirements

### Requirement: Schema Authoring Sub-Resources and Meta-Entity Operational Endpoints

The system MUST expose schema-authoring sub-resources and registry
meta-entity operational endpoints beyond the uniform resource-CRUD contract.

`SchemasController` MUST provide:

- `download` (`GET /api/schemas/{id}/download`) — return the schema as a JSON
  document (`404` when the schema does not exist);
- `upload` (`POST /api/schemas/upload`) and `uploadUpdate`
  (`PUT /api/schemas/{id}/upload`) — create or update a schema from a JSON
  document supplied by `file`, `url`, or inline `json`;
- `related` (`GET /api/schemas/{id}/related`) — return the incoming and
  outgoing `$ref` relationships of the schema as `{incoming, outgoing, total}`;
- `explore` (`GET /api/schemas/{id}/explore`) — analyse all objects of the
  schema to discover properties present in object data but absent from the
  schema definition; and
- `updateFromExploration` (`POST /api/schemas/{id}/update-from-exploration`) —
  apply selected discovered properties back onto the schema.

`RegistersController` MUST provide the register sub-resource lookups
`schemas` (`GET /api/registers/{id}/schemas`, returning `{results, total}`)
and `objects` (`GET /api/registers/{id}/objects`).

`EndpointsController::test` (`POST /api/endpoints/{id}/test`) and
`MappingsController::test` (`POST /api/mappings/test`) MUST execute a dry-run
of the endpoint or mapping against supplied sample input and return the
execution result (status code, transformed output, or structured error)
WITHOUT persisting any side effect.

All of these endpoints MUST return `404` with an `{error}` body for an unknown
id and MUST respect the same RBAC + multi-tenancy filters as the underlying
mapper/service.

#### Scenario: Download a schema as JSON
- **GIVEN** a schema with a known id
- **WHEN** `GET /api/schemas/{id}/download` is called
- **THEN** the response MUST return HTTP 200 with the schema's JSON document
- **AND** an unknown id MUST return HTTP 404 with `{error: "Schema not found"}`

#### Scenario: Explore discovers undeclared properties
- **GIVEN** a schema whose objects carry properties not present in the schema definition
- **WHEN** `GET /api/schemas/{id}/explore` is called
- **THEN** the response MUST list the discovered properties
- **AND** `POST /api/schemas/{id}/update-from-exploration` MUST be able to apply selected discovered properties onto the schema

#### Scenario: Related returns incoming and outgoing references
- **GIVEN** schema A is `$ref`-referenced by schema B and itself references schema C
- **WHEN** `GET /api/schemas/{idA}/related` is called
- **THEN** the response MUST include B under `incoming` and C under `outgoing` with a `total` count

#### Scenario: Dry-run test does not persist
- **GIVEN** an endpoint or mapping with a known id
- **WHEN** its `test` endpoint is called with sample input
- **THEN** the response MUST return the execution result
- **AND** no entity or audit side effect MUST be persisted

## Non-Functional Requirements

- **i18n (ADR-007)**: These are administrator/authoring JSON REST endpoints. The
  only app-authored strings are `{error}` diagnostics (e.g. `{error: "Schema
  not found"}`), which are operator copy and exempt from translation. Schema
  documents, `$ref` relationship maps, and dry-run results are machine-readable
  artefacts, not localisable UI copy. (ADR-007 n/a.)
- **REST/error contract (ADR-002)**: Follows OpenRegister REST conventions —
  `404` with an `{error}` body for unknown ids, the `{results, total}` envelope
  on sub-resource lookups, and `{incoming, outgoing, total}` on the relationship
  endpoint. The `test` dry-run endpoints MUST return the execution result
  (status / transformed output / structured error) WITHOUT persisting any side
  effect, and all endpoints MUST respect the underlying mapper/service RBAC +
  multi-tenancy filters.

## Acceptance Criteria

- [x] `SchemasController`, `RegistersController`, `EndpointsController`, and `MappingsController` carry `@spec openapi-generation#...` annotations for these verbs.
- [x] `download`/`upload`/`uploadUpdate`/`related`/`explore`/`updateFromExploration` behave as specified; unknown ids return `404`.
- [x] Register `schemas`/`objects` sub-resource lookups return their envelopes; `related` returns `{incoming, outgoing, total}`.
- [x] `EndpointsController::test` and `MappingsController::test` run a dry-run with no persisted side effect and respect RBAC/multi-tenancy.
- [x] `openspec validate retrofit-2026-05-25-bw2-ctrl-2 --strict` passes.
