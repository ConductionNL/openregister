# Coverage Report — openregister

Generated: 2026-04-30 00:00 UTC
Branch: `feature/reverse-spec`
Scanner: opsx-coverage-scan v1

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 1532 lines / 249 files / 138 unique change targets | — (already tagged) |
| plumbing | ~55 methods across 14 files | — (never tagged) |
| 1 — REQ matched | 138 methods/classes | `/opsx-annotate openregister` |
| 2a — existing capability, no REQ | ~90 methods (14 clusters) | `/opsx-reverse-spec openregister --extend <cap>` |
| 2b — no capability owner | ~55 methods (11 clusters) | `/opsx-reverse-spec openregister --cluster <name>` |
| 3a — REQ broken (code removed) | 1 | Verify + separate fix PR |
| 3b — REQ never implemented | 15 | Mark deferred or remove |
| 4 — ADR conformance | 9 findings across 2 rules | Follow-up issue |

## Bucket 1 — Ready to annotate

Methods classified with confidence ≥ 0.70 against an identified REQ. Items marked NEEDS-REVIEW have confidence 0.70–0.85 and require human verification before tagging.

### capability: audit-hash-chain (5 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/AuditHashService.php` | `computeHash` | Req:HashChain | 0.99 | SHA-256 hash computation for chain |
| `lib/Service/AuditHashService.php` | `getGenesisHash` | Req:GenesisHash | 0.99 | genesis hash scenario exact match |
| `lib/Service/AuditHashService.php` | `getCanonicalJson` | Req:CanonicalJson | 0.99 | canonical JSON scenario match |
| `lib/Service/AuditHashService.php` | `verifyChain` | Req:VerifyEndpoint | 0.99 | verify chain with from/to params |
| `lib/Service/AuditHashService.php` | `getLastHash` | Req:SerializedWrites | 0.85 | supports serialized writes for hash chaining |
| `lib/Controller/AuditTrailController.php` | `verify` | Req:HashChainVerification | 0.95 | verify method + AuditHashService::verifyChain |

### capability: audit-trail-immutable (7 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/AuditTrailController.php` | `index` | Req:ListAuditTrail | 0.95 | AuditTrailController CRUD |
| `lib/Controller/AuditTrailController.php` | `show` | Req:AuditTrailEntry | 0.95 | path+name match |
| `lib/Controller/AuditTrailController.php` | `destroy` | Req:ImmutableAuditTrail | 0.80 | **NEEDS-REVIEW**: spec says reject — verify method returns 403 not 200 |
| `lib/Controller/AuditTrailController.php` | `destroyMultiple` | Req:ImmutableAuditTrail | 0.80 | **NEEDS-REVIEW**: same concern |
| `lib/Service/Object/AuditHandler.php` | `getLogs` | Req:AuditTrailLogs | 0.90 | AuditHandler + getLogs |

### capability: verwerkingsregister-api (3 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/AuditTrailController.php` | `verwerkingsregister` | Req:ProcessingActivities | 0.99 | method name exact match to spec capability |
| `lib/Controller/AuditTrailController.php` | `inzageverzoek` | Req:DataSubjectAccess | 0.99 | method name exact match to spec scenario |
| `lib/Controller/AuditTrailController.php` | `export` | Req:AuditExport | 0.90 | audit trail export endpoint |

### capability: archival-destruction-workflow (16 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/BackgroundJob/DestructionCheckJob.php` | `run` | Req:DestructionCheckJob | 0.99 | class name exact match to spec |
| `lib/BackgroundJob/DestructionExecutionJob.php` | `run` | Req:DestructionExecutionJob | 0.99 | class name exact match to spec |
| `lib/Service/Archival/ArchiefactiedatumCalculator.php` | `calculate` | Req:Archiefactiedatum | 0.99 | class name exact match |
| `lib/Service/Archival/DestructionService.php` | `findEligibleObjects` | Req:DestructionCheckJob | 0.95 | eligible objects for destruction |
| `lib/Service/Archival/DestructionService.php` | `createDestructionList` | Req:DestructionListAPI | 0.95 | creates destruction lists |
| `lib/Service/Archival/DestructionService.php` | `approveList` | Req:ApprovalWorkflow | 0.95 | multi-step approval backing |
| `lib/Service/Archival/DestructionService.php` | `rejectList` | Req:ApprovalWorkflow | 0.95 | full/partial rejection path |
| `lib/Service/Archival/DestructionService.php` | `executeDestruction` | Req:DestructionExecutionJob | 0.95 | batch permanent deletion |
| `lib/Service/Archival/DestructionService.php` | `generateCertificate` | Req:DestructionCertificate | 0.99 | destruction certificate generation |
| `lib/Service/Archival/LegalHoldService.php` | `placeHold` | Req:LegalHold | 0.95 | legal hold placement |
| `lib/Service/Archival/LegalHoldService.php` | `releaseHold` | Req:LegalHold | 0.95 | legal hold release |
| `lib/Service/Archival/LegalHoldService.php` | `bulkPlaceHold` | Req:LegalHold | 0.95 | bulk legal hold on schema |
| `lib/Controller/ArchivalController.php` | `listDestructionLists` | Req:DestructionListAPI | 0.95 | ArchivalController list |
| `lib/Controller/ArchivalController.php` | `getDestructionList` | Req:DestructionListDetail | 0.95 | get destruction list detail |
| `lib/Controller/ArchivalController.php` | `approveDestructionList` | Req:ApprovalWorkflow | 0.95 | approval workflow scenario match |
| `lib/Controller/ArchivalController.php` | `rejectDestructionList` | Req:ApprovalWorkflow | 0.95 | full/partial rejection path |
| `lib/Controller/ArchivalController.php` | `createLegalHold` | Req:LegalHold | 0.95 | legal hold spec requirement exact match |
| `lib/Controller/ArchivalController.php` | `releaseLegalHold` | Req:LegalHold | 0.95 | release legal hold |
| `lib/Controller/ArchivalController.php` | `listLegalHolds` | Req:LegalHold | 0.90 | list legal holds |
| `lib/Controller/ArchivalController.php` | `listCertificates` | Req:DestructionCertificate | 0.90 | destruction certificates listing |

