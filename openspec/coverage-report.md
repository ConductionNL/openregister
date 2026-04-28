# Coverage Report — openregister

Generated: 2026-04-28 10:21 UTC  
Branch: `retrofit/reverse-spec-openregister-tenant-lifecycle-2026-04-28`  
Scanner: opsx-coverage-scan v1

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 333 | — (already tagged) |
| plumbing | 335 | — (never tagged) |
| 1 — REQ matched | 400 (74 clean, 326 NEEDS-REVIEW) | `/opsx-annotate openregister` |
| 2a — existing capability, no REQ | 491 (7 caps) | `/opsx-reverse-spec openregister --extend <cap>` |
| 2b — no capability owner | 2158 (23 clusters) | `/opsx-reverse-spec openregister --cluster <name>` |
| 3a — REQ keyword in code, no match | 319 | Review — likely missed Bucket 1 entries |
| 3b — REQ never implemented | 3 | Mark deferred or remove |
| 4 — ADR conformance | 5 findings | Follow-up issue |

## Bucket 1 — Ready to annotate

### data-import-export (4 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/ObjectsController.php | collectNamesForResponse() | data-import-export#REQ-009 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Configuration/ExportHandler.php | exportSchema() | data-import-export#REQ-016 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Object/DataManipulationHandler.php | getValueFromPath() | data-import-export#REQ-011 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/ObjectService.php | collectNamesForResults() | data-import-export#REQ-009 | 0.77 | ⚠ NEEDS-REVIEW |

### deep-link-registry (1 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Event/DeepLinkRegistrationEvent.php | getRegistry() | deep-link-registry#REQ-009 | 0.7 | ⚠ NEEDS-REVIEW |

### edepot-transfer (19 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/DashboardController.php | getAuditTrailActionChart() | edepot-transfer#REQ-008 | 0.85 |  |
| Service/ArchivalService.php | setRetentionMetadata() | edepot-transfer#REQ-001 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/DashboardService.php | getAuditTrailActionChartData() | edepot-transfer#REQ-008 | 0.85 |  |
| Service/Edepot/EdepotTransferService.php | processResults() | edepot-transfer#REQ-003 | 0.82 | ⚠ NEEDS-REVIEW |
| Service/Edepot/EdepotTransferService.php | markObjectTransferred() | edepot-transfer#REQ-006 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/Edepot/EdepotTransferService.php | markObjectTransferFailed() | edepot-transfer#REQ-006 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/Edepot/EdepotTransferService.php | notifyTransferCompletion() | edepot-transfer#REQ-003 | 0.92 |  |
| Service/Edepot/SipPackageBuilder.php | build() | edepot-transfer#REQ-003 | 0.82 | ⚠ NEEDS-REVIEW |
| Service/Edepot/SipPackageBuilder.php | buildSinglePackage() | edepot-transfer#REQ-003 | 0.82 | ⚠ NEEDS-REVIEW |
| Service/Edepot/SipPackageBuilder.php | splitIntoBatches() | edepot-transfer#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Edepot/SipPackageBuilder.php | createManifestEntry() | edepot-transfer#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Edepot/Transport/TransportResult.php | getTransferReference() | edepot-transfer#REQ-002 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/Mcp/McpResourcesService.php | readObjects() | edepot-transfer#REQ-007 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Object/MetadataHandler.php | generateSlugFromValue() | edepot-transfer#REQ-001 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/Object/SaveObjects.php | generateObjectIdentifiers() | edepot-transfer#REQ-001 | 0.7 | ⚠ NEEDS-REVIEW |
| ... | +4 more | | | |

### environment-otap (1 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Service/Index/SchemaHandler.php | analyzeAndResolveFieldConflicts() | environment-otap#REQ-001 | 0.7 | ⚠ NEEDS-REVIEW |

### event-driven-architecture (1 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| AppInfo/Application.php | registerEventListeners() | event-driven-architecture#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |

