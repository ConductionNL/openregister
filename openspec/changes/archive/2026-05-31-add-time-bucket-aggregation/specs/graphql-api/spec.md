## ADDED Requirements

### Requirement: GraphQL list queries SHALL support an ad-hoc `groupBy` argument with optional time-bucketing

Every auto-generated list-query field on every schema SHALL accept an optional `groupBy: GroupByInput` argument. When supplied, the returned connection SHALL include a non-null `groups: [GroupBucket!]` field with the bucketed aggregation result. When `groupBy` is absent, `groups` SHALL be `null`.

Type declarations (added to the auto-generated schema):

```graphql
input GroupByInput {
  field: String!
  interval: TimeInterval
  from: String        # ISO-8601, required when interval is set
  to: String          # ISO-8601, required when interval is set
  metric: AggregationMetric = COUNT
  metricField: String # required when metric != COUNT
}

enum TimeInterval {
  MINUTE
  HOUR
  DAY
  WEEK
  MONTH
  QUARTER
  YEAR
}

enum AggregationMetric {
  COUNT
  SUM
  AVG
  MIN
  MAX
}

type GroupBucket {
  key: String!
  value: Float!
}
```

#### Scenario: Categorical groupBy returns one bucket per distinct value
- **GIVEN** schema `applications` with property `status: string` and 30 rows distributed across `active|deprecated|archived`
- **WHEN** the client issues `query { applications(groupBy: { field: "status" }) { groups { key value } } }`
- **THEN** the response SHALL contain `groups` with exactly three entries
- **AND** each `groups[i].key` SHALL be one of `active`, `deprecated`, `archived`
- **AND** the sum of `groups[i].value` SHALL equal `30`

#### Scenario: Time-bucketed groupBy by DAY produces ISO-keyed buckets
- **GIVEN** schema `calllogs` with `created: date-time`
- **WHEN** the client issues `query { calllogs(groupBy: { field: "created", interval: DAY, from: "2026-05-01T00:00:00Z", to: "2026-05-22T00:00:00Z" }) { groups { key value } } }`
- **THEN** each `groups[i].key` SHALL be an ISO-8601-UTC string at midnight UTC on a day in the range
- **AND** each `groups[i].value` SHALL equal the count of calllogs whose `created` falls in that day-bucket
- **AND** days with zero rows SHALL be omitted (the client fills empties at render time)

#### Scenario: groupBy SHALL coexist with filter and totalCount
- **WHEN** the client issues `query { calllogs(filter: { status: "error" }, groupBy: { field: "created", interval: DAY, from: "...", to: "..." }) { totalCount groups { key value } } }`
- **THEN** the response SHALL include both `totalCount` (the size of the filtered set) AND `groups` (the bucketed series of the same filtered set)
- **AND** the sum of `groups[i].value` SHALL equal `totalCount`

#### Scenario: Sub-day interval against a date-only field is rejected
- **GIVEN** schema `meetings` with `meetingDate: { format: date }`
- **WHEN** the client issues `query { meetings(groupBy: { field: "meetingDate", interval: HOUR, from: "...", to: "..." }) { groups { key value } } }`
- **THEN** the response SHALL include a GraphQL field-error stating that sub-day intervals require a `date-time` field
- **AND** the `groups` field SHALL be `null`

#### Scenario: Unknown `field` produces a GraphQL field-error
- **WHEN** the client issues `groupBy: { field: "__totally_made_up" }`
- **THEN** the response SHALL include a GraphQL field-error referencing the unknown field
- **AND** the `groups` field SHALL be `null`

#### Scenario: Multi-tenant filter is applied before bucketing
- **GIVEN** two tenants `tenant-a` and `tenant-b` each owning 10 rows
- **AND** the authenticated user's active organisation is `tenant-a`
- **WHEN** the client issues a categorical `groupBy` query
- **THEN** the sum of `groups[i].value` SHALL be `10` (tenant-a only)

#### Scenario: Non-count metric requires metricField
- **WHEN** the client issues `groupBy: { field: "status", metric: SUM }` with no `metricField`
- **THEN** the response SHALL include a GraphQL field-error stating `metricField` is required for non-count metrics

### Requirement: The connection type SHALL include the new `groups` field

`TypeMapperHandler::getConnectionType()` SHALL add a nullable `groups: [GroupBucket!]` field to every auto-generated `<Schema>Connection` type. When the resolver did not run an ad-hoc aggregation (the client did not request `groupBy`), the field SHALL be `null`.

#### Scenario: Connection type structure includes `groups`
- **GIVEN** any schema `meldingen`
- **WHEN** `TypeMapperHandler.getConnectionType()` builds `MeldingenConnection`
- **THEN** the type SHALL include the fields: `edges`, `pageInfo`, `totalCount`, `facets`, `facetable`, AND `groups: [GroupBucket!]`
- **AND** existing clients that select only `edges`/`pageInfo`/`totalCount`/`facets`/`facetable` SHALL be unaffected

## MODIFIED Requirements

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
  - `meldingen(filter: MeldingenFilter, sort: SortInput, selfFilter: SelfFilter, search: String, fuzzy: Boolean, facets: [String], first: Int, offset: Int, after: String, groupBy: GroupByInput): MeldingenConnection` -- list with pagination via `GraphQLResolver.resolveList()`; the optional `groupBy` argument enables ad-hoc aggregation as defined under the "GraphQL list queries SHALL support an ad-hoc `groupBy` argument" requirement
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
- **THEN** it MUST include fields: `edges: [MeldingenEdge!]!`, `pageInfo: PageInfo!`, `totalCount: Int!`, `facets: JSON`, `facetable: [String]`, `groups: [GroupBucket!]`
- **AND** each edge type MUST include: `cursor: String!`, `node: Melding!`, `_relevance: Float` (fuzzy search relevance score)
- **AND** `groups` SHALL be `null` unless the client supplied a `groupBy` argument on the list query
