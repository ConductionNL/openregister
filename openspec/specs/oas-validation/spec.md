# OAS Validation Specification

## Purpose

@e2e exclude backend OAS validation — covered by PHPUnit
Ensure that `OasService::createOas()` produces valid OpenAPI 3.1.0 JSON that passes Redocly CLI lint without errors. The current output may contain invalid property structures, broken `$ref` references, or non-compliant schema compositions that cause tools like Redocly, Swagger UI, and Swagger Editor to fail.

## ADDED Requirements

### Requirement: Valid OpenAPI 3.1.0 Output
The system MUST produce output that conforms to the OpenAPI Specification 3.1.0 standard. The generated JSON MUST pass `redocly lint` with zero errors.

#### Scenario: Single register OAS passes Redocly lint
- GIVEN a register with one or more schemas
- WHEN `GET /api/registers/{id}/oas` is called
- THEN the response MUST be valid JSON
- AND the response MUST contain `"openapi": "3.1.0"`
- AND running `redocly lint` on the saved JSON file MUST produce zero errors

#### Scenario: All-registers OAS passes Redocly lint
- GIVEN multiple registers exist with various schemas
- WHEN `GET /api/registers/oas` is called
- THEN the response MUST pass `redocly lint` with zero errors

### Requirement: Valid Schema Component References
The system MUST ensure all `$ref` references in the generated OAS point to existing components. No dangling references SHALL exist.

#### Scenario: Schema references resolve correctly
- GIVEN a register with schemas "Module" and "Organisatie"
- WHEN OAS is generated for the register
- THEN every `$ref` in paths and response schemas MUST point to an entry in `components.schemas`
- AND `#/components/schemas/Module` and `#/components/schemas/Organisatie` MUST exist
- AND `#/components/schemas/PaginatedResponse`, `#/components/schemas/Error`, and `#/components/schemas/@self` MUST exist

#### Scenario: Schema names are OpenAPI-compliant
- GIVEN a schema with title "Module Versie" (contains spaces)
- WHEN OAS is generated
- THEN the schema component name MUST match the pattern `^[a-zA-Z0-9._-]+$`
- AND all `$ref` references to this schema MUST use the sanitized name

### Requirement: Valid Property Definitions
Each property in a schema component MUST have at minimum a `type` or `$ref` field. Composition keywords (`allOf`, `anyOf`, `oneOf`) MUST contain at least one item when present.

#### Scenario: Properties with missing type get a default
- GIVEN a schema property definition that has no `type` and no `$ref`
- WHEN OAS is generated
- THEN the property MUST be assigned `"type": "string"` as fallback

#### Scenario: Empty composition arrays are removed
- GIVEN a schema property with `"allOf": []` (empty array)
- WHEN OAS is generated
- THEN the `allOf` key MUST NOT appear in the output
- AND the property MUST still be valid OpenAPI

#### Scenario: Invalid allOf items are filtered
- GIVEN a schema property with `"allOf": [{"$ref": ""}, {"type": "object", "properties": {...}}]`
- WHEN OAS is generated
- THEN the empty `$ref` item MUST be removed
- AND the valid `type: object` item MUST be preserved

### Requirement: Valid Query Parameters
Collection endpoint parameters MUST conform to OpenAPI parameter schema rules. Array-type parameters MUST include an `items` definition.

#### Scenario: Array query parameter has items definition
- GIVEN a schema with a property of type "array"
- WHEN OAS is generated for the collection GET endpoint
- THEN the query parameter for that property MUST have `"schema": {"type": "array", "items": {"type": "string"}}`

### Requirement: Server URL is Absolute
The `servers[0].url` field MUST be an absolute URL pointing to the actual Nextcloud instance, not a relative path.

#### Scenario: Server URL uses instance base URL
- GIVEN the Nextcloud instance is running at `https://example.com`
- WHEN OAS is generated
- THEN `servers[0].url` MUST be `https://example.com/apps/openregister/api`
- AND `servers[0].description` MUST be present

### Requirement: OperationId Uniqueness
Every operation in the generated OAS MUST have a unique `operationId`. No two operations SHALL share the same `operationId`.