### capability: retention-management (10 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/RetentionService.php` | `applyArchivalMetadata` | Req:MDTOCompliantMetadata | 0.95 | MDTO archival metadata on objects |
| `lib/Service/RetentionService.php` | `calculateArchiefactiedatum` | Req:Archiefactiedatum | 0.99 | exact match to spec requirement |
| `lib/Service/RetentionService.php` | `recalculateArchiefactiedatum` | Req:Archiefactiedatum | 0.90 | recalculate when source property changes |
| `lib/Service/RetentionService.php` | `createDestructionList` | Req:DestructionListJob | 0.95 | generates destruction lists |
| `lib/Service/RetentionService.php` | `generateDestructionCertificate` | Req:DestructionCertificate | 0.95 | generates destruction certificates |
| `lib/Controller/RetentionController.php` | `approveDestructionList` | Req:DestructionApprovalWorkflow | 0.95 | RetentionController + destruction approval |
| `lib/Controller/RetentionController.php` | `rejectDestructionList` | Req:DestructionApprovalWorkflow | 0.95 | retention rejection path |
| `lib/Controller/RetentionController.php` | `placeLegalHold` | Req:LegalHolds | 0.95 | legal holds (bevriezing) |
| `lib/Controller/RetentionController.php` | `releaseLegalHold` | Req:LegalHolds | 0.95 | release legal hold |
| `lib/Controller/RetentionController.php` | `placeBulkLegalHold` | Req:LegalHolds | 0.95 | bulk legal hold on schema |

### capability: webhook-payload-mapping (10 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/WebhooksController.php` | `index` | Req:WebhookRegistration | 0.95 | WebhooksController CRUD |
| `lib/Controller/WebhooksController.php` | `create` | Req:WebhookRegistration | 0.95 | webhook creation with URL, events, secret |
| `lib/Controller/WebhooksController.php` | `update` | Req:WebhookRegistration | 0.90 | update webhook |
| `lib/Controller/WebhooksController.php` | `destroy` | Req:WebhookRegistration | 0.90 | webhook deletion |
| `lib/Controller/WebhooksController.php` | `test` | Req:WebhookDelivery | 0.90 | test delivery endpoint |
| `lib/Controller/WebhooksController.php` | `retry` | Req:DeliveryRetry | 0.90 | retry delivery with backoff |
| `lib/Service/WebhookService.php` | `dispatchEvent` | Req:PayloadFormat | 0.90 | dispatches events with payload strategies |
| `lib/Service/WebhookService.php` | `deliverWebhook` | Req:DeliveryRetry | 0.90 | delivery with retry logic |
| `lib/Service/Webhook/CloudEventFormatter.php` | *(class)* | Req:CloudEventsFormat | 0.99 | class name exact match: CloudEvents formatter |
| `lib/BackgroundJob/WebhookDeliveryJob.php` | `run` | Req:DeliveryRetry | 0.95 | async webhook delivery background job |

### capability: event-driven-architecture (4 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Listener/WebhookEventListener.php` | `handle` | Req:WebhookEventListener | 0.99 | spec explicitly names WebhookEventListener |
| `lib/Controller/WebhooksController.php` | `events` | Req:EventSubscription | 0.90 | events listing for webhook subscription |
| `lib/Controller/GraphQLSubscriptionController.php` | `subscribe` | Req:SSESubscriptions | 0.90 | SSE subscription for real-time events |
| `lib/Service/GraphQL/SubscriptionService.php` | `pushEvent` | Req:SSEEventPush | 0.90 | pushes events to SSE buffer |

### capability: object-lifecycle (8 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/ObjectsController.php` | `create` | REQ-001 | 0.95 | object creation through save pipeline |
| `lib/Controller/ObjectsController.php` | `update` | REQ-001 | 0.95 | update through save pipeline |
| `lib/Service/Object/CrudHandler.php` | `create` | REQ-001 | 0.95 | CrudHandler::create + object lifecycle pipeline |
| `lib/Service/Object/CrudHandler.php` | `update` | REQ-001 | 0.95 | CrudHandler::update in save pipeline |
| `lib/Service/Object/CrudHandler.php` | `list` | REQ-003 | 0.90 | list with cache read |
| `lib/Service/Object/CrudHandler.php` | `get` | REQ-003 | 0.90 | get from cache when available |
| `lib/Service/Object/ValidateObject.php` | `validateObject` | REQ-002 | 0.95 | schema validation before persistence |
| `lib/Service/Object/SaveObjects.php` | *(class)* | REQ-004 | 0.90 | bulk save with chunked processing |

