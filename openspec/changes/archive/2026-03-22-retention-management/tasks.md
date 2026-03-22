## 1. Database and Entity Layer

- [x] 1.1 Create database migration extending ObjectEntity.retention JSON structure with archival metadata fields (archiefnominatie, archiefstatus, archiefactiedatum, classificatie, bewaartermijn, legalHold)
- [x] 1.2 Extend Schema.archive JSON structure with new configuration keys (enabled, defaultNominatie, defaultBewaartermijn, afleidingswijze, bronEigenschap, procestermijn, closureField, classificatie, bewaartermijnOverride, overrideReason, requireDualApproval)
- [x] 1.3 Add PostgreSQL GIN index on ObjectEntity.retention JSON field for efficient filtering

## 2. Retention Settings

- [x] 2.1 Extend ObjectRetentionHandler with archival settings (destructionCheckInterval, notificationLeadDays, defaultExtensionPeriod, destructionBatchSize, selectielijstRegister, selectielijstSchema, destructionListRegister, destructionListSchema, archivalRegister)
- [x] 2.2 Add GET /api/settings/retention endpoint for archival settings retrieval
- [x] 2.3 Add PUT /api/settings/retention endpoint for archival settings update

## 3. RetentionService Core

- [x] 3.1 Create RetentionService class with dependency injection (ObjectService, SchemaMapper, ObjectMapper, IAppConfig, AuditTrailMapper)
- [x] 3.2 Implement applyArchivalMetadata() method that populates retention fields on object creation based on schema archive config and selectielijst lookup
- [x] 3.3 Implement calculateArchiefactiedatum() with support for afleidingswijzen: afgehandeld, eigenschap, termijn
- [x] 3.4 Implement recalculateArchiefactiedatum() triggered on source property updates
- [x] 3.5 Add archival metadata validation in SaveObject to reject updates on destroyed/transferred objects (HTTP 409)

## 4. Selectielijst Support

- [x] 4.1 Implement lookupSelectielijstEntry() in RetentionService to find selectielijst entries by categorie code from the configured selectielijst register/schema
- [x] 4.2 Implement schema-level override logic (bewaartermijnOverride) with audit trail recording
- [x] 4.3 Add selectielijst version tracking on objects (store selectielijst bron reference at creation time)

## 5. Destruction List Generation

- [x] 5.1 Create DestructionCheckJob extending OCP\BackgroundJob\TimedJob with configurable interval
- [x] 5.2 Implement findEligibleForDestruction() query: objects with archiefactiedatum < now, archiefnominatie = vernietigen, archiefstatus = nog_te_archiveren, no active legal hold, not on pending destruction list
- [x] 5.3 Implement destruction list creation as register object in configured register/schema with status in_review
- [x] 5.4 Register DestructionCheckJob in info.xml background-jobs section

## 6. Destruction Approval Workflow

- [x] 6.1 Create RetentionController with routes for destruction list management
- [x] 6.2 Implement POST /api/retention/destruction-lists/{id}/approve endpoint (full and partial approval)
- [x] 6.3 Implement POST /api/retention/destruction-lists/{id}/reject endpoint with mandatory reason
- [x] 6.4 Implement two-step approval logic checking schema archive.requireDualApproval and enforcing different approvers
- [x] 6.5 Implement archiefactiedatum extension for excluded/rejected objects using configured defaultExtensionPeriod

## 7. Destruction Execution

- [x] 7.1 Create DestructionExecutionJob extending OCP\BackgroundJob\QueuedJob with batch processing
- [x] 7.2 Implement batch destruction with configurable batch size, re-checking legal holds at execution time
- [x] 7.3 Implement cascading destruction respecting referential integrity (CASCADE, RESTRICT, legal hold on children)
- [x] 7.4 Create audit trail entries for each destroyed object with action archival.destroyed
- [x] 7.5 Implement destruction certificate generation as immutable register object after execution completes

## 8. Legal Hold Management

- [x] 8.1 Implement POST /api/retention/legal-holds endpoint to place hold on a single object
- [x] 8.2 Implement DELETE /api/retention/legal-holds/{id} endpoint to release hold with reason and history preservation
- [x] 8.3 Implement POST /api/retention/legal-holds/bulk endpoint for schema-wide legal holds via QueuedJob
- [x] 8.4 Add legal hold exclusion check in DestructionCheckJob (skip held objects when generating lists)
- [x] 8.5 Add legal hold exclusion check in DestructionExecutionJob (skip held objects at execution time)

## 9. Notifications

- [x] 9.1 Implement pre-destruction notification via INotification in DestructionCheckJob for objects within notificationLeadDays of archiefactiedatum
- [x] 9.2 Implement destruction list review notification sent to archivaris group when new list is created
- [x] 9.3 Implement notification deduplication to avoid repeat notifications for the same object
- [x] 9.4 Implement notification for bewaren objects approaching transfer date (distinct message from destruction)

## 10. Integration and Testing

- [x] 10.1 Register all new routes in appinfo/routes.php
- [x] 10.2 Write unit tests for RetentionService (archiefactiedatum calculation, selectielijst lookup, legal hold logic)
- [x] 10.3 Write unit tests for DestructionCheckJob (eligible object query, list generation, deduplication)
- [x] 10.4 Write unit tests for DestructionExecutionJob (batch processing, cascade handling, certificate generation)
- [x] 10.5 Test with opencatalogi and softwarecatalog to verify no regressions on object CRUD operations
