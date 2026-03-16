# graphql-api Specification

## Purpose
Provide an auto-generated GraphQL API alongside the existing REST API for register data. The GraphQL schema MUST be derived from register schema definitions, support queries with nested object resolution, mutations for CRUD operations, and subscriptions for real-time updates. This improves developer experience by reducing over-fetching and enabling efficient nested data retrieval.

**Source**: Gap identified in cross-platform analysis; three platforms offer GraphQL APIs.

## ADDED Requirements

### Requirement: The GraphQL schema MUST be auto-generated from register schemas
Each register schema MUST automatically produce corresponding GraphQL types, queries, and mutations.

#### Scenario: Generate GraphQL type from schema
- GIVEN a register schema `meldingen` with properties: title (string), status (string), priority (enum), created (datetime)
- WHEN the GraphQL schema is generated
- THEN a GraphQL type `Melding` MUST be created with fields matching the schema properties
- AND property types MUST be mapped: string -> String, integer -> Int, number -> Float, boolean -> Boolean, datetime -> DateTime

#### Scenario: Generate queries
- GIVEN schema `meldingen` exists
- THEN the following queries MUST be generated:
  - `melding(id: ID!): Melding` - fetch single object
  - `meldingen(filter: MeldingenFilter, sort: MeldingenSort, first: Int, offset: Int): MeldingenConnection` - list with pagination

#### Scenario: Generate mutations
- GIVEN schema `meldingen` exists
- THEN the following mutations MUST be generated:
  - `createMelding(input: CreateMeldingInput!): Melding`
  - `updateMelding(id: ID!, input: UpdateMeldingInput!): Melding`
  - `deleteMelding(id: ID!): Boolean`

### Requirement: GraphQL MUST support nested object resolution
References between schemas MUST be resolvable as nested objects in a single query.

#### Scenario: Resolve nested references
- GIVEN schema `orders` with property `klant` referencing schema `klanten`
- WHEN a client queries `order(id: "order-1") { title klant { naam email } }`
- THEN the response MUST include the resolved klant data inline
- AND only one API call MUST be needed (no N+1 queries on the client)

#### Scenario: Resolve array of references
- GIVEN schema `dossiers` with property `documenten` referencing an array of `document` objects
- WHEN a client queries `dossier { documenten { filename type } }`
- THEN all referenced documents MUST be resolved inline

### Requirement: GraphQL MUST support filtering and sorting
List queries MUST support filter arguments matching the REST API filter capabilities.

#### Scenario: Filter by property value
- GIVEN a query: `meldingen(filter: { status: "in_behandeling" }) { title }`
- THEN only meldingen with status `in_behandeling` MUST be returned

#### Scenario: Sort results
- GIVEN a query: `meldingen(sort: { field: "created", order: DESC }) { title created }`
- THEN results MUST be sorted by created date descending

#### Scenario: Pagination
- GIVEN 100 meldingen objects
- AND a query: `meldingen(first: 10, offset: 20) { title }`
- THEN exactly 10 objects MUST be returned starting from offset 20
- AND the response MUST include `totalCount` in the connection

### Requirement: GraphQL MUST enforce RBAC
Authorization policies MUST apply to GraphQL queries and mutations identically to the REST API.

#### Scenario: Unauthorized schema access
- GIVEN user `medewerker-1` has no access to schema `vertrouwelijk`
- WHEN they query `vertrouwelijk { title }`
- THEN the system MUST return a GraphQL error indicating insufficient permissions

#### Scenario: Cross-schema authorization in nested queries
- GIVEN user `medewerker-1` can read `orders` but not `klanten`
- WHEN they query `order { title klant { naam } }`
- THEN the `klant` field MUST return null with an authorization error in the errors array

### Requirement: The GraphQL endpoint MUST include an interactive explorer
A GraphiQL or similar IDE MUST be available for developers to explore the schema and test queries.

#### Scenario: Access GraphQL IDE
- GIVEN an authenticated user navigates to /api/graphql/explorer
- THEN a GraphQL IDE MUST be displayed with:
  - Schema documentation browser
  - Query editor with autocomplete
  - Query execution with formatted results

### Requirement: GraphQL MUST support subscriptions for real-time updates
Subscriptions MUST be available for receiving object change events.

#### Scenario: Subscribe to object changes
- GIVEN a subscription for meldingen updates
- WHEN melding `melding-1` is updated
- THEN the subscriber MUST receive the updated object data via WebSocket
