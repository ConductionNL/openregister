## 1. Dependencies & Project Setup

- [x] 1.1 Add `webonyx/graphql-php` to composer.json and run composer install
- [x] 1.2 Register `/api/graphql` POST route and `/api/graphql/explorer` GET route in `appinfo/routes.php`
- [x] 1.3 Create `GraphQLController` extending Nextcloud's `OCSController` with `execute()` and `explorer()` methods

## 2. Custom Scalar Types

- [x] 2.1 Implement `DateTime` scalar (ISO 8601 serialization/parsing)
- [x] 2.2 Implement `UUID` scalar (UUID v4 validation)
- [x] 2.3 Implement `Email` scalar (RFC 5321 validation)
- [x] 2.4 Implement `URI` scalar
- [x] 2.5 Implement `JSON` scalar (arbitrary JSON pass-through)
- [x] 2.6 Implement `Upload` scalar following GraphQL multipart request spec, delegating to `FilePropertyHandler`
- [x] 2.7 Implement `File` output type with fields: `filename`, `mimeType`, `size`, `url`

## 3. Schema Generator

- [x] 3.1 Create `SchemaGenerator` service that reads all register schemas and produces a `webonyx/graphql-php` Schema object
- [x] 3.2 Implement JSON Schema property → GraphQL field type mapping (string→String, integer→Int, number→Float, boolean→Boolean, format-based→custom scalars)
- [x] 3.3 Implement `$ref` property → GraphQL object type mapping for relation fields
- [x] 3.4 Implement allOf → merged type, oneOf → union type, anyOf → interface type generation
- [x] 3.5 Generate per-schema query fields: `{name}(id: ID!): {Type}` and `{names}(filter, sort, search, fuzzy, selfFilter, facets, first, offset, after): {Type}Connection`
- [x] 3.6 Generate per-schema mutation fields: `create{Name}`, `update{Name}`, `delete{Name}` with input types
- [x] 3.7 Generate `register(id: ID!)` root query for register-scoped queries
- [x] 3.8 Add `_register`, `_schema`, `_uuid`, `_created`, `_updated`, `_owner` metadata fields to all object types
- [x] 3.9 Add `_auditTrail(last: Int)` field to all object types
- [x] 3.10 Add `_usedBy` field as union type to all object types for reverse relationship traversal
- [x] 3.11 Implement APCu caching of generated schema, keyed by hash of all schema definitions
- [x] 3.12 Add schema cache invalidation listener on Schema entity save events
- [x] 3.13 Copy JSON Schema `description` fields into GraphQL field descriptions, annotating property-level auth requirements

## 4. Connection Types & Pagination

- [x] 4.1 Create generic `Connection` type factory producing `{Name}Connection` with `edges`, `pageInfo`, `totalCount`, `facets`, `facetable`
- [x] 4.2 Create `Edge` type factory producing `{Name}Edge` with `cursor` and `node`
- [x] 4.3 Create shared `PageInfo` type with `hasNextPage`, `hasPreviousPage`, `startCursor`, `endCursor`
- [x] 4.4 Implement offset-based pagination: translate `first`/`offset` to QueryHandler `_limit`/`_offset`
- [x] 4.5 Implement cursor-based pagination: encode/decode opaque cursors with `{uuid, sortValue}`, translate to WHERE clause

## 5. Resolvers

- [x] 5.1 Create `ObjectResolver` — generic resolver for single object queries delegating to `QueryHandler::find()`
- [x] 5.2 Create `ListResolver` — generic resolver for list queries delegating to `QueryHandler::searchObjectsPaginatedDatabase()`
- [x] 5.3 Create `MutationResolver` — generic resolver for create/update/delete delegating to `ObjectService::saveObject()` and `ObjectService::deleteObject()`
- [x] 5.4 Create `RelationFieldResolver` — deferred resolver using `webonyx/graphql-php` `Deferred` class to batch relation UUIDs
- [x] 5.5 Implement DataLoader buffer: collect UUIDs per depth level, flush via `RelationHandler::bulkLoadRelationshipsBatched()`, populate ultra-preload cache
- [x] 5.6 Implement `inversedBy` resolution using `RelationHandler::applyInversedByFilter()`
- [x] 5.7 Implement `_usedBy` resolver using `RelationHandler::getUsedBy()`
- [x] 5.8 Implement `_auditTrail` resolver delegating to `AuditTrailMapper::findAll()` with object UUID filter

## 6. Filtering, Sorting & Facets

- [x] 6.1 Implement `filter` input type generation with operators: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `notIn`, `isNull`, `isNotNull`
- [x] 6.2 Implement `sort` input type with `field` and `order` (ASC/DESC)
- [x] 6.3 Implement `search` and `fuzzy` arguments, delegating to MagicSearchHandler full-text search
- [x] 6.4 Implement `selfFilter` input type for metadata column filtering (`_owner`, `_organisation`, etc.)
- [x] 6.5 Implement `facets` argument, delegating to FacetHandler with pagination-independent calculation
- [x] 6.6 Implement `facetable` field on connections, listing fields with facet configuration
- [x] 6.7 Include `_relevance` field on edges when `fuzzy: true`

