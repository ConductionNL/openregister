# Tasks: backend coverage — Service/Index

## 1. Reverse-spec new behaviors

- [x] task-1 — Reverse-spec `DocumentBuilder` SOLR value coercion, type-compatibility validation, byte-limit truncation, dot-notation array reconstruction, and register/schema ID resolution (REQ-6). Annotate `extractArraysFromRelations`, `extractIdFromObject`, `extractIndexableArrayValues`, `flattenFilesForSolr`, `isValueCompatibleWithSolrType`, `resolveRegisterToId`, `resolveSchemaToId`, `validateFieldForSolr`.
- [x] task-2 — Reverse-spec `SchemaHandler` cross-schema field-type conflict resolution and `knn_vector` provisioning (REQ-7). Annotate `mirrorSchemas`, `ensureVectorFieldType`, `getCollectionFieldStatus`, `createMissingFields`, `fixMismatchedFields`.
- [x] task-3 — Reverse-spec `FileHandler` chunk indexing from `ChunkMapper` into the backend file collection (REQ-8). Annotate `indexFileChunks`, `processUnindexedChunks`, `getChunkingStats`, `getFileStats`, `getFileIndexStats`, `indexFiles`.

## 2. Annotate against existing REQs

- [x] task-4 — Annotate `ConfigurationHandler` URL/status helpers (`buildSolrBaseUrl`, `getEndpointUrl`, `getCoreStatus`, `getPortStatus`, `isSolrConfigured`) against the existing configuration requirement (REQ-4).

## 3. Exclude boilerplate

- [x] task-5 — `@spec exclude` the boilerplate majority: Guzzle HTTP verb wrappers, facade delegations to extracted primitives, `SearchBackendInterface` contract declarations, and simplified stubs.