### capability: deletion-audit-trail (9 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/Object/DeleteObject.php` | `delete` | Req:SoftDelete | 0.95 | soft delete implementation |
| `lib/Service/Object/DeleteObject.php` | `canDelete` | Req:PreflightAnalysis | 0.90 | pre-flight deletion analysis + RESTRICT blocks |
| `lib/Service/Object/CrudHandler.php` | `delete` | Req:SoftDelete | 0.90 | delete path covers soft-delete + audit |
| `lib/Controller/ObjectsController.php` | `destroy` | Req:SoftDelete | 0.95 | object deletion via controller |
| `lib/Controller/DeletedController.php` | `index` | Req:SoftDelete | 0.95 | DeletedController = trash API |
| `lib/Controller/DeletedController.php` | `restore` | Req:TrashRestore | 0.99 | restore soft-deleted object via trash API |
| `lib/Controller/DeletedController.php` | `restoreMultiple` | Req:TrashRestore | 0.95 | bulk restore |
| `lib/Controller/DeletedController.php` | `destroy` | Req:PermanentDeletion | 0.95 | permanent deletion requiring prior soft delete |
| `lib/Controller/DeletedController.php` | `destroyMultiple` | Req:PermanentDeletion | 0.95 | bulk permanent delete |

### capability: zoeken-filteren (3 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/ObjectsController.php` | `index` | Req:FullTextSearch | 0.90 | main object listing with search + filter params |
| `lib/Service/Object/SearchQueryHandler.php` | `buildSearchQuery` | Req:FullTextSearch | 0.95 | SearchQueryHandler covers full-text + field filters |
| `lib/Service/Object/SearchQueryHandler.php` | `addPaginationUrls` | Req:Pagination | 0.90 | pagination URL generation matches spec scenarios |

### capability: faceting-configuration (3 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/Object/FacetHandler.php` | `getFacetsForObjects` | Req:FacetCounts | 0.95 | FacetHandler = faceting engine |
| `lib/Service/Object/FacetHandler.php` | `getFacetableFields` | Req:FacetDiscovery | 0.90 | auto-detection of facetable fields |
| `lib/Service/Object/FacetHandler.php` | `getMetadataFacetableFields` | Req:MetadataFacets | 0.90 | @self namespace metadata facets |

### capability: data-import-export (6 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/ImportService.php` | `importFromExcel` | Req:ImportFormats | 0.99 | Excel import |
| `lib/Service/ImportService.php` | `importFromCsv` | Req:ImportFormats | 0.99 | CSV import |
| `lib/Service/ExportService.php` | `exportToExcel` | Req:ExportFormats | 0.99 | Excel XLSX export |
| `lib/Service/ExportService.php` | `exportToCsv` | Req:ExportFormats | 0.99 | CSV export with UTF-8 BOM |
| `lib/Controller/ObjectsController.php` | `export` | Req:ExportObjects | 0.90 | object export endpoint |
| `lib/Controller/ObjectsController.php` | `import` | Req:BulkImport | 0.90 | object import endpoint |

### capability: graphql-api (2 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/GraphQLController.php` | `execute` | Req:GraphQLEndpoint | 0.99 | main GraphQL endpoint |
| `lib/Controller/GraphQLController.php` | `explorer` | Req:GraphQLIntrospection | 0.90 | GraphQL explorer UI |

### capability: mcp-discovery (5 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/McpController.php` | `discover` | Req:Tier1DiscoveryCatalog | 0.99 | MCP Tier 1 discovery catalog |
| `lib/Controller/McpController.php` | `discoverCapability` | Req:Tier2CapabilityDetail | 0.99 | MCP Tier 2 capability detail |
| `lib/Controller/McpServerController.php` | `handle` | Req:MCPProtocolEndpoint | 0.99 | MCP JSON-RPC 2.0 protocol handler |
| `lib/Service/McpDiscoveryService.php` | `getCatalog` | Req:Tier1DiscoveryCatalog | 0.99 | builds MCP catalog |
| `lib/Service/McpDiscoveryService.php` | `getCapabilityDetail` | Req:Tier2CapabilityDetail | 0.99 | capability detail with live data |

### capability: calendar-integration (5 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Calendar/RegisterCalendarProvider.php` | `getCalendars` | REQ-001 | 0.99 | CalDAV calendar provider for register objects |
| `lib/Calendar/RegisterCalendar.php` | `search` | REQ-001 | 0.95 | calendar search returns objects as VEVENT |
| `lib/Calendar/CalendarEventTransformer.php` | `transform` | REQ-002 | 0.99 | transforms register objects to iCalendar VEVENT format |
| `lib/Calendar/CalendarEventTransformer.php` | `determineAllDay` | REQ-002 | 0.95 | all-day event from boolean date schema property |
| `lib/Calendar/CalendarEventTransformer.php` | `interpolateTemplate` | REQ-002 | 0.95 | template interpolation in SUMMARY |