### faceting-configuration (11 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/ObjectsController.php | index() | faceting-configuration#REQ-010 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/Object/FacetHandler.php | normalizeFacetConfig() | faceting-configuration#REQ-001 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/Object/ObjectServiceFacetExample.php | legacyFacetingApproach() | faceting-configuration#REQ-001 | 0.8 | ⚠ NEEDS-REVIEW |
| Service/Object/ObjectServiceFacetExample.php | transformBuckets() | faceting-configuration#REQ-001 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Object/ObjectServiceFacetExample.php | extractAppliedFilters() | faceting-configuration#REQ-001 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Object/ObjectServiceFacetExample.php | isAuditTrailsEnabled() | faceting-configuration#REQ-001 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Object/ObjectServiceFacetExample.php | calculatePerformanceImprovement() | faceting-configuration#REQ-001 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/ObjectService.php | getFacetableFields() | faceting-configuration#REQ-010 | 0.85 |  |
| Service/Settings/SolrSettingsHandler.php | getSolrFacetConfiguration() | faceting-configuration#REQ-011 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/Settings/SolrSettingsHandler.php | updateSolrFacetConfiguration() | faceting-configuration#REQ-011 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/Settings/SolrSettingsHandler.php | validateFacetConfiguration() | faceting-configuration#REQ-011 | 0.7 | ⚠ NEEDS-REVIEW |

### graphql-api (42 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Service/GraphQL/GraphQLErrorFormatter.php | format() | graphql-api#REQ-017 | 0.88 |  |
| Service/GraphQL/GraphQLResolver.php | resolveRelation() | graphql-api#REQ-003 | 0.82 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/GraphQLResolver.php | filterProperties() | graphql-api#REQ-007 | 0.82 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/GraphQLResolver.php | flushRelationBuffer() | graphql-api#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/GraphQLResolver.php | argsToRequestParams() | graphql-api#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/GraphQLResolver.php | objectToArray() | graphql-api#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/GraphQLResolver.php | encodeCursor() | graphql-api#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/Scalar/JsonType.php | serialize() | graphql-api#REQ-012 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/SchemaGenerator.php | buildSchemaFields() | graphql-api#REQ-001 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/SchemaGenerator.php | buildMutationFields() | graphql-api#REQ-012 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/SchemaGenerator.php | getObjectType() | graphql-api#REQ-012 | 0.85 |  |
| Service/GraphQL/SchemaGenerator.php | buildObjectFields() | graphql-api#REQ-012 | 1.0 |  |
| Service/GraphQL/SchemaGenerator.php | toTypeName() | graphql-api#REQ-012 | 0.85 |  |
| Service/GraphQL/SchemaGenerator.php | initScalars() | graphql-api#REQ-012 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/GraphQL/SchemaGenerator.php | initHandlers() | graphql-api#REQ-012 | 0.72 | ⚠ NEEDS-REVIEW |
| ... | +27 more | | | |

### linked-entity-types (14 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Service/Configuration/ImportHandler.php | getDuplicateSchemaInfo() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Configuration/PreviewHandler.php | previewSchemaChange() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/EmailService.php | unlinkEmail() | linked-entity-types#REQ-010 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/Index/Backends/Solr/SolrSchemaManager.php | getSchema() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Index/SetupHandler.php | addOrUpdateSchemaFieldWithTracking() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Index/SetupHandler.php | addSchemaFieldWithResult() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Index/SetupHandler.php | replaceSchemaFieldWithResult() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Object/SaveObject.php | resolveDefaultTemplateValue() | linked-entity-types#REQ-002 | 1.0 |  |
| Service/Object/SaveObject/FilePropertyHandler.php | processSingleFileProperty() | linked-entity-types#REQ-002 | 1.0 |  |
| Service/Object/SaveObjects/PreparationHandler.php | getSchemaAnalysisWithCache() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Object/ValidateObject.php | extractObjectConfigurationHandling() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/ObjectService.php | resolveRegisterAndSchema() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Schemas/SchemaCacheHandler.php | cacheSchemaConfiguration() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/TmloService.php | getSchemaDefaults() | linked-entity-types#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |

