## ADDED Requirements

### Requirement: Custom scalar types MUST map to OpenRegister property formats
GraphQL MUST expose custom scalars matching the JSON Schema format annotations that MagicMapper uses for column typing.

#### Scenario: DateTime scalar
- **WHEN** a schema property has `type: "string", format: "date-time"`
- **THEN** the GraphQL field MUST use a `DateTime` scalar that serializes as ISO 8601
- **AND** input filters MUST accept ISO 8601 strings and support range comparisons

#### Scenario: UUID scalar
- **WHEN** a schema property has `type: "string", format: "uuid"`
- **THEN** the GraphQL field MUST use a `UUID` scalar that validates UUID v4 format

#### Scenario: Email scalar
- **WHEN** a schema property has `type: "string", format: "email"`
- **THEN** the GraphQL field MUST use an `Email` scalar that validates RFC 5321 format
- **AND** invalid email values in mutations MUST produce a validation error

#### Scenario: URI scalar
- **WHEN** a schema property has `type: "string", format: "uri"`
- **THEN** the GraphQL field MUST use a `URI` scalar

#### Scenario: JSON scalar for unstructured data
- **WHEN** a schema property has `type: "object"` without `$ref` or `type: "array"` with mixed items
- **THEN** the GraphQL field MUST use a `JSON` scalar that accepts arbitrary JSON

#### Scenario: File Upload scalar
- **WHEN** a schema property is configured as a file field via `objectConfiguration`
- **THEN** the field MUST use an `Upload` scalar for mutations following the GraphQL multipart request spec
- **AND** the field MUST return a `File` type in queries with fields: `filename`, `mimeType`, `size`, `url`
- **AND** file upload MUST reuse `FilePropertyHandler` including MIME validation and executable blocking

### Requirement: DataLoader batching MUST use RelationHandler
Nested object resolution MUST batch UUID lookups using the existing RelationHandler to prevent N+1 queries.

#### Scenario: Batch resolution of nested references
- **WHEN** a query fetches 20 orders each with a `klant` reference
- **THEN** all 20 klant UUIDs MUST be collected and loaded in a single batch via `RelationHandler::bulkLoadRelationshipsBatched()`
- **AND** the ultra-preload cache MUST be populated for sub-resolvers

#### Scenario: Circuit breaker limits
- **WHEN** a query would resolve more than 200 relation IDs
- **THEN** the RelationHandler circuit breaker MUST cap at 200 IDs
- **AND** array relations MUST be capped at 10 items per property per object

#### Scenario: Depth limiting matches schema maxDepth
- **WHEN** a schema has `maxDepth: 3` and a query nests 5 levels deep
- **THEN** resolution MUST stop at depth 3 and return `null` for deeper levels
- **AND** no error MUST be raised

#### Scenario: Cross-register relation resolution
- **WHEN** schema `aanvraag` in register `vergunningen` references schema `persoon` in register `basisregistratie`
- **THEN** the resolver MUST use MagicMapper's cross-register table lookup
- **AND** RBAC MUST be checked independently for each register/schema combination

#### Scenario: Bidirectional relationships via inversedBy
- **WHEN** schema `project` has property `taken` with inversedBy pointing to `taak.project`
- **THEN** the resolver MUST use `RelationHandler::applyInversedByFilter()` to find referencing objects

### Requirement: Dual pagination mode with Relay cursor support
List queries MUST support both offset-based pagination (matching REST API) and Relay-style cursor pagination.

#### Scenario: Offset-based pagination
- **WHEN** a client queries `meldingen(first: 10, offset: 20)`
- **THEN** exactly 10 objects MUST be returned starting from offset 20
- **AND** the connection MUST include `totalCount`, `page`, and `pages`

#### Scenario: Cursor-based pagination
- **WHEN** a client queries `meldingen(first: 10, after: "cursor-abc")`
- **THEN** 10 objects MUST be returned after the cursor position
- **AND** `pageInfo.hasNextPage` MUST be `true` if more results exist
- **AND** cursors MUST be opaque, encoding `{uuid, sortValue}` for stability across concurrent inserts

