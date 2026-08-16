## Context

OpenRegister exposes a REST API via Nextcloud controllers for CRUD operations on register objects. Nested data requires multiple requests or the `_extend` query parameter. The codebase already has mature subsystems for RBAC (PermissionHandler, PropertyRbacHandler), relation batching (RelationHandler with ultra-preload cache), audit trailing (AuditTrailMapper), rate limiting (SecurityService/APCu), pagination (offset-based via QueryHandler), faceted search (FacetHandler), and multi-tenancy (MultiTenancyTrait). The GraphQL layer must sit on top of these existing services, not beside them.

The target consumers are municipal developers integrating OpenRegister data into frontends and third-party systems who need flexible, efficient queries across registers.

## Goals / Non-Goals

**Goals:**
- Provide a fully auto-generated GraphQL API derived from register schemas
- Reuse all existing service-layer logic (RBAC, audit, relations, multi-tenancy, validation)
- Support nested cross-register queries with batched resolution
- Offer both offset and cursor-based pagination
- Include query complexity protection and configurable introspection
- Deliver a GraphiQL explorer for developer self-service

**Non-Goals:**
- Replacing the REST API — GraphQL is an alternative, not a replacement
- Custom GraphQL resolvers per schema — everything is auto-generated from schema definitions
- Federation with external GraphQL services (Apollo Federation, schema stitching with third parties)
- Real-time subscriptions in the initial release (can be phased in; requires WebSocket infrastructure)
- Modifying the MagicMapper table structure — GraphQL reads from existing tables

## Decisions

### 1. Library: `webonyx/graphql-php`

**Choice**: Use `webonyx/graphql-php` as the GraphQL execution engine.

