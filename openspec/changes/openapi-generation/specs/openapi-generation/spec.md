---
status: draft
---
# OpenAPI Generation

## Purpose
Auto-generate OpenAPI 3.1.0 specifications from register and schema definitions stored in OpenRegister, producing complete API documentation that covers every CRUD endpoint, query parameter, authentication scheme, and response model. The generated spec MUST be downloadable in JSON and YAML formats, serveable via an interactive Swagger UI, and MUST regenerate automatically when schemas change so that documentation never drifts from the live API surface. The generation pipeline MUST also support NL API Design Rules compliance markers for Dutch government API interoperability.

**Source**: Gap identified in cross-platform analysis; developer experience improvement. Competitors Strapi (`@strapi/openapi`) and Directus both auto-generate OpenAPI specs from their data models. NocoDB exposes a Swagger endpoint per base.

## ADDED Requirements

### Requirement: The system MUST auto-generate OpenAPI 3.1.0 specs from register/schema definitions
Each register MUST have an automatically generated OpenAPI 3.1.0 specification reflecting all schemas belonging to that register, their properties, and all available CRUD operations. The generation MUST be driven by `OasService::createOas()` reading from `RegisterMapper` and `SchemaMapper`, using `BaseOas.json` as the foundation template.

#### Scenario: Generate OpenAPI spec for a single register
- **GIVEN** register `zaken` (id=1) exists with schemas `meldingen` and `vergunningen`
- **WHEN** `GET /api/registers/1/oas` is requested
- **THEN** the response MUST return a valid OpenAPI 3.1.0 JSON document containing:
  - `openapi`: `"3.1.0"`
  - `info.title`: `"zaken API"` (register title + " API")
  - `info.version`: the register's version string from `Register::getVersion()`
  - `info.contact` and `info.license` preserved from `BaseOas.json`
  - Paths for each schema: `GET /objects/zaken/meldingen`, `POST /objects/zaken/meldingen`, `GET /objects/zaken/meldingen/{id}`, `PUT /objects/zaken/meldingen/{id}`, `DELETE /objects/zaken/meldingen/{id}`
  - Matching paths for `vergunningen`
  - Schema definitions under `components.schemas` derived from each schema's property definitions

#### Scenario: Generate combined OpenAPI spec for all registers
- **GIVEN** registers `zaken` and `burgerzaken` both exist with schemas
- **WHEN** `GET /api/registers/oas` is requested (no register ID)
- **THEN** the response MUST return a single OpenAPI document covering all registers
- **AND** `operationId` values MUST be prefixed with the PascalCase register title (e.g., `ZakenGetAllMeldingen`, `BurgerzakenGetAllAdressen`) to ensure uniqueness across registers

#### Scenario: Register without schemas produces minimal valid spec
- **GIVEN** register `leeg` exists but has zero schemas assigned
- **WHEN** `GET /api/registers/{leeg-id}/oas` is requested
- **THEN** the response MUST be a valid OpenAPI 3.1.0 document with empty `paths: {}` and only the base `components.schemas` (Error, PaginatedResponse, _self)

#### Scenario: Schema with empty title is excluded
- **GIVEN** a schema exists with `title = ""` or `title = null`
- **WHEN** OAS is generated
- **THEN** that schema MUST be silently skipped (no paths, no component definition, no tag)

### Requirement: Schema property definitions MUST map correctly to OpenAPI types
Every property defined in an OpenRegister schema MUST be translated to a valid OpenAPI 3.1.0 schema definition. The mapping MUST handle all JSON Schema types, format annotations, enumerations, composition keywords, and nested structures. Property sanitization is performed by `OasService::sanitizePropertyDefinition()`.

#### Scenario: Basic property type mapping
- **GIVEN** schema `meldingen` with properties:
  - `title` (type: string, required: true)
  - `count` (type: integer)
  - `active` (type: boolean)
  - `tags` (type: array, items: {type: string})
  - `metadata` (type: object)
  - `score` (type: number)
- **THEN** the OpenAPI component schema MUST define:
  - `title`: `{type: "string"}`
  - `count`: `{type: "integer"}`
  - `active`: `{type: "boolean"}`
  - `tags`: `{type: "array", items: {type: "string"}}`
  - `metadata`: `{type: "object"}`
  - `score`: `{type: "number"}`