## 7. RBAC & Security

- [x] 7.1 Add schema-level RBAC check in resolvers: call `PermissionHandler::checkPermission()` before query/mutation execution
- [x] 7.2 Add property-level RBAC in field resolution: call `PropertyRbacHandler::filterReadableProperties()` for query results
- [x] 7.3 Add property-level write RBAC in mutations: call `PropertyRbacHandler::getUnauthorizedProperties()` before save
- [x] 7.4 Implement partial error responses: return `null` + error entry with `FIELD_FORBIDDEN` code for unauthorized properties
- [x] 7.5 Implement conditional authorization evaluation (`evaluateMatchConditions`) for org-scoped RBAC in GraphQL context
- [x] 7.6 Implement admin group bypass in GraphQL resolvers
- [x] 7.7 Add multi-tenancy enforcement: apply `MultiTenancyTrait` organisation filtering on all GraphQL queries
- [x] 7.8 Implement parent-organisation visibility for child org data in GraphQL queries

## 8. Query Complexity & Rate Limiting

- [x] 8.1 Implement depth-limiting AST visitor (default max: 10, configurable via `graphql_max_depth` app setting)
- [x] 8.2 Implement cost-based complexity AST visitor (fields=1, resolvers=10, lists multiply by `first` arg, default budget 10000)
- [x] 8.3 Add `graphqlCost` property to Schema entity for per-schema cost overrides
- [x] 8.4 Include `extensions.complexity` in all successful responses with `estimated`, `max`, `depth`, `maxDepth`
- [x] 8.5 Integrate with SecurityService for per-user/per-IP rate limiting with progressive delays
- [x] 8.6 Return `RATE_LIMITED` error code and `Retry-After` header on rate limit exceeded

## 9. Introspection Control

- [x] 9.1 Add `graphql_introspection` app config setting (values: `enabled`, `authenticated`, `disabled`)
- [x] 9.2 Implement introspection middleware: block `__schema` and `__type` queries based on config and auth state
- [x] 9.3 Return `INTROSPECTION_DISABLED` error code when introspection is blocked

## 10. Audit Trail Integration

- [x] 10.1 Call `AuditTrailMapper::createAuditTrail()` in mutation resolver for create/update/delete with field-level diffs
- [x] 10.2 Include GraphQL operation name in audit trail metadata field
- [x] 10.3 Support optional read audit for schemas with `auditReads: true` configuration
- [x] 10.4 Ensure referential integrity cascade deletions produce audit entries (existing behavior via ReferentialIntegrityService)

## 11. Error Handling

- [x] 11.1 Create `GraphQLErrorFormatter` mapping OpenRegister exceptions to structured error format with `extensions.code`
- [x] 11.2 Map `NotAuthorizedException` → `FORBIDDEN`, property RBAC → `FIELD_FORBIDDEN`, not found → `NOT_FOUND`
- [x] 11.3 Map validation errors from SaveObject to `VALIDATION_ERROR` with `extensions.details.field` and `extensions.details.constraint`
- [x] 11.4 Implement partial success: return data for authorized fields alongside errors for unauthorized fields

## 12. GraphiQL Explorer

- [x] 12.1 Bundle GraphiQL static assets (HTML/JS/CSS) in the app
- [x] 12.2 Serve GraphiQL at `GET /api/graphql/explorer` with the GraphQL endpoint pre-configured
- [x] 12.3 Pass Nextcloud auth context to GraphiQL so it works with the authenticated user's permissions

## 13. Testing

- [x] 13.1 Unit tests for SchemaGenerator: verify type mapping for all JSON Schema types and formats
- [x] 13.2 Unit tests for custom scalars: serialization, parsing, validation errors
- [x] 13.3 Unit tests for query complexity visitor: depth limiting, cost budgeting, per-schema overrides
- [x] 13.4 Unit tests for cursor encoding/decoding and pagination translation
- [x] 13.5 Integration tests for RBAC: schema-level access, property-level filtering, conditional org matching, admin bypass
- [x] 13.6 Integration tests for DataLoader batching: verify single query for N relations, circuit breaker limits
- [x] 13.7 Integration tests for mutations: create/update/delete with audit trail verification
- [x] 13.8 Integration tests for filtering: all operators, full-text search, fuzzy search, metadata filters
- [x] 13.9 Integration tests for facets: facet calculation, pagination independence, non-aggregated facets
- [x] 13.10 Integration tests for cross-register resolution: nested queries spanning registers
- [x] 13.11 Regression tests with opencatalogi and softwarecatalog to verify no side effects

## 14. Subscriptions (SSE-based)

- [x] 14.1 Design WebSocket transport strategy (ExApp sidecar vs PHP WebSocket server)
- [x] 14.2 Implement subscription type generation per schema
- [x] 14.3 Implement event bridge from AuditTrail creation to subscription delivery
- [x] 14.4 Implement RBAC-gated subscription authorization (connect-time and per-event)
- [x] 14.5 Implement subscription filter arguments for server-side event filtering
