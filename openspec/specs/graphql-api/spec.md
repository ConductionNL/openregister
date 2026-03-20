---
status: implemented
---

# GraphQL API
## Purpose

Provide an auto-generated GraphQL API alongside the existing REST API for register data, enabling clients to request exactly the fields they need in a single round-trip and resolve nested relationships without over-fetching. The GraphQL schema MUST be derived dynamically from register schema definitions at runtime, supporting queries with nested object resolution, mutations for CRUD operations, and subscriptions for real-time updates via Server-Sent Events (SSE).

The GraphQL layer MUST reuse existing OpenRegister services -- `PermissionHandler` for schema-level RBAC, `PropertyRbacHandler` for field-level security, `RelationHandler` for nested resolution and DataLoader batching, `AuditTrailMapper` for change logging, `SecurityService` for rate limiting, `MagicMapper` for cross-register queries, and `MultiTenancyTrait` for organisation scoping -- rather than reimplementing any of these concerns. The implementation is built on the `webonyx/graphql-php` library, with the full service stack comprising `GraphQLService` (orchestrator), `SchemaGenerator` (type generation), `GraphQLResolver` (query/mutation resolution), `QueryComplexityAnalyzer` (abuse prevention), `GraphQLErrorFormatter` (structured errors), `SubscriptionService` (SSE event buffer), and `GraphQLSubscriptionListener` (event bridge).

**Source**: Gap identified in cross-platform analysis; Directus, Strapi, and Twenty CRM all provide auto-generated GraphQL APIs. See cross-references: `zoeken-filteren`, `realtime-updates`, `rbac-scopes`.

## Requirements

### Requirement: The GraphQL schema MUST be auto-generated from register schemas

Each register schema MUST automatically produce corresponding GraphQL types, queries, and mutations. `SchemaGenerator.generate()` MUST load all registers via `RegisterMapper.findAll()` and all schemas via `SchemaMapper.findAll()`, then iterate over each schema calling `buildSchemaFields()` to produce query and mutation field definitions. Type generation MUST follow the same JSON Schema property type/format mapping used by `MagicMapper`, ensuring consistency between REST and GraphQL responses. Schema slugs MUST be converted to valid GraphQL names: PascalCase for type names (via `toTypeName()`) and camelCase for field names (via `toFieldName()`), with naive Dutch/English singularization (via `singularize()`) to derive single-object query names from plural schema slugs.

#### Scenario: Generate GraphQL type from schema
- **GIVEN** a register schema `meldingen` with properties: title (string), status (string), priority (enum), created (datetime)
- **WHEN** `SchemaGenerator.generate()` is called
- **THEN** a GraphQL `ObjectType` named `Meldingen` (or its singularized PascalCase form) MUST be created via `getObjectType()`
- **AND** property types MUST be mapped by `TypeMapperHandler.mapPropertyToGraphQLType()`: string -> `Type::string()`, integer -> `Type::int()`, number -> `Type::float()`, boolean -> `Type::boolean()`, datetime -> `DateTimeType` scalar
- **AND** each type MUST include metadata fields: `_uuid` (UUID scalar), `_register` (Int), `_schema` (Int), `_created` (DateTime), `_updated` (DateTime), `_owner` (String)

#### Scenario: Generate queries for a schema
- **GIVEN** schema `meldingen` exists with slug `meldingen`
- **WHEN** `buildQueryFields()` is called
- **THEN** the following root query fields MUST be generated:
  - `melding(id: ID!): Melding` -- fetch single object via `GraphQLResolver.resolveSingle()`
  - `meldingen(filter: MeldingenFilter, sort: SortInput, selfFilter: SelfFilter, search: String, fuzzy: Boolean, facets: [String], first: Int, offset: Int, after: String): MeldingenConnection` -- list with pagination via `GraphQLResolver.resolveList()`
- **AND** list query arguments MUST be defined by `TypeMapperHandler.getListArgs()` with defaults: `first: 20`, `fuzzy: false`

#### Scenario: Generate mutations for a schema
- **GIVEN** schema `meldingen` exists
- **WHEN** `buildMutationFields()` is called
- **THEN** the following mutation fields MUST be generated:
  - `createMelding(input: CreateMeldingInput!): Melding` -- delegates to `GraphQLResolver.resolveCreate()`
  - `updateMelding(id: ID!, input: UpdateMeldingInput!): Melding` -- delegates to `GraphQLResolver.resolveUpdate()`
  - `deleteMelding(id: ID!): Boolean` -- delegates to `GraphQLResolver.resolveDelete()`
- **AND** `CreateMeldingInput` MUST mark `required` fields from the schema as `Type::nonNull()` via `TypeMapperHandler.getCreateInputType()`
- **AND** `UpdateMeldingInput` MUST leave all fields nullable (partial updates) via `TypeMapperHandler.getUpdateInputType()`

#### Scenario: Schema changes regenerate GraphQL types
- **GIVEN** schema `meldingen` has a GraphQL type `Melding`
- **WHEN** a property `urgentie` (integer) is added to the schema
- **THEN** the next call to `SchemaGenerator.generate()` MUST produce an updated `Melding` type including `urgentie: Int`
- **AND** existing queries using `Melding` without `urgentie` MUST continue to work (GraphQL field selection is additive)
- **AND** schema generation MUST be fast (~50ms for typical installs) since APCu caching of webonyx Schema objects is not feasible due to closures

#### Scenario: Type name collision resolution
- **GIVEN** two schemas with slug `items` exist in different registers
- **WHEN** `toTypeName()` is called for both
- **THEN** the second schema's type MUST be disambiguated by appending its schema ID (e.g., `Items` and `Items42`)
- **AND** the `usedTypeNames` map MUST track which schema ID owns each type name

### Requirement: Custom scalar types MUST map to OpenRegister property formats

GraphQL MUST expose custom scalars matching the JSON Schema format annotations that `TypeMapperHandler.mapPropertyToGraphQLType()` uses for type resolution. Six custom scalar classes MUST be implemented in `lib/Service/GraphQL/Scalar/`.