#### Scenario: Multi-schema register produces unique operationIds
- GIVEN a register with schemas "Module" and "Organisatie"
- WHEN OAS is generated
- THEN `operationId` values MUST be unique across all operations
- AND the operationId for GET collection of Module MUST differ from GET collection of Organisatie (e.g., `getAllModule` vs `getAllOrganisatie`)

### Requirement: Tags Reference Existing Definitions
Every tag referenced in path operations MUST be defined in the top-level `tags` array.

#### Scenario: Schema tags are defined
- GIVEN a register with schema "Module"
- WHEN OAS is generated
- THEN the top-level `tags` array MUST contain an entry with `"name": "Module"`
- AND all operations tagged "Module" MUST reference this existing tag

### Current Implementation Status
- **Fully implemented — OAS generation**: `OasService` (`lib/Service/OasService.php`) implements `createOas()` (line ~122) which generates OpenAPI specifications from register/schema definitions. The service reads from a `BaseOas.json` template (`lib/Service/Resources/BaseOas.json`).
- **Fully implemented — OAS controller**: `OasController` (`lib/Controller/OasController.php`) exposes endpoints for single-register and all-registers OAS generation. `RegistersController` (`lib/Controller/RegistersController.php`) also provides OAS access via `/api/registers/{id}/oas`.
- **Fully implemented — RBAC scope extraction**: `OasService::createOas()` (line ~210) extracts RBAC groups from all schemas and generates OAuth2 scopes. `extractGroupFromRule()` (line ~373) handles individual rule parsing.
- **Implemented but validation status unknown**: The spec requires output to pass `redocly lint` with zero errors. The OAS generation code exists, but whether the current output passes Redocly validation is an ongoing concern (the spec was created to address known validation issues).
- **Partially implemented — schema name sanitization**: Schema component names need to match `^[a-zA-Z0-9._-]+$` pattern; the implementation may not fully sanitize all names (e.g., titles with spaces).
- **Partially implemented — empty composition array cleanup**: The spec requires removing empty `allOf`/`anyOf`/`oneOf` arrays and filtering invalid items; this may not be fully implemented.
- **Base template exists**: `BaseOas.json` (`lib/Service/Resources/BaseOas.json`) provides the foundation OAS structure.