**Alternatives considered**:
- `lighthouse-php` (Laravel-only, not compatible with Nextcloud's Symfony-based routing)
- `graphql-relay-php` (too narrow, only covers Relay spec)
- Custom implementation (high effort, low value)

**Rationale**: `webonyx/graphql-php` is the de facto PHP GraphQL library, framework-agnostic, supports all GraphQL features (queries, mutations, subscriptions, custom scalars, directives), and has no framework coupling. It integrates cleanly with Nextcloud's controller-based routing.

### 2. Architecture: Schema-on-read generation with caching

**Choice**: Generate the GraphQL schema dynamically from register schemas at request time, cached in APCu with invalidation on schema changes.

**Alternatives considered**:
- Static schema generation (written to file on schema save) — rejected because it introduces stale schema risk and deployment complexity
- Database-backed schema cache — rejected because APCu is already the caching layer and schema generation is fast enough (~50ms for typical installs)

**Rationale**: Register schemas can change at runtime. Dynamic generation ensures the GraphQL schema is always consistent. APCu cache (keyed by schema hash) avoids regeneration on every request. Cache is invalidated when any Schema entity is saved via a Nextcloud event listener.

### 3. Resolver pattern: Generic resolver factory with service delegation

**Choice**: A single `ObjectResolver` class that handles all schema types, delegating to existing services.

```
GraphQL Request
  → GraphQLController::execute()
    → SchemaGenerator::getSchema() [cached]
    → GraphQL::executeQuery()
      → ObjectResolver::resolve()
        → QueryHandler::searchObjectsPaginatedDatabase() [for lists]
        → QueryHandler::find() [for single objects]
        → RelationHandler::bulkLoadRelationshipsBatched() [for nested fields]
        → PermissionHandler::checkPermission() [RBAC]
        → PropertyRbacHandler::filterReadableProperties() [field RBAC]
        → AuditTrailMapper::createAuditTrail() [logging]
```

**Alternatives considered**:
- Per-schema resolver classes (code generation) — rejected because it creates file sprawl and maintenance burden with no benefit over a generic resolver
- Direct MagicMapper queries in resolvers — rejected because it bypasses RBAC, audit, and multi-tenancy enforced in service layer

**Rationale**: The existing service layer already handles all cross-cutting concerns. The resolver is a thin adapter between GraphQL field resolution and OpenRegister services.

### 4. DataLoader: PHP-native deferred resolution

**Choice**: Use `webonyx/graphql-php`'s built-in deferred field resolution (`Deferred` class) to implement DataLoader-style batching.

**How it works**:
1. When a relation field is resolved, the UUID is collected into a batch buffer
2. After all fields at a depth level are visited, the buffer is flushed
3. `RelationHandler::bulkLoadRelationshipsBatched()` loads all UUIDs in one query (batch size 50)
4. Results are placed in the ultra-preload cache
5. Individual resolvers read from the cache

**Rationale**: This matches exactly how RelationHandler already works for REST `_extend`. No new batching infrastructure needed.

### 5. Pagination: Dual mode via connection types

**Choice**: All list queries return Relay-compatible connection types with both offset and cursor support.

**Offset mode**: `meldingen(first: 10, offset: 20)` → delegates to `QueryHandler` with `_limit`/`_offset`
**Cursor mode**: `meldingen(first: 10, after: "base64-cursor")` → cursor encodes `{uuid, sortValue}`, translated to a WHERE clause

**Rationale**: Offset pagination matches existing REST behavior. Cursor pagination enables stable infinite scrolling. Both modes share the same connection response type.

### 6. Custom scalars: Mapped from JSON Schema format

**Choice**: Register custom scalar types that mirror MagicMapper's type mapping.

| JSON Schema | GraphQL Scalar | Validation |
|---|---|---|
| `string + date-time` | `DateTime` | ISO 8601 |
| `string + uuid` | `UUID` | UUID v4 format |
| `string + email` | `Email` | RFC 5321 |
| `string + uri` | `URI` | Valid URI |
| `object` (no $ref) | `JSON` | Valid JSON |
| file property | `Upload` | Multipart spec |

**Rationale**: Clients get type safety matching the underlying data model. Scalars handle serialization/validation, preventing invalid data at the GraphQL layer before it reaches SaveObject.

### 7. Query complexity: Static analysis before execution

**Choice**: Implement a complexity visitor that walks the AST before execution.

- **Depth limit**: Configurable, default 10 (matches typical schema maxDepth)
- **Cost budget**: Default 10000 points. Fields cost 1, object resolvers cost 10, list resolvers multiply by `first` argument
- **Per-schema cost override**: Schema entity gets a `graphqlCost` property for expensive schemas
- **Response**: Estimated cost returned in `extensions.complexity`

**Rationale**: Static analysis catches expensive queries before any database work. The cost model mirrors the actual performance characteristics (list × nested is the expensive operation).

### 8. Introspection control: App config setting

**Choice**: A single `graphql_introspection` IAppConfig setting with values `enabled` (default for dev), `authenticated`, or `disabled`.

**Rationale**: Mirrors the MCP discovery tiering (tier 1 public, tier 2 authenticated). Simple to configure, no code changes needed per environment.

### 9. Entry point: Single Nextcloud controller

**Choice**: A `GraphQLController` registered in `routes.php` handling:
- `POST /api/graphql` — query execution
- `GET /api/graphql/explorer` — GraphiQL HTML page (static asset)

**Rationale**: Follows Nextcloud's routing conventions. POST for queries (standard GraphQL transport). GET for explorer (serves static HTML/JS). No custom server process needed.

### 10. Subscriptions: Deferred to phase 2

**Choice**: Subscriptions are specced but not implemented in the initial release.

**Rationale**: Subscriptions require WebSocket infrastructure (separate process or Nextcloud ExApp). The core value proposition (efficient querying) is delivered without subscriptions. The spec ensures the design accommodates them when ready.

## Risks / Trade-offs

**[Performance] Large schema installs generate complex GraphQL schemas** → Mitigation: APCu caching with hash-based invalidation. Schema generation benchmarked and monitored via existing performance metrics in response `@self.metrics`.

**[Complexity budget gaming] Clients can craft queries just under the limit** → Mitigation: Cost model is conservative (multiplies list sizes). Admin can lower budget per-install. Rate limiting via SecurityService provides a second defense layer.

**[Dependency] `webonyx/graphql-php` adds a Composer dependency** → Mitigation: Library is stable (v15+), widely used, MIT licensed, no transitive dependencies beyond PHP stdlib.

**[Cache coherence] Schema changes must invalidate GraphQL type cache** → Mitigation: Listen to Schema entity save events, clear APCu key. Worst case on race condition: one request uses stale schema, next request regenerates.

**[Partial errors complexity] Property-level RBAC returns null + errors** → Mitigation: This is standard GraphQL partial error behavior. Clients expect it. Document clearly in explorer.

**[WebSocket for subscriptions] Not available in standard Nextcloud hosting** → Mitigation: Subscriptions deferred to phase 2. Can use ExApp sidecar for WebSocket server when needed.

## Migration Plan

1. **Add dependency**: `composer require webonyx/graphql-php`
2. **Deploy code**: New controller, schema generator, resolver — no database migrations needed
3. **Enable**: Endpoint available immediately; introspection defaults to `enabled`
4. **Production hardening**: Set `graphql_introspection` to `authenticated` or `disabled`, tune complexity budget
5. **Rollback**: Remove routes from `routes.php` — no data changes, no schema changes, fully reversible

## Open Questions

- Should the GraphQL endpoint support persisted queries (client sends a query hash instead of full query text) for production performance?
- Should we expose register/schema metadata as GraphQL types (e.g., `_registers`, `_schemas` queries) or keep those REST-only?
- What is the right default complexity budget? 10000 is a starting estimate — needs benchmarking with real municipal datasets.