#### Scenario: Properties with format, enum, and constraints
- **GIVEN** a property `email` with `{type: "string", format: "email", maxLength: 255}`
- **AND** a property `status` with `{type: "string", enum: ["open", "closed", "pending"]}`
- **THEN** the OpenAPI output MUST preserve `format`, `enum`, `maxLength`, `minLength`, `pattern`, `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`, `minItems`, `maxItems`, `uniqueItems`, `default`, `const`, `example`

#### Scenario: Non-array property without type gets default
- **GIVEN** a property definition that is not an array (e.g., a plain string value)
- **WHEN** OAS is generated
- **THEN** the property MUST be rendered as `{type: "string", description: "Property value"}`

#### Scenario: Internal fields are stripped from output
- **GIVEN** a property definition containing internal keys: `objectConfiguration`, `inversedBy`, `authorization`, `defaultBehavior`, `cascadeDelete`
- **WHEN** OAS is generated
- **THEN** only standard OpenAPI schema keywords (type, format, description, enum, $ref, allOf, etc.) MUST appear in the output
- **AND** all internal/non-OAS keys MUST be removed by the allowed-keywords whitelist

#### Scenario: System properties _self and id are injected
- **GIVEN** any schema
- **WHEN** OAS is generated
- **THEN** the component schema MUST include:
  - `_self`: `{$ref: "#/components/schemas/_self", readOnly: true}` (metadata with uuid, uri, version, register, schema, owner, updated, created)
  - `id`: `{type: "string", format: "uuid", readOnly: true}`

### Requirement: The OpenAPI spec MUST document all CRUD endpoints accurately
Every API endpoint for each register/schema combination MUST be documented with correct HTTP methods, path parameters, query parameters, request bodies, and response schemas. Endpoint generation is handled by `OasService::addCrudPaths()`.

#### Scenario: Collection endpoint (GET list)
- **GIVEN** schema `meldingen` in register `zaken`
- **THEN** the OpenAPI spec MUST document `GET /objects/zaken/meldingen` with:
  - Query parameters: `_extend`, `_filter`, `_unset`, `_search` (collection-specific), plus dynamic filter parameters for each schema property (e.g., `title`, `status`, `count`)
  - Response 200: `allOf` composing `PaginatedResponse` with `results` array of `$ref: #/components/schemas/Meldingen`
  - Response 400: Error schema for invalid query parameters
  - Response 403: Error for RBAC authorization failures (added by `applyRbacToOperation()`)

#### Scenario: Single resource endpoint (GET by ID)
- **GIVEN** schema `meldingen`
- **THEN** the OpenAPI spec MUST document `GET /objects/zaken/meldingen/{id}` with:
  - Path parameter `id` (string, format: uuid, required: true)
  - Query parameters: `_extend`, `_filter`, `_unset`
  - Response 200: `$ref: #/components/schemas/Meldingen`
  - Response 404: Error schema

#### Scenario: Create endpoint (POST)
- **GIVEN** schema `meldingen`
- **THEN** the OpenAPI spec MUST document `POST /objects/zaken/meldingen` with:
  - Request body: `application/json` referencing the schema component
  - Response 201: created object with `$ref` to schema component
  - Response 400: validation error
  - Response 403: RBAC authorization failure

#### Scenario: Update endpoint (PUT)
- **GIVEN** schema `meldingen`
- **THEN** the OpenAPI spec MUST document `PUT /objects/zaken/meldingen/{id}` with:
  - Path parameter `id` (string, format: uuid)
  - Request body: `application/json` referencing the schema component
  - Response 200: updated object
  - Response 404: not found
  - Response 403: RBAC authorization failure

#### Scenario: Delete endpoint (DELETE)
- **GIVEN** schema `meldingen`
- **THEN** the OpenAPI spec MUST document `DELETE /objects/zaken/meldingen/{id}` with:
  - Path parameter `id` (string, format: uuid)
  - Response 204: no content
  - Response 404: not found
  - Response 403: RBAC authorization failure