#### Scenario: Connection type follows Relay spec
- **WHEN** any list query is executed
- **THEN** the response MUST include `edges[].cursor`, `edges[].node`, `pageInfo`, and `totalCount`

### Requirement: Filter operators MUST match MagicSearchHandler capabilities
List query filters MUST support the full operator set available in the REST API.

#### Scenario: Range operators
- **WHEN** a query filters with `{ created: { gte: "2025-01-01", lt: "2025-07-01" } }`
- **THEN** the filter MUST delegate to MagicSearchHandler with equivalent operators

#### Scenario: Full-text search with fuzzy matching
- **WHEN** a query includes `search: "wateroverlast", fuzzy: true`
- **THEN** the search MUST delegate to MagicSearchHandler's full-text search
- **AND** results MUST include a `_relevance` field (0-100) when fuzzy is enabled

#### Scenario: Metadata filtering via selfFilter
- **WHEN** a query includes `selfFilter: { owner: "user-1", organisation: "gemeente-tilburg" }`
- **THEN** the filter MUST apply to metadata columns (`_owner`, `_organisation`)
- **AND** this MUST match the REST API's `@self[owner]=user-1` behavior

### Requirement: Faceted search MUST be available through connections
Connection types MUST expose facets and facetable field lists matching FacetHandler behavior.

#### Scenario: Request facets in a list query
- **WHEN** a query includes `meldingen(facets: ["status", "priority"]) { ... }`
- **THEN** the connection MUST include a `facets` field with value counts per field
- **AND** facets MUST be calculated on the full filtered dataset independent of pagination

#### Scenario: Discover facetable fields
- **WHEN** a query requests `facetable` on a connection
- **THEN** all fields with `facetable` configuration MUST be listed

### Requirement: Query complexity analysis MUST prevent resource abuse
The endpoint MUST analyze query complexity before execution using depth limiting and cost-based budgeting.

#### Scenario: Depth limiting
- **WHEN** a query exceeds the maximum depth (default 10)
- **THEN** the query MUST be rejected with `extensions.code: "QUERY_TOO_COMPLEX"`
- **AND** `extensions.maxDepth` and `extensions.actualDepth` MUST be included

#### Scenario: Cost-based budgeting
- **WHEN** a query's estimated cost exceeds the budget (default 10000)
- **THEN** the query MUST be rejected before execution
- **AND** `extensions.estimatedCost` and `extensions.maxCost` MUST be included
- **AND** cost calculation: fields = 1 point, object resolvers = 10 points, list resolvers multiply child costs by `first` argument

#### Scenario: Cost reported in response extensions
- **WHEN** a query executes successfully
- **THEN** `extensions.complexity` MUST include `estimated`, `max`, `depth`, and `maxDepth`

#### Scenario: Rate limiting via SecurityService
- **WHEN** a client exceeds the GraphQL rate limit
- **THEN** the response MUST include `extensions.code: "RATE_LIMITED"` and a `Retry-After` header
- **AND** the progressive delay mechanism (2s → 4s → ... → 60s max) MUST apply

### Requirement: Introspection MUST be controllable per environment
Schema introspection MUST be configurable via `graphql_introspection` app setting.

#### Scenario: Introspection enabled
- **WHEN** `graphql_introspection` is `enabled`
- **THEN** any client MAY run introspection queries and receive the full schema

#### Scenario: Introspection disabled
- **WHEN** `graphql_introspection` is `disabled`
- **THEN** introspection queries MUST be rejected with `extensions.code: "INTROSPECTION_DISABLED"`

#### Scenario: Introspection restricted to authenticated users
- **WHEN** `graphql_introspection` is `authenticated`
- **THEN** anonymous introspection MUST be rejected
- **AND** authenticated users MUST receive the full schema

### Requirement: Cross-register schema stitching MUST provide a unified graph
All registers and schemas MUST be queryable through a single GraphQL schema with transparent cross-register resolution.

#### Scenario: Unified root queries across registers
- **WHEN** the GraphQL schema is generated
- **THEN** every schema from every register MUST produce root-level queries and mutations
- **AND** each type MUST include a `_register` metadata field

