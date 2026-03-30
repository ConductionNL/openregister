# graphql-api Specification

---
status: implemented
---

## Purpose
Provide an auto-generated GraphQL API alongside the existing REST API for register data. The GraphQL schema MUST be derived from register schema definitions, support queries with nested object resolution, mutations for CRUD operations, and subscriptions for real-time updates. This improves developer experience by reducing over-fetching and enabling efficient nested data retrieval.

The GraphQL layer MUST reuse existing OpenRegister services — PermissionHandler for RBAC, PropertyRbacHandler for field-level security, RelationHandler for nested resolution, AuditTrailMapper for logging, SecurityService for rate limiting, and MagicMapper for cross-register queries — rather than reimplementing any of these concerns.

**Source**: Gap identified in cross-platform analysis; three platforms offer GraphQL APIs.

## ADDED Requirements

### Requirement: The GraphQL schema MUST be auto-generated from register schemas
Each register schema MUST automatically produce corresponding GraphQL types, queries, and mutations. Type generation MUST follow the same JSON Schema → SQL type mapping used by MagicMapper, ensuring consistency between REST and GraphQL responses.

#### Scenario: Generate GraphQL type from schema
- GIVEN a register schema `meldingen` with properties: title (string), status (string), priority (enum), created (datetime)
- WHEN the GraphQL schema is generated
- THEN a GraphQL type `Melding` MUST be created with fields matching the schema properties
- AND property types MUST be mapped: string -> String, integer -> Int, number -> Float, boolean -> Boolean, datetime -> DateTime

#### Scenario: Generate queries
- GIVEN schema `meldingen` exists
- THEN the following queries MUST be generated:
  - `melding(id: ID!): Melding` - fetch single object
  - `meldingen(filter: MeldingenFilter, sort: MeldingenSort, first: Int, after: String, offset: Int): MeldingenConnection` - list with pagination

#### Scenario: Generate mutations
- GIVEN schema `meldingen` exists
- THEN the following mutations MUST be generated:
  - `createMelding(input: CreateMeldingInput!): Melding`
  - `updateMelding(id: ID!, input: UpdateMeldingInput!): Melding`
  - `deleteMelding(id: ID!): Boolean`

#### Scenario: Schema changes regenerate GraphQL types
- GIVEN schema `meldingen` has a GraphQL type `Melding`
- WHEN a property `urgentie` (integer) is added to the schema
- THEN the `Melding` type MUST be regenerated to include `urgentie: Int`
- AND existing queries using `Melding` without `urgentie` MUST continue to work

#### Scenario: allOf/oneOf/anyOf composition maps to GraphQL
- GIVEN schema `zaak` uses `allOf` to compose schemas `basisZaak` and `uitgebreideZaak`
- WHEN the GraphQL schema is generated
- THEN a `Zaak` type MUST include fields from both composed schemas
- AND for `oneOf` compositions, a GraphQL union type MUST be generated
- AND for `anyOf` compositions, a GraphQL interface MUST be generated

### Requirement: Custom scalar types MUST map to OpenRegister property formats
GraphQL MUST expose custom scalars matching the JSON Schema format annotations that MagicMapper uses for column typing.

#### Scenario: DateTime scalar
- GIVEN a schema property with `type: "string", format: "date-time"`
- WHEN the GraphQL type is generated
- THEN the field MUST use a `DateTime` scalar that serializes as ISO 8601
- AND input filters MUST accept ISO 8601 strings and support range comparisons

#### Scenario: UUID scalar
- GIVEN a schema property with `type: "string", format: "uuid"`
- WHEN the GraphQL type is generated
- THEN the field MUST use a `UUID` scalar that validates UUID v4 format
- AND the `id` argument on single-object queries MUST accept UUID values

#### Scenario: Email scalar
- GIVEN a schema property with `type: "string", format: "email"`
- WHEN the GraphQL type is generated
- THEN the field MUST use an `Email` scalar that validates RFC 5321 format
- AND invalid email values in mutations MUST produce a validation error

#### Scenario: URI scalar
- GIVEN a schema property with `type: "string", format: "uri"`
- WHEN the GraphQL type is generated
- THEN the field MUST use a `URI` scalar