### Requirement: The spec MUST document authentication and RBAC authorization
The generated spec MUST describe all supported authentication methods and dynamically map Nextcloud group-based RBAC rules to OAuth2 scopes. Implementation: `OasService::extractSchemaGroups()`, `extractGroupFromRule()`, `applyRbacToOperation()`.

#### Scenario: Security schemes from BaseOas.json
- **THEN** the OpenAPI spec MUST include `components.securitySchemes` with:
  - `basicAuth`: `{type: "http", scheme: "basic"}` for Nextcloud username/password
  - `oauth2`: authorization code flow with `authorizationUrl: "/apps/oauth2/authorize"`, `tokenUrl: "/apps/oauth2/api/v1/token"`, and dynamically populated scopes

#### Scenario: RBAC groups mapped to OAuth2 scopes
- **GIVEN** schema `meldingen` with authorization rules: `{create: ["medewerkers"], read: ["public"], update: ["medewerkers"], delete: ["admin"]}`
- **WHEN** OAS is generated
- **THEN** `components.securitySchemes.oauth2.flows.authorizationCode.scopes` MUST include:
  - `admin`: `"Full administrative access"`
  - `medewerkers`: `"Access for medewerkers group"`
  - `public`: `"Public (unauthenticated) access"`

#### Scenario: RBAC info appended to operation descriptions
- **GIVEN** schema `meldingen` with `create` restricted to group `medewerkers`
- **WHEN** the POST operation is generated
- **THEN** the operation description MUST end with `**Required scopes:** \`admin\`, \`medewerkers\``
- **AND** a 403 response MUST be added with description `"Forbidden -- user does not have the required group membership for this action"`

#### Scenario: Property-level authorization groups are extracted
- **GIVEN** a schema property `bsn` with `authorization: {read: ["medewerkers"], update: ["admin"]}`
- **WHEN** OAS scopes are generated
- **THEN** the `medewerkers` and `admin` groups from property-level rules MUST be merged into the global scope list

### Requirement: The system MUST include example payloads in the generated spec
Each endpoint MUST include example request and response payloads to help developers understand the expected data structures. Examples SHOULD be generated from existing object data when available, falling back to synthetic examples derived from schema property definitions.

#### Scenario: Example for create endpoint
- **GIVEN** schema `meldingen` with properties: `title` (string, required), `description` (string), `status` (string, enum: ["open", "closed"])
- **WHEN** OAS is generated
- **THEN** the POST request body MUST include an `example` value like:
  ```json
  {"title": "Geluidsoverlast", "description": "Overlast na middernacht", "status": "open"}
  ```
- **AND** the 201 response MUST include an example with `_self` metadata (uuid, created, updated) populated

#### Scenario: Example from existing objects
- **GIVEN** schema `meldingen` has 5 existing objects in the register
- **WHEN** OAS is generated with example generation enabled
- **THEN** the system SHOULD use field values from the first existing object as examples
- **AND** sensitive fields (marked `writeOnly` or with restricted RBAC) MUST be masked or omitted from examples

#### Scenario: Array and nested object examples
- **GIVEN** a property `tags` with type `array` and items of type `string`
- **AND** a property `address` with type `object` and sub-properties `street`, `city`, `zipcode`
- **THEN** the example MUST include realistic nested values: `tags: ["urgent", "geluid"]`, `address: {street: "Keizersgracht 1", city: "Amsterdam", zipcode: "1015AA"}`

### Requirement: The system MUST serve a Swagger UI for interactive exploration
An interactive API explorer MUST be available for each register, allowing developers to browse endpoints, view schemas, and execute test requests directly from the browser.

#### Scenario: Access Swagger UI for a specific register
- **GIVEN** register `zaken` has a generated OpenAPI spec
- **WHEN** a user navigates to `/api/docs/zaken`
- **THEN** a Swagger UI MUST be displayed with:
  - All endpoints grouped by schema tag (Meldingen, Vergunningen)
  - Try-it-out functionality for authenticated users
  - Schema model browser showing all component definitions
  - The spec URL pre-configured to `/api/registers/{id}/oas`