### mail-sidebar (3 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Service/GraphQL/SchemaGenerator.php | singularize() | mail-sidebar#REQ-009 | 1.0 |  |
| Twig/MappingRuntime.php | zgwEnum() | mail-sidebar#REQ-009 | 1.0 |  |
| Twig/MappingRuntime.php | zgwEnumReverse() | mail-sidebar#REQ-009 | 1.0 |  |

### mariadb-ci-matrix (2 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Service/ConditionMatcher.php | resolveDynamicValue() | mariadb-ci-matrix#REQ-004 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Object/CacheHandler.php | extractDynamicFieldsFromObject() | mariadb-ci-matrix#REQ-004 | 0.77 | ⚠ NEEDS-REVIEW |

### mcp-discovery (28 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/ConfigurationController.php | handlePublishingError() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/McpServerController.php | handleNotification() | mcp-discovery#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/McpServerController.php | jsonRpcError() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/OrganisationController.php | handleUpdateError() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/UiController.php | makeSpaResponse() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/UserController.php | getNotificationPreferences() | mcp-discovery#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/UserController.php | updateNotificationPreferences() | mcp-discovery#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/UserController.php | errorResponse() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Exception/ReferentialIntegrityException.php | toResponseBody() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Chat/ToolManagementHandler.php | convertToolsToFunctions() | mcp-discovery#REQ-007 | 1.0 |  |
| Service/Chat/ToolManagementHandler.php | convertFunctionsToFunctionInfo() | mcp-discovery#REQ-007 | 1.0 |  |
| Service/File/FileFormattingHandler.php | formatLock() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Index/Backends/Solr/SolrQueryExecutor.php | convertToPaginatedFormat() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Index/ObjectHandler.php | convertToOpenRegisterFormat() | mcp-discovery#REQ-015 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Mcp/McpToolsService.php | listTools() | mcp-discovery#REQ-007 | 1.0 |  |
| ... | +13 more | | | |

### object-interactions (121 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| AppInfo/Application.php | registerObjectInteractionServices() | object-interactions#REQ-011 | 0.77 | ⚠ NEEDS-REVIEW |
| Calendar/CalendarEventTransformer.php | resolveStatus() | object-interactions#REQ-004 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/FileSidebarController.php | getObjectsForFile() | object-interactions#REQ-006 | 0.77 | ⚠ NEEDS-REVIEW |
| Listener/ObjectCleanupListener.php | cleanupNotes() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Listener/ObjectCleanupListener.php | cleanupTasks() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Listener/ObjectCleanupListener.php | cleanupEmails() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Listener/ObjectCleanupListener.php | cleanupCalendarEvents() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Listener/ObjectCleanupListener.php | cleanupContacts() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Listener/ObjectCleanupListener.php | cleanupDeckCards() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/ActivityService.php | buildRegisterLink() | object-interactions#REQ-002 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/CalendarEventService.php | unlinkEventsForObject() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/ContactService.php | deleteLinksForObject() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/DashboardService.php | fetchRegister() | object-interactions#REQ-002 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/DeckCardService.php | deleteLinksForObject() | object-interactions#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Edepot/EdepotTransferService.php | gatherObjectsWithFiles() | object-interactions#REQ-006 | 0.77 | ⚠ NEEDS-REVIEW |
| ... | +106 more | | | |