#### Scenario: JSON scalar for unstructured data
- GIVEN a schema property with `type: "object"` without `$ref` (generic object)
- OR a schema property with `type: "array"` containing mixed items
- WHEN the GraphQL type is generated
- THEN the field MUST use a `JSON` scalar that accepts arbitrary JSON

#### Scenario: File/Upload scalar
- GIVEN a schema property configured as a file field via `objectConfiguration`
- WHEN the GraphQL type is generated
- THEN the field MUST use a `Upload` scalar for mutations (following the GraphQL multipart request spec)
- AND the field MUST return a `File` type in queries with fields: `filename`, `mimeType`, `size`, `url`
- AND file upload MUST reuse `FilePropertyHandler` including MIME validation and executable blocking

### Requirement: GraphQL MUST support nested object resolution via DataLoader batching
References between schemas MUST be resolvable as nested objects in a single query. Resolution MUST use the existing RelationHandler batch-loading strategy to prevent N+1 queries.

#### Scenario: Resolve nested references with batching
- GIVEN schema `orders` with property `klant` referencing schema `klanten`
- AND a query fetches 20 orders with their klant
- WHEN the GraphQL resolver executes
- THEN klant resolution MUST be batched: all 20 klant UUIDs collected and loaded in a single query
- AND the resolver MUST use RelationHandler's `bulkLoadRelationshipsBatched()` (batch size 50)
- AND the ultra-preload cache MUST be populated for sub-resolvers

#### Scenario: Resolve array of references
- GIVEN schema `dossiers` with property `documenten` referencing an array of `document` objects
- WHEN a client queries `dossier { documenten { filename type } }`
- THEN all referenced documents MUST be resolved inline
- AND array relations MUST respect the RelationHandler circuit breaker (max 200 IDs per request, max 10 items per array property per object)

#### Scenario: Depth limiting prevents infinite recursion
- GIVEN schema `persoon` with a self-referencing property `manager` referencing `persoon`
- AND the schema's `maxDepth` is set to 3
- WHEN a client queries deeply nested manager chains
- THEN resolution MUST stop at depth 3 and return `null` for deeper levels
- AND no error MUST be raised (graceful truncation)

#### Scenario: Cross-register relation resolution
- GIVEN schema `aanvraag` in register `vergunningen` references schema `persoon` in register `basisregistratie`
- WHEN a client queries `aanvraag { aanvrager { naam bsn } }`
- THEN the resolver MUST use MagicMapper's cross-register table lookup
- AND RBAC MUST be checked independently for each register/schema combination

#### Scenario: Bidirectional relationships via inversedBy
- GIVEN schema `project` has property `taken` with inversedBy pointing to `taak.project`
- WHEN a client queries `project { taken { titel status } }`
- THEN the resolver MUST use RelationHandler's `applyInversedByFilter()` to find all taak objects referencing this project
- AND results MUST be paginated within the nested field

### Requirement: GraphQL MUST support filtering and sorting matching the REST API
List queries MUST support the full filtering, sorting, and search capabilities of the REST API including faceted search.

#### Scenario: Filter by property value
- GIVEN a query: `meldingen(filter: { status: "in_behandeling" }) { title }`
- THEN only meldingen with status `in_behandeling` MUST be returned

#### Scenario: Filter with operators
- GIVEN a query: `meldingen(filter: { created: { gte: "2025-01-01", lt: "2025-07-01" } }) { title }`
- THEN only meldingen created in the first half of 2025 MUST be returned
- AND the operator set MUST match MagicSearchHandler capabilities: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `notIn`, `isNull`, `isNotNull`

#### Scenario: Full-text search
- GIVEN a query: `meldingen(search: "wateroverlast") { title }`
- THEN the search MUST delegate to MagicSearchHandler's full-text search (ILIKE across string properties)
- AND the query MUST support an optional `fuzzy: true` argument for PostgreSQL trigram similarity scoring
- AND results with fuzzy search MUST include a `_relevance` field (0-100)

#### Scenario: Sort results
- GIVEN a query: `meldingen(sort: { field: "created", order: DESC }) { title created }`
- THEN results MUST be sorted by created date descending