#### Scenario: Access combined Swagger UI for all registers
- **WHEN** a user navigates to `/api/docs`
- **THEN** a Swagger UI MUST be displayed with all registers combined
- **AND** operations MUST be grouped by schema tags

#### Scenario: Swagger UI authentication pass-through
- **GIVEN** a user is logged into Nextcloud
- **WHEN** they use Swagger UI try-it-out on a protected endpoint
- **THEN** the Nextcloud session cookie MUST be forwarded
- **AND** basic auth credentials MUST be configurable in the Swagger UI authorize dialog

### Requirement: The OpenAPI spec MUST be downloadable in JSON and YAML formats
The generated specification MUST be available in both JSON and YAML formats to support different toolchains (Swagger Codegen, OpenAPI Generator, Postman, Insomnia).

#### Scenario: Download as JSON (default)
- **GIVEN** `GET /api/registers/{id}/oas` or `GET /api/registers/{id}/oas?format=json`
- **THEN** the response MUST have `Content-Type: application/json`
- **AND** the body MUST be valid JSON conforming to OpenAPI 3.1.0

#### Scenario: Download as YAML
- **GIVEN** `GET /api/registers/{id}/oas?format=yaml`
- **THEN** the response MUST have `Content-Type: application/x-yaml`
- **AND** the body MUST be valid YAML conforming to OpenAPI 3.1.0
- **AND** the YAML output MUST be semantically identical to the JSON output

#### Scenario: Content negotiation via Accept header
- **GIVEN** `GET /api/registers/{id}/oas` with header `Accept: application/x-yaml`
- **THEN** the response MUST be in YAML format
- **AND** if `Accept: application/json` or no Accept header, the response MUST be JSON

### Requirement: The OpenAPI spec MUST be versioned and track schema changes
Spec versions MUST track schema changes to enable API change detection, backwards-compatibility analysis, and changelog generation. The version MUST be derived from the register's version field and schema modification timestamps.

#### Scenario: Spec version reflects register version
- **GIVEN** register `zaken` has `version = "2.1.0"`
- **WHEN** OAS is generated
- **THEN** `info.version` MUST be `"2.1.0"`

#### Scenario: Schema change detection via hash
- **GIVEN** the OAS spec was generated with a content hash `abc123`
- **WHEN** a property is added to schema `meldingen`
- **THEN** the next OAS generation MUST produce a different content hash
- **AND** the response SHOULD include an `x-spec-hash` extension field for change detection

#### Scenario: ETag-based caching for spec consumers
- **GIVEN** a client requests `GET /api/registers/{id}/oas`
- **WHEN** the spec has not changed since the last request
- **THEN** the response SHOULD include an `ETag` header derived from the spec content hash
- **AND** subsequent requests with `If-None-Match` matching the ETag SHOULD return 304 Not Modified

### Requirement: The spec MUST regenerate in real-time when schemas change
The generated OpenAPI specification MUST always reflect the current state of register and schema definitions. There SHALL be no stale cache serving outdated specs after schema modifications.

#### Scenario: New property added to schema
- **GIVEN** schema `meldingen` has properties `title` and `status`
- **WHEN** an admin adds property `priority` (type: string, enum: ["low", "medium", "high"])
- **THEN** the next `GET /api/registers/{id}/oas` MUST include `priority` in the component schema AND as a query filter parameter on the collection endpoint

#### Scenario: Schema added to register
- **GIVEN** register `zaken` has schema `meldingen`
- **WHEN** schema `klachten` is added to the register
- **THEN** the next OAS generation MUST include full CRUD paths for `klachten` and a new component schema definition

#### Scenario: Schema removed from register
- **GIVEN** register `zaken` has schemas `meldingen` and `klachten`
- **WHEN** `klachten` is removed from the register
- **THEN** the next OAS generation MUST NOT include paths or component schemas for `klachten`

### Requirement: The server URL MUST be absolute and instance-specific
The `servers[0].url` field MUST be an absolute URL pointing to the actual Nextcloud instance, not a relative path. This is generated by `IURLGenerator::getAbsoluteURL()`.

