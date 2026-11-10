# Tasks

- [x] task-1: object-lifecycle#REQ-006 — Request-scoped register/schema/object context resolution (ObjectService::setRegister, setSchema, setObject) (retroactive annotation)
- [x] task-2: object-lifecycle#REQ-007 — Result name hydration / related-UUID collection (ObjectService::collectNamesForResults) (retroactive annotation)
- [x] task-3: object-lifecycle#REQ-008 — saveObject facade orchestration ordering (ObjectService::saveObject) (retroactive annotation)
- [x] task-4: object-lifecycle#REQ-009 — Transferred & append-only write protection (ObjectService::rejectIfTransferred) (retroactive annotation)
- [x] task-5: object-lifecycle#REQ-010 — Two-tier schema cache + register/schema invalidation (SchemaCacheHandler::getSchema, SchemaCacheHandler::invalidate, RegisterCacheHandler::invalidate) (retroactive annotation)

## Dropped (already specced or out of scope)

- DROP: SaveObject.php — owned by object-lifecycle#REQ-001/REQ-005 (save pipeline)
- DROP: MergeHandler.php — facade delegation; merge mechanics owned by referential-integrity
- DROP: RenderObject.php — render owned by object-lifecycle render path
- DROP: LockHandler.php — already annotated (object-interactions retrofit task-59)
- DROP: BatchOperationStatus.php — already annotated (reference-existence-validation)
- DROP: TransformationHandler.php — already annotated (annotate-openregister task-25)
- DROP: FacetCacheHandler.php — already annotated (annotate-openregister task-30)
- DROP: PropertyValidatorHandler.php — property validation owned by object-lifecycle#REQ-002
- DROP: RequestScopedCache.php — already annotated (annotate-openregister task-58)
- DROP: ObjectService facade delegations (listObjects, createObject, updateObject, patchObject, mergeObjects, validateObjectsBySchema, validateAndSaveObjectsBySchema) — thin delegations to object-lifecycle handlers
- DROP: ObjectService disabled stubs (exportObjects, importObjects, downloadObjectFiles) — throw "temporarily disabled" unconditionally
- DROP: ObjectServiceMapperAdapter.php — mapper-routing shim, no observable behavior beyond delegation