#### Scenario: Faceted search
- GIVEN a query: `meldingen(facets: ["status", "priority"]) { title }`
- THEN the response MUST include a `facets` field with value counts per requested field
- AND facets MUST be calculated on the full filtered dataset, independent of pagination (matching FacetHandler behavior)
- AND the response MUST include `facetable` listing all fields that support faceting

#### Scenario: Metadata filtering via @self
- GIVEN a query using `selfFilter: { owner: "user-1", organisation: "gemeente-tilburg" }`
- THEN the filter MUST apply to object metadata columns (`_owner`, `_organisation`) rather than schema properties
- AND this MUST match the REST API's `@self[owner]=user-1` behavior

### Requirement: GraphQL MUST support dual pagination modes
The API MUST support both offset-based pagination (matching the REST API) and Relay-style cursor pagination for efficient infinite scrolling and real-time list stability.

#### Scenario: Offset-based pagination
- GIVEN 100 meldingen objects
- AND a query: `meldingen(first: 10, offset: 20) { title }`
- THEN exactly 10 objects MUST be returned starting from offset 20
- AND the connection MUST include `totalCount`, `page`, and `pages`

#### Scenario: Relay-style cursor pagination
- GIVEN 100 meldingen objects
- AND a query: `meldingen(first: 10, after: "cursor-abc") { edges { cursor node { title } } pageInfo { hasNextPage endCursor } }`
- THEN 10 objects MUST be returned after the cursor position
- AND `pageInfo.hasNextPage` MUST be `true` if more results exist
- AND `pageInfo.endCursor` MUST be an opaque cursor encoding the last result's position
- AND cursors MUST be stable across concurrent inserts (using UUID-based ordering as tiebreaker)

#### Scenario: Connection type structure
- GIVEN any schema `meldingen`
- THEN the connection type MUST follow the Relay specification:
  ```graphql
  type MeldingenConnection {
    edges: [MeldingenEdge!]!
    pageInfo: PageInfo!
    totalCount: Int!
    facets: JSON
    facetable: [String!]
  }
  type MeldingenEdge {
    cursor: String!
    node: Melding!
  }
  type PageInfo {
    hasNextPage: Boolean!
    hasPreviousPage: Boolean!
    startCursor: String
    endCursor: String
  }
  ```

### Requirement: GraphQL MUST enforce schema-level RBAC via PermissionHandler
Authorization policies MUST apply to GraphQL queries and mutations identically to the REST API, delegating all checks to the existing PermissionHandler service.

#### Scenario: Unauthorized schema access
- GIVEN schema `vertrouwelijk` has authorization `{ "read": ["geautoriseerd-personeel"] }`
- AND user `medewerker-1` is not in group `geautoriseerd-personeel`
- WHEN they query `vertrouwelijk { title }`
- THEN the system MUST return a GraphQL error with `extensions.code: "FORBIDDEN"`
- AND PermissionHandler.checkPermission() MUST be called with action `read`

#### Scenario: Mutation authorization
- GIVEN schema `besluiten` has authorization `{ "create": ["behandelaars"], "update": ["behandelaars"], "delete": ["managers"] }`
- AND user `medewerker-1` is in group `behandelaars` but not `managers`
- WHEN they attempt `deleteBesluit(id: "...")`
- THEN the mutation MUST be rejected with a FORBIDDEN error
- AND `createBesluit` and `updateBesluit` MUST succeed

#### Scenario: Cross-schema authorization in nested queries
- GIVEN user `medewerker-1` can read `orders` but not `klanten`
- WHEN they query `order { title klant { naam } }`
- THEN the `klant` field MUST return null
- AND a partial error MUST appear in the `errors` array with path `["order", "klant"]`
- AND the rest of the query MUST still return data (partial success)

