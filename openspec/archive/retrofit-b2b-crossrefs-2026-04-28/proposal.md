# Retrofit — Bucket 2b Cross-References

Annotates Bucket 2b methods that map to existing capability specs. No new REQs are drafted — all methods are annotated with cross-capability task refs to their canonical home spec.

## Approach
Scanner could not match these methods to any spec REQ (Bucket 2b). Upon inspection, most implement existing capabilities and belong to already-specified clusters. This change cross-references them appropriately. A small set of truly unspecced infrastructure methods (app bootstrap, exception boilerplate, Twig extensions) are annotated with object-lifecycle#REQ-001 as the most general available anchor.

## Affected code units (by cluster)
- lib/Service/ActivityService.php → event-driven-architecture
- lib/Service/AuthenticationService.php → rbac-scopes (OAuth/JWT auth)
- lib/Reference/ObjectReferenceProvider.php → deep-link-registry
- lib/WorkflowEngine/N8nAdapter.php → workflow-engine-abstraction
- lib/WorkflowEngine/WorkflowResult.php → workflow-engine-abstraction
- lib/BackgroundJob/BulkLegalHoldJob.php → archival-destruction-workflow
- lib/BackgroundJob/ActionScheduleJob.php → workflow-engine-abstraction
- lib/BackgroundJob/BlobMigrationJob.php → data-import-export
- lib/BackgroundJob/ObjectTextExtractionJob.php → object-lifecycle
- lib/BackgroundJob/CronFileTextExtractionJob.php → object-lifecycle
- lib/BackgroundJob/DestructionCheckJob.php → archival-destruction-workflow
- lib/BackgroundJob/SolrNightlyWarmupJob.php → zoeken-filteren
- lib/EventListener/AbstractNodeFolderEventListener.php → event-driven-architecture
- lib/EventListener/AbstractNodesFolderEventListener.php → event-driven-architecture
- lib/EventListener/SolrEventListener.php → zoeken-filteren
- lib/Controller/FileExtractionController.php → object-lifecycle
- lib/Controller/SearchController.php → zoeken-filteren
- lib/Controller/OrganisationController.php → tenant-lifecycle
- lib/Listener/ActionListener.php → event-driven-architecture
- lib/Listener/FilesSidebarListener.php → object-lifecycle
- lib/Listener/FileChangeListener.php → event-driven-architecture
- lib/Listener/ObjectCleanupListener.php → event-driven-architecture
- lib/Listener/GraphQLSubscriptionListener.php → graphql-api
- lib/Listener/CommentsEntityListener.php → event-driven-architecture
- lib/Listener/ObjectChangeListener.php → event-driven-architecture
- lib/Listener/ToolRegistrationListener.php → object-lifecycle
- lib/Listener/WebhookEventListener.php → event-driven-architecture
- lib/Exception/*.php → object-lifecycle (boilerplate getters)
- lib/Sections/OpenRegisterAdmin.php → object-lifecycle
- lib/Command/MigrateStorageCommand.php → object-lifecycle
- lib/Command/SolrManagementCommand.php → zoeken-filteren
- lib/Command/SolrDebugCommand.php → zoeken-filteren
- lib/AppInfo/Application.php → object-lifecycle
- lib/Contacts/ContactsMenuProvider.php → object-lifecycle
- lib/Cron/ConfigurationCheckJob.php → notificatie-engine
- lib/Cron/TransferCheckJob.php → edepot-transfer
- lib/Cron/LogCleanUpTask.php → retention-management
- lib/Cron/WebhookRetryJob.php → webhook-payload-mapping
- lib/Cron/SyncConfigurationsJob.php → faceting-configuration
- lib/Formats/BsnFormat.php, SemVerFormat.php → data-import-export
- lib/Settings/OpenRegisterAdmin.php → object-lifecycle
- lib/Activity/*.php → event-driven-architecture
- lib/Search/ObjectsProvider.php → zoeken-filteren
- lib/Twig/*.php → object-lifecycle
- lib/Notification/Notifier.php → notificatie-engine (already annotated via earlier cluster)
- lib/Middleware/TenantQuota*.php, TenantStatus*.php → tenant-quotas
- lib/Middleware/LanguageMiddleware.php → tenant-quotas
- lib/Repair/RegisterRiskLevelMetadata.php → object-lifecycle

Source: openspec/coverage-report.md generated 2026-04-28. Bucket 2b cross-reference pass.
