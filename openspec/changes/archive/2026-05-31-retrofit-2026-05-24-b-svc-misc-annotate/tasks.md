# Tasks

Annotate-only retrofit (Bucket 1 style) for the `miscellaneous-service-helpers`
sub-cluster. Each task below points at an EXISTING capability requirement; the
matching method docblock carries a `@spec ...#task-N` annotation. No new
capabilities are minted and no spec deltas are written — every annotated method
maps to a requirement that already lives in `openspec/specs/`.

The sub-cluster was flagged by the architect as "the dregs": constructor-only
shells and private helpers that aggressive Phase 2/3 triage already drained. The
only genuinely-annotatable residual is the GraphQL `SchemaGenerator` suite, which
the architect explicitly called out as the one exception that may want coverage.
It turned out to map cleanly onto the already-`implemented` `graphql-api`
capability, so it is annotated here rather than deferred.

## Annotated to graphql-api capability

- [x] task-1: graphql-api#Requirement:The GraphQL schema MUST be auto-generated from register schemas — schema-field assembly (`buildSchemaFields`, `buildMutationFields`, `getObjectType`, `buildObjectFields`) and name derivation (`toTypeName`, `toFieldName`, `singularize`, `resolveRef`) (retroactive annotation)
- [x] task-2: graphql-api#Requirement:Custom scalar types MUST map to OpenRegister property formats — `JsonType.serialize` (JSON scalar serialization) and `SchemaGenerator.initScalars` (scalar registration) (retroactive annotation)
- [x] task-3: graphql-api#Requirement:GraphQL MUST support nested object resolution via DataLoader batching — `TypeMapperHandler.mapPropertyToGraphQLType` / `mapPropertyToInputType` ($ref + array-of-ref mapping) and `SchemaGenerator` resolver placeholders (`createSingleResolverPlaceholder`, `createListResolverPlaceholder`, `createMutationResolverPlaceholder`, `initHandlers`) (retroactive annotation)
- [x] task-4: graphql-api#Requirement:GraphQL MUST support filtering and sorting matching the REST API — `TypeMapperHandler` input/filter/sort builders (`getFilterInputType`, `getCreateInputType`, `getUpdateInputType`, `buildInputFields`, `getSortInputType`, `getSelfFilterType`) (retroactive annotation)
- [x] task-5: graphql-api#Requirement:GraphQL MUST support dual pagination modes — `TypeMapperHandler.getConnectionType` and `getPageInfoType` (Relay connection + page info) (retroactive annotation)
- [x] task-6: graphql-api#Requirement:GraphQL MUST enforce property-level RBAC via PropertyRbacHandler — `TypeMapperHandler.getPropertyAuthDescriptions` (per-property auth descriptions surfaced on the schema) (retroactive annotation)
- [x] task-7: graphql-api#Requirement:JSON Schema composition MUST map to GraphQL type system — `CompositionHandler.applyComposition` / `applyAllOf` / `applyOneOf` / `applyAnyOf` / `resolveCompositionRefs` / `extractSharedFields` (allOf/oneOf/anyOf → merged/union/interface) (retroactive annotation)
- [x] task-8: graphql-api#Requirement:GraphQL errors MUST follow a structured format with machine-readable codes — `GraphQLErrorFormatter.format` (structured error envelope) (retroactive annotation)

## Dropped as plumbing (documented in proposal.md, NOT annotated)

- [x] task-9: DROP bucket — constructors, trivial getters/predicates, and private
      helpers with no standalone behavioral contract; plus the substantive
      bare methods that belong to OTHER sub-clusters (auth-system, contacts-actions,
      calendar-provider, file-actions, data-import-export, text-extraction-*,
      retention/archival, tenant-*, etc.) and are out of scope for this dregs pass.
      See proposal.md "Dropped" section for the full enumeration.