#### Scenario: Register-scoped queries
- **WHEN** a client queries `register(id: "basisregistratie") { personen { naam } }`
- **THEN** the query MUST be scoped to that register's schemas only

#### Scenario: Relationship traversal with _usedBy
- **WHEN** a client queries `persoon(id: "persoon-1") { _usedBy { ... on Aanvraag { titel } } }`
- **THEN** the resolver MUST use `RelationHandler::getUsedBy()` to find all referencing objects
- **AND** results MUST be a GraphQL union type

### Requirement: Schema composition MUST map to GraphQL type system
JSON Schema composition keywords (allOf, oneOf, anyOf) MUST produce corresponding GraphQL types.

#### Scenario: allOf maps to merged type
- **WHEN** schema `zaak` uses `allOf` referencing `basisZaak` and `uitgebreideZaak`
- **THEN** the `Zaak` type MUST include fields from both composed schemas

#### Scenario: oneOf maps to union type
- **WHEN** schema `betrokkene` uses `oneOf` referencing `persoon` and `organisatie`
- **THEN** a `Betrokkene` union type MUST be generated: `union Betrokkene = Persoon | Organisatie`

#### Scenario: anyOf maps to interface
- **WHEN** schema `document` uses `anyOf` referencing multiple document subtypes
- **THEN** a `Document` interface MUST be generated with shared fields

### Requirement: Multi-tenancy MUST be enforced on all GraphQL operations
All queries, mutations, and subscriptions MUST respect the MultiTenancyTrait organisation scoping.

#### Scenario: Organisation scoping on queries
- **WHEN** user with active organisation `gemeente-tilburg` queries meldingen
- **THEN** only meldingen belonging to `gemeente-tilburg` MUST be returned
- **AND** the filter MUST be applied at the MagicMapper query level

#### Scenario: Parent organisation sees child data
- **WHEN** user with active organisation `provincie-brabant` (parent of `gemeente-tilburg`) queries
- **THEN** data from both organisations MUST be visible

### Requirement: Audit trail logging for GraphQL operations
All mutations MUST produce audit trail entries via AuditTrailMapper matching REST API detail level.

#### Scenario: Mutation creates audit entry
- **WHEN** a user executes `createMelding(input: { title: "Wateroverlast" })`
- **THEN** an AuditTrail entry MUST be created with `action: "create"`, field-level diffs in `changed`, user/session/IP context, and GDPR compliance fields

#### Scenario: Update records field-level changes
- **WHEN** a user executes `updateMelding(id: "...", input: { status: "in_behandeling" })`
- **THEN** the `changed` field MUST contain only the modified fields with old/new values

#### Scenario: Queryable audit trail on objects
- **WHEN** a client queries `melding(id: "...") { _auditTrail(last: 10) { action user changed created } }`
- **THEN** the last 10 audit entries for that object MUST be returned via AuditTrailMapper

#### Scenario: GraphQL operation name in audit metadata
- **WHEN** a named GraphQL operation executes
- **THEN** the operation name MUST be included in the audit trail metadata

### Requirement: Structured error responses
All errors MUST follow a consistent format with machine-readable extension codes.

#### Scenario: Error format
- **WHEN** any error occurs during GraphQL execution
- **THEN** the error MUST include `message`, `path`, `locations`, and `extensions.code`
- **AND** supported codes MUST be: `FORBIDDEN`, `FIELD_FORBIDDEN`, `NOT_FOUND`, `VALIDATION_ERROR`, `QUERY_TOO_COMPLEX`, `RATE_LIMITED`, `INTROSPECTION_DISABLED`, `INTERNAL_ERROR`

#### Scenario: Partial success with authorized and unauthorized fields
- **WHEN** a query requests both authorized and unauthorized fields
- **THEN** authorized fields MUST return data and unauthorized fields MUST return null
- **AND** partial errors MUST appear in the `errors` array with the field path

#### Scenario: Validation errors from SaveObject
- **WHEN** a mutation violates JSON Schema validation (e.g., `minLength`)
- **THEN** `extensions.code` MUST be `VALIDATION_ERROR` with `extensions.details.field` and `extensions.details.constraint`
