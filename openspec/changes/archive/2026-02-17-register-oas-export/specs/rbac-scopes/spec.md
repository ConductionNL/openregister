# RBAC Scopes Specification

## Purpose
Map Nextcloud group-based RBAC configuration from schema properties to standard OAuth2 scopes in the OAS output, and apply per-operation security requirements so that API consumers can see which groups have access to which CRUD operations on each endpoint.

## ADDED Requirements

### Requirement: Extract Groups from Schema RBAC Configuration
The system MUST read all `authorization` blocks from schema property definitions and collect the unique group names referenced in `read` and `update` rules.

#### Scenario: Groups are extracted from property authorization rules
- GIVEN a schema with property "interneAantekening" that has authorization:
  ```json
  { "read": [{ "group": "redacteuren" }], "update": [{ "group": "redacteuren" }] }
  ```
- AND property "status" has authorization:
  ```json
  { "read": [{ "group": "public" }], "update": [{ "group": "admin" }] }
  ```
- WHEN OAS is generated for the register containing this schema
- THEN the extracted read groups MUST include "redacteuren" and "public"
- AND the extracted update groups MUST include "redacteuren" and "admin"

#### Scenario: Schemas with no RBAC rules produce no extra groups
- GIVEN a schema where no properties have `authorization` blocks
- WHEN OAS is generated
- THEN no additional scopes MUST be added beyond the base security definition

#### Scenario: Duplicate groups across properties are deduplicated
- GIVEN a schema with 3 properties all referencing group "redacteuren" in their read authorization
- WHEN groups are extracted
- THEN "redacteuren" MUST appear only once in the scopes list

### Requirement: Map Groups to OAuth2 Scopes
The system MUST generate OAuth2 scopes in `components.securitySchemes.oauth2.flows.authorizationCode.scopes` from the extracted group names.

#### Scenario: Groups become OAuth2 scopes
- GIVEN extracted groups: "admin", "redacteuren", "public"
- WHEN OAS is generated
- THEN `components.securitySchemes.oauth2.flows.authorizationCode.scopes` MUST contain:
  - `"admin": "Full administrative access"`
  - `"redacteuren": "Access for redacteuren group"`
  - `"public": "Public (unauthenticated) access"`

#### Scenario: Admin group always gets full access description
- GIVEN a register where "admin" group appears in RBAC rules
- WHEN scopes are generated
- THEN the "admin" scope description MUST be "Full administrative access"

#### Scenario: Public group gets public access description
- GIVEN a register where "public" group appears in RBAC rules
- WHEN scopes are generated
- THEN the "public" scope description MUST be "Public (unauthenticated) access"

#### Scenario: Regular groups get descriptive scope text
- GIVEN a register where "redacteuren" group appears in RBAC rules
- WHEN scopes are generated
- THEN the scope description MUST be "Access for redacteuren group"

### Requirement: Per-Operation Security Requirements
The system MUST apply `security` requirements at the operation level (GET, POST, PUT, DELETE) based on which groups have read or update access to the schema's properties.

#### Scenario: GET operations use read groups
- GIVEN a schema where read authorization references groups "public" and "redacteuren"
- WHEN OAS is generated for the GET collection endpoint
- THEN the operation MUST have a `security` array
- AND it MUST include `{ "oauth2": ["public", "redacteuren"] }`
- AND it MUST include `{ "basicAuth": [] }` as alternative

#### Scenario: POST operation uses update groups
- GIVEN a schema where update authorization references groups "redacteuren" and "admin"
- WHEN OAS is generated for the POST endpoint
- THEN the operation `security` MUST include `{ "oauth2": ["redacteuren", "admin"] }`

#### Scenario: PUT operation uses update groups
- GIVEN a schema where update authorization references groups "admin"
- WHEN OAS is generated for the PUT endpoint
- THEN the operation `security` MUST include `{ "oauth2": ["admin"] }`

#### Scenario: DELETE operation uses update groups
- GIVEN a schema where update authorization references groups "admin"
- WHEN OAS is generated for the DELETE endpoint
- THEN the operation `security` MUST include `{ "oauth2": ["admin"] }`

#### Scenario: Admin group is always included in write operations
- GIVEN a schema with RBAC rules that do NOT explicitly mention "admin"
- WHEN OAS is generated for POST/PUT/DELETE endpoints
- THEN "admin" MUST still be included in the operation's OAuth2 scopes
- AND the "admin" scope MUST exist in the security schemes

### Requirement: Fallback Security for Schemas Without RBAC
When a schema has no property-level authorization rules, the system MUST use the global-level security definition instead of per-operation overrides.

#### Scenario: Schema without RBAC uses global security
- GIVEN a schema where no properties define `authorization` blocks
- WHEN OAS is generated for that schema's endpoints
- THEN the operations MUST NOT have an operation-level `security` field
- AND the global `security` definition at the document root SHALL apply

#### Scenario: Mixed register with RBAC and non-RBAC schemas
- GIVEN a register with schema "Module" (has RBAC rules) and schema "Tag" (no RBAC rules)
- WHEN OAS is generated
- THEN Module operations MUST have per-operation `security` with group-based scopes
- AND Tag operations MUST NOT have per-operation `security` overrides
- AND the global-level security MUST still be present

### Requirement: Base Template Cleanup
The base OAS template (`BaseOas.json`) MUST NOT contain hardcoded `read`/`write` scopes. Scopes SHALL be dynamically generated from RBAC configuration.

#### Scenario: BaseOas.json has empty scopes placeholder
- GIVEN the base template file `BaseOas.json`
- WHEN it is loaded before RBAC processing
- THEN `components.securitySchemes.oauth2.flows.authorizationCode.scopes` MUST be an empty object `{}`
- AND the dynamic scope generation MUST populate it based on register RBAC

#### Scenario: Register with no RBAC still has valid security schemes
- GIVEN a register where no schemas have RBAC rules
- WHEN OAS is generated
- THEN `components.securitySchemes` MUST still contain `basicAuth` and `oauth2`
- AND the oauth2 scopes object MAY be empty or contain generic fallback scopes