#### Scenario: Server URL uses instance base URL
- **GIVEN** the Nextcloud instance is running at `https://gemeente.example.nl`
- **WHEN** OAS is generated
- **THEN** `servers[0].url` MUST be `https://gemeente.example.nl/apps/openregister/api`
- **AND** `servers[0].description` MUST be `"OpenRegister API Server"`

#### Scenario: Local development URL
- **GIVEN** the Nextcloud instance is running at `http://localhost:8080`
- **WHEN** OAS is generated
- **THEN** `servers[0].url` MUST be `http://localhost:8080/apps/openregister/api`

### Requirement: The spec MUST comply with NL API Design Rules markers
For Dutch government deployments, the generated OpenAPI spec MUST include extension fields that mark compliance with the NL API Design Rules (API Designrules, formerly known as the "NLGov API Design Rules" from Forum Standaardisatie).

#### Scenario: NLGov extension markers present
- **WHEN** OAS is generated for a register with NLGov compliance enabled
- **THEN** the spec MUST include `x-nl-api-design-rules` extension at the root level
- **AND** it MUST declare compliance with applicable rules:
  - `API-01`: Operations MUST use standard HTTP methods
  - `API-03`: Only standard HTTP status codes SHALL be used
  - `API-05`: Document API in OpenAPI 3.x specification
  - `API-16`: Use OAS 3.x for documentation
  - `API-20`: Include `Content-Type` in response headers
  - `API-48`: Leave MSB UUID ordering to client
  - `API-51`: Publish OAS at a standard location

#### Scenario: Pagination follows NL API Design Rules
- **GIVEN** a collection endpoint for schema `meldingen`
- **THEN** the paginated response MUST document `page`, `pages`, `total`, `limit`, `offset` fields conforming to the `API-42` pagination rule

#### Scenario: Error responses follow NL API problem details
- **GIVEN** an error response (400, 404, 403)
- **THEN** the error schema SHOULD include `type`, `title`, `status`, `detail`, `instance` per RFC 7807 / `API-46`

### Requirement: Multi-register specs MUST be organized with unique operation IDs and prefixed tags
When generating a combined spec for multiple registers, operations MUST be uniquely identifiable and grouped logically. Implemented via `$useRegisterPrefix` and `pascalCase()` prefixing in `OasService::createOas()`.

#### Scenario: Two registers with same-named schema
- **GIVEN** register `zaken` has schema `documenten` AND register `archief` has schema `documenten`
- **WHEN** combined OAS is generated via `GET /api/registers/oas`
- **THEN** operationIds MUST be unique: `ZakenGetAllDocumenten` vs `ArchiefGetAllDocumenten`
- **AND** paths MUST be unique: `/objects/zaken/documenten` vs `/objects/archief/documenten`

#### Scenario: Tags are defined for every schema
- **GIVEN** a register with schemas `Meldingen` and `Vergunningen`
- **WHEN** OAS is generated
- **THEN** the top-level `tags` array MUST contain entries with `name` matching each schema title
- **AND** each tag MUST have a `description` (from schema description or auto-generated)

#### Scenario: Shared schemas across registers are deduplicated in components
- **GIVEN** registers `zaken` and `burgerzaken` both reference schema ID 5
- **WHEN** combined OAS is generated
- **THEN** `components.schemas` MUST contain exactly one definition for schema 5 (not duplicated)

### Requirement: Extended endpoints MUST be controllable via whitelist
The system MUST support extended endpoints (audit-trails, files, lock/unlock) controlled by the `INCLUDED_EXTENDED_ENDPOINTS` constant in `OasService`. Only whitelisted endpoints SHALL appear in the generated spec.

#### Scenario: No extended endpoints by default
- **GIVEN** `INCLUDED_EXTENDED_ENDPOINTS` is an empty array (current default)
- **WHEN** OAS is generated
- **THEN** only standard CRUD paths (`GET`, `POST`, `PUT`, `DELETE`) SHALL appear
- **AND** audit-trail, file, lock, and unlock endpoints SHALL NOT be present

#### Scenario: Audit trail endpoint whitelisted
- **GIVEN** `INCLUDED_EXTENDED_ENDPOINTS` contains `"audit-trails"`
- **WHEN** OAS is generated
- **THEN** `GET /objects/{register}/{schema}/{id}/audit-trails` MUST appear with:
  - Response 200: array of `AuditTrail` references
  - Response 404: not found

