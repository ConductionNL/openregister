---
status: draft
---

# OAS Generation

## Purpose

Extend oas-generation with the register/schema publication lifecycle and the
OAS-to-GitHub publishing HTTP surface. The existing spec covers on-demand OAS
*document generation* (`GET /api/oas`) but has no requirement for the
publish/depublish controller verbs or for pushing a generated OAS document to
a GitHub repository. Reverse-specced from the live `RegistersController` and
`SchemasController`.

## ADDED Requirements

### Requirement: Register and Schema Publication and GitHub OAS Publishing

The system MUST expose publication-lifecycle verbs on registers and schemas
and a GitHub OAS publishing endpoint on registers.

`RegistersController` MUST provide `publish`
(`POST /api/registers/{id}/publish`) and `depublish`
(`POST /api/registers/{id}/depublish`), and `SchemasController` MUST provide
`publish` (`POST /api/schemas/{id}/publish`) and `depublish`
(`POST /api/schemas/{id}/depublish`), which toggle the entity's published
state. Each verb MUST return the updated entity and MUST return `404` with an
`{error}` body for an unknown id.

`RegistersController::publishToGitHub`
(`POST /api/registers/{id}/publish/github`) MUST generate the register's OAS
document and commit it to a GitHub repository. The request MUST require
`owner` and `repo` parameters and MUST return `400` when either is missing.
The endpoint MAY accept `path`, `branch` (default `main`), and
`commitMessage`; on failure it MUST return a `500` with a descriptive
`{error}` body rather than leaking the underlying exception trace.

#### Scenario: Publish and depublish a register
- **GIVEN** a register with a known id
- **WHEN** `POST /api/registers/{id}/publish` is called
- **THEN** the register MUST be marked published and the updated entity returned
- **AND** `POST /api/registers/{id}/depublish` MUST clear the published state
- **AND** an unknown id MUST return HTTP 404 with an `{error}` body

#### Scenario: Publish a schema
- **GIVEN** a schema with a known id
- **WHEN** `POST /api/schemas/{id}/publish` is called
- **THEN** the schema MUST be marked published and the updated entity returned

#### Scenario: Publish a register OAS to GitHub requires owner and repo
- **GIVEN** a register with a known id
- **WHEN** `POST /api/registers/{id}/publish/github` is called without `owner` or `repo`
- **THEN** the response MUST be HTTP 400 with an `{error}` body
- **AND** a request with valid `owner` and `repo` MUST commit the generated OAS document to the target repository