### Standards & References
- OpenAPI Specification 3.1.0 (https://spec.openapis.org/oas/v3.1.0)
- Redocly CLI for OAS validation (https://redocly.com/docs/cli/)
- JSON Schema Draft 2020-12 (referenced by OAS 3.1.0)
- OAuth 2.0 Authorization Code Flow (RFC 6749) for security scheme definitions

### Specificity Assessment
- **Highly specific and implementable as-is**: The spec provides clear, testable scenarios for every validation aspect: `$ref` resolution, property types, query parameters, server URLs, operation IDs, and tags.
- **Well-scoped**: Focuses exclusively on OAS output correctness, not on new features.
- **Testable**: Each scenario can be validated by running `redocly lint` on the generated output.
- **No ambiguity**: Requirements are precise with concrete examples of valid/invalid output.

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: OasService implements createOas() which generates OpenAPI specifications from register and schema definitions. OasController exposes endpoints for single-register (/api/registers/{id}/oas) and all-registers OAS generation. RegistersController also provides OAS access. The service reads from a BaseOas.json template and dynamically populates paths, schema components, and security definitions. RBAC groups are extracted from schema authorization blocks and mapped to OAuth2 scopes.

**Nextcloud Core Integration**: The OpenAPI 3.0 generation integrates with Nextcloud's own OpenAPI tooling direction. Nextcloud has been moving toward standardized OpenAPI documentation for its core and app APIs. The generated OAS is served at /api/oas endpoints using standard Nextcloud controller routing with @PublicPage annotation for unauthenticated access (useful for developer portals). Server URLs are derived from Nextcloud's IURLGenerator to produce absolute URLs pointing to the actual instance. The security schemes include Basic Auth (native Nextcloud authentication) and OAuth2 with dynamically generated scopes from the RBAC configuration.

**Recommendation**: The OAS generation is solid and well-integrated with Nextcloud's routing and authentication infrastructure. To enhance compliance with Nextcloud's OpenAPI standards, ensure the generated output follows Nextcloud's own OpenAPI conventions (attribute annotations on controllers, typed responses). The validation focus of this spec (passing redocly lint with zero errors) is the right approach for ensuring interoperability with API tooling. Consider registering the OAS endpoints in Nextcloud's capabilities API so that other apps can discover available OpenAPI specs programmatically.
## Requirements
### Requirement: Valid OpenAPI 3.1.0 Output
The system MUST produce output that conforms to the OpenAPI Specification 3.1.0 standard. The generated JSON MUST pass `redocly lint` with zero errors. The existing `validateOasIntegrity()` method in `OasService` provides internal validation; this requirement mandates external tool validation as the acceptance criterion.

#### Scenario: Single register OAS passes Redocly lint
- GIVEN a register with one or more schemas
- WHEN `GET /api/registers/{id}/oas` is called
- THEN the response MUST be valid JSON
- AND the response MUST contain `"openapi": "3.1.0"`
- AND running `redocly lint` on the saved JSON file MUST produce zero errors
- AND running `redocly lint` MUST produce zero warnings for structural rules (info-contact, no-empty-servers, operation-operationId-unique)

#### Scenario: All-registers OAS passes Redocly lint
- GIVEN multiple registers exist with various schemas
- WHEN `GET /api/registers/oas` is called
- THEN the response MUST pass `redocly lint` with zero errors
- AND operationId values generated with `$operationIdPrefix` (from `pascalCase()` of register title) MUST be globally unique

#### Scenario: Empty register produces valid minimal spec
- GIVEN a register with zero schemas assigned
- WHEN `GET /api/registers/{id}/oas` is called
- THEN the response MUST be a valid OpenAPI 3.1.0 document
- AND `paths` MUST be an empty object `{}`
- AND `components.schemas` MUST contain only the base schemas from `BaseOas.json`: `Error`, `PaginatedResponse`, and `_self`
- AND `redocly lint` MUST produce zero errors on this minimal document

#### Scenario: OAS with 50+ schemas passes validation
- GIVEN a register with 50 or more schemas (stress test)
- WHEN OAS is generated
- THEN the output MUST still pass `redocly lint` with zero errors
- AND no operationId collision MUST occur even with many schemas

### Requirement: Valid Schema Component References
The system MUST ensure all `$ref` references in the generated OAS point to existing components. No dangling references SHALL exist. The existing `validateSchemaReferences()` method performs recursive `$ref` checking; this requirement extends it to cover all reference contexts.

#### Scenario: Schema references resolve correctly
- GIVEN a register with schemas "Module" and "Organisatie"
- WHEN OAS is generated for the register
- THEN every `$ref` in paths and response schemas MUST point to an entry in `components.schemas`
- AND `#/components/schemas/Module` and `#/components/schemas/Organisatie` MUST exist
- AND `#/components/schemas/PaginatedResponse`, `#/components/schemas/Error`, and `#/components/schemas/_self` MUST exist

#### Scenario: Schema names are OpenAPI-compliant
- GIVEN a schema with title "Module Versie" (contains spaces)
- WHEN OAS is generated
- THEN `sanitizeSchemaName()` MUST produce a name matching the pattern `^[a-zA-Z0-9._-]+$`
- AND all `$ref` references to this schema MUST use the identical sanitized name (e.g., `#/components/schemas/Module_Versie`)
- AND no `$ref` in the document SHALL contain spaces or special characters outside `[a-zA-Z0-9._-/]`

#### Scenario: Bare $ref values are normalized to component paths
- GIVEN a property definition with `"$ref": "vestiging"` (bare name, not a full JSON Pointer)
- WHEN `sanitizePropertyDefinition()` processes it
- THEN the `$ref` MUST be normalized to `"#/components/schemas/vestiging"` (or its sanitized equivalent)
- AND if `vestiging` does not exist in `components.schemas`, the `$ref` MUST be removed or a warning logged

#### Scenario: Cross-register $ref deduplication
- GIVEN two registers both containing schema ID 5 with title "Adres"
- WHEN combined OAS is generated via `GET /api/registers/oas`
- THEN `components.schemas` MUST contain exactly one `Adres` definition (not duplicated)
- AND all paths from both registers MUST reference the same `#/components/schemas/Adres`

### Requirement: Valid Property Definitions
Each property in a schema component MUST have at minimum a `type` or `$ref` field. Composition keywords (`allOf`, `anyOf`, `oneOf`) MUST contain at least one item when present. The `sanitizePropertyDefinition()` method enforces this via an allowed-keywords whitelist and recursive cleanup.

#### Scenario: Properties with missing type get a default
- GIVEN a schema property definition that has no `type` and no `$ref`
- WHEN OAS is generated
- THEN the property MUST be assigned `"type": "string"` as fallback
- AND a `"description": "Property value"` MUST be added

#### Scenario: Empty composition arrays are removed
- GIVEN a schema property with `"allOf": []` (empty array)
- WHEN OAS is generated
- THEN the `allOf` key MUST NOT appear in the output
- AND the same rule applies to `"anyOf": []` and `"oneOf": []`

#### Scenario: Invalid allOf items are filtered
- GIVEN a schema property with `"allOf": [{"$ref": ""}, {"type": "object", "properties": {"name": {"type": "string"}}}]`
- WHEN OAS is generated
- THEN the empty `$ref` item MUST be removed
- AND the valid `type: object` item MUST be preserved
- AND if all items are invalid, the `allOf` key MUST be removed entirely

#### Scenario: Invalid type values are corrected
- GIVEN a property with `"type": "datetime"` (not a valid OpenAPI 3.1 type)
- WHEN `sanitizePropertyDefinition()` processes it
- THEN the type MUST be corrected to `"string"`
- AND the only valid types are: `object`, `array`, `string`, `number`, `integer`, `boolean`, `null`

#### Scenario: Boolean required field is stripped
- GIVEN a property with `"required": true` (boolean instead of array)
- WHEN OAS is generated
- THEN the `required` field MUST be removed (OpenAPI requires `required` to be an array of property names at the object level, not a boolean on individual properties)

#### Scenario: Internal fields are stripped from output
- GIVEN a property definition containing internal keys: `objectConfiguration`, `inversedBy`, `authorization`, `defaultBehavior`, `cascadeDelete`
- WHEN OAS is generated
- THEN only standard OpenAPI schema keywords from the `$allowedKeywords` whitelist MUST appear
- AND all internal/non-OAS keys MUST be removed

#### Scenario: Array type without items gets default items
- GIVEN a property with `"type": "array"` but no `items` definition
- WHEN OAS is generated
- THEN `items` MUST be set to `{"type": "string"}` as a safe default
- AND if `items` is a sequential array (list), the first element MUST be used; if empty, fallback to `{"type": "string"}`

### Requirement: Valid Query Parameters
Collection endpoint parameters MUST conform to OpenAPI parameter schema rules. Array-type parameters MUST include an `items` definition. Parameters generated by `createCommonQueryParameters()` and dynamic filter parameters from schema properties MUST all be valid.

#### Scenario: Array query parameter has items definition
- GIVEN a schema with a property of type "array"
- WHEN OAS is generated for the collection GET endpoint
- THEN the query parameter for that property MUST have `"schema": {"type": "array", "items": {"type": "string"}}`

#### Scenario: Common query parameters are valid
- GIVEN any schema with a collection endpoint
- WHEN OAS is generated
- THEN the `_extend`, `_filter`, `_unset`, and `_search` parameters MUST each have a valid `schema` with `type` defined
- AND `_search` MUST only appear on collection endpoints (GET list), not on single-resource endpoints

#### Scenario: Dynamic filter parameters match property types
- GIVEN a schema with property `status` of type `string` with enum `["open", "closed"]`
- WHEN OAS is generated
- THEN the collection endpoint MUST include a query parameter `status` with `schema: {type: "string", enum: ["open", "closed"]}`

### Requirement: Server URL is Absolute
The `servers[0].url` field MUST be an absolute URL pointing to the actual Nextcloud instance, not the relative path from `BaseOas.json`. The `IURLGenerator::getAbsoluteURL()` call in `createOas()` step 5 handles this transformation.

#### Scenario: Server URL uses instance base URL
- GIVEN the Nextcloud instance is running at `https://example.com`
- WHEN OAS is generated
- THEN `servers[0].url` MUST be `https://example.com/apps/openregister/api`
- AND `servers[0].description` MUST be `"OpenRegister API Server"`
- AND the URL MUST start with `http://` or `https://` (not a relative path like `/apps/...`)

#### Scenario: Server URL in local development
- GIVEN the Nextcloud instance is running at `http://localhost:8080`
- WHEN OAS is generated
- THEN `servers[0].url` MUST be `http://localhost:8080/apps/openregister/api`

### Requirement: OperationId Uniqueness
Every operation in the generated OAS MUST have a unique `operationId`. No two operations SHALL share the same `operationId`. For multi-register specs, `operationId` values are prefixed with `pascalCase()` of the register title.

#### Scenario: Multi-schema register produces unique operationIds
- GIVEN a register with schemas "Module" and "Organisatie"
- WHEN OAS is generated for that single register
- THEN `operationId` values MUST be unique across all operations
- AND the operationId for GET collection of Module MUST differ from GET collection of Organisatie (e.g., `GetAllModule` vs `GetAllOrganisatie`)

#### Scenario: Multi-register spec produces prefixed operationIds
- GIVEN registers "Zaken" and "Archief" both with schema "Documenten"
- WHEN combined OAS is generated via `GET /api/registers/oas`
- THEN operationIds MUST be prefixed: `ZakenGetAllDocumenten` vs `ArchiefGetAllDocumenten`
- AND all 5 CRUD operationIds per schema MUST be unique across the entire spec

#### Scenario: OperationId collision detection
- GIVEN a configuration that would produce duplicate operationIds (e.g., two schemas with identical slugs in the same register)
- WHEN OAS is generated
- THEN `validateOasIntegrity()` MUST detect the collision
- AND the system MUST append a numeric suffix to resolve collisions (e.g., `GetAllDocumenten`, `GetAllDocumenten_2`)

### Requirement: Tags Reference Existing Definitions
Every tag referenced in path operations MUST be defined in the top-level `tags` array. The tag name MUST match the schema title (original, not sanitized), and a description MUST be present.

#### Scenario: Schema tags are defined
- GIVEN a register with schema "Module"
- WHEN OAS is generated
- THEN the top-level `tags` array MUST contain an entry with `"name": "Module"`
- AND the tag MUST have a `"description"` field (from `Schema::getDescription()` or auto-generated as `"Operations for Module"`)
- AND all operations tagged "Module" in paths MUST reference this existing tag name

#### Scenario: No orphaned tags
- GIVEN OAS has been generated
- WHEN the document is validated
- THEN every tag name used in any operation's `tags` array MUST appear in the top-level `tags` array
- AND every tag in the top-level `tags` array MUST be referenced by at least one operation

### Requirement: Request Validation Against OAS Schema
API consumers SHOULD be able to use the generated OAS to validate their request payloads before sending them. The generated schema components MUST be complete enough for client-side validation libraries (e.g., ajv, opis/json-schema) to validate request bodies.

#### Scenario: POST request body validates against generated schema
- GIVEN the generated OAS defines schema `Meldingen` with required property `title` (type: string)
- WHEN a consumer submits `{"title": "Test"}` to `POST /objects/zaken/meldingen`
- THEN the request body MUST pass validation against `#/components/schemas/Meldingen`

#### Scenario: Invalid POST request body fails validation
- GIVEN the generated OAS defines schema `Meldingen` with required property `title` (type: string)
- WHEN a consumer submits `{"count": 42}` (missing required `title`) to `POST /objects/zaken/meldingen`
- THEN the request body MUST fail validation against the schema
- AND the generated OAS MUST include enough constraints (required array, type definitions) to detect this

#### Scenario: Response body conforms to documented schema
- GIVEN the generated OAS documents a 200 response for `GET /objects/zaken/meldingen/{id}`
- WHEN the actual API returns an object
- THEN the response MUST conform to the schema referenced in the OAS response definition
- AND the response MUST include `_self` metadata and `id` as documented in the component schema

### Requirement: NLGov API Design Rules Validation
The generated OAS MUST be verifiable against NL API Design Rules (Forum Standaardisatie). Validation checks MUST cover structural rules that can be verified from the OAS document alone, without executing API calls.

#### Scenario: Standard HTTP methods documented (API-01)
- GIVEN any schema's CRUD paths
- WHEN OAS is generated
- THEN only standard HTTP methods MUST be used: GET (list, read), POST (create), PUT (update), DELETE (delete)
- AND no custom HTTP methods or non-standard verbs SHALL appear

#### Scenario: Standard HTTP status codes used (API-03)
- GIVEN any operation in the generated OAS
- WHEN response codes are validated
- THEN only standard HTTP status codes SHALL be used: 200, 201, 204, 400, 403, 404, 500
- AND no non-standard or extension status codes SHALL appear

#### Scenario: Pagination structure follows API-42
- GIVEN a collection endpoint response schema
- WHEN OAS is generated
- THEN the `PaginatedResponse` component MUST include `page` (integer), `pages` (integer), `total` (integer), `limit` (integer), and `offset` (integer) fields
- AND these field names MUST match the NL API Design Rules pagination convention

#### Scenario: Error responses include problem details (API-46 / RFC 7807)
- GIVEN an error response (400, 403, 404)
- WHEN the Error schema in `BaseOas.json` is validated
- THEN the Error schema SHOULD include at minimum `error` (string) and `code` (integer)
- AND a future enhancement SHOULD add RFC 7807 fields: `type` (URI), `title` (string), `status` (integer), `detail` (string), `instance` (URI)

### Requirement: Validation Error Reporting
When `validateOasIntegrity()` detects issues in the generated OAS, errors MUST be reported in a structured format that identifies the exact location of the problem. Errors MUST NOT silently produce invalid output.

#### Scenario: Dangling $ref is reported with context
- GIVEN a schema property references `#/components/schemas/NonExistent` which does not exist
- WHEN `validateSchemaReferences()` processes this property
- THEN the error MUST include the JSON Pointer path to the invalid reference (e.g., `components.schemas.Meldingen.properties.related.$ref`)
- AND the error MUST identify the target that could not be resolved

#### Scenario: Invalid allOf in path response is reported
- GIVEN a path response schema contains `allOf: [{}]` (empty object item)
- WHEN `validateOasIntegrity()` processes path responses
- THEN the error MUST include the path context (e.g., `path:/objects/zaken/meldingen:get:response:200`)

#### Scenario: Validation errors are logged
- GIVEN `validateOasIntegrity()` finds one or more issues
- WHEN the issues are detected
- THEN each issue MUST be logged via `LoggerInterface::warning()` with the context path
- AND the generated OAS MUST still be returned (best-effort output) but with issues auto-corrected where possible (e.g., removing dangling `$ref`, stripping empty `allOf`)

#### Scenario: Validation summary is available
- GIVEN `GET /api/registers/{id}/oas?validate=true` is called
- WHEN OAS is generated and validated
- THEN the response SHOULD include an `x-validation-summary` extension field with:
  - `errors`: count of errors found and auto-corrected
  - `warnings`: count of non-critical issues
  - `passed`: boolean indicating whether the spec passed all checks

### Requirement: Validation Modes (Strict vs Lenient)
The OAS generation MUST support two validation modes: strict mode that rejects invalid schemas and lenient mode that auto-corrects issues. The default MUST be lenient mode to maintain backwards compatibility.

#### Scenario: Lenient mode auto-corrects issues (default)
- GIVEN a schema property has `"type": "datetime"` (invalid)
- WHEN OAS is generated in lenient mode (default)
- THEN the type MUST be silently corrected to `"string"`
- AND the generated OAS MUST be returned without errors
- AND a warning MUST be logged for the auto-correction

#### Scenario: Strict mode rejects invalid schemas
- GIVEN a schema property has `"type": "datetime"` (invalid)
- WHEN OAS is generated with `?strict=true` query parameter
- THEN the response MUST return HTTP 422 with a detailed error listing all validation failures
- AND the error response MUST include the property path and the specific violation

#### Scenario: Strict mode validates all $ref targets exist
- GIVEN a schema property references another schema that does not exist in the register
- WHEN OAS is generated in strict mode
- THEN the system MUST return an error identifying the unresolvable `$ref`
- AND the response MUST NOT contain the invalid reference

### Requirement: Performance Impact of Validation
OAS validation MUST NOT significantly degrade API response times. The `validateOasIntegrity()` method performs recursive traversal of all schemas and paths; this MUST remain performant even for large registers.

#### Scenario: OAS generation with validation completes within time budget
- GIVEN a register with 20 schemas, each having 15 properties
- WHEN `GET /api/registers/{id}/oas` is called
- THEN the total response time (generation + validation) MUST be under 2 seconds
- AND `validateOasIntegrity()` MUST account for less than 20% of the total generation time

#### Scenario: Validation does not cause memory exhaustion
- GIVEN a register with 100 schemas with deeply nested property structures (3+ levels of nesting)
- WHEN OAS is generated and validated
- THEN memory usage MUST NOT exceed 128MB above baseline
- AND recursive `validateSchemaReferences()` calls MUST not cause stack overflow

#### Scenario: Cached validation results for unchanged schemas
- GIVEN OAS was generated and validated for register ID 5 at timestamp T1
- AND no schemas in register 5 have been modified since T1
- WHEN `GET /api/registers/5/oas` is called again at T2
- THEN the system SHOULD return a cached result without re-running full validation
- AND the `ETag` header SHOULD be used for cache revalidation

### Requirement: CI Integration for OAS Validation
The OAS validation MUST be runnable as part of CI/CD pipelines to catch regressions in OAS output quality. A PHPUnit test suite MUST verify that generated OAS passes both internal validation and external Redocly lint.

#### Scenario: PHPUnit test validates OAS output structure
- GIVEN the test suite runs `OasService::createOas()` with a test register containing representative schemas
- WHEN the test executes
- THEN the test MUST assert `openapi` key equals `"3.1.0"`
- AND the test MUST assert `servers[0].url` starts with `http`
- AND the test MUST assert all `$ref` values resolve to existing `components.schemas` entries
- AND the test MUST assert all operationIds are unique
- AND the test MUST assert all tag names in operations exist in the top-level `tags` array

#### Scenario: CI runs Redocly lint on generated output
- GIVEN a CI pipeline step generates OAS output to a temporary JSON file
- WHEN `npx @redocly/cli lint --extends=recommended output.json` is executed
- THEN the command MUST exit with code 0 (no errors)
- AND the CI step MUST fail the build if any errors are detected

#### Scenario: Regression test for known OAS issues
- GIVEN the test suite includes test cases for previously fixed OAS bugs:
  - Empty `allOf` arrays
  - Boolean `required` fields
  - Bare `$ref` values without `#/components/schemas/` prefix
  - Properties with invalid types like `datetime`
  - Schema names with spaces or special characters
- WHEN the test suite runs
- THEN all regression test cases MUST pass, confirming that `sanitizePropertyDefinition()` and `sanitizeSchemaName()` continue to handle these edge cases

#### Scenario: Snapshot testing for OAS stability
- GIVEN a baseline OAS output snapshot exists for a known register configuration
- WHEN the test generates OAS for the same configuration
- THEN the structural keys (paths, components.schemas keys, operationIds, tags) MUST match the snapshot
- AND property type/format mappings MUST be identical
- AND differences MUST cause a test failure requiring explicit snapshot update

### Requirement: Schema Validation on Import
When schemas are imported from external sources (via `ImportHandler` or `ConfigurationService`), the schema definition MUST be validated for OAS compatibility before being stored. This prevents invalid schemas from producing broken OAS output downstream.

#### Scenario: Imported schema with valid properties passes
- GIVEN a schema JSON is imported with properties containing valid types, formats, and `$ref` references
- WHEN the import is processed
- THEN the schema MUST be stored without modification
- AND the schema MUST produce valid OAS output when `createOas()` is called

#### Scenario: Imported schema with invalid types gets warning
- GIVEN a schema JSON is imported with a property having `"type": "timestamp"` (not a valid JSON Schema / OAS type)
- WHEN the import is processed in lenient mode
- THEN the schema MUST be stored (for data preservation)
- AND a warning MUST be logged indicating the invalid type
- AND when OAS is generated, `sanitizePropertyDefinition()` MUST correct the type to `"string"`

#### Scenario: Imported schema with circular $ref is detected
- GIVEN a schema has property A referencing schema B, and schema B has a property referencing schema A
- WHEN OAS is generated
- THEN `validateSchemaReferences()` MUST detect the circular reference
- AND the system MUST NOT enter an infinite loop
- AND the circular `$ref` MUST be preserved in the output (circular references are valid in OpenAPI 3.1.0 which uses JSON Schema Draft 2020-12)

### Requirement: OAS Security Scheme Validation
The security schemes in the generated OAS MUST be structurally valid and consistent with the RBAC configuration. OAuth2 scopes generated by `extractSchemaGroups()` MUST be referenced correctly.

#### Scenario: OAuth2 scopes match RBAC groups
- GIVEN schemas with authorization rules referencing groups "medewerkers", "admin", and "public"
- WHEN OAS is generated
- THEN `components.securitySchemes.oauth2.flows.authorizationCode.scopes` MUST contain exactly these groups plus "admin" (always included)
- AND each scope MUST have a non-empty description from `getScopeDescription()`

#### Scenario: 403 responses reference valid scopes
- GIVEN a POST operation with RBAC restricting create to group "medewerkers"
- WHEN `applyRbacToOperation()` processes the operation
- THEN the operation description MUST end with `**Required scopes:** \`admin\`, \`medewerkers\``
- AND a 403 response MUST be added with description "Forbidden -- user does not have the required group membership for this action"
- AND the 403 response MUST reference the `Error` schema

#### Scenario: Security schemes from BaseOas.json are preserved
- GIVEN the `BaseOas.json` template defines `basicAuth` and `oauth2` security schemes
- WHEN OAS is generated
- THEN both security schemes MUST be present in the output
- AND `basicAuth` MUST have `type: "http"` and `scheme: "basic"`
- AND `oauth2` MUST have `type: "oauth2"` with `authorizationCode` flow

### Requirement: Error responses MUST follow RFC 7807 problem details (API-46)

OpenRegister error responses MUST be emitted as RFC 7807 problem documents.
`ProblemDetailsBuilder::build()` MUST produce a payload carrying `type`
(defaulting to `about:blank`), `title`, and `status`, with optional `detail`
and `instance`, plus free-form extensions (e.g. `errors`, legacy `code`). The
response MUST carry `Content-Type: application/problem+json`. The `Error`
schema in `BaseOas.json` MUST document these fields, retaining the legacy
`error` and `code` aliases for backward compatibility.

#### Scenario: Validation failure returns an RFC 7807 422 document
- **GIVEN** a request fails OAS schema validation
- **WHEN** `ProblemDetailsBuilder::validationFailed()` builds the response
- **THEN** the payload MUST include `type: "about:blank"`, `title: "Validation failed"`, and `status: 422`
- **AND** the per-field errors MUST be carried in the `errors` extension array
- **AND** the response MUST be sent with `Content-Type: application/problem+json`

#### Scenario: Not-found error carries problem-details shape
- **GIVEN** an object lookup misses
- **WHEN** `ProblemDetailsBuilder::notFound()` builds the response
- **THEN** the payload MUST include `title: "Not found"` and `status: 404`
- **AND** the `Error` schema in `BaseOas.json` MUST declare `type`, `title`, `status`, `detail`, and `instance` per RFC 7807