#### Scenario: File endpoints whitelisted
- **GIVEN** `INCLUDED_EXTENDED_ENDPOINTS` contains `"files"`
- **WHEN** OAS is generated
- **THEN** `GET /objects/{register}/{schema}/{id}/files` and `POST /objects/{register}/{schema}/{id}/files` MUST appear
- **AND** the POST endpoint MUST document `multipart/form-data` request body with `file` field of format `binary`

### Requirement: Schema names MUST be sanitized for OpenAPI compliance
Schema component names MUST match the pattern `^[a-zA-Z0-9._-]+$`. The sanitization is performed by `OasService::sanitizeSchemaName()`.

#### Scenario: Schema with spaces in title
- **GIVEN** a schema with title `"Module Versie"`
- **WHEN** OAS is generated
- **THEN** the component name MUST be `"Module_Versie"` (spaces replaced with underscores)
- **AND** all `$ref` references MUST use `#/components/schemas/Module_Versie`

#### Scenario: Schema with special characters
- **GIVEN** a schema with title `"Zaak (type 2) #1"`
- **WHEN** OAS is generated
- **THEN** invalid characters MUST be replaced: `"Zaak_type_2_1"`

#### Scenario: Schema title starting with number
- **GIVEN** a schema with title `"123test"`
- **WHEN** OAS is generated
- **THEN** the component name MUST be prefixed: `"Schema_123test"`

#### Scenario: Bare $ref values are normalized
- **GIVEN** a property definition with `"$ref": "vestiging"` (bare name, not a full path)
- **WHEN** `sanitizePropertyDefinition()` processes it
- **THEN** the `$ref` MUST be normalized to `"#/components/schemas/vestiging"`

### Requirement: Composition keywords MUST be validated and cleaned
The system MUST ensure that composition keywords (`allOf`, `anyOf`, `oneOf`) are valid OpenAPI constructs. Empty arrays, invalid items, and empty `$ref` strings MUST be removed or corrected.

#### Scenario: Empty allOf array is removed
- **GIVEN** a property with `"allOf": []`
- **WHEN** OAS is generated
- **THEN** the `allOf` key MUST NOT appear in the output

#### Scenario: Invalid allOf items are filtered
- **GIVEN** a property with `"allOf": [{"$ref": ""}, {"type": "object", "properties": {"name": {"type": "string"}}}]`
- **WHEN** OAS is generated
- **THEN** the empty `$ref` item MUST be removed
- **AND** the valid `type: object` item MUST be preserved

#### Scenario: Boolean required field is stripped
- **GIVEN** a property with `"required": true` (boolean instead of array)
- **WHEN** OAS is generated
- **THEN** the `required` field MUST be removed (OpenAPI requires `required` to be an array of property names at the object level)

#### Scenario: Invalid type is corrected
- **GIVEN** a property with `"type": "datetime"` (not a valid OpenAPI type)
- **WHEN** OAS is generated
- **THEN** the type MUST be corrected to `"string"`

### Requirement: API descriptions MUST support i18n
The generated OpenAPI spec MUST support internationalized descriptions for endpoints, parameters, and schema properties to serve multilingual developer communities (minimum: Dutch and English).

#### Scenario: Default language is English
- **GIVEN** no language preference is specified
- **WHEN** OAS is generated
- **THEN** all summaries, descriptions, and parameter descriptions MUST be in English

#### Scenario: Dutch language requested
- **GIVEN** `GET /api/registers/{id}/oas?lang=nl` or `Accept-Language: nl`
- **WHEN** OAS is generated
- **THEN** all auto-generated descriptions MUST be in Dutch:
  - `"Haal alle {schema} objecten op"` instead of `"Get all {schema} objects"`
  - `"Maak een nieuw {schema} object aan"` instead of `"Create a new {schema} object"`

#### Scenario: Schema-defined descriptions preserved as-is
- **GIVEN** a schema with `description: "Register voor het opslaan van meldingen"`
- **WHEN** OAS is generated in any language
- **THEN** the schema's own description MUST be preserved verbatim (not translated)