### rbac-scopes (9 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| AppInfo/Application.php | registerCacheAndFileHandlers() | rbac-scopes#REQ-005 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/RegistersController.php | checkRegisterManagePermission() | rbac-scopes#REQ-004 | 0.77 | ⚠ NEEDS-REVIEW |
| Reference/ObjectReferenceProvider.php | getCachePrefix() | rbac-scopes#REQ-005 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/OasService.php | resolveEffectiveAuthorization() | rbac-scopes#REQ-002 | 0.85 |  |
| Service/Object/PermissionHandler.php | resolveAuthorization() | rbac-scopes#REQ-002 | 0.85 |  |
| Service/Object/PermissionHandler.php | expandRoles() | rbac-scopes#REQ-002 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/Object/RenderObject.php | getRegister() | rbac-scopes#REQ-005 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Object/SaveObject.php | getCachedRegister() | rbac-scopes#REQ-005 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Object/SaveObjects.php | loadRegisterWithCache() | rbac-scopes#REQ-005 | 0.77 | ⚠ NEEDS-REVIEW |

### retention-management (7 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/RetentionController.php | placeLegalHold() | retention-management#REQ-007 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/RetentionController.php | releaseLegalHold() | retention-management#REQ-007 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/RetentionController.php | placeBulkLegalHold() | retention-management#REQ-007 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/Settings/ConfigurationSettingsController.php | getRetentionSettings() | retention-management#REQ-009 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/Settings/ConfigurationSettingsController.php | updateRetentionSettings() | retention-management#REQ-009 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/SettingsService.php | getRetentionSettingsOnly() | retention-management#REQ-009 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/SettingsService.php | updateRetentionSettingsOnly() | retention-management#REQ-009 | 0.77 | ⚠ NEEDS-REVIEW |

### schema-hooks (8 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Exception/HookStoppedException.php | getErrors() | schema-hooks#REQ-016 | 0.85 |  |
| Service/ActionExecutor.php | handleFailure() | schema-hooks#REQ-006 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Webhook/CloudEventFormatter.php | formatAsCloudEvent() | schema-hooks#REQ-003 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Webhook/CloudEventFormatter.php | getSource() | schema-hooks#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Webhook/CloudEventFormatter.php | getSubject() | schema-hooks#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Webhook/CloudEventFormatter.php | getRequestHeaders() | schema-hooks#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Webhook/CloudEventFormatter.php | getContentTypeHeader() | schema-hooks#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Webhook/CloudEventFormatter.php | getAppVersion() | schema-hooks#REQ-003 | 0.72 | ⚠ NEEDS-REVIEW |

### tenant-isolation-audit (2 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/OrganisationController.php | isolationVerify() | tenant-isolation-audit#REQ-002 | 0.7 | ⚠ NEEDS-REVIEW |
| Controller/OrganisationController.php | isolationMetrics() | tenant-isolation-audit#REQ-004 | 0.7 | ⚠ NEEDS-REVIEW |

### verwerkingsregister-api (9 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/DashboardController.php | getAuditTrailStatistics() | verwerkingsregister-api#REQ-003 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/DashboardController.php | getAuditTrailActionDistribution() | verwerkingsregister-api#REQ-003 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/SearchTrailController.php | export() | verwerkingsregister-api#REQ-003 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/UiController.php | auditTrail() | verwerkingsregister-api#REQ-003 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/DashboardService.php | getAuditTrailStatistics() | verwerkingsregister-api#REQ-003 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/DashboardService.php | getAuditTrailActionDistribution() | verwerkingsregister-api#REQ-003 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/LogService.php | exportLogs() | verwerkingsregister-api#REQ-003 | 1.0 |  |
| Service/LogService.php | prepareLogsForExport() | verwerkingsregister-api#REQ-003 | 1.0 |  |
| Service/UserService.php | exportPersonalData() | verwerkingsregister-api#REQ-003 | 1.0 |  |

### webhook-payload-mapping (1 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Service/Webhook/CloudEventFormatter.php | formatRequestAsCloudEvent() | webhook-payload-mapping#REQ-015 | 1.0 |  |