#### Scenario: DateTime scalar
- **GIVEN** a schema property with `type: "string", format: "date-time"` or `format: "date"`
- **WHEN** the GraphQL type is generated
- **THEN** the field MUST use the `DateTimeType` scalar (name: `DateTime`)
- **AND** serialization MUST output ISO 8601 format via `DateTimeInterface::ATOM`
- **AND** parsing MUST accept three formats: `ATOM` (`2025-01-15T10:30:00+00:00`), `Y-m-d\TH:i:s`, and `Y-m-d`
- **AND** invalid date strings MUST throw a `GraphQL\Error\Error`

#### Scenario: UUID scalar
- **GIVEN** a schema property with `type: "string", format: "uuid"`
- **WHEN** the GraphQL type is generated
- **THEN** the field MUST use the `UuidType` scalar that validates UUID v4 format
- **AND** the `id` argument on single-object queries MUST accept UUID values

#### Scenario: Email scalar
- **GIVEN** a schema property with `type: "string", format: "email"`
- **WHEN** the GraphQL type is generated
- **THEN** the field MUST use the `EmailType` scalar that validates RFC 5321 format
- **AND** invalid email values in mutations MUST produce a validation error

#### Scenario: URI scalar
- **GIVEN** a schema property with `type: "string", format: "uri"` or `format: "url"`
- **WHEN** the GraphQL type is generated
- **THEN** the field MUST use the `UriType` scalar

#### Scenario: JSON scalar for unstructured data
- **GIVEN** a schema property with `type: "object"` without `$ref` (generic object)
- **OR** a schema property with `type: "array"` containing mixed items
- **WHEN** the GraphQL type is generated
- **THEN** the field MUST use the `JsonType` scalar that accepts arbitrary JSON

#### Scenario: Upload scalar for file fields
- **GIVEN** a schema property configured as a file field via `objectConfiguration`
- **WHEN** the GraphQL type is generated
- **THEN** the field MUST use the `UploadType` scalar for mutations (following the GraphQL multipart request spec)
- **AND** `parseLiteral()` MUST always throw an error ("use multipart form upload")
- **AND** `parseValue()` MUST accept arrays (file metadata) or strings (file references)
- **AND** file upload MUST reuse `FilePropertyHandler` including MIME validation and executable blocking

### Requirement: GraphQL MUST support nested object resolution via DataLoader batching

References between schemas MUST be resolvable as nested objects in a single query. `GraphQLResolver` MUST implement the DataLoader pattern using a `relationBuffer` (collecting UUIDs) and `relationCache` (storing loaded objects), with deferred resolution via `GraphQL\Deferred`.

#### Scenario: Resolve nested references with batching
- **GIVEN** schema `orders` with property `klant` referencing schema `klanten` (via `$ref`)
- **AND** a query fetches 20 orders with their klant: `orders { klant { naam } }`
- **WHEN** `GraphQLResolver.resolveRelation()` is called for each order's klant UUID
- **THEN** each UUID MUST be added to `$this->relationBuffer`
- **AND** a `Deferred` callback MUST be returned that calls `flushRelationBuffer()` on first access
- **AND** `flushRelationBuffer()` MUST call `RelationHandler.bulkLoadRelationshipsBatched()` with all collected UUIDs in a single batch
- **AND** loaded objects MUST be stored in `$this->relationCache` indexed by UUID

#### Scenario: Object references map to nested types in schema generation
- **GIVEN** schema property `klant` has `type: "object"` and `$ref: "klanten"`
- **WHEN** `TypeMapperHandler.mapPropertyToGraphQLType()` is called
- **THEN** it MUST resolve the `$ref` via the `refResolver` callback to find the `klanten` schema
- **AND** it MUST return the `ObjectType` for `klanten` (via `objectTypeFactory`), enabling nested field selection

#### Scenario: Array of references maps to list type
- **GIVEN** schema property `documenten` has `type: "array"` with `items.$ref: "document"`
- **WHEN** `TypeMapperHandler.mapPropertyToGraphQLType()` is called
- **THEN** it MUST return `Type::listOf(ObjectType)` for the referenced document type
- **AND** each array element MUST be individually resolved through the DataLoader buffer

#### Scenario: Depth limiting prevents infinite recursion
- **GIVEN** schema `persoon` with a self-referencing property `manager` referencing `persoon`
- **AND** the schema's `maxDepth` is set to 3
- **WHEN** a client queries deeply nested manager chains
- **THEN** resolution MUST stop at depth 3 and return `null` for deeper levels
- **AND** no error MUST be raised (graceful truncation)

#### Scenario: Cross-register relation resolution
- **GIVEN** schema `aanvraag` in register `vergunningen` references schema `persoon` in register `basisregistratie`
- **WHEN** a client queries `aanvraag { aanvrager { naam bsn } }`
- **THEN** the resolver MUST use `MagicMapper`'s cross-register table lookup
- **AND** RBAC MUST be checked independently for each register/schema combination via `checkSchemaPermission()`

#### Scenario: Bidirectional relationships via _usedBy
- **GIVEN** object `persoon-1` is referenced by multiple objects across schemas
- **WHEN** a client queries `persoon(id: "persoon-1") { _usedBy }`
- **THEN** the resolver MUST call `GraphQLResolver.resolveUsedBy()` which delegates to `RelationHandler.getUsedBy()`
- **AND** results MUST be returned as JSON (the `_usedBy` field uses the `JSON` scalar type)

### Requirement: GraphQL MUST support filtering and sorting matching the REST API

List queries MUST support the full filtering, sorting, and search capabilities of the REST API. `GraphQLResolver.argsToRequestParams()` MUST translate GraphQL arguments into the request parameter format expected by `ObjectService.buildSearchQuery()`.

#### Scenario: Filter by property value
- **GIVEN** a query: `meldingen(filter: { status: "in_behandeling" }) { edges { node { title } } }`
- **WHEN** `argsToRequestParams()` processes the filter argument
- **THEN** it MUST set `$params['status'] = "in_behandeling"` (property filters are flattened into top-level params)
- **AND** `ObjectService.buildSearchQuery()` MUST receive these params and delegate to `MagicSearchHandler`

