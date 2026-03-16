# openapi-generation Specification

## Purpose
Auto-generate OpenAPI 3.0 specifications from register schema definitions. Each register and schema combination MUST produce a complete OpenAPI spec documenting all available endpoints, request/response schemas, authentication requirements, and example payloads. The generated spec MUST be downloadable and serveable via a Swagger UI endpoint.

**Source**: Gap identified in cross-platform analysis; developer experience improvement.

## ADDED Requirements

### Requirement: The system MUST auto-generate OpenAPI specs from schemas
Each register MUST have an automatically generated OpenAPI 3.0 specification reflecting its schemas and available operations.

#### Scenario: Generate OpenAPI spec for a register
- GIVEN register `zaken` with schemas `meldingen` and `vergunningen`
- WHEN GET /api/openapi/{register} is requested
- THEN the response MUST return a valid OpenAPI 3.0 JSON document containing:
  - `info.title`: register name
  - `info.version`: the register version
  - Paths for each schema: GET (list), GET (single), POST, PUT, DELETE
  - Schema definitions derived from schema property definitions

#### Scenario: Schema property mapping to OpenAPI types
- GIVEN schema `meldingen` with properties:
  - `title` (string, required)
  - `count` (integer)
  - `active` (boolean)
  - `tags` (array of strings)
  - `metadata` (object)
- THEN the OpenAPI schema MUST define:
  - `title`: `{type: "string"}` in `required` array
  - `count`: `{type: "integer"}`
  - `active`: `{type: "boolean"}`
  - `tags`: `{type: "array", items: {type: "string"}}`
  - `metadata`: `{type: "object"}`

### Requirement: The OpenAPI spec MUST document all endpoints accurately
Every API endpoint available for the register MUST be documented with correct HTTP methods, parameters, request bodies, and responses.

#### Scenario: Document list endpoint
- GIVEN schema `meldingen`
- THEN the OpenAPI spec MUST document:
  - `GET /api/objects/{register}/meldingen`
  - Query parameters: `_search`, `_order`, `_limit`, `_offset`, and filter parameters per property
  - Response: 200 with paginated array of melding objects

#### Scenario: Document create endpoint
- GIVEN schema `meldingen`
- THEN the OpenAPI spec MUST document:
  - `POST /api/objects/{register}/meldingen`
  - Request body: JSON object with schema properties
  - Response: 201 with the created object
  - Response: 400 for validation errors
  - Response: 403 for authorization failures

### Requirement: The OpenAPI spec MUST include example payloads
Each endpoint MUST include example request and response payloads for developer convenience.

#### Scenario: Example for create endpoint
- GIVEN schema `meldingen` with properties title (required), description, status
- THEN the OpenAPI spec MUST include an example request body:
  - `{"title": "Geluidsoverlast", "description": "Overlast na middernacht", "status": "nieuw"}`
- AND an example 201 response with UUID and metadata fields included

### Requirement: The system MUST serve a Swagger UI for interactive exploration
An interactive API explorer MUST be available for each register.

#### Scenario: Access Swagger UI
- GIVEN register `zaken` has an OpenAPI spec
- WHEN a user navigates to /api/docs/{register}
- THEN a Swagger UI MUST be displayed with:
  - All endpoints grouped by schema
  - Try-it-out functionality for authenticated users
  - Schema model browser

### Requirement: The OpenAPI spec MUST document authentication
The spec MUST describe all supported authentication methods.

#### Scenario: Authentication documentation
- THEN the OpenAPI spec MUST include `securitySchemes` for:
  - Basic Auth (Nextcloud username/password)
  - Bearer token (API consumer JWT)
- AND each endpoint MUST reference the applicable security scheme

### Requirement: The OpenAPI spec MUST be versioned
Spec versions MUST track schema changes to enable API change detection.

#### Scenario: Schema change increments spec version
- GIVEN the OpenAPI spec was generated at version `1.0.0`
- WHEN a new property is added to schema `meldingen`
- THEN the spec version MUST increment to `1.1.0` (minor for backward-compatible changes)
- AND removing a required property MUST increment the major version

### Requirement: The spec MUST be downloadable in multiple formats
The OpenAPI spec MUST be available in JSON and YAML formats.

#### Scenario: Download as JSON
- GIVEN GET /api/openapi/{register}?format=json
- THEN the response MUST be a valid JSON OpenAPI document

#### Scenario: Download as YAML
- GIVEN GET /api/openapi/{register}?format=yaml
- THEN the response MUST be a valid YAML OpenAPI document