### workflow-engine-abstraction (12 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/ConfigurationController.php | applyConfigurationUpdates() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/ConfigurationController.php | validateConfigurationForPublishing() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/ConfigurationController.php | prepareConfigurationForGitHub() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |
| Controller/ConfigurationController.php | updateConfigurationWithGitHubInfo() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/ActionExecutor.php | processWorkflowResult() | workflow-engine-abstraction#REQ-005 | 0.85 |  |
| Service/Configuration/FetchHandler.php | fetchRemoteConfiguration() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Configuration/FetchHandler.php | decode() | workflow-engine-abstraction#REQ-008 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Configuration/ImportHandler.php | createOrUpdateConfiguration() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/ConfigurationService.php | previewConfigurationChanges() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Index/SetupHandler.php | getObjectEntityFieldDefinitions() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Object/SearchQueryHandler.php | logSearchTrail() | workflow-engine-abstraction#REQ-016 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/WorkflowEngineRegistry.php | createEngine() | workflow-engine-abstraction#REQ-008 | 0.77 | ⚠ NEEDS-REVIEW |

### workflow-in-import (2 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/RegistersController.php | import() | workflow-in-import#REQ-001 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/Configuration/ExportHandler.php | exportWorkflowsForSchema() | workflow-in-import#REQ-018 | 0.85 |  |

### workflow-integration (14 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/Settings/N8nSettingsController.php | getN8nSettings() | workflow-integration#REQ-016 | 1.0 |  |
| Controller/Settings/N8nSettingsController.php | updateN8nSettings() | workflow-integration#REQ-016 | 1.0 |  |
| Service/Configuration/ExportHandler.php | setWorkflowEngineRegistry() | workflow-integration#REQ-016 | 1.0 |  |
| Service/Configuration/ExportHandler.php | setDeployedWorkflowMapper() | workflow-integration#REQ-016 | 1.0 |  |
| Service/Configuration/ExportHandler.php | getLastNumericSegment() | workflow-integration#REQ-016 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Configuration/ImportHandler.php | setWorkflowEngineRegistry() | workflow-integration#REQ-016 | 1.0 |  |
| Service/Configuration/ImportHandler.php | setDeployedWorkflowMapper() | workflow-integration#REQ-016 | 1.0 |  |
| Service/Configuration/ImportHandler.php | processWorkflowDeployment() | workflow-integration#REQ-016 | 1.0 |  |
| Service/Configuration/ImportHandler.php | getDuplicateRegisterInfo() | workflow-integration#REQ-016 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Configuration/ImportHandler.php | importSeedData() | workflow-integration#REQ-016 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Configuration/ImportHandler.php | ensureDependenciesForSeedData() | workflow-integration#REQ-016 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Configuration/ImportHandler.php | handleNextcloudAppDependencies() | workflow-integration#REQ-016 | 0.72 | ⚠ NEEDS-REVIEW |
| Service/Settings/ConfigurationSettingsHandler.php | getN8nSettingsOnly() | workflow-integration#REQ-016 | 1.0 |  |
| Service/Settings/ConfigurationSettingsHandler.php | updateN8nSettingsOnly() | workflow-integration#REQ-016 | 1.0 |  |

### zoeken-filteren (89 methods)