## Current Implementation Status
- **Fully implemented -- OAS generation from schemas**: `OasService` (`lib/Service/OasService.php`) generates OpenAPI specs from register/schema definitions via `createOas()`. It maps schema properties to OpenAPI types, generates paths for CRUD operations, and handles multi-register generation with operationId prefixing.
- **Fully implemented -- controller and endpoints**: `OasController` (`lib/Controller/OasController.php`) exposes endpoints at `/api/registers/{id}/oas` (single register) and `/api/registers/oas` (all registers). Both are annotated `@PublicPage` and `@NoCSRFRequired` for unauthenticated access. `RegistersController` also provides OAS access and GitHub publishing of generated specs.
- **Fully implemented -- base template**: `BaseOas.json` (`lib/Service/Resources/BaseOas.json`) provides the foundation with `openapi: "3.1.0"`, `info`, `servers`, `securitySchemes` (Basic Auth and OAuth2), common schema components (Error, PaginatedResponse, _self).
- **Fully implemented -- authentication documentation**: The base template includes `securitySchemes` for Basic Auth and OAuth2. RBAC groups from schema authorization rules (both schema-level and property-level) are dynamically mapped to OAuth2 scopes via `extractSchemaGroups()` and `extractGroupFromRule()`. Operations include 403 responses with RBAC scope requirements in descriptions.
- **Fully implemented -- schema property sanitization**: `sanitizePropertyDefinition()` strips internal fields, validates types, cleans composition keywords (allOf/anyOf/oneOf), normalizes bare `$ref` values, enforces array items on array types, and falls back to `type: "string"` for unknown types.
- **Fully implemented -- schema name sanitization**: `sanitizeSchemaName()` replaces invalid characters, removes consecutive underscores, handles number-prefixed names, and falls back to `"UnknownSchema"`.
- **Fully implemented -- OAS integrity validation**: `validateOasIntegrity()` recursively validates `$ref` references and `allOf` constructs in both component schemas and path response schemas.
- **Fully implemented -- dynamic query parameters**: `createCommonQueryParameters()` generates `_extend`, `_filter`, `_unset`, `_search` (collection-only), plus dynamic filter parameters derived from each schema's property definitions.
- **Fully implemented -- extended endpoint whitelist**: `INCLUDED_EXTENDED_ENDPOINTS` constant controls which extended endpoints (audit-trails, files, lock, unlock) appear in the generated spec. Currently all are excluded by default.
- **Fully implemented -- server URL from Nextcloud**: `IURLGenerator::getAbsoluteURL()` generates the absolute server URL pointing to the actual Nextcloud instance.
- **Fully implemented -- GitHub publishing**: `RegistersController::publishToGitHub()` generates OAS via `OasService::createOas()` and publishes the JSON to a configurable GitHub repository, branch, and path.
- **Not implemented -- Swagger UI**: No interactive Swagger UI endpoint exists. The OAS is generated as JSON but not served with an interactive explorer.
- **Not implemented -- YAML format**: Only JSON output is supported; YAML export is not implemented.
- **Not implemented -- spec versioning/hashing**: No content hash, ETag, or version tracking tied to schema changes exists.
- **Not implemented -- example payloads**: The generated OAS does not include example request/response bodies for endpoints (though individual properties may carry `example` from schema definitions).
- **Not implemented -- NL API Design Rules markers**: No `x-nl-api-design-rules` extension or RFC 7807 problem details schema.
- **Not implemented -- i18n of API descriptions**: All descriptions are English-only; no language parameter or Accept-Language support.

