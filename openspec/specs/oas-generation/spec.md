---
retrofit: true
status: implemented
---
# OAS Generation

## Purpose

OAS Generation provides on-demand OpenAPI Specification (OAS 3.x) documents derived from the live register and schema configuration. Callers can retrieve a combined specification covering all registers, or a scoped specification for a single register. The endpoint is publicly accessible without authentication — OAS is treated as self-describing public API documentation.

## Requirements

### REQ-001: Generate OpenAPI Specification for all registers

The system SHALL expose a public HTTP endpoint that generates and returns an OpenAPI Specification document covering all configured registers and their associated schemas.

The specification is built by loading the base OAS template (`Resources/BaseOas.json`), then iterating every register and its schemas to populate `components/schemas`, `paths`, `tags`, OAuth2 scopes, and the `servers` block with the absolute API base URL. RBAC and multi-tenancy filters are bypassed during generation — all registers and schemas are included regardless of the requesting user's group membership.

#### Scenario: All-register OAS is generated and returned

- **GIVEN** an OpenRegister installation with one or more configured registers and schemas
- **WHEN** `GET /api/oas` is requested (no register ID provided)
- **THEN** the response is `200 OK` with a JSON body conforming to OpenAPI 3.x
- **AND** the `components.schemas` section contains one entry per schema with a non-empty title
- **AND** the `paths` section contains CRUD endpoints (`GET /objects/{register}/{schema}`, `POST`, `GET /{id}`, `PUT /{id}`, `DELETE /{id}`) for every register/schema combination
- **AND** the `servers` block contains the absolute URL of the OpenRegister API

#### Scenario: OAS generation fails with a readable error

- **GIVEN** the base OAS template file is missing or unparseable
- **WHEN** `GET /api/oas` is requested
- **THEN** the response is `500 Internal Server Error` with a JSON body containing an `error` key describing the failure

#### Notes

- `@PublicPage` — no authentication required. This is deliberate: OAS is API documentation, not protected data.
- The `INCLUDED_EXTENDED_ENDPOINTS` whitelist is currently empty, so only core CRUD paths are generated. Extended endpoints (audit-trails, files, lock/unlock) are defined in the code but commented out of the whitelist.
- Property-level `required: boolean` fields from schema configuration are silently stripped because OpenAPI 3.x requires `required` at object level, not property level.

---

### REQ-002: Generate OpenAPI Specification for a specific register

The system SHALL expose a public HTTP endpoint that generates and returns an OpenAPI Specification document scoped to a single register identified by its slug or database ID.

When scoped to a specific register, the `info` section of the specification is updated with the register's title (as API title), version, and description. Only the schemas associated with that register appear in `components/schemas` and `paths`. All other generation logic (RBAC bypass, sanitization, integrity validation) is identical to REQ-001.

#### Scenario: Single-register OAS is generated with register-specific info

- **GIVEN** a register with slug `vergunningen` and title `Vergunningen` and two associated schemas
- **WHEN** `GET /api/oas/vergunningen` is requested
- **THEN** the response is `200 OK` with a JSON body where `info.title` is `Vergunningen API`
- **AND** `paths` contains CRUD endpoints only for the two schemas belonging to that register
- **AND** schemas from other registers are absent from `components.schemas`

#### Scenario: Register with empty description gets a generated description

- **GIVEN** a register with no description set
- **WHEN** the OAS for that register is generated
- **THEN** `info.description` is auto-generated as `"API for {register title} register providing CRUD operations, filtering, and search capabilities."`

#### Scenario: Request for non-existent register ID

- **GIVEN** a register ID that does not exist in the database
- **WHEN** `GET /api/oas/{id}` is requested
- **THEN** the response is `500 Internal Server Error` with a JSON body containing an `error` key
- **AND** no OAS document is returned

#### Notes

- The `id` parameter accepts both the register's integer database ID and its slug string — `OasService::createOas()` passes the value directly to `RegisterMapper::find()` which handles both forms.
- There is no `404 Not Found` path: the controller wraps all exceptions in a `500` response. A missing register produces a mapper exception that surfaces as `500`, not `404`.