| File | Method | REQ | Confidence | Notes |
|---|---|---|---|---|
| Controller/ApplicationsController.php | index() | zoeken-filteren#REQ-007 | 1.0 |  |
| Controller/ObjectsController.php | paginate() | zoeken-filteren#REQ-007 | 1.0 |  |
| Controller/ObjectsController.php | crossTableSearch() | zoeken-filteren#REQ-013 | 0.85 |  |
| Controller/SearchTrailController.php | paginate() | zoeken-filteren#REQ-007 | 1.0 |  |
| Controller/SettingsController.php | debugTypeFiltering() | zoeken-filteren#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/Archival/ArchiefactiedatumCalculator.php | brondatumFromProperty() | zoeken-filteren#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/CalendarEventService.php | getEventsForObject() | zoeken-filteren#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/CalendarEventService.php | veventToArray() | zoeken-filteren#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/Chat/ContextRetrievalHandler.php | searchKeywordOnly() | zoeken-filteren#REQ-010 | 0.77 | ⚠ NEEDS-REVIEW |
| Service/ConditionMatcher.php | getObjectValue() | zoeken-filteren#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/ContactMatchingService.php | invalidateCacheForObject() | zoeken-filteren#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/ContactMatchingService.php | hasMatchingProperty() | zoeken-filteren#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/ExportService.php | isRelationProperty() | zoeken-filteren#REQ-003 | 0.7 | ⚠ NEEDS-REVIEW |
| Service/Index/SearchBackendInterface.php | indexObject() | zoeken-filteren#REQ-017 | 0.75 | ⚠ NEEDS-REVIEW |
| Service/IndexService.php | searchObjectsPaginated() | zoeken-filteren#REQ-007 | 0.75 | ⚠ NEEDS-REVIEW |
| ... | +74 more | | | |

## Bucket 2a — Existing capability, no REQ

### cluster: archival-destruction-workflow (10 methods)
- Service/Archival/ArchiefactiedatumCalculator.php :: determineBrondatum() — ArchiefactiedatumCalculator::determineBrondatum (score 0.5)
- Service/Archival/ArchiefactiedatumCalculator.php :: brondatumFromClosure() — ArchiefactiedatumCalculator::brondatumFromClosure (score 0.33)
- Service/Archival/ArchiefactiedatumCalculator.php :: brondatumFromTermijn() — ArchiefactiedatumCalculator::brondatumFromTermijn (score 0.5)
- Service/Archival/DestructionService.php :: extendArchiefactiedatum() — DestructionService::extendArchiefactiedatum (score 0.45)
- Service/Archival/LegalHoldService.php :: placeHold() — LegalHoldService::placeHold (score 0.4)
- ... +5 more

### cluster: edepot-transfer (37 methods)
- Service/Edepot/MdtoXmlGenerator.php :: addIdentificatie() — MdtoXmlGenerator::addIdentificatie (score 0.4)
- Service/Edepot/MdtoXmlGenerator.php :: addNaam() — MdtoXmlGenerator::addNaam (score 0.4)
- Service/Edepot/MdtoXmlGenerator.php :: addWaardering() — MdtoXmlGenerator::addWaardering (score 0.35)
- Service/Edepot/MdtoXmlGenerator.php :: addBewaartermijn() — MdtoXmlGenerator::addBewaartermijn (score 0.35)
- Service/Edepot/MdtoXmlGenerator.php :: addInformatiecategorie() — MdtoXmlGenerator::addInformatiecategorie (score 0.35)
- ... +32 more

### cluster: event-driven-architecture (97 methods)
- Event/RegisterUpdatedEvent.php :: getNewRegister() — dispatched::getNewRegister (score 0.47)
- Event/RegisterUpdatedEvent.php :: getOldRegister() — dispatched::getOldRegister (score 0.47)
- Event/FileCopiedEvent.php :: getObjectUuid() — FileCopiedEvent::getObjectUuid (score 0.43)
- Event/FileCopiedEvent.php :: getFileId() — FileCopiedEvent::getFileId (score 0.43)
- Event/FileCopiedEvent.php :: getData() — FileCopiedEvent::getData (score 0.43)
- ... +92 more

### cluster: faceting-configuration (48 methods)
- Service/Configuration/UploadHandler.php :: getUploadedJson() — for::getUploadedJson (score 0.55)
- Service/Configuration/UploadHandler.php :: decode() — for::decode (score 0.5)
- Service/Configuration/UploadHandler.php :: ensureArrayStructure() — for::ensureArrayStructure (score 0.5)
- Service/Configuration/UploadHandler.php :: getJSONfromFile() — for::getJSONfromFile (score 0.55)
- Service/Configuration/UploadHandler.php :: getJSONfromURL() — for::getJSONfromURL (score 0.67)
- ... +43 more