### capability: deep-link-registry (4 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/DeepLinkRegistryService.php` | `register` | Req:AppRegistration | 0.99 | apps register deep link patterns at boot |
| `lib/Service/DeepLinkRegistryService.php` | `resolve` | Req:RegistryResolve | 0.99 | resolves URLs for search results |
| `lib/Service/DeepLinkRegistryService.php` | `resolveUrl` | Req:URLTemplates | 0.99 | URL template placeholder resolution |
| `lib/Service/DeepLinkRegistryService.php` | `resolveIcon` | Req:RegistryResolve | 0.90 | icon resolution for search results |

### capability: tenant-lifecycle (12 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/TenantLifecycleService.php` | `provision` | Req:Provisioning | 0.99 | tenant provisioning with default resources |
| `lib/Service/TenantLifecycleService.php` | `suspend` | Req:LifecycleStatus | 0.99 | suspend active organisation |
| `lib/Service/TenantLifecycleService.php` | `reactivate` | Req:LifecycleStatus | 0.95 | reactivate suspended organisation |
| `lib/Service/TenantLifecycleService.php` | `deprovision` | Req:Deprovisioning | 0.99 | graceful deprovisioning with data retention |
| `lib/Service/TenantLifecycleService.php` | `archive` | Req:Deprovisioning | 0.95 | archive after deprovisioning |
| `lib/Service/TenantLifecycleService.php` | `validateTransition` | Req:LifecycleStatus | 0.95 | validates state transitions |
| `lib/Service/TenantLifecycleService.php` | `isValidEnvironment` | REQ-005 | 0.95 | OTAP environment validation |
| `lib/Service/TenantLifecycleService.php` | `isValidPromotionOrder` | REQ-005 | 0.99 | unidirectional promotion order enforcement |
| `lib/Controller/OrganisationController.php` | `suspend` | Req:LifecycleStatus | 0.95 | organisation suspend API |
| `lib/Controller/OrganisationController.php` | `activate` | Req:LifecycleStatus | 0.95 | organisation reactivation API |
| `lib/Controller/OrganisationController.php` | `deprovision` | Req:Deprovisioning | 0.95 | deprovisioning API |
| `lib/BackgroundJob/TenantPurgeJob.php` | `run` | Req:Deprovisioning | 0.90 | purge archived tenant data |
| `lib/BackgroundJob/TenantDeprovisionJob.php` | `run` | Req:Deprovisioning | 0.95 | graceful tenant deprovisioning background job |

### capability: tenant-isolation-audit (2 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/OrganisationController.php` | `isolationVerify` | Req:IsolationVerification | 0.99 | method name exact match to spec scenario |
| `lib/Controller/OrganisationController.php` | `isolationMetrics` | Req:IsolationMetrics | 0.99 | tenant isolation metrics endpoint |

### capability: tenant-quotas (3 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Middleware/TenantQuotaMiddleware.php` | `beforeController` | Req:RequestQuota | 0.99 | quota enforcement middleware before controller |
| `lib/Middleware/TenantQuotaMiddleware.php` | `afterController` | Req:BandwidthQuota | 0.90 | tracks bandwidth per response payload |
| `lib/BackgroundJob/TenantUsageSyncJob.php` | `run` | Req:UsageCounters | 0.99 | background job persists usage counters |

### capability: schema-hooks (2 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/HookExecutor.php` | `executeHooks` | Req:HookLifecycle | 0.99 | HookExecutor::executeHooks = schema hook execution |
| `lib/Listener/HookListener.php` | `handle` | Req:HookLifecycle | 0.90 | HookListener registered in Application.php |

### capability: workflow-engine-abstraction (6 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `deployWorkflow` | Req:EngineInterface | 0.99 | engine interface definition exact match |
| `lib/WorkflowEngine/WorkflowEngineInterface.php` | `executeWorkflow` | Req:ExecutionAPI | 0.99 | sync execution with structured result |
| `lib/WorkflowEngine/N8nAdapter.php` | *(class)* | Req:N8nAdapter | 0.99 | n8n adapter implementation |
| `lib/WorkflowEngine/WindmillAdapter.php` | *(class)* | Req:WindmillAdapter | 0.99 | Windmill adapter implementation |
| `lib/Controller/WorkflowEngineController.php` | `create` | Req:EngineRegistration | 0.95 | register a workflow engine via API |
| `lib/Controller/WorkflowEngineController.php` | `available` | Req:AutoDiscovery | 0.90 | auto-discover engines from ExApps |

