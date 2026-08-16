# OpenAPI Generation

## Problem
Auto-generate OpenAPI 3.1.0 specifications from register and schema definitions stored in OpenRegister, producing complete API documentation that covers every CRUD endpoint, query parameter, authentication scheme, and response model. The generated spec MUST be downloadable in JSON and YAML formats, serveable via an interactive Swagger UI, and MUST regenerate automatically when schemas change so that documentation never drifts from the live API surface. The generation pipeline MUST also support NL API Design Rules compliance markers for Dutch government API interoperability.
**Source**: Gap identified in cross-platform analysis; developer experience improvement. Competitors Strapi (`@strapi/openapi`) and Directus both auto-generate OpenAPI specs from their data models. NocoDB exposes a Swagger endpoint per base.

## Proposed Solution
Implement OpenAPI Generation following the detailed specification. Key requirements include:
- Requirement: The system MUST auto-generate OpenAPI 3.1.0 specs from register/schema definitions
- Requirement: Schema property definitions MUST map correctly to OpenAPI types
- Requirement: The OpenAPI spec MUST document all CRUD endpoints accurately
- Requirement: The spec MUST document authentication and RBAC authorization
- Requirement: The system MUST include example payloads in the generated spec

## Scope
This change covers all requirements defined in the openapi-generation specification.

## Success Criteria
- Generate OpenAPI spec for a single register
- Generate combined OpenAPI spec for all registers
- Register without schemas produces minimal valid spec
- Schema with empty title is excluded
- Basic property type mapping