### cluster: graphql-api (47 methods)
- Service/GraphQL/GraphQLResolver.php :: resolveSingle() — GraphQLResolver::resolveSingle (score 0.65)
- Service/GraphQL/GraphQLResolver.php :: resolveList() — GraphQLResolver::resolveList (score 0.65)
- Service/GraphQL/GraphQLResolver.php :: resolveCreate() — GraphQLResolver::resolveCreate (score 0.65)
- Service/GraphQL/GraphQLResolver.php :: resolveUpdate() — GraphQLResolver::resolveUpdate (score 0.65)
- Service/GraphQL/GraphQLResolver.php :: resolveDelete() — GraphQLResolver::resolveDelete (score 0.65)
- ... +42 more

### cluster: object-interactions (252 methods)
- Service/Object/PermissionHandler.php :: hasPermission() — PermissionHandler::hasPermission (score 0.5)
- Service/Object/PermissionHandler.php :: checkPermission() — PermissionHandler::checkPermission (score 0.5)
- Service/Object/PermissionHandler.php :: filterObjectsForPermissions() — PermissionHandler::filterObjectsForPermissions (score 0.58)
- Service/Object/PermissionHandler.php :: filterUuidsForPermissions() — PermissionHandler::filterUuidsForPermissions (score 0.5)
- Service/Object/PermissionHandler.php :: getActiveOrganisationForContext() — PermissionHandler::getActiveOrganisationForContext (score 0.5)
- ... +247 more

### cluster: webhook-payload-mapping (0 methods)

## Bucket 2b — No capability owner

### cluster: service (1263 methods)
- publishObjectCreated()
- publishObjectUpdated()
- publishObjectDeleted()
- ... +1260 more

### cluster: controller (589 methods)
- index()
- show()
- extract()
- ... +586 more

### cluster: tool (49 methods)
- getName()
- getDescription()
- executeFunction()
- ... +46 more

### cluster: activity (37 methods)
- applySubjectText()
- buildRichParams()
- applySimpleSubject()
- ... +34 more

### cluster: backgroundjob (35 methods)
- run()
- run()
- run()
- ... +32 more

### cluster: workflowengine (27 methods)
- configure()
- deployWorkflow()
- updateWorkflow()
- ... +24 more

### cluster: calendar (19 methods)
- getCalendars()
- getCalendarEnabledSchemas()
- isValidUserPrincipal()
- ... +16 more

### cluster: listener (19 methods)
- handle()
- getEventTypeName()
- extractPayload()
- ... +16 more

### cluster: command (19 methods)
- configure()
- execute()
- configure()
- ... +16 more

### cluster: cron (16 methods)
- run()
- isJobDisabled()
- checkSingleConfiguration()
- ... +13 more

### cluster: reference (13 methods)
- getTitle()
- getOrder()
- getIconUrl()
- ... +10 more

### cluster: twig (13 methods)
- oauthToken()
- decosToken()
- jwtToken()
- ... +10 more

### cluster: eventlistener (11 methods)
- handle()
- handleNodeCreated()
- handleNodeDeleted()
- ... +8 more

### cluster: search (8 methods)
- getName()
- getOrder()
- getSupportedFilters()
- ... +5 more

### cluster: exception (7 methods)
- getHttpStatusCode()
- fromDatabaseException()
- parseConstraintError()
- ... +4 more

### cluster: appinfo (7 methods)
- register()
- registerMappersWithCircularDependencies()
- registerConfigurationServices()
- ... +4 more

### cluster: middleware (7 methods)
- getQuota()
- getResetAt()
- getRetryAfter()
- ... +4 more

### cluster: sections (4 methods)
- getIcon()
- getID()
- getName()
- ... +1 more

### cluster: contacts (4 methods)
- process()
- doProcess()
- injectCountBadge()
- ... +1 more