### capability: object-interactions (12 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/NotesController.php` | `index` | Req:Notes | 0.99 | Notes via ICommentsManager |
| `lib/Controller/NotesController.php` | `create` | Req:Notes | 0.99 | create note on object |
| `lib/Controller/NotesController.php` | `destroy` | Req:Notes | 0.99 | delete note |
| `lib/Controller/TasksController.php` | `index` | Req:Tasks | 0.99 | tasks via CalDAV VTODO |
| `lib/Controller/TasksController.php` | `create` | Req:Tasks | 0.99 | create task linked to object |
| `lib/Controller/TasksController.php` | `update` | Req:Tasks | 0.95 | update task status |
| `lib/Controller/TasksController.php` | `destroy` | Req:Tasks | 0.99 | delete task |
| `lib/Service/TaskService.php` | `createTask` | Req:Tasks | 0.99 | task creation via CalDAV VTODO |
| `lib/Service/TaskService.php` | `getTasksForObject` | Req:Tasks | 0.99 | list tasks for object |
| `lib/Controller/FilesController.php` | `create` | Req:FileAttachments | 0.90 | upload file to object |
| `lib/Controller/FilesController.php` | `publish` | Req:FileAttachments | 0.90 | publish file for public access |
| `lib/Service/Object/LockHandler.php` | `lock` | Req:FileLock | 0.90 | object lock mechanism |
| `lib/Service/Object/LockHandler.php` | `unlock` | Req:FileLock | 0.90 | object unlock |
| `lib/Listener/CommentsEntityListener.php` | `handle` | Req:CommentsEntity | 0.95 | registers OpenRegister as Comments entity type |

### capability: linked-entity-types (6 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/LinkedEntityController.php` | `addObjectLink` | Req:GenericMetadataAPI | 0.99 | generic metadata API for ad-hoc linking |
| `lib/Controller/LinkedEntityController.php` | `removeObjectLink` | Req:GenericMetadataAPI | 0.99 | remove ad-hoc link |
| `lib/Controller/LinkedEntityController.php` | `reverseLookup` | Req:ReverseLookup | 0.99 | reverse lookup across tables |
| `lib/Service/LinkedEntityService.php` | `addLink` | Req:GenericMetadataAPI | 0.99 | add link to object |
| `lib/Service/LinkedEntityService.php` | `removeLink` | Req:GenericMetadataAPI | 0.99 | remove link |
| `lib/Service/LinkedEntityService.php` | `reverseLookup` | Req:ReverseLookup | 0.99 | reverse lookup |

### capability: mail-sidebar (6 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/EmailService.php` | `getEmailsForObject` | Req:ReverseLookup | 0.99 | reverse-lookup by mail message ID |
| `lib/Service/EmailService.php` | `linkEmail` | Req:QuickLink | 0.99 | quick-link email to object |
| `lib/Service/EmailService.php` | `searchBySender` | Req:SenderDiscovery | 0.99 | sender-based object discovery |
| `lib/Controller/EmailsController.php` | `search` | Req:ReverseLookup | 0.95 | search emails linked to object |
| `lib/Controller/EmailsController.php` | `bySender` | Req:SenderDiscovery | 0.99 | sender-based discovery endpoint |
| `lib/Listener/MailAppScriptListener.php` | `handle` | Req:ScriptInjection | 0.99 | injects sidebar script when Mail app is active |

### capability: edepot-transfer (10 methods)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/Edepot/MdtoXmlGenerator.php` | `generate` | Req:MDTOXml | 0.99 | MDTO-compliant XML generation per object |
| `lib/Service/Edepot/SipPackageBuilder.php` | `build` | Req:SIPPackages | 0.99 | SIP package assembly for e-Depot transfer |
| `lib/Service/Edepot/TransferListService.php` | `createTransferList` | Req:TransferListManagement | 0.99 | transfer list management |
| `lib/Service/Edepot/TransferListService.php` | `approveTransferList` | Req:TransferListManagement | 0.99 | archivist approves transfer list |
| `lib/Service/Edepot/TransferListService.php` | `rejectTransferList` | Req:TransferListManagement | 0.99 | reject transfer list |
| `lib/Service/Edepot/EdepotTransferService.php` | `executeTransfer` | Req:TransportProtocols | 0.99 | executes transfer via transport protocol |
| `lib/Service/Edepot/Transport/SftpTransport.php` | `send` | Req:SFTPTransport | 0.99 | SFTP transport for SIP delivery |
| `lib/Service/Edepot/Transport/RestApiTransport.php` | `send` | Req:RESTTransport | 0.99 | REST API transport |
| `lib/BackgroundJob/TransferExecutionJob.php` | `run` | Req:TransferStatus | 0.90 | transfer execution background job |
| `lib/Controller/TransferController.php` | `create` | Req:TransferListManagement | 0.95 | create transfer |

### capability: content-versioning (2 methods — NEEDS-REVIEW)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/Object/RevertHandler.php` | `revert` | Req:VersionRollback | 0.85 | **NEEDS-REVIEW**: rollback exists; draft/publish lifecycle unclear |
| `lib/Controller/RevertController.php` | `revert` | Req:VersionRollback | 0.85 | **NEEDS-REVIEW**: rollback endpoint; draft/publish lifecycle unclear |

### capability: datetime-input-handling (1 class)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/DateTimeNormalizer.php` | *(class)* | Req:NormalizationHelper | 0.99 | class name exact match — canonical normalization helper |

---

## Bucket 2a — Existing capability, no REQ

These controllers/services belong to recognizable capability domains but no spec requirement covers the observed behavior. Each cluster needs `/opsx-reverse-spec openregister --extend <capability>` to either find the missing REQ or create one.

### cluster: chat-ai (9 methods)

