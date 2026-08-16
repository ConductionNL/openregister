# Retrofit — object-lifecycle (new cluster)

Describes observed behavior of 252 methods classified by the scanner under `object-interactions` (Bucket 2a) as 5 new REQs in a new `object-lifecycle` capability. Code already exists — this change retroactively specifies it.

## Affected code units (core object-lifecycle)
- lib/Service/Object/SaveObjects.php (33 methods)
- lib/Service/Object/ValidateObject.php (21 methods)
- lib/Service/Object/CacheHandler.php (16 methods)
- lib/Service/Object/ValidationHandler.php (13 methods)
- lib/Service/Object/SaveObject/MetadataHydrationHandler.php (13 methods)
- lib/Service/Object/SaveObject.php (9 methods)
- lib/Service/Object/PerformanceHandler.php (8 methods)
- lib/Service/Object/SaveObject/RelationCascadeHandler.php (8 methods)
- lib/Service/Object/CrudHandler.php (7 methods)
- lib/Service/Object/UtilityHandler.php (6 methods)
- lib/Service/Object/RenderObject.php (5 methods)
- lib/Service/Object/RelationshipOptimizationHandler.php (5 methods)
- lib/Service/Object/TranslationHandler.php (4 methods)
- lib/Service/Object/SaveObjects/PreparationHandler.php (4 methods)
- lib/Service/Object/VectorizationHandler.php (3 methods)
- lib/Service/Object/DataManipulationHandler.php (3 methods)
- lib/Service/Object/GetObject.php (3 methods)
- lib/Service/Object/SaveObjects/BulkValidationHandler.php (3 methods)
- lib/Service/Object/ReferentialIntegrityService.php (2 methods)
- lib/Service/Object/DeleteObject.php (2 methods)
- lib/Service/Object/MetadataHandler.php (2 methods)
- lib/Service/Object/SaveObject/ComputedFieldHandler.php (2 methods)
- lib/Service/Object/MigrationHandler.php (1 method)
- lib/Service/Object/PerformanceOptimizationHandler.php (1 method)
- lib/Service/Object/SaveObjects/TransformationHandler.php (1 method)
- lib/Service/Object/SaveObjects/ChunkProcessingHandler.php (1 method)

## Cross-capability misclassified units (existing task refs)
- lib/Service/Object/PermissionHandler.php → rbac-scopes#REQ-001
- lib/Service/Object/LinkedEntityEnricher.php → linked-entity-types#REQ-003
- lib/Service/Object/RelationHandler.php → linked-entity-types#REQ-003
- lib/Service/Object/SaveObject/RelationCascadeHandler.php → linked-entity-types#REQ-003
- lib/Service/Object/SaveObjects/BulkRelationHandler.php → linked-entity-types#REQ-003
- lib/Service/Object/AuditHandler.php → audit-trail-immutable#REQ-002
- lib/Service/Object/FacetHandler.php → faceting-configuration#REQ-002
- lib/Service/Object/SearchQueryHandler.php → zoeken-filteren#REQ-001
- lib/Service/Object/QueryHandler.php → zoeken-filteren#REQ-001
- lib/Service/Object/ExportHandler.php → data-import-export#REQ-007
- lib/Service/Object/RevertHandler.php → content-versioning#REQ-005
- lib/Service/Object/SaveObject/FilePropertyHandler.php → content-versioning#REQ-017

## Approach
The coverage scanner classified all 252 methods under `object-interactions` (the notes/tasks/files spec), which is a misclassification. None of these handlers deal with the ICommentsManager, CalDAV, or file attachment APIs. The core CRUD handlers (SaveObjects, ValidateObject, etc.) implement an internal layered pipeline with no corresponding capability spec. This change mints `object-lifecycle` as a new capability to anchor them. Cross-cap handlers (PermissionHandler, AuditHandler, etc.) get cross-references to their correct capability specs.

Notes:
- `NewFacetingExample.php` and `ObjectServiceFacetExample.php` are example/demo files — annotated under faceting-configuration as documentation aids
- `ReferentialIntegrityService.php` is annotated under object-lifecycle#REQ-001 since it enforces referential integrity during the save pipeline
- The scanner misclassification is expected to persist until the next scan re-scores these methods against the new object-lifecycle spec

Source: openspec/coverage-report.md generated 2026-04-28. See retrofit playbook.