#### Scenario: Filter with operators
- **GIVEN** a query with complex filter: `meldingen(filter: { created: { gte: "2025-01-01", lt: "2025-07-01" } })`
- **THEN** operator-based filters MUST be passed through to `MagicSearchHandler`
- **AND** the supported operator set MUST include: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `notIn`, `isNull`, `isNotNull`

#### Scenario: Full-text search with fuzzy matching
- **GIVEN** a query: `meldingen(search: "wateroverlast", fuzzy: true) { edges { node { title } } }`
- **WHEN** `argsToRequestParams()` processes the arguments
- **THEN** it MUST set `$params['_search'] = "wateroverlast"` and `$params['_fuzzy'] = "true"`
- **AND** the search MUST delegate to `MagicSearchHandler`'s full-text search (ILIKE across string properties)
- **AND** when fuzzy is enabled, each edge MUST include a `_relevance` field (0-100) in the connection response

#### Scenario: Sort results
- **GIVEN** a query: `meldingen(sort: { field: "created", order: "DESC" })`
- **WHEN** `argsToRequestParams()` processes the sort argument
- **THEN** it MUST set `$params['_order']` to a JSON-encoded array: `[{"field": "created", "direction": "DESC"}]`
- **AND** `SortInput` is a shared `InputObjectType` with fields `field: String!` and `order: String` (default "ASC")

#### Scenario: Metadata filtering via selfFilter
- **GIVEN** a query using `selfFilter: { owner: "user-1", organisation: "gemeente-tilburg" }`
- **WHEN** `argsToRequestParams()` processes the selfFilter argument
- **THEN** it MUST set `$params['@self']['owner'] = "user-1"` and `$params['@self']['organisation'] = "gemeente-tilburg"`
- **AND** this MUST match the REST API's `@self[owner]=user-1` behavior
- **AND** `SelfFilter` is a shared `InputObjectType` with fields: `owner`, `organisation`, `register`, `schema`, `uuid`

### Requirement: GraphQL MUST support faceted search through connections

Connection types MUST expose facets and facetable field lists matching `FacetHandler` behavior. This is a cross-reference to the `zoeken-filteren` spec.

#### Scenario: Request facets in a list query
- **GIVEN** a query: `meldingen(facets: ["status", "priority"]) { edges { node { title } } facets facetable }`
- **WHEN** `argsToRequestParams()` processes the facets argument
- **THEN** it MUST set `$params['_facets'] = "status,priority"` (comma-separated)
- **AND** `ObjectService.searchObjectsPaginated()` MUST return facet data
- **AND** the connection response MUST include `facets` (JSON scalar with value counts per field) and `facetable` (list of field names)
- **AND** facets MUST be calculated on the full filtered dataset, independent of pagination

#### Scenario: Facets in connection type structure
- **GIVEN** any schema `meldingen`
- **WHEN** `TypeMapperHandler.getConnectionType()` builds the connection type
- **THEN** it MUST include fields: `edges: [MeldingenEdge!]!`, `pageInfo: PageInfo!`, `totalCount: Int!`, `facets: JSON`, `facetable: [String]`
- **AND** each edge type MUST include: `cursor: String!`, `node: Melding!`, `_relevance: Float` (fuzzy search relevance score)

### Requirement: GraphQL MUST support dual pagination modes

The API MUST support both offset-based pagination (matching the REST API) and Relay-style cursor pagination for efficient infinite scrolling. `GraphQLResolver.resolveList()` MUST build connection responses with both pagination modes from the results of `ObjectService.searchObjectsPaginated()`.

#### Scenario: Offset-based pagination
- **GIVEN** 100 meldingen objects
- **AND** a query: `meldingen(first: 10, offset: 20) { edges { node { title } } totalCount }`
- **THEN** `argsToRequestParams()` MUST set `$params['_limit'] = 10` and `$params['_offset'] = 20`
- **AND** exactly 10 objects MUST be returned starting from offset 20
- **AND** `totalCount` MUST reflect the total filtered count (100)

#### Scenario: Relay-style cursor pagination
- **GIVEN** 100 meldingen objects
- **AND** a query: `meldingen(first: 10, after: "eyJ1dWlk...") { edges { cursor node { title } } pageInfo { hasNextPage endCursor } }`
- **THEN** 10 objects MUST be returned after the cursor position
- **AND** `pageInfo.hasNextPage` MUST be `true` if `(offset + limit) < totalCount`
- **AND** cursors MUST be opaque base64-encoded JSON containing `{uuid, offset}` (via `GraphQLResolver.encodeCursor()`)

#### Scenario: Connection type follows Relay specification
- **GIVEN** any schema `meldingen`
- **THEN** the connection type MUST follow:
  ```graphql
  type MeldingenConnection {
    edges: [MeldingenEdge!]!
    pageInfo: PageInfo!
    totalCount: Int!
    facets: JSON
    facetable: [String]
  }
  type MeldingenEdge {
    cursor: String!
    node: Melding!
    _relevance: Float
  }
  type PageInfo {
    hasNextPage: Boolean!
    hasPreviousPage: Boolean!
    startCursor: String
    endCursor: String
  }
  ```

#### Scenario: Page info boundary conditions
- **GIVEN** a connection with `offset = 0` and total results available
- **THEN** `hasPreviousPage` MUST be `false` (since `offset > 0` is false)
- **AND** when no edges are returned, `startCursor` and `endCursor` MUST be `null`

### Requirement: GraphQL MUST enforce schema-level RBAC via PermissionHandler

Authorization policies MUST apply to GraphQL queries and mutations identically to the REST API, delegating all checks to the existing `PermissionHandler` service. This is a cross-reference to the `rbac-scopes` spec.

