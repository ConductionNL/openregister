# OAS Validation Specification

## Purpose
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