### cluster: notification (4 methods)
- getID()
- getName()
- prepare()
- ... +1 more

### cluster: settings (3 methods)
- getForm()
- getSection()
- getPriority()

### cluster: repair (2 methods)
- getName()
- run()

### cluster: formats (2 methods)
- validate()
- validate()

## Bucket 3 — Surfaced for human triage

### 3a — keyword in code but not matched
- archival-destruction-workflow#REQ-001 — The system MUST provide a DestructionCheckJob that generates destruction lists f
- archival-destruction-workflow#REQ-002 — The system MUST provide API endpoints for destruction list management
- archival-destruction-workflow#REQ-003 — Destruction MUST follow a multi-step approval workflow with full, partial, and r
- archival-destruction-workflow#REQ-004 — The DestructionExecutionJob MUST permanently delete approved objects in batches
- archival-destruction-workflow#REQ-005 — The system MUST generate destruction certificates upon completed destruction
- archival-destruction-workflow#REQ-006 — The system MUST support legal holds that prevent destruction
- archival-destruction-workflow#REQ-007 — The system MUST calculate archiefactiedatum using configurable afleidingswijzen
- archival-destruction-workflow#REQ-008 — WOO-published objects MUST be flagged on destruction lists
- archivering-vernietiging#REQ-001 — Objects MUST support archival metadata (MDTO)
- archivering-vernietiging#REQ-002 — The system MUST support configurable selection lists (selectielijsten)
- archivering-vernietiging#REQ-003 — The system MUST support automated destruction workflows
- archivering-vernietiging#REQ-004 — The system MUST support e-Depot export (transfer/overbrenging)
- archivering-vernietiging#REQ-005 — NEN 2082 compliance MUST be verifiable
- audit-hash-chain#REQ-001 — Every audit trail entry MUST include a SHA-256 hash chained to the previous entr
- audit-hash-chain#REQ-002 — The system MUST provide a hash chain verification endpoint
- audit-hash-chain#REQ-003 — Hash chain writes MUST be serialized to prevent race conditions
- audit-hash-chain#REQ-004 — A database migration MUST add hash columns
- audit-trail-immutable#REQ-001 — Every mutation MUST produce an immutable audit trail entry
- audit-trail-immutable#REQ-002 — The AuditTrail entity MUST include hash and previousHash fields
- audit-trail-immutable#REQ-003 — The audit trail MUST use cryptographic hash chaining
- ... +299 more

### 3b — no code evidence (never implemented)
- content-versioning#REQ-003 — Drafts MUST be promotable to published version
- mcp-discovery#REQ-013 — Versioned URL Paths
- mock-registers#REQ-013 — Mock Data Distinguishability

## Bucket 4 — ADR conformance findings

### direct-sql (ADR-001 — use OpenRegister abstractions)
- lib/Service/RegisterService.php
- lib/Service/Object/ReferentialIntegrityService.php
- lib/Service/Object/LinkedEntityEnricher.php
- lib/Service/Object/CacheHandler.php
- lib/Controller/SettingsController.php

## Notes for the human reviewer

- 333 methods already carry @spec retrofit annotations — annotation coverage is well underway.
- Bucket 1 (400 methods, 176 NEEDS-REVIEW) represents the remaining unannotated methods with REQ matches.
- Bucket 2a/2b are large (641+2158) — many service methods use generic names that don't match REQ keywords directly.
- Bucket 3: 319 REQs show grep-evidence in code but weren't matched in Pass A — these are likely Bucket 1 candidates the keyword scorer missed; treat 3b (3 REQs) as truly unimplemented.
- Bucket 4: 5 files use direct SQL ($this->db->query/prepare) — review against ADR-001 OpenRegister abstraction requirement.
- Scoring note: confidence thresholds are conservative for this repo; many NEEDS-REVIEW entries (0.70-0.85) likely valid matches given openregister's domain vocabulary.