## Standards & References
- OpenAPI Specification 3.1.0 (https://spec.openapis.org/oas/v3.1.0)
- JSON Schema Draft 2020-12 (referenced by OAS 3.1.0 for schema validation)
- Swagger UI (https://swagger.io/tools/swagger-ui/) for interactive API exploration
- OAuth 2.0 Authorization Code Flow (RFC 6749) for security scheme definitions
- NL API Design Rules (https://docs.geostandaarden.nl/api/API-Designrules/) for Dutch government API compliance
- RFC 7807 Problem Details for HTTP APIs (for standardized error responses)
- Redocly CLI (https://redocly.com/docs/cli/) for OAS validation (see `oas-validation` spec)

## Cross-References
- **oas-validation**: Validates that the generated OAS output passes `redocly lint` with zero errors. Covers `$ref` resolution, composition cleanup, server URL absoluteness, operationId uniqueness, and tag integrity. This spec focuses on generation features; `oas-validation` focuses on output correctness.
- **mcp-discovery**: The MCP discovery endpoint (`/api/mcp/v1/discover`) provides a complementary API discovery mechanism optimized for AI agents. The OpenAPI spec serves human developers and code generation tools; MCP discovery serves LLM-based integrations.
- **api-test-coverage**: (referenced in `unit-test-coverage` spec) Test coverage for the OAS generation endpoints should verify that generated specs are valid and complete.
- **auth-system**: The RBAC authorization model documented in the auth-system spec drives the OAuth2 scope generation in OAS output.

## Specificity Assessment
- **Highly specific and implementable**: The spec provides 14 requirements with 40+ scenarios covering all aspects of OAS generation: auto-generation, property mapping, CRUD documentation, authentication, examples, Swagger UI, YAML export, versioning, real-time regeneration, server URLs, NLGov compliance, multi-register organization, extended endpoints, schema name sanitization, composition validation, and i18n.
- **Grounded in implementation**: Requirements reference specific classes (`OasService`, `OasController`, `RegistersController`), methods (`createOas()`, `sanitizePropertyDefinition()`, `extractSchemaGroups()`), and files (`BaseOas.json`, `routes.php`).
- **Competitor-informed**: Strapi's dual-purpose Zod validation + spec generation pattern, Directus's auto-generated REST API per collection, and NocoDB's per-base Swagger endpoint informed the feature scope.
- **Clear separation from oas-validation**: This spec covers generation features; `oas-validation` covers output correctness. No overlap.

## Nextcloud Integration Analysis

**Status**: Partially implemented (core generation pipeline is production-ready; Swagger UI, YAML, versioning, examples, NLGov markers, and i18n are not yet implemented)

**Existing Implementation**: `OasService::createOas()` generates OpenAPI 3.1.0 specs from register/schema definitions using `RegisterMapper` and `SchemaMapper`. The service reads from `BaseOas.json`, populates paths via `addCrudPaths()` and `addExtendedPaths()`, maps properties via `sanitizePropertyDefinition()`, extracts RBAC groups to OAuth2 scopes, and validates integrity via `validateOasIntegrity()`. `OasController` serves the generated spec at two routes (`/api/registers/{id}/oas` for single register, `/api/registers/oas` for all registers), both as `@PublicPage` endpoints. `RegistersController::publishToGitHub()` enables publishing generated OAS to GitHub repositories.

**Nextcloud Core Integration**: The auto-generation pipeline is tightly integrated with Nextcloud's infrastructure. Register and schema metadata stored in Nextcloud's database (via `OCP\AppFramework\Db\Entity` mappers) drives the generation. Server URLs are derived from `IURLGenerator::getAbsoluteURL()`. The security schemes include Nextcloud-native Basic Auth and OAuth2 with scopes derived from Nextcloud group memberships. Routes are registered via `appinfo/routes.php` using Nextcloud's standard routing system. The generated spec is compatible with Nextcloud's own OpenAPI tooling initiative (attribute annotations on controllers).

**Recommendation**: The core generation pipeline is production-ready. Priority enhancements: (1) Swagger UI -- bundle a static HTML page using swagger-ui-dist that loads the generated JSON; serve at `/api/docs/{register}`. (2) YAML format -- use Symfony's YAML component (already a Nextcloud dependency) for JSON-to-YAML conversion. (3) Example payloads -- generate from schema defaults and existing object data via `ObjectMapper::findAll()`. (4) NLGov markers -- add `x-nl-api-design-rules` extension and RFC 7807 error schema. (5) i18n -- leverage Nextcloud's `IL10N` service for auto-generated descriptions. (6) Versioning -- compute SHA-256 hash of generated spec for ETag support.