- `lib/Controller/ChatController.php::sendMessage()` — AI chat with object context and conversation history
- `lib/Controller/ChatController.php::getHistory()` — retrieve chat history per conversation
- `lib/Controller/ChatController.php::clearHistory()` — clear chat history
- `lib/Controller/ChatController.php::getChatStats()` — chat usage statistics
- `lib/Service/ChatService.php::processMessage()` — LLM message processing with tool use
- `lib/Controller/ConversationController.php::index()` — list conversations
- `lib/Controller/ConversationController.php::create()` — create conversation
- `lib/Controller/ConversationController.php::destroy()` — delete conversation
- `lib/Controller/AgentsController.php` (all methods) — agent management for AI chat

### cluster: approval-workflow (6 methods)

- `lib/Controller/ApprovalController.php::approve()` — approve step in approval chain
- `lib/Controller/ApprovalController.php::reject()` — reject step in approval chain
- `lib/Controller/ApprovalController.php::steps()` — list approval steps
- `lib/Service/ApprovalService.php::initializeChain()` — initialize approval chain for an object
- `lib/Service/ApprovalService.php::approveStep()` — approve a specific step with comment
- `lib/Service/ApprovalService.php::rejectStep()` — reject a step with reason

### cluster: search-trail (5 methods)

- `lib/Controller/SearchTrailController.php::index()` — list search trail entries
- `lib/Controller/SearchTrailController.php::statistics()` — search trail statistics
- `lib/Controller/SearchTrailController.php::popularTerms()` — popular search terms
- `lib/Controller/SearchTrailController.php::activity()` — search activity over time
- `lib/Controller/SearchTrailController.php::userAgentStats()` — user agent breakdown of search traffic

### cluster: configuration (2 methods)

- `lib/Controller/ConfigurationsController.php::export()` — export configuration as JSON/YAML
- `lib/Controller/ConfigurationsController.php::import()` — import configuration from URL or file

### cluster: solr-search (7 methods)

- `lib/Controller/Settings/SolrOperationsController.php::setupSolr()` — setup Solr search backend
- `lib/Controller/Settings/SolrOperationsController.php::warmupSolrIndex()` — warm up Solr index
- `lib/Service/IndexService.php::indexObject()` — index object in Solr
- `lib/Service/IndexService.php::reindexAll()` — reindex all objects
- `lib/Service/IndexService.php::searchObjects()` — search objects via Solr backend
- `lib/BackgroundJob/SolrWarmupJob.php::run()` — scheduled Solr cache warmup
- `lib/BackgroundJob/SolrNightlyWarmupJob.php::run()` — nightly Solr optimization

### cluster: gdpr-processing (4 methods)

- `lib/Controller/GdprEntitiesController.php::index()` — list GDPR processing activities
- `lib/Controller/GdprEntitiesController.php::getTypes()` — get GDPR entity types
- `lib/Controller/GdprEntitiesController.php::getCategories()` — get personal data categories
- `lib/Controller/GdprEntitiesController.php::getStats()` — GDPR processing statistics

### cluster: tmlo-export (3 methods)

- `lib/Controller/TmloController.php::exportSingle()` — export single object as TMLO XML
- `lib/Controller/TmloController.php::exportBatch()` — export batch as TMLO
- `lib/Controller/TmloController.php::summary()` — TMLO export summary statistics

### cluster: oas-generation (2 methods)

- `lib/Controller/OasController.php::generateAll()` — generate OpenAPI spec for all registers
- `lib/Controller/OasController.php::generate()` — generate OpenAPI spec for specific register

### cluster: scheduled-workflows (3 methods)

- `lib/Controller/ScheduledWorkflowController.php::index()` — list scheduled workflow executions
- `lib/Controller/ScheduledWorkflowController.php::create()` — schedule a workflow execution
- `lib/BackgroundJob/ScheduledWorkflowJob.php::run()` — executes scheduled workflow via engine

### cluster: workflow-import (2 methods)

- `lib/Service/Configuration/ImportHandler.php::importFromJson()` — import config including workflow definitions and schema hook attachments
- `lib/BackgroundJob/HookRetryJob.php::run()` — retry failed schema hooks with backoff

### cluster: actions (5 methods)

- `lib/Controller/ActionsController.php` (all methods) — action configuration management (CRUD for schema actions)
- `lib/Service/ActionService.php::createAction()` — create schema action with event trigger config
- `lib/Service/ActionExecutor.php::executeActions()` — execute configured actions on entity mutation events
- `lib/BackgroundJob/ActionRetryJob.php::run()` — retry failed action delivery
- `lib/BackgroundJob/ActionScheduleJob.php::run()` — execute scheduled action triggers

### cluster: file-extraction (6 methods)

- `lib/Controller/FileExtractionController.php::extract()` — extract text/metadata from files for search indexing
- `lib/Controller/FileExtractionController.php::extractAll()` — extract text from all unindexed files
- `lib/Controller/FileExtractionController.php::vectorizeBatch()` — vectorize file content for semantic search
- `lib/Service/TextExtractionService.php::extractFile()` — extract text from a single file
- `lib/BackgroundJob/CronFileTextExtractionJob.php::run()` — scheduled text extraction
- `lib/BackgroundJob/ObjectTextExtractionJob.php::run()` — text extraction from object-linked files

### cluster: dashboard (4 methods)