#### Scenario: Unauthorized schema access
- **GIVEN** schema `vertrouwelijk` has authorization `{ "read": ["geautoriseerd-personeel"] }`
- **AND** user `medewerker-1` is not in group `geautoriseerd-personeel`
- **WHEN** they query `vertrouwelijk { title }`
- **THEN** `GraphQLResolver.checkSchemaPermission()` MUST call `PermissionHandler.checkPermission($schema, 'read')`
- **AND** the `NotAuthorizedException` MUST be caught and re-thrown as `GraphQL\Error\Error` with `extensions.code: "FORBIDDEN"`

#### Scenario: Mutation authorization per action
- **GIVEN** schema `besluiten` has authorization `{ "create": ["behandelaars"], "update": ["behandelaars"], "delete": ["managers"] }`
- **AND** user `medewerker-1` is in group `behandelaars` but not `managers`
- **WHEN** they attempt `deleteBesluit(id: "...")`
- **THEN** `resolveDelete()` MUST call `checkSchemaPermission(schema, 'delete')` which MUST throw FORBIDDEN
- **AND** `createBesluit` and `updateBesluit` MUST succeed (checkSchemaPermission with 'create'/'update' passes)

#### Scenario: Cross-schema authorization in nested queries
- **GIVEN** user `medewerker-1` can read `orders` but not `klanten`
- **WHEN** they query `order { title klant { naam } }`
- **THEN** the `klant` field resolver MUST check permissions for the `klanten` schema independently
- **AND** unauthorized nested fields MUST return null with a partial error in the `errors` array
- **AND** the rest of the query MUST still return data (partial success pattern)

