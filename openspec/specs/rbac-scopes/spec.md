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

## ZGW Autorisaties Mapping Guide

OpenRegister's existing group-based RBAC maps directly to ZGW autorisaties concepts. No additional code is required — this is a configuration and documentation concern.

### Consumer = Nextcloud User

A ZGW **Applicatie** (consumer application) maps to an OpenRegister **Consumer** entity. Each Consumer has a `userId` field that links it to a Nextcloud user. Authentication is handled via OpenRegister's multi-auth support (JWT, Basic Auth, OAuth2, API Key), and each authenticated request is resolved to a Nextcloud user identity.

| ZGW Concept | OpenRegister Equivalent |
|---|---|
| Applicatie | Consumer entity with `userId` field |
| Applicatie.clientIds | Consumer authentication credentials (JWT subject, API key, etc.) |
| Applicatie.label | Consumer name |

### Scope = Nextcloud Group

A ZGW **scope** (e.g., `zaken.lezen`, `zaken.aanmaken`) maps to a **Nextcloud group**. Schema-level and property-level authorization rules reference groups for CRUD access control.

| ZGW Scope | OpenRegister Configuration |
|---|---|
| `zaken.lezen` | Schema property `authorization.read: [{ "group": "zaken-lezen" }]` |
| `zaken.aanmaken` | Schema property `authorization.create: [{ "group": "zaken-aanmaken" }]` |
| `zaken.bijwerken` | Schema property `authorization.update: [{ "group": "zaken-bijwerken" }]` |
| `zaken.verwijderen` | Schema property `authorization.delete: [{ "group": "zaken-verwijderen" }]` |

To grant a consumer a scope, add the consumer's Nextcloud user to the corresponding Nextcloud group.

### heeftAlleAutorisaties = Admin Group

The ZGW `heeftAlleAutorisaties` flag (superuser access) maps to **admin group membership** in Nextcloud. Users in the admin group bypass all schema-level and property-level authorization checks.

### maxVertrouwelijkheidaanduiding = Property-Level Authorization

ZGW confidentiality levels (`maxVertrouwelijkheidaanduiding`) map to OpenRegister's **property-level authorization** with conditional matching. Properties can be restricted based on group membership with conditions like organisation context (`$organisation`), user identity (`$userId`), or custom conditions via `ConditionMatcher`.

Example: restricting a confidential property to specific groups:
```json
{
  "vertrouwelijkAanduiding": {
    "type": "string",
    "authorization": {
      "read": [{ "group": "vertrouwelijk-lezen", "condition": { "$organisation": "{{ object.bronorganisatie }}" } }],
      "update": [{ "group": "vertrouwelijk-schrijven" }]
    }
  }
}
```

### Query-Time Filtering

OpenRegister's `MagicRbacHandler` automatically filters query results at the database level based on the authenticated user's group memberships. This ensures that API list endpoints only return objects the consumer is authorized to see — equivalent to ZGW's filtered listing behavior based on autorisaties.

### Current Implementation Status
- **Fully implemented — OAS generation with RBAC scopes**: `OasService` (`lib/Service/OasService.php`) extracts RBAC groups from schema property authorization blocks (line ~210) and generates OAuth2 scopes in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`. The `extractGroupFromRule()` method (line ~373) handles rule parsing.
- **Fully implemented — per-operation security requirements**: `OasService::createOas()` applies `security` requirements at the operation level (GET uses read groups, POST/PUT/DELETE use update groups) based on schema authorization rules.
- **Fully implemented — base template**: `BaseOas.json` (`lib/Service/Resources/BaseOas.json`) provides the foundation with `basicAuth` and `oauth2` security schemes.
- **Fully implemented — RBAC infrastructure**: `PermissionHandler` (`lib/Service/Object/PermissionHandler.php`) handles schema-level authorization. `PropertyRbacHandler` (`lib/Service/PropertyRbacHandler.php`) handles property-level authorization. `MagicRbacHandler` (`lib/Db/MagicMapper/MagicRbacHandler.php`) filters query results at the database level.
- **Fully implemented — consumer entity**: `Consumer` (`lib/Db/Consumer.php`) maps API consumers to Nextcloud users with JWT, API key, and other authentication methods.
- **Fully implemented — authorization service**: `AuthorizationService` (`lib/Service/AuthorizationService.php`) orchestrates authentication and authorization.
- **Fully implemented — condition matching**: `ConditionMatcher` (`lib/Service/ConditionMatcher.php`) evaluates conditional authorization rules with organisation matching.

### Standards & References
- OAuth 2.0 Authorization Code Flow (RFC 6749) for scope-based access control
- OpenAPI Specification 3.1.0 for security scheme definitions
- ZGW Autorisaties API (VNG) for Dutch government authorization patterns
- ZGW Autorisaties Componentencatalogus for scope naming conventions
- Nextcloud Group-based access control for underlying authorization model

### Specificity Assessment
- **Highly specific and fully implemented**: The spec provides detailed scenarios for group extraction, OAuth2 scope mapping, per-operation security, fallback behavior, and ZGW mapping.
- **Well-documented ZGW mapping**: The spec includes a comprehensive mapping guide from ZGW autorisatie concepts to OpenRegister equivalents.
- **No ambiguity**: Requirements are testable with clear expected outputs for each scenario.
- **No open questions**: The implementation matches the specification closely.

### Requirement: GraphQL operations MUST enforce schema-level RBAC identically to REST
The PermissionHandler MUST be called for all GraphQL queries and mutations with the same action mapping as REST endpoints.

#### Scenario: GraphQL read maps to REST read authorization
- **WHEN** a GraphQL query requests data from schema `vertrouwelijk`
- **THEN** `PermissionHandler::checkPermission()` MUST be called with action `read`
- **AND** the same `authorization.read` groups MUST be evaluated as for `GET /api/objects/{register}/{schema}`

#### Scenario: GraphQL mutations map to corresponding CRUD actions
- **WHEN** a `createMelding` mutation is executed
- **THEN** `PermissionHandler::checkPermission()` MUST be called with action `create`
- **AND** `updateMelding` MUST check `update` and `deleteMelding` MUST check `delete`

#### Scenario: Conditional authorization with organisation matching in GraphQL
- **WHEN** schema `dossiers` has authorization `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user queries dossiers from a different organisation
- **THEN** those dossiers MUST be silently filtered out by `PermissionHandler::evaluateMatchConditions()`
- **AND** no GraphQL error MUST be raised (consistent with REST behavior)

#### Scenario: Admin bypass in GraphQL
- **WHEN** a user in the `admin` group queries any schema via GraphQL
- **THEN** all RBAC checks MUST be bypassed matching PermissionHandler's admin override

#### Scenario: Cross-schema authorization in nested GraphQL queries
- **WHEN** user can read `orders` but not `klanten`
- **AND** they query `order { title klant { naam } }`
- **THEN** `klant` MUST return `null` with a partial error at `["order", "klant"]` with `extensions.code: "FORBIDDEN"`
- **AND** the `title` field MUST still return data (partial success)
