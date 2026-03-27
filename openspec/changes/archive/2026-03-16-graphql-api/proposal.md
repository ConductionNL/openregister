## Why

The REST API requires multiple round-trips to resolve nested data and forces clients to over-fetch fields they don't need. Three competing platforms (Directus, Strapi, NocoDB) already offer GraphQL APIs, making this a competitive gap. A GraphQL layer lets developers fetch exactly the data they need — including deeply nested cross-register references — in a single request, drastically improving integration DX for municipal and government consumers.

## What Changes

- New `/api/graphql` endpoint accepting GraphQL queries, mutations, and subscriptions
- Auto-generated GraphQL schema derived from register schema definitions (types, queries, mutations per schema)
- Custom scalar types (DateTime, UUID, Email, URI, JSON, Upload) mapping to existing JSON Schema format annotations
- DataLoader-based batched resolution for nested object references, reusing RelationHandler
- Relay-style cursor pagination alongside existing offset pagination
- Cross-register schema stitching: unified graph across all registers with transparent reference resolution
- Query complexity analysis (depth limiting + cost budgeting) to prevent resource abuse
- Configurable introspection (enabled/authenticated/disabled) per environment
- Full RBAC enforcement at schema-level (PermissionHandler) and property-level (PropertyRbacHandler)
- Multi-tenancy enforcement on all GraphQL operations via MultiTenancyTrait
- Audit trail logging for all mutations and optionally reads, via AuditTrailMapper
- Real-time subscriptions via WebSocket with RBAC-gated event delivery
- Interactive GraphiQL explorer at `/api/graphql/explorer`

## Capabilities

### New Capabilities
- `graphql-api`: Core GraphQL endpoint — schema generation, type mapping, queries, mutations, subscriptions, explorer, error handling

### Modified Capabilities
- `row-field-level-security`: GraphQL resolvers MUST enforce property-level RBAC via PropertyRbacHandler, returning null + partial errors for unauthorized fields
- `rbac-scopes`: Schema-level RBAC authorization rules MUST apply identically to GraphQL operations as to REST
- `faceting-configuration`: Faceted search MUST be exposable through GraphQL connection types with `facets` and `facetable` fields

## Impact

- **New code**: GraphQL controller, schema generator, resolver factory, DataLoader implementation, complexity analyzer, subscription handler
- **Modified code**: AuditTrailMapper (GraphQL operation name in metadata), SecurityService (GraphQL-specific rate limit tracking)
- **New dependencies**: PHP GraphQL library (e.g., `webonyx/graphql-php`), WebSocket server for subscriptions
- **APIs**: New `/api/graphql` and `/api/graphql/explorer` endpoints; existing REST API unchanged
- **Dependent apps**: opencatalogi, softwarecatalog, and other consumers can optionally use GraphQL for more efficient data fetching — no breaking changes to existing REST integrations
- **Infrastructure**: WebSocket support required for subscriptions (optional feature, can be deployed without)