- `lib/Controller/DashboardController.php::calculate()` — calculate dashboard statistics
- `lib/Controller/DashboardController.php::getAuditTrailActionChart()` — audit trail action chart data
- `lib/Controller/DashboardController.php::getObjectsByRegisterChart()` — objects by register chart
- `lib/Service/DashboardService.php::calculate()` — aggregates multi-dimensional stats for dashboard

### cluster: endpoints (4 methods)

- `lib/Controller/EndpointsController.php::index()` — list configured API endpoints
- `lib/Controller/EndpointsController.php::create()` — create custom API endpoint
- `lib/Controller/EndpointsController.php::test()` — test endpoint configuration
- `lib/Service/EndpointService.php::testEndpoint()` — executes endpoint test with sample data

---

## Bucket 2b — No capability owner

These clusters need `/opsx-reverse-spec openregister --cluster <name>` to create new specs from scratch. Items flagged below need human pre-split because the cluster label is a namespace word.

### cluster: blob-migration (1 method)

- `lib/BackgroundJob/BlobMigrationJob.php::run()` — migrates objects from blob storage to MagicMapper dedicated tables

### cluster: names-cache (5 methods)

- `lib/Controller/NamesController.php::index()` — list cached object names
- `lib/Controller/NamesController.php::warmup()` — warm up the names cache
- `lib/Controller/NamesController.php::stats()` — names cache statistics
- `lib/BackgroundJob/NameCacheWarmupJob.php::run()` — scheduled names cache warmup
- `lib/Service/Object/CacheHandler.php::warmupNameCache()` — warms up the distributed names cache

### cluster: tables-sync (5 methods)

- `lib/Controller/TablesController.php::sync()` — sync register/schema to MagicMapper SQL table
- `lib/Controller/TablesController.php::syncAll()` — sync all schemas to dedicated SQL tables
- `lib/Service/MigrationService.php::migrateToMagicTable()` — migrate to schema-specific SQL tables
- `lib/Controller/MigrationController.php::status()` — get storage migration status
- `lib/Controller/MigrationController.php::migrate()` — trigger storage migration

### cluster: deck-integration (4 methods)

- `lib/Controller/DeckController.php::index()` — list Deck cards linked to object
- `lib/Controller/DeckController.php::create()` — link or create Deck card for object
- `lib/Controller/DeckController.php::objects()` — get objects linked to a board
- `lib/Service/DeckCardService.php::linkOrCreateCard()` — links existing or creates new card in Deck board

### cluster: security-auth (3 methods) ⚠️ namespace-word warning — needs human pre-split

- `lib/Service/AuthorizationService.php::authorizeJwt()` — JWT-based endpoint authorization
- `lib/Service/AuthorizationService.php::authorizeOAuth()` — OAuth-based endpoint authorization
- `lib/Service/SecurityService.php` (class) — security operations including rate limiting

### cluster: user-management (8 methods)

- `lib/Controller/UserController.php::me()` — get current authenticated user profile
- `lib/Controller/UserController.php::updateMe()` — update user profile fields
- `lib/Controller/UserController.php::changePassword()` — change user password
- `lib/Controller/UserController.php::exportData()` — export personal data (GDPR right of access)
- `lib/Controller/UserController.php::listTokens()` — list API tokens for user
- `lib/Controller/UserController.php::createToken()` — create personal API token
- `lib/Controller/UserController.php::revokeToken()` — revoke an API token
- `lib/Controller/UserController.php::requestDeactivation()` — request account deactivation

### cluster: health-metrics (4 methods)

- `lib/Controller/HealthController.php::index()` — health check endpoint with component status
- `lib/Controller/HeartbeatController.php::heartbeat()` — lightweight heartbeat ping endpoint
- `lib/Controller/MetricsController.php::index()` — Prometheus-compatible metrics text output
- `lib/Service/MetricsService.php::getDashboardMetrics()` — aggregated metrics for dashboard

### cluster: mappings (3 methods)

- `lib/Controller/MappingsController.php::index()` — list data transformation mappings
- `lib/Controller/MappingsController.php::test()` — test mapping against sample payload
- `lib/Service/MappingService.php` (class) — data transformation mapping execution

### cluster: applications (2 methods)

- `lib/Controller/ApplicationsController.php::index()` — list registered applications
- `lib/Controller/ApplicationsController.php::create()` — register an external application

### cluster: views (2 methods)

- `lib/Controller/ViewsController.php::index()` — list saved object views/filters
- `lib/Controller/ViewsController.php::create()` — create named view with filter preset

### cluster: file-sidebar (4 methods)

- `lib/Controller/FileSidebarController.php::getObjectsForFile()` — get objects linked to a file (file sidebar panel)
- `lib/Controller/FileSidebarController.php::getExtractionStatus()` — get text extraction status for file
- `lib/Listener/FilesSidebarListener.php::handle()` — injects object panel in Nextcloud files sidebar
- `lib/Listener/FileChangeListener.php::handle()` — handles file change events for linked objects

---

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (code removed)

| REQ | Evidence |
|---|---|
| `larping-skill-widget#all` | 172 removed git lines with 'larp' keyword — spec was redirected to larpingapp ownership; code may have existed in OpenRegister before redirect. Likely intentional removal, not a bug. |

