# GraphQL API

## Problem
Provide an auto-generated GraphQL API alongside the existing REST API for register data, enabling clients to request exactly the fields they need in a single round-trip and resolve nested relationships without over-fetching. The GraphQL schema MUST be derived dynamically from register schema definitions at runtime, supporting queries with nested object resolution, mutations for CRUD operations, and subscriptions for real-time updates via Server-Sent Events (SSE).
The GraphQL layer MUST reuse existing OpenRegister services -- `PermissionHandler` for schema-level RBAC, `PropertyRbacHandler` for field-level security, `RelationHandler` for nested resolution and DataLoader batching, `AuditTrailMapper` for change logging, `SecurityService` for rate limiting, `MagicMapper` for cross-register queries, and `MultiTenancyTrait` for organisation scoping -- rather than reimplementing any of these concerns. The implementation is built on the `webonyx/graphql-php` library, with the full service stack comprising `GraphQLService` (orchestrator), `SchemaGenerator` (type generation), `GraphQLResolver` (query/mutation resolution), `QueryComplexityAnalyzer` (abuse prevention), `GraphQLErrorFormatter` (structured errors), `SubscriptionService` (SSE event buffer), and `GraphQLSubscriptionListener` (event bridge).
**Source**: Gap identified in cross-platform analysis; Directus, Strapi, and Twenty CRM all provide auto-generated GraphQL APIs. See cross-references: `zoeken-filteren`, `realtime-updates`, `rbac-scopes`.

## Proposed Solution
Implement GraphQL API following the detailed specification. Key requirements include:
- Requirement: The GraphQL schema MUST be auto-generated from register schemas
- Requirement: Custom scalar types MUST map to OpenRegister property formats
- Requirement: GraphQL MUST support nested object resolution via DataLoader batching
- Requirement: GraphQL MUST support filtering and sorting matching the REST API
- Requirement: GraphQL MUST support faceted search through connections

## Scope
This change covers all requirements defined in the graphql-api specification.

## Success Criteria
- Generate GraphQL type from schema
- Generate queries for a schema
- Generate mutations for a schema
- Schema changes regenerate GraphQL types
- Type name collision resolution