#### Scenario: Admin bypass
- **GIVEN** user is in the `admin` group
- **WHEN** they query any schema
- **THEN** all RBAC checks MUST be bypassed (matching `PermissionHandler`'s admin override)

#### Scenario: Conditional authorization with organisation matching
- **GIVEN** schema `dossiers` has authorization `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user belongs to group `behandelaars` in organisation `gemeente-tilburg`
- **WHEN** they query dossiers from `gemeente-utrecht`
- **THEN** those dossiers MUST be filtered out by `PermissionHandler.evaluateMatchConditions()`
- **AND** no error MUST be raised (silently excluded from results, matching REST behavior)

### Requirement: GraphQL MUST enforce property-level RBAC via PropertyRbacHandler

Individual fields within a type MUST respect the property-level authorization defined on schemas. `GraphQLResolver` MUST call `PropertyRbacHandler.filterReadableProperties()` on query results and `PropertyRbacHandler.getUnauthorizedProperties()` before mutation execution.

#### Scenario: Property read authorization
- **GIVEN** schema `inwoners` has property `bsn` with authorization `{ "read": [{ "group": "bsn-geautoriseerd" }] }`
- **AND** user `medewerker-1` is NOT in group `bsn-geautoriseerd`
- **WHEN** they query `inwoner { naam bsn adres }`
- **THEN** `GraphQLResolver.filterProperties()` MUST call `PropertyRbacHandler.filterReadableProperties($schema, $data)`
- **AND** `bsn` MUST be removed from the returned data (resolves to `null`)
- **AND** `naam` and `adres` MUST still be returned

#### Scenario: Property write authorization on mutations
- **GIVEN** schema `inwoners` has property `interneAantekening` with authorization `{ "update": [{ "group": "redacteuren" }] }`
- **AND** user `medewerker-1` is NOT in group `redacteuren`
- **WHEN** they attempt `updateInwoner(id: "...", input: { interneAantekening: "nieuwe tekst" })`
- **THEN** `resolveUpdate()` MUST call `PropertyRbacHandler.getUnauthorizedProperties($schema, [], $input, false)`
- **AND** the mutation MUST be rejected with `extensions.code: "FIELD_FORBIDDEN"` and message listing unauthorized fields

#### Scenario: Property authorization applied to list results
- **GIVEN** a list query returns 20 objects
- **WHEN** `resolveList()` processes the results
- **THEN** EACH object MUST be individually filtered through `filterProperties()` before building edges
- **AND** property-level RBAC MUST be applied consistently across all items in the list

#### Scenario: GraphQL introspection includes authorization annotations
- **GIVEN** property `bsn` on schema `inwoners` requires group `bsn-geautoriseerd`
- **WHEN** `TypeMapperHandler.getPropertyAuthDescriptions()` is called for the schema
- **THEN** the field description MUST be annotated: "Requires group: bsn-geautoriseerd"
- **AND** this annotation MUST be visible in introspection queries (authorization enforced at resolution time, not schema time)

### Requirement: GraphQL MUST log operations to the audit trail

All GraphQL mutations MUST produce audit trail entries using the existing `AuditTrailMapper`, matching the same detail level as REST API operations. Query audit trails MUST be available through a dedicated `_auditTrail` field on every object type.

#### Scenario: Mutation creates audit trail entry
- **GIVEN** a user executes `createMelding(input: { title: "Wateroverlast", status: "nieuw" })`
- **WHEN** `resolveCreate()` delegates to `ObjectService.saveObject()`
- **THEN** `ObjectService` MUST create an `AuditTrail` entry with:
  - `action: "create"`
  - `changed`: JSON showing `{ "title": { "old": null, "new": "Wateroverlast" }, "status": { "old": null, "new": "nieuw" } }`
  - `user`: the authenticated user ID
  - `session`, `request`, `ipAddress`: captured from the HTTP context
  - `registerUuid`, `schemaUuid`, `objectUuid`: linking to the affected entities

#### Scenario: Update mutation records field-level changes
- **GIVEN** melding `melding-1` has `status: "nieuw"`
- **AND** a user executes `updateMelding(id: "melding-1", input: { status: "in_behandeling" })`
- **THEN** the audit trail `changed` field MUST contain only modified fields: `{ "status": { "old": "nieuw", "new": "in_behandeling" } }`

#### Scenario: Queryable audit trail on objects
- **GIVEN** a user has access to object `melding-1`
- **WHEN** they query `melding(id: "melding-1") { _auditTrail(last: 5) { action user changed created } }`
- **THEN** `GraphQLResolver.resolveAuditTrail()` MUST call `AuditTrailMapper.findAll()` with filter `object_uuid = melding-1`, limit 5, ordered by `created DESC`
- **AND** the `AuditTrailEntry` type MUST include fields: `action`, `user`, `userName`, `changed` (JSON), `created` (DateTime), `ipAddress`, `processingActivityId`, `confidentiality`, `retentionPeriod`

#### Scenario: GraphQL operation name in audit metadata
- **GIVEN** a named GraphQL operation: `mutation MarkUrgent($id: ID!) { updateMelding(id: $id, input: { priority: "urgent" }) { id } }`
- **WHEN** the mutation executes
- **THEN** `GraphQLService.createContext()` MUST pass `operationName: "MarkUrgent"` in the resolver context
- **AND** the operation name MUST be available for audit trail metadata

### Requirement: Query complexity analysis MUST prevent resource abuse

The GraphQL endpoint MUST analyze query complexity before execution to prevent denial-of-service through deeply nested or excessively broad queries. `QueryComplexityAnalyzer` MUST perform static AST analysis using depth counting and cost-based budgeting, rejecting queries that exceed configurable thresholds.

#### Scenario: Depth limiting
- **GIVEN** a system-wide maximum query depth configured via `graphql_max_depth` app setting (default: 10)
- **AND** a client submits a query nested 15 levels deep
- **WHEN** `QueryComplexityAnalyzer.analyze()` traverses the AST via `analyzeSelectionSet()`
- **THEN** the query MUST be rejected before execution with a `GraphQL\Error\Error`
- **AND** the error MUST include `extensions.code: "QUERY_TOO_COMPLEX"`, `extensions.maxDepth: 10`, `extensions.actualDepth: 15`

#### Scenario: Cost-based complexity budgeting
- **GIVEN** each field has a default cost of 1 (`FIELD_COST`) and each nested object resolver has a cost of 10 (`RESOLVER_COST`)
- **AND** each list query multiplies child costs by the `first` argument (resolved via `getListMultiplier()` which reads the `first` argument from the AST, including variable resolution)
- **AND** the maximum query cost budget is configured via `graphql_max_cost` app setting (default: 10000)
- **WHEN** a client submits a query exceeding the cost budget
- **THEN** the query MUST be rejected with `extensions.code: "QUERY_TOO_COMPLEX"`, `extensions.estimatedCost`, and `extensions.maxCost`

#### Scenario: Cost reported in response extensions
- **GIVEN** a query executes successfully with estimated cost 3500
- **WHEN** `GraphQLService.execute()` adds complexity info to the response
- **THEN** `extensions.complexity` MUST include: `{ "estimated": 3500, "max": 10000, "depth": 4, "maxDepth": 10 }`

#### Scenario: Per-schema cost overrides
- **GIVEN** schema `documenten` is expensive to query
- **AND** `QueryComplexityAnalyzer.setSchemaCosts()` is called with `{ "documenten": 25 }`
- **WHEN** `getResolverCost()` is called for the `documenten` field
- **THEN** the elevated cost of 25 MUST be used instead of the default 10

#### Scenario: Rate limiting integration with SecurityService
- **GIVEN** the `graphql_rate_limit` app setting configures max requests per 60-second window (default: 100)
- **AND** `GraphQLService.checkRateLimit()` tracks requests in APCu using per-user or per-IP keys
- **WHEN** a client exceeds the rate limit
- **THEN** a `GraphQL\Error\Error` MUST be thrown with `extensions.code: "RATE_LIMITED"` and `extensions.retryAfter`
- **AND** the progressive delay MUST be calculated as `min(60, 2^overCount)` where overCount is requests beyond the limit
- **AND** `GraphQLController.execute()` MUST set HTTP status 429 and add a `Retry-After` header

### Requirement: Introspection MUST be controllable per environment

Schema introspection MUST be configurable via the `graphql_introspection` app setting to restrict exposure in production while remaining open in development. `GraphQLService.checkIntrospection()` MUST parse the AST and detect `__schema` or `__type` fields.

#### Scenario: Introspection enabled (default)
- **GIVEN** the app configuration `graphql_introspection` is set to `enabled` (the default)
- **WHEN** a client sends an introspection query (`{ __schema { types { name } } }`)
- **THEN** the full schema MUST be returned including all types, fields, arguments, and directives

#### Scenario: Introspection disabled in production
- **GIVEN** the app configuration `graphql_introspection` is set to `disabled`
- **WHEN** `checkIntrospection()` detects `__schema` or `__type` in the parsed document
- **THEN** the query MUST be rejected with `extensions.code: "INTROSPECTION_DISABLED"`
- **AND** regular queries without introspection fields MUST continue to work normally

#### Scenario: Introspection restricted to authenticated users
- **GIVEN** the app configuration `graphql_introspection` is set to `authenticated`
- **WHEN** an anonymous client (no user session) sends an introspection query
- **THEN** the query MUST be rejected with message "Introspection requires authentication"
- **AND** an authenticated user (via `IUserSession.getUser()`) MUST receive the full schema

#### Scenario: Schema documentation via descriptions
- **GIVEN** a schema `meldingen` with property `status` that has a JSON Schema `description: "Huidige status van de melding"`
- **WHEN** `SchemaGenerator.buildObjectFields()` processes the property
- **THEN** the GraphQL field MUST include the description text
- **AND** if the property has authorization requirements, `TypeMapperHandler.getPropertyAuthDescriptions()` MUST append "Requires group: ..." to the description

### Requirement: JSON Schema composition MUST map to GraphQL type system

JSON Schema composition keywords (`allOf`, `oneOf`, `anyOf`) MUST produce corresponding GraphQL types. `CompositionHandler.applyComposition()` MUST handle all three keywords, modifying the field array in-place.

#### Scenario: allOf maps to merged type
- **GIVEN** schema `zaak` uses `allOf` referencing schemas `basisZaak` and `uitgebreideZaak`
- **WHEN** `CompositionHandler.applyAllOf()` processes the schema
- **THEN** fields from both referenced schemas MUST be merged into the `Zaak` type via `array_merge($refFields, $fields)` (current schema fields take priority)
- **AND** the `$ref` is resolved via the `refResolver` callback and fields are built via the `fieldBuilder` callback

#### Scenario: oneOf maps to union type
- **GIVEN** schema `betrokkene` uses `oneOf` referencing `persoon` and `organisatie`
- **WHEN** `CompositionHandler.applyOneOf()` processes the schema
- **THEN** a GraphQL `UnionType` named `BetrokkeneUnion` MUST be generated containing the `Persoon` and `Organisatie` object types
- **AND** the union MUST be accessible as the `_oneOf` field on the parent type

#### Scenario: anyOf maps to interface with shared fields
- **GIVEN** schema `document` uses `anyOf` referencing multiple document subtypes that share common fields
- **WHEN** `CompositionHandler.applyAnyOf()` processes the schema
- **THEN** a GraphQL `InterfaceType` named `DocumentInterface` MUST be generated
- **AND** `extractSharedFields()` MUST identify fields present in ALL referenced types (excluding `_`-prefixed metadata fields)
- **AND** the interface MUST be accessible as the `_anyOf` field on the parent type

### Requirement: Cross-register schema stitching MUST provide a unified graph

All registers and schemas MUST be queryable through a single unified GraphQL schema. `SchemaGenerator.generate()` MUST iterate over ALL schemas from ALL registers and produce root-level queries and mutations for each.

#### Scenario: Unified root queries across registers
- **GIVEN** register `basisregistratie` with schema `personen` and register `vergunningen` with schema `aanvragen`
- **WHEN** `SchemaGenerator.generate()` builds the schema
- **THEN** both `persoon` and `aanvraag` queries MUST be available at the root Query type
- **AND** each object type MUST include a `_register` metadata field (Int) identifying its source register

#### Scenario: Register-scoped query
- **GIVEN** a client wants to query only within a specific register
- **THEN** a `register(id: ID!)` root query MUST be available
- **AND** this field currently returns `JSON` scalar (placeholder for future register-scoped subqueries)

#### Scenario: Cross-register nested resolution
- **GIVEN** `aanvraag` in register `vergunningen` has property `aanvrager` referencing `persoon` in register `basisregistratie`
- **WHEN** a client queries `aanvraag { titel aanvrager { naam geboortedatum } }`
- **THEN** the resolver MUST locate the correct register for the `persoon` schema via `findRegisterForSchema()`
- **AND** the cross-register join MUST be transparent to the client

#### Scenario: Relationship traversal with _usedBy
- **GIVEN** object `persoon-1` is referenced by multiple objects across registers
- **WHEN** a client queries `persoon(id: "persoon-1") { _usedBy }`
- **THEN** the `_usedBy` field MUST use `RelationHandler.getUsedBy()` to find all referencing objects
- **AND** results MUST be returned as JSON (since referencing objects may be of different types)

### Requirement: Multi-tenancy MUST be enforced on all GraphQL operations

All GraphQL queries, mutations, and subscriptions MUST respect the existing multi-tenancy model. `GraphQLResolver.resolveList()` MUST pass `_multitenancy: true` to `ObjectService.searchObjectsPaginated()`.

#### Scenario: Organisation scoping on queries
- **GIVEN** user `medewerker-1` has active organisation `gemeente-tilburg`
- **WHEN** they query `meldingen { edges { node { title } } }`
- **THEN** `resolveList()` MUST call `searchObjectsPaginated(query, _rbac: true, _multitenancy: true)`
- **AND** only meldingen belonging to `gemeente-tilburg` MUST be returned
- **AND** the organisation filter MUST be applied at the MagicMapper query level (not post-filter)

#### Scenario: Parent organisation sees child data
- **GIVEN** organisation `gemeente-tilburg` is a child of `provincie-brabant`
- **AND** user `medewerker-2` has active organisation `provincie-brabant`
- **WHEN** they query meldingen
- **THEN** meldingen from both `provincie-brabant` and `gemeente-tilburg` MUST be visible (matching `MultiTenancyTrait` behavior)

#### Scenario: Published items bypass multi-tenancy
- **GIVEN** an object is marked as `published: true`
- **AND** the schema allows public read access
- **WHEN** any user queries the object
- **THEN** it MUST be visible regardless of the user's active organisation

### Requirement: GraphQL MUST support subscriptions for real-time updates via SSE

Subscriptions MUST be available for receiving object change events via Server-Sent Events (SSE), integrated with the event system. This is a cross-reference to the `realtime-updates` spec. The implementation uses `SubscriptionService` for event buffering in APCu and `GraphQLSubscriptionController` for SSE delivery.

#### Scenario: Subscribe to object changes
- **GIVEN** a client connects to `GET /api/graphql/subscribe`
- **WHEN** a melding is created, updated, or deleted
- **THEN** `GraphQLSubscriptionListener.handle()` MUST detect `ObjectCreatedEvent`, `ObjectUpdatedEvent`, or `ObjectDeletedEvent`
- **AND** it MUST call `SubscriptionService.pushEvent()` with the action and object
- **AND** the event MUST be buffered in APCu with key `openregister_graphql_events`, including: `id` (unique), `action`, `timestamp`, `object` (uuid, register, schema, owner, data)
- **AND** for delete events, object `data` MUST be omitted

#### Scenario: SSE event delivery with polling
- **GIVEN** a client is connected to the SSE endpoint
- **WHEN** `GraphQLSubscriptionController.subscribe()` runs
- **THEN** it MUST set SSE headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `Connection: keep-alive`, `X-Accel-Buffering: no`
- **AND** it MUST poll for new events every 1 second for a maximum of 30 seconds
- **AND** each event MUST be formatted via `SubscriptionService.formatAsSSE()` as: `id: {id}\nevent: graphql.{action}\ndata: {json}\n\n`
- **AND** heartbeat comments (`: heartbeat\n\n`) MUST be sent every poll interval to keep the connection alive
- **AND** the controller MUST check `connection_aborted()` each cycle to detect client disconnection

#### Scenario: Subscribe with schema/register filters
- **GIVEN** a client connects with `GET /api/graphql/subscribe?schema=5&register=2`
- **WHEN** events are retrieved via `SubscriptionService.getEventsSince()`
- **THEN** only events matching the specified schema ID and register ID MUST be returned
- **AND** `filterEventStream()` MUST apply these filters before RBAC checking

#### Scenario: Reconnection via Last-Event-ID
- **GIVEN** a client reconnects with `Last-Event-ID: gql_abc123`
- **WHEN** `getEventsSince("gql_abc123")` scans the APCu buffer
- **THEN** only events AFTER the specified event ID MUST be returned (replay from last known position)
- **AND** the event buffer retains events for 5 minutes (`EVENT_TTL = 300`) with a maximum of 1000 events (`MAX_BUFFER_SIZE`)

#### Scenario: Subscription authorization enforcement
- **GIVEN** user `medewerker-1` is subscribed and an event fires for schema `vertrouwelijk`
- **WHEN** `SubscriptionService.verifyEventRBAC()` checks the event
- **THEN** it MUST load the schema via `SchemaMapper.find()` and call `PermissionHandler.hasPermission($schema, 'read')`
- **AND** events for unauthorized schemas MUST be silently filtered out

### Requirement: The GraphQL endpoint MUST include an interactive GraphiQL explorer

A GraphiQL IDE MUST be served at `/api/graphql/explorer` for developers to explore the schema and test queries. `GraphQLController.explorer()` MUST render a full-page HTML response with CDN-hosted GraphiQL.

#### Scenario: Access GraphQL IDE
- **GIVEN** an authenticated user navigates to `/api/graphql/explorer`
- **WHEN** `GraphQLController.explorer()` is called (annotated with `@NoAdminRequired`, `@NoCSRFRequired`)
- **THEN** a full-page HTML response MUST be returned loading GraphiQL v3 from `unpkg.com`
- **AND** React 18 and ReactDOM MUST be loaded from unpkg.com CDN
- **AND** the GraphiQL fetcher MUST be configured with the endpoint URL (via `IURLGenerator.linkToRoute('openregister.graphQL.execute')`) and include the CSRF `requesttoken` header
- **AND** `defaultEditorToolsVisibility` MUST be set to `true`

#### Scenario: Content Security Policy for explorer
- **GIVEN** the GraphiQL page loads external scripts from unpkg.com
- **WHEN** `explorer()` sets the Content Security Policy
- **THEN** `addAllowedScriptDomain('https://unpkg.com')` and `addAllowedStyleDomain('https://unpkg.com')` MUST be called
- **AND** inline scripts MUST use the CSP nonce from `ContentSecurityPolicyNonceManager`
- **AND** `allowEvalScript(true)` MUST be set for GraphiQL's internal code execution

#### Scenario: Explorer endpoint security
- **GIVEN** the explorer serves a full HTML page
- **THEN** the endpoint MUST require authentication (`@NoAdminRequired` but NOT `@PublicPage`)
- **AND** the GraphQL execution endpoint (`POST /api/graphql`) MUST be public (`@PublicPage`, `@CORS`) to support both authenticated and anonymous queries based on schema permissions

### Requirement: GraphQL errors MUST follow a structured format with machine-readable codes

Error responses MUST provide actionable information for developers while not leaking internal system details. `GraphQLErrorFormatter` MUST map exception types to standardized extension codes.

#### Scenario: Error format structure
- **GIVEN** any error occurs during GraphQL execution
- **THEN** the error MUST follow the format:
  ```json
  {
    "errors": [{
      "message": "Human-readable description",
      "path": ["query", "field", "subfield"],
      "locations": [{ "line": 2, "column": 3 }],
      "extensions": {
        "code": "FORBIDDEN|FIELD_FORBIDDEN|NOT_FOUND|VALIDATION_ERROR|QUERY_TOO_COMPLEX|RATE_LIMITED|INTROSPECTION_DISABLED|INTERNAL_ERROR|BAD_REQUEST"
      }
    }],
    "data": { ... }
  }
  ```
- **AND** partial success MUST be supported: data for authorized fields returned alongside errors for unauthorized fields

#### Scenario: Exception type mapping in GraphQLErrorFormatter
- **GIVEN** `GraphQLErrorFormatter.format()` receives a `GraphQL\Error\Error`
- **WHEN** the previous exception is `NotAuthorizedException`
- **THEN** `extensions.code` MUST be `FORBIDDEN`
- **WHEN** the previous exception is `ValidationException` or `CustomValidationException`
- **THEN** `extensions.code` MUST be `VALIDATION_ERROR`
- **WHEN** the error has explicit extensions (set in constructor)
- **THEN** the explicit code MUST be preserved
- **WHEN** the previous exception is any other type
- **THEN** `extensions.code` MUST be `INTERNAL_ERROR`

#### Scenario: Static error factory methods
- **GIVEN** `GraphQLErrorFormatter` provides static factory methods
- **THEN** `fieldForbidden($field, $path)` MUST create an error with code `FIELD_FORBIDDEN` and the field path
- **AND** `notFound($type, $id)` MUST create an error with code `NOT_FOUND` and message `"{type} with ID '{id}' not found"`

#### Scenario: HTTP status code mapping
- **GIVEN** `GraphQLController.execute()` processes a response
- **WHEN** the response has `data` (even with errors): HTTP 200
- **WHEN** the response has only `errors` and no `data`: HTTP 400
- **WHEN** the first error code is `RATE_LIMITED`: HTTP 429 with `Retry-After` header

#### Scenario: Invalid request body handling
- **GIVEN** a POST to `/api/graphql` with invalid JSON or missing `query` field
- **THEN** the controller MUST return HTTP 400 with `extensions.code: "BAD_REQUEST"`
- **AND** message: "Request body must be JSON with a 'query' field"

### Requirement: GraphQL resolver MUST reset state between requests

The `GraphQLResolver` MUST provide a `reset()` method to clear all per-request state, preventing data leakage between concurrent GraphQL operations.

#### Scenario: State reset between requests
- **GIVEN** a GraphQL query has been executed, populating `relationBuffer`, `relationCache`, and `partialErrors`
- **WHEN** `GraphQLService.execute()` calls `this.resolver.reset()` before generating the schema
- **THEN** `relationBuffer` MUST be cleared to an empty array
- **AND** `relationCache` MUST be cleared to an empty array
- **AND** `partialErrors` MUST be cleared to an empty array

#### Scenario: Resolver context creation
- **GIVEN** `GraphQLService.createContext()` is called for each execution
- **THEN** the context array MUST include references to: `objectService`, `permissionHandler`, `propertyRbac`, `auditTrailMapper`, `registerMapper`, `schemaMapper`, `schemaGenerator`, `operationName`, `request`, and an empty `errors` array

## Current Implementation Status

- **Fully implemented -- GraphQL service layer**: `GraphQLService` (`lib/Service/GraphQL/GraphQLService.php`) orchestrates query execution with rate limiting, introspection control, complexity analysis, and structured error handling.
- **Fully implemented -- auto-generated schema from registers**: `SchemaGenerator` (`lib/Service/GraphQL/SchemaGenerator.php`) auto-generates GraphQL types from register schema definitions, with helpers `TypeMapperHandler` and `CompositionHandler` extracted to manage complexity.
- **Fully implemented -- custom scalar types**: Six custom scalars: `DateTimeType`, `UuidType`, `UriType`, `EmailType`, `JsonType`, `UploadType` (all in `lib/Service/GraphQL/Scalar/`).
- **Fully implemented -- nested object resolution with DataLoader batching**: `GraphQLResolver` (`lib/Service/GraphQL/GraphQLResolver.php`) uses `Deferred` from webonyx/graphql-php with a `relationBuffer`/`relationCache` pattern, delegating to `RelationHandler.bulkLoadRelationshipsBatched()`.
- **Fully implemented -- query complexity analysis**: `QueryComplexityAnalyzer` (`lib/Service/GraphQL/QueryComplexityAnalyzer.php`) implements depth limiting and cost-based budgeting with configurable thresholds via app settings.
- **Fully implemented -- structured error formatting**: `GraphQLErrorFormatter` (`lib/Service/GraphQL/GraphQLErrorFormatter.php`) maps exception types to extension codes with static factory methods.
- **Fully implemented -- subscriptions via SSE**: `SubscriptionService` (`lib/Service/GraphQL/SubscriptionService.php`) buffers events in APCu. `GraphQLSubscriptionController` (`lib/Controller/GraphQLSubscriptionController.php`) delivers SSE with polling, filtering, and reconnection support. `GraphQLSubscriptionListener` (`lib/Listener/GraphQLSubscriptionListener.php`) bridges object CRUD events.
- **Fully implemented -- controller and routes**: `GraphQLController` (`lib/Controller/GraphQLController.php`) exposes `POST /api/graphql` (public+CORS), `GET /api/graphql/explorer` (authenticated), and `GET /api/graphql/subscribe` (authenticated+CORS).
- **Fully implemented -- RBAC integration**: Schema-level via `PermissionHandler.checkPermission()`, property-level via `PropertyRbacHandler.filterReadableProperties()` and `getUnauthorizedProperties()`.
- **Fully implemented -- JSON Schema composition**: `CompositionHandler` handles `allOf` (merged fields), `oneOf` (UnionType), `anyOf` (InterfaceType).
- **Fully implemented -- audit trail integration**: `_auditTrail` field on every object type, delegating to `AuditTrailMapper.findAll()`.
- **Fully implemented -- multi-tenancy**: `resolveList()` passes `_multitenancy: true` to `searchObjectsPaginated()`.
- **Tests present**: Unit tests in `tests/Unit/Service/GraphQL/` (SchemaGeneratorTest, GraphQLErrorFormatterTest, QueryComplexityAnalyzerTest, ScalarTypesTest) and integration test in `tests/Service/GraphQLIntegrationTest.php`. Postman collection at `tests/postman/openregister-graphql-tests.postman_collection.json`.

## Standards & References

- GraphQL specification (https://spec.graphql.org/)
- Relay specification for cursor-based pagination (https://relay.dev/graphql/connections.htm)
- RFC 5321 for email validation (Email scalar)
- RFC 4122 for UUID v4 format (UUID scalar)
- ISO 8601 for DateTime serialization
- GraphQL multipart request spec for file uploads (https://github.com/jaydenseric/graphql-multipart-request-spec)
- `webonyx/graphql-php` library (PHP GraphQL implementation, per `composer.json`)

## Competitive Analysis Summary

| Capability | Directus | Strapi | OpenRegister |
|-----------|----------|--------|-------------|
| Auto-generated schema | Runtime from DB schema | From content types (shadowCRUD) | From register schemas via SchemaGenerator |
| Queries (single + list) | Yes | Yes | Yes |
| Mutations (CRUD) | Yes + batch | Yes | Yes (no batch) |
| Subscriptions | WebSocket (graphql-ws) | Not built-in | SSE (APCu buffer) |
| Filtering operators | 30+ operators | Mirrors REST operators | Mirrors REST (eq, neq, gt, gte, lt, lte, like, in, notIn, etc.) |
| Pagination | Offset only | Page-based | Offset + Relay cursor |
| Aggregation | `_aggregated` suffix with groupBy | Not built-in | Via facets |
| Query depth limiting | Not documented | `depthLimit: 7` | Configurable (default 10) + cost budgeting |
| Schema extension | N/A (auto-generated) | Extension service (shadowCRUD disable) | N/A (auto-generated) |
| Introspection control | Always on | `playgroundAlways` config | 3-tier: enabled/disabled/authenticated |
| File uploads via GraphQL | Not supported | Not documented | Upload scalar (multipart spec) |
| RBAC in GraphQL | Permission filters on types/fields | Role-based content access | Schema-level + property-level RBAC |
| Union types (composition) | M2A native | Not documented | oneOf -> UnionType, anyOf -> InterfaceType |
| Playground/IDE | Not built-in (use external) | GraphQL Playground | GraphiQL v3 at /api/graphql/explorer |