#### Scenario: Conditional authorization with organisation matching
- GIVEN schema `dossiers` has authorization `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- AND user belongs to group `behandelaars` in organisation `gemeente-tilburg`
- WHEN they query dossiers from `gemeente-utrecht`
- THEN those dossiers MUST be filtered out by PermissionHandler's `evaluateMatchConditions()`
- AND no error MUST be raised (silently excluded from results, matching REST behavior)

#### Scenario: Admin bypass
- GIVEN user is in the `admin` group
- WHEN they query any schema
- THEN all RBAC checks MUST be bypassed (matching PermissionHandler's admin override)

### Requirement: GraphQL MUST enforce property-level RBAC via PropertyRbacHandler
Individual fields within a type MUST respect the property-level authorization defined on schemas, using the existing PropertyRbacHandler service.

#### Scenario: Property read authorization
- GIVEN schema `inwoners` has property `bsn` with authorization `{ "read": [{ "group": "bsn-geautoriseerd" }] }`
- AND user `medewerker-1` is NOT in group `bsn-geautoriseerd`
- WHEN they query `inwoner { naam bsn adres }`
- THEN `bsn` MUST resolve to `null`
- AND a partial error MUST appear: `{ "message": "Not authorized to read field 'bsn'", "path": ["inwoner", "bsn"], "extensions": { "code": "FIELD_FORBIDDEN" } }`
- AND `naam` and `adres` MUST still be returned

#### Scenario: Property write authorization on mutations
- GIVEN schema `inwoners` has property `interneAantekening` with authorization `{ "update": [{ "group": "redacteuren" }] }`
- AND user `medewerker-1` is NOT in group `redacteuren`
- WHEN they attempt `updateInwoner(id: "...", input: { interneAantekening: "nieuwe tekst" })`
- THEN the mutation MUST be rejected with error `extensions.code: "FIELD_FORBIDDEN"`
- AND PropertyRbacHandler.getUnauthorizedProperties() MUST be called

#### Scenario: Conditional property authorization with organisation match
- GIVEN property `interneAantekening` has authorization `{ "read": [{ "group": "redacteuren", "match": { "_organisation": "$organisation" } }] }`
- AND user is in `redacteuren` for `gemeente-tilburg`
- WHEN they query an inwoner from `gemeente-utrecht`
- THEN `interneAantekening` MUST resolve to `null` (organisation mismatch)

#### Scenario: GraphQL introspection respects field visibility
- GIVEN user `medewerker-1` cannot read property `bsn` on schema `inwoners`
- WHEN they run an introspection query on type `Inwoner`
- THEN `bsn` MUST still appear in the introspection result (schema is public)
- BUT the field description MUST indicate it requires authorization
- AND querying it MUST return null with a partial error (authorization enforced at resolution time, not schema time)

### Requirement: GraphQL MUST log operations to the audit trail
All GraphQL queries and mutations MUST produce audit trail entries using the existing AuditTrailMapper, matching the same detail level as REST API operations.

#### Scenario: Mutation creates audit trail entry
- GIVEN a user executes `createMelding(input: { title: "Wateroverlast", status: "nieuw" })`
- THEN an AuditTrail entry MUST be created with:
  - `action: "create"`
  - `changed`: JSON showing `{ "title": { "old": null, "new": "Wateroverlast" }, "status": { "old": null, "new": "nieuw" } }`
  - `user`: the authenticated user ID
  - `session`, `request`, `ipAddress`: captured from the HTTP context
  - `registerUuid`, `schemaUuid`, `objectUuid`: linking to the affected entities
- AND the entry MUST include `organisationId` and `confidentiality` from the schema configuration

#### Scenario: Update mutation records field-level changes
- GIVEN melding `melding-1` has `status: "nieuw"`
- AND a user executes `updateMelding(id: "melding-1", input: { status: "in_behandeling" })`
- THEN the audit trail `changed` field MUST contain `{ "status": { "old": "nieuw", "new": "in_behandeling" } }`
- AND only changed fields MUST appear in the diff (unchanged fields excluded)

#### Scenario: Delete mutation creates audit trail
- GIVEN a user executes `deleteMelding(id: "melding-1")`
- THEN an AuditTrail entry MUST be created with `action: "delete"`
- AND referential integrity cascades MUST also produce audit entries (matching ReferentialIntegrityService behavior)

#### Scenario: Read queries optionally log to audit trail
- GIVEN a schema `vertrouwelijk` is configured with `auditReads: true`
- AND a user queries `vertrouwelijkDocument(id: "doc-1") { inhoud }`
- THEN an AuditTrail entry MUST be created with `action: "read"`
- AND schemas without `auditReads` MUST NOT generate read audit entries (matching current REST behavior)

#### Scenario: Audit trail includes GraphQL operation context
- GIVEN a user executes a named query `query GetMeldingDetails($id: ID!) { melding(id: $id) { title status } }`
- THEN the audit trail entry MUST include the GraphQL operation name in a metadata field
- AND batch queries (multiple root fields) MUST produce separate audit entries per affected object

#### Scenario: Query audit trail via GraphQL
- GIVEN a user has access to object `melding-1`
- WHEN they query `melding(id: "melding-1") { _auditTrail(last: 10) { action user changed created } }`
- THEN the last 10 audit trail entries MUST be returned
- AND this MUST delegate to AuditTrailMapper.findAll() with the object UUID filter
- AND audit trail entries MUST include GDPR compliance fields: `processingActivityId`, `confidentiality`, `retentionPeriod`

### Requirement: Query complexity analysis MUST prevent resource abuse
The GraphQL endpoint MUST analyze query complexity before execution to prevent denial-of-service through deeply nested or excessively broad queries. This complements the existing SecurityService rate limiting.

#### Scenario: Depth limiting
- GIVEN a system-wide maximum query depth of 10
- AND a client submits a query nested 15 levels deep
- THEN the query MUST be rejected before execution with error `extensions.code: "QUERY_TOO_COMPLEX"`
- AND the error MUST include `extensions.maxDepth: 10` and `extensions.actualDepth: 15`

#### Scenario: Cost-based complexity budgeting
- GIVEN each field has a default cost of 1 and each nested object resolver has a cost of 10
- AND each list query multiplies child costs by the `first` argument (or default limit 20)
- AND the maximum query cost budget is 10000
- WHEN a client submits: `meldingen(first: 100) { klant { orders(first: 50) { items { product { naam } } } } }`
- THEN the estimated cost MUST be calculated as: 100 × (10 + 50 × (10 + 1 × (10 + 1))) = 100 × (10 + 50 × 21) = 106000
- AND the query MUST be rejected with `extensions.estimatedCost: 106000` and `extensions.maxCost: 10000`

#### Scenario: Cost budget communicated in response
- GIVEN a query executes successfully with estimated cost 3500
- THEN the response `extensions` MUST include: `{ "complexity": { "estimated": 3500, "max": 10000, "depth": 4, "maxDepth": 10 } }`

#### Scenario: Per-schema cost overrides
- GIVEN schema `documenten` contains large text fields and is expensive to query
- AND the schema configures `graphqlCost: 25` (instead of default 10)
- WHEN cost is calculated for queries involving `documenten`
- THEN the elevated cost MUST be used in the complexity budget

#### Scenario: Rate limiting integration with SecurityService
- GIVEN the existing SecurityService tracks requests via APCu
- WHEN a client exceeds the GraphQL rate limit
- THEN the response MUST include `extensions.code: "RATE_LIMITED"` and a `Retry-After` header
- AND rate limits MUST be tracked per authenticated user, falling back to per-IP for anonymous requests
- AND the progressive delay mechanism (2s → 4s → 8s → ... → 60s max) MUST apply to repeated violations

### Requirement: Introspection MUST be controllable per environment
Schema introspection MUST be configurable to restrict exposure in production while remaining open in development, aligned with the existing tiered MCP discovery approach.

#### Scenario: Introspection enabled in development
- GIVEN the app configuration `graphql_introspection` is set to `enabled`
- WHEN a client sends an introspection query (`__schema { types { name } }`)
- THEN the full schema MUST be returned including all types, fields, arguments, and directives

#### Scenario: Introspection disabled in production
- GIVEN the app configuration `graphql_introspection` is set to `disabled`
- WHEN a client sends an introspection query
- THEN the query MUST be rejected with error `extensions.code: "INTROSPECTION_DISABLED"`
- AND regular queries MUST continue to work normally

#### Scenario: Introspection restricted to authenticated users
- GIVEN the app configuration `graphql_introspection` is set to `authenticated`
- WHEN an anonymous client sends an introspection query
- THEN the query MUST be rejected
- AND an authenticated user MUST receive the full schema
- AND this mirrors the MCP discovery tier model (tier 1 public, tier 2 authenticated)

#### Scenario: Schema documentation in GraphQL descriptions
- GIVEN a schema `meldingen` with property `status` that has a JSON Schema `description: "Huidige status van de melding"`
- WHEN the GraphQL schema is generated
- THEN the field MUST include the description: `status: String @deprecated(reason: "...") "Huidige status van de melding"`
- AND property-level authorization requirements MUST be noted in descriptions: `"Requires group: bsn-geautoriseerd"`

### Requirement: Cross-register schema stitching MUST provide a unified graph
All registers and schemas MUST be queryable through a single unified GraphQL schema, with cross-register references resolved transparently. This MUST leverage MagicMapper's cross-table search capabilities.

#### Scenario: Unified root queries across registers
- GIVEN register `basisregistratie` with schema `personen` and register `vergunningen` with schema `aanvragen`
- WHEN the GraphQL schema is generated
- THEN both `persoon` and `aanvraag` queries MUST be available at the root level
- AND each type MUST include a `_register` metadata field identifying its source register

#### Scenario: Cross-register nested resolution
- GIVEN `aanvraag` in register `vergunningen` has property `aanvrager` referencing `persoon` in register `basisregistratie`
- WHEN a client queries `aanvraag { titel aanvrager { naam geboortedatum } }`
- THEN the resolver MUST use MagicMapper's `getExistingRegisterSchemaTables()` to locate the personen table
- AND the cross-register join MUST be transparent to the client

#### Scenario: Register-scoped queries
- GIVEN a client wants to query only within register `basisregistratie`
- THEN a `register(id: ID!)` root query MUST be available: `register(id: "basisregistratie") { personen { naam } adressen { straat } }`
- AND this scoped query MUST apply the register's default RBAC and multi-tenancy filters

#### Scenario: Schema composition across registers
- GIVEN schema `zaakDossier` uses `allOf` referencing schemas from two different registers
- WHEN the GraphQL type is generated
- THEN fields from both referenced schemas MUST be merged into a single `ZaakDossier` type
- AND field-level authorization MUST be evaluated per source schema

#### Scenario: Relationship traversal queries
- GIVEN object `persoon-1` is referenced by multiple objects across registers
- WHEN a client queries `persoon(id: "persoon-1") { _usedBy { ... on Aanvraag { titel } ... on Melding { status } } }`
- THEN the resolver MUST use RelationHandler's `getUsedBy()` to find all referencing objects
- AND results MUST be returned as a GraphQL union type
- AND each result MUST include its source register and schema in the `_self` metadata

### Requirement: The GraphQL endpoint MUST include an interactive explorer
A GraphiQL or similar IDE MUST be available for developers to explore the schema and test queries.

#### Scenario: Access GraphQL IDE
- GIVEN an authenticated user navigates to /api/graphql/explorer
- THEN a GraphQL IDE MUST be displayed with:
  - Schema documentation browser
  - Query editor with autocomplete
  - Query execution with formatted results
  - Query history and saved queries

#### Scenario: Explorer respects authentication context
- GIVEN user `medewerker-1` opens the GraphQL explorer
- THEN the documentation MUST show only schemas the user has at least read access to
- AND attempting queries on unauthorized schemas MUST show inline errors
- AND the explorer MUST display the user's current complexity budget usage

### Requirement: GraphQL MUST support subscriptions for real-time updates
Subscriptions MUST be available for receiving object change events, integrated with the audit trail system for event sourcing.

#### Scenario: Subscribe to object changes
- GIVEN a subscription: `subscription { onMeldingUpdated { id title status _auditAction } }`
- WHEN melding `melding-1` is updated
- THEN the subscriber MUST receive the updated object data via WebSocket
- AND `_auditAction` MUST contain the action type (`create`, `update`, `delete`)
- AND the subscription MUST respect schema-level RBAC (only users with read permission receive events)

#### Scenario: Subscribe with filters
- GIVEN a subscription: `subscription { onMeldingUpdated(filter: { status: "urgent" }) { id title } }`
- THEN only updates to meldingen with status `urgent` MUST trigger notifications
- AND filter evaluation MUST happen server-side to minimize WebSocket traffic

#### Scenario: Subscribe to cross-register events
- GIVEN a subscription on `aanvraag` that references `persoon`
- WHEN the referenced `persoon` is updated
- THEN subscribers watching the `aanvraag` MUST optionally receive a notification if `includeRelatedChanges: true` is set

#### Scenario: Subscription authorization enforcement
- GIVEN user `medewerker-1` subscribes to schema `vertrouwelijk` without read permission
- THEN the subscription MUST be rejected immediately with a FORBIDDEN error
- AND if a user's permissions are revoked while subscribed, the subscription MUST be terminated with a `PERMISSION_REVOKED` close reason

### Requirement: Multi-tenancy MUST be enforced on all GraphQL operations
All GraphQL queries, mutations, and subscriptions MUST respect the existing multi-tenancy model implemented via MultiTenancyTrait.

#### Scenario: Organisation scoping
- GIVEN user `medewerker-1` has active organisation `gemeente-tilburg`
- WHEN they query `meldingen { title }`
- THEN only meldingen belonging to `gemeente-tilburg` MUST be returned
- AND the organisation filter MUST be applied at the MagicMapper query level (not post-filter)

#### Scenario: Cross-organisation access for parent orgs
- GIVEN organisation `gemeente-tilburg` is a child of `provincie-brabant`
- AND user `medewerker-2` has active organisation `provincie-brabant`
- WHEN they query meldingen
- THEN meldingen from both `provincie-brabant` and `gemeente-tilburg` MUST be visible
- AND unpublished items from child orgs MUST also be visible (matching MultiTenancyTrait behavior)

#### Scenario: Published items bypass multi-tenancy
- GIVEN an object is marked as `published: true`
- AND the schema allows public read access
- WHEN any user queries the object
- THEN it MUST be visible regardless of the user's active organisation

### Requirement: GraphQL errors MUST follow a structured format
Error responses MUST provide actionable information for developers while not leaking internal system details.

#### Scenario: Structured error response
- GIVEN any error occurs during GraphQL execution
- THEN the error MUST follow this format:
  ```json
  {
    "errors": [{
      "message": "Human-readable description",
      "path": ["query", "field", "subfield"],
      "locations": [{ "line": 2, "column": 3 }],
      "extensions": {
        "code": "FORBIDDEN|FIELD_FORBIDDEN|NOT_FOUND|VALIDATION_ERROR|QUERY_TOO_COMPLEX|RATE_LIMITED|INTROSPECTION_DISABLED|INTERNAL_ERROR",
        "details": {}
      }
    }],
    "data": { ... }
  }
  ```
- AND partial success MUST be supported: data for authorized fields returned alongside errors for unauthorized fields

#### Scenario: Validation errors map from schema validation
- GIVEN a mutation `createMelding(input: { title: "" })` where title has `minLength: 1`
- THEN the error MUST include `extensions.code: "VALIDATION_ERROR"` and `extensions.details.field: "title"` and `extensions.details.constraint: "minLength"`
- AND validation MUST reuse the existing JSON Schema validation from SaveObject

### Current Implementation Status
- **Fully implemented — GraphQL service layer**: `GraphQLService` (`lib/Service/GraphQL/GraphQLService.php`) handles query execution with APCu schema caching.
- **Fully implemented — auto-generated schema from registers**: `SchemaGenerator` (`lib/Service/GraphQL/SchemaGenerator.php`) auto-generates GraphQL types from register schema definitions, including queries and mutations.
- **Fully implemented — custom scalar types**: Six custom scalars are implemented: `DateTimeType` (`lib/Service/GraphQL/Scalar/DateTimeType.php`), `UuidType`, `UriType`, `EmailType`, `JsonType`, and `UploadType` (all in `lib/Service/GraphQL/Scalar/`).
- **Fully implemented — nested object resolution with DataLoader batching**: `GraphQLResolver` (`lib/Service/GraphQL/GraphQLResolver.php`) uses a DataLoader buffer for batch-loading relation UUIDs, integrating with `RelationHandler`.
- **Fully implemented — query complexity analysis**: `QueryComplexityAnalyzer` (`lib/Service/GraphQL/QueryComplexityAnalyzer.php`) implements depth limiting and cost-based budgeting.
- **Fully implemented — structured error formatting**: `GraphQLErrorFormatter` (`lib/Service/GraphQL/GraphQLErrorFormatter.php`) formats errors with extension codes.
- **Fully implemented — subscriptions**: `SubscriptionService` (`lib/Service/GraphQL/SubscriptionService.php`) and `GraphQLSubscriptionController` (`lib/Controller/GraphQLSubscriptionController.php`) handle real-time subscriptions. `GraphQLSubscriptionListener` (`lib/Listener/GraphQLSubscriptionListener.php`) listens for object change events.
- **Fully implemented — controller and routes**: `GraphQLController` (`lib/Controller/GraphQLController.php`) exposes the GraphQL endpoint. Routes registered in `appinfo/routes.php`.
- **Fully implemented — RBAC integration**: The resolver integrates with `PermissionHandler` and `PropertyRbacHandler` for schema-level and field-level authorization.
- **Tests present**: Unit tests in `tests/Unit/Service/GraphQL/` (SchemaGeneratorTest, GraphQLErrorFormatterTest, QueryComplexityAnalyzerTest, ScalarTypesTest) and integration test in `tests/Service/GraphQLIntegrationTest.php`. Postman collection at `tests/postman/openregister-graphql-tests.postman_collection.json`.
- **Partially implemented — introspection control**: Needs verification of per-environment introspection toggling via app configuration.
- **Partially implemented — multi-tenancy enforcement**: Needs verification that `MultiTenancyTrait` is applied at the GraphQL resolver level.

### Standards & References
- GraphQL specification (https://spec.graphql.org/)
- Relay specification for cursor-based pagination (https://relay.dev/graphql/connections.htm)
- RFC 5321 for email validation (Email scalar)
- RFC 4122 for UUID v4 format (UUID scalar)
- ISO 8601 for DateTime serialization
- GraphQL multipart request spec for file uploads (https://github.com/jaydenseric/graphql-multipart-request-spec)
- webonyx/graphql-php library (used as the PHP GraphQL implementation, per `composer.json`)

### Specificity Assessment
- **Highly specific and largely implemented**: This is one of the most detailed specs, with comprehensive scenarios covering type generation, pagination, RBAC, audit trailing, subscriptions, complexity analysis, and cross-register stitching.
- **Sufficient for implementation**: All major requirements have corresponding implementation code.
- **Open questions**:
  - Is the GraphiQL/explorer IDE accessible at `/api/graphql/explorer` as specified, or is it at a different path?
  - Are Relay-style cursor pagination and offset pagination both fully functional?
  - How does `auditReads` configuration work for read audit trail entries in GraphQL context?

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

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: Full GraphQL stack including `GraphQLController`, `GraphQLService`, `SchemaGenerator` (auto-generates types from register schemas), `QueryComplexityAnalyzer` (depth/cost budgeting), `GraphQLErrorFormatter`, `SubscriptionService` (SSE-based real-time updates), and `GraphQLSubscriptionListener`. Six custom scalar types (DateTime, UUID, URI, Email, JSON, Upload) are implemented. RBAC enforced via `PermissionHandler` and `PropertyRbacHandler`.
- **Nextcloud Core Integration**: Uses `IBootstrap` for service registration in the DI container. Routes registered via `appinfo/routes.php`. The `GraphQLSubscriptionListener` listens for typed events extending the `OCP\EventDispatcher\Event` base class. Rate limiting integrates with APCu via `SecurityService`. Consider implementing `IWebhookCompatibleEvent` on GraphQL mutation events to enable native Nextcloud webhook forwarding.
- **Recommendation**: Mark as implemented. Consider adding `IWebhookCompatibleEvent` support on mutation events for deeper NC webhook integration, and verify multi-tenancy enforcement via `MultiTenancyTrait` at the resolver level.
