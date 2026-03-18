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

### Current Implementation Status
- **Fully implemented — OAS generation from schemas**: `OasService` (`lib/Service/OasService.php`) generates OpenAPI specs from register/schema definitions via `createOas()`. It maps schema properties to OpenAPI types and generates paths for CRUD operations.
- **Fully implemented — controller and endpoints**: `OasController` (`lib/Controller/OasController.php`) and `RegistersController` (`lib/Controller/RegistersController.php`) expose OAS endpoints. Routes exist for both single-register (`/api/registers/{id}/oas`) and all-registers OAS generation.
- **Fully implemented — base template**: `BaseOas.json` (`lib/Service/Resources/BaseOas.json`) provides the foundation including `info`, `servers`, `securitySchemes` (Basic Auth and OAuth2), and common schema components.
- **Fully implemented — authentication documentation**: The base template includes `securitySchemes` for Basic Auth and OAuth2. RBAC groups are mapped to OAuth2 scopes dynamically.
- **Partially implemented — schema property mapping**: Properties are mapped to OpenAPI types, but the quality of the output (valid references, correct composition handling) is covered by the separate `oas-validation` spec.
- **Not implemented — Swagger UI**: No interactive Swagger UI endpoint exists at `/api/docs/{register}`. The OAS is generated as JSON but not served with an interactive explorer.
- **Not implemented — YAML format**: Only JSON output is supported; YAML export is not implemented.
- **Not implemented — spec versioning**: No version tracking tied to schema changes exists. The spec does not auto-increment versions on schema modifications.
- **Not implemented — example payloads**: The generated OAS does not include example request/response bodies for endpoints.

### Standards & References
- OpenAPI Specification 3.0 / 3.1.0 (https://spec.openapis.org/oas/v3.1.0)
- Swagger UI (https://swagger.io/tools/swagger-ui/) for interactive API exploration
- OAuth 2.0 (RFC 6749) for security scheme definitions
- JSON Schema for property type mapping

### Specificity Assessment
- **Moderately specific**: The spec covers endpoint documentation, property mapping, authentication, versioning, and interactive exploration.
- **Overlap with oas-validation spec**: The `oas-validation` spec focuses on output correctness, while this spec focuses on generation features (Swagger UI, YAML, versioning, examples). These are complementary.
- **Missing details**:
  - How versioning is tracked (database field? Git-based? Hash-based?)
  - How example payloads are generated (from existing objects? Synthetic data?)
  - Swagger UI deployment specifics (embedded or external?)
- **Open questions**:
  - Should this use OpenAPI 3.0 (as stated) or 3.1.0 (as the `oas-validation` spec requires)?
  - How does the Swagger UI integrate with Nextcloud's authentication system for try-it-out functionality?

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: OasService generates OpenAPI specs from register/schema definitions via createOas(), mapping schema properties to OpenAPI types and generating paths for CRUD operations. OasController and RegistersController expose OAS endpoints for single-register and all-registers generation. BaseOas.json provides the foundation template including info, servers, securitySchemes (Basic Auth and OAuth2), and common schema components. RBAC groups are dynamically mapped to OAuth2 scopes in the generated output. The authentication documentation is auto-generated from the security configuration.

**Nextcloud Core Integration**: The auto-generation pipeline is tightly integrated with Nextcloud's infrastructure. Register and schema metadata stored in Nextcloud's database (via OCP\AppFramework\Db\Entity mappers) drives the generation. The OAS output includes Nextcloud-native authentication schemes: Basic Auth maps directly to Nextcloud username/password authentication, and OAuth2 scopes are derived from Nextcloud group memberships configured in schema authorization rules. The generated spec is compatible with Nextcloud's own OpenAPI tooling initiative, where apps expose their API contracts as machine-readable specifications.

**Recommendation**: The core generation pipeline is production-ready and well-aligned with Nextcloud's API documentation direction. The main enhancement opportunities are: adding a Swagger UI endpoint (could be a simple static HTML page bundled in the app that loads the generated JSON), implementing YAML format output alongside JSON, and adding example payloads generated from existing object data or schema defaults. For Nextcloud-specific integration, consider making the generated OAS available through Nextcloud's capabilities endpoint so external tools can auto-discover the API surface. Version tracking could leverage schema entity timestamps to detect changes and auto-increment the spec version.