### 3b — never implemented (or spec is redirect/infra)

| REQ | Notes |
|---|---|
| `built-in-dashboards#all` | `status:redirect` — owned by root openspec cross-app pattern |
| `larping-skill-widget#all` | `status:redirect` — moved to larpingapp/openspec |
| `no-code-app-builder#all` | `status:redirect` — owned by root openspec |
| `open-raadsinformatie#all` | `status:redirect` — moved to procest/openspec |
| `product-service-catalog#all` | `status:redirect` — moved to pipelinq/openspec |
| `document-zaakdossier#all` | `status:redirect` — moved to procest/openspec |
| `dso-omgevingsloket#all` | `status:redirect` — moved to procest/openspec |
| `zgw-api-mapping#all` | `status:redirect` — moved to procest/openspec |
| `content-versioning#Req:DraftPublishedLifecycle` | Draft/published lifecycle — no DraftService or version entity found; RevertHandler covers rollback only |
| `content-versioning#Req:VersionComparison` | Visual diff comparison between versions — no diffing service found |
| `content-versioning#Req:DeltaStorage` | Delta strategy for drafts vs full snapshots — not found |
| `environment-otap#Req:ConfigurationPromotion` | Promotion-copy feature between OTAP environments not found (environment validation via TenantLifecycleService IS implemented as REQ-005) |
| `mock-registers#Req:IdempotentImport` | Mock data seeding (BRP/KVK/BAG/DSO/ORI registers) — no seed JSON files or MockRegisterService in main codebase |
| `unit-test-coverage-phase2#all` | Test coverage tracking spec — no production code REQs |
| `mariadb-ci-matrix#Req:CIMatrix` | CI workflow configuration spec — not a production code REQ |

---

## Bucket 4 — ADR conformance findings

### adr-014-license-missing

**0 findings** — All 524 non-migration PHP files have `@license` or `SPDX-License-Identifier` headers. Full compliance.

### adr-014-copyright-missing

**0 findings** — All 568 PHP files with `@copyright` annotation confirm compliance.

### forbidden-debug-calls (ADR-003)

| File | Line | Finding |
|---|---|---|
| `lib/Db/SchemaMapper.php` | 892 | `print_r()` used for string conversion — replace with `json_encode()` or `(string)` cast |

### direct-sql-prepare (ADR-001 / ADR-003)

8 occurrences of `$this->db->prepare()` bypassing QueryBuilder. These are in low-level cross-table query paths where QueryBuilder cannot express the required SQL, but flag for review:

| File | Lines |
|---|---|
| `lib/Service/RegisterService.php` | 442 |
| `lib/Service/Object/ReferentialIntegrityService.php` | 373, 902 |
| `lib/Service/Object/LinkedEntityEnricher.php` | 121, 161, 247, 301, 347, 400 |
| `lib/Service/Object/CacheHandler.php` | 1770 |

---

## Notes for the human reviewer

1. **Annotated bulk is large**: 1532 `@spec` lines across 249 files cover most of the mature code paths. The prior `retrofit-2026-04-23-annotate-openregister` run was comprehensive. Focus on Bucket 1 (138 unannotated methods) next.

2. **content-versioning partial gap**: `RevertHandler` and `RevertController` implement rollback (Bucket 1, NEEDS-REVIEW), but the draft/publish lifecycle, version comparison, and delta storage described in the spec were not found. These are Bucket 3b — either mark as deferred or verify if they exist under different class names.

3. **8 redirect specs**: `built-in-dashboards`, `larping-skill-widget`, `no-code-app-builder`, `open-raadsinformatie`, `product-service-catalog`, `document-zaakdossier`, `dso-omgevingsloket`, `zgw-api-mapping` are `status:redirect` stubs. They contribute 0 un-implemented REQs to this app — the owning app should track them.

4. **Bucket 2a chat-ai cluster (9 methods)**: AI chat, conversation history, and agent management have no spec. If this feature is intentional and production-facing, it needs a spec. `/opsx-reverse-spec openregister --extend chat-ai` should create it.

5. **Bucket 2a actions cluster (5 methods)**: `ActionsController`, `ActionService`, `ActionExecutor` are distinct from `HookExecutor`/`schema-hooks`. These appear to be an evolved action system replacing hooks. Likely needs `/opsx-reverse-spec openregister --extend actions`.

6. **Bucket 2b security-auth and user-management**: Both are labeled with namespace words. `security-auth` likely covers at least `rbac-scopes` and `endpoint-auth` sub-specs. `user-management` may partially overlap with `tenant-lifecycle`. Human pre-split required before reverse-spec.

7. **AuditTrailController::destroy NEEDS-REVIEW**: The spec says audit trail entries MUST NOT be deletable. The controller has `destroy` and `destroyMultiple` methods. Verify these return 403 Forbidden rather than actually deleting — if they delete, this is a spec violation, not just a missing annotation.

8. **Direct SQL calls**: 8 `$this->db->prepare()` calls in service layer. These are not ADR violations per se (the ADR bans them in controllers, and some cross-table queries genuinely require raw SQL), but they should be reviewed for MariaDB compatibility given the `mariadb-ci-matrix` spec intent.
