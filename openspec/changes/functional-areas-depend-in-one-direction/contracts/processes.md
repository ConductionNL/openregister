# Contract design: the Processes area

Wave 5 design package D08 of the change `functional-areas-depend-in-one-direction` (hydra ADR-119,
Decisions 8, 9, 12, 13 and 14). Processes is rank 7 in `area-order.json`: it may use Kernel,
Core, Notifications, Search, Compliance, Files and Integrations, and only Configuration and Platform may use
it. This file is a design. It changes nothing in `lib/`.

Measured on `origin/main` `911c2702` with the module graph of the maintainability checker,
read the way `modularity_check.py` reads it (whole app: 155,651 of 459,456 lines exposed,
Kernel 10,440 lines, violation degree 0.0874, 727 references against the order). Lines are code lines as
that tool counts them (no comments, no blank lines). Fleet usage was measured with `fleet_checkout.sh`
and `fleet_surface.py` on the development branches of the 19 fleet apps (2026-11-19).

## Summary

| | Today | After this design |
|---|---|---|
| Files of Processes | 1,526 | about 1,550 (31 rows in, 25 out, 9 new ports, 2 new wiring files, about 7 new port implementations) |
| Lines of Processes | 85,705 | about 86,500 |
| Exposed files of Processes | 413 | 23 |
| **Exposed lines of Processes** | **32,009** | **290** |
| References against the order into Processes (from lower areas) | 159 | 5 (Kernel task-sequence events, `ExpressionDefaultException`: left with the reason, section 4) |
| References against the order out of Processes (to Configuration, Platform) | 50 | 30: 22 removed, 2 existing pairs whose source now counts to Processes (translation and listener blocks, section 4) |
| References against the order, whole app | 727 | 545, no new pair |
| Exposed lines, whole app | 155,651 | 123,543 |

Processes exposes 37% of its code today, the largest share of any area after Core. After this design it
exposes 0.34%, under its proportional share of the 4.5 target (about 554 lines, section 4).

The "after" column is a simulation, not an estimate. A script replayed every move of section 3 on the graph
measured at `911c2702` (csv rows reassigned, binding entries moved, callers rewired to the ports, new ports
added with their line counts) and ran the exposure and order arithmetic of `modularity_check.py` again. It
includes the rows that the Core (D02) and Notifications (D03) designs already decided for files of this area,
and it assumes the wave 4 packages that own the lower areas' callers use the ports named in section 3.4.
Port line counts are estimates from the existing ports in `lib/Kernel/Contracts/Core/` (6 to 9 lines for one
to three one-line methods).

Three facts shape the design:

1. **Two thirds of the exposure is one file.** `lib/Service/ContractBindings.php` (csv row Configuration) binds
   about 150 interface pairs of Processes. 263 Processes files, 20,860 lines, are exposed by that file and by
   nothing else. Processes binding its own classes in its own map (BRIEF way 5, ADR-119 Decision 12) removes
   them without touching a caller. It is the single largest item of this design and the same move D02 makes
   for Core.
2. **Everything else that is exposed is named from below, against the order.** 159 references come from
   Core, Notifications, Search, Compliance, Files, Integrations and Kernel. They are wave 4 work and already
   have packages. What this design adds is the port each of them should use, so that the Processes class
   ends up internal instead of exposed to a different caller (section 3.4).
3. **The higher areas need little.** Configuration and Platform name 64 Processes files outside the binding
   map, almost all for example sets, repair steps and the operations console. Nine narrow Processes-owned
   ports (69 lines) and 31 csv rows replace them.

## 1. The exposed files of Processes today

413 files, 32,009 lines. Grouped by why they are exposed:

| Why exposed | Files | Lines | Treatment |
|---|---|---|---|
| W: named only by `Service/ContractBindings` (Configuration) | 261 | 20,829 | Processes' own service map (3.1) |
| Named by lower areas only (against the order) | 76 | 6,385 | lower-owned ports, existing Kernel contracts, events and rows (3.4); callers owned by wave 4 |
| Named by Configuration or Platform only | 36 | 3,004 | Processes-owned ports (3.3) and rows (3.2) |
| Named by both | 10 | 1,147 | both of the above |
| Moved out by a row (the file is another area's work) | 19 | 506 | rows (3.2) |
| Kept exposed | 11 | 138 | section 4 |

Two more files that only the binding map names (`FavouriteService`, `FavouriteServiceInterface`, 31 lines) leave
by a D03 row and are counted under "moved out".

Distinct outside caller files per area, the binding map excluded: Core 45, Configuration 34, Notifications 16,
Platform 15, Compliance 14, Integrations 11, Kernel 5, Search 2, Files 1.

The wiring-only files are listed by directory in Appendix A. The other 150 files, with their outside callers
(`ES/` is `Service/ExampleSet/`, `N/` is `Notifications/`) and their state after this design:

| File | Lines | Outside callers | After |
|---|---|---|---|
| `Service/Lifecycle/TransitionEngine.php` | 950 | Compl: BackgroundJob/Support/AutoTransitionServices, Controller/DsarCaseController, Controller/Support/DsarCaseCases | internal |
| `Db/TaskMapper.php` | 461 | Config: Command/RollbackApprovalMigrationCommand, ES/ExampleSetAppointments, ES/ExampleSetCasePlans, ES/ExampleSetTasks; Notif: N/BackgroundJob/TaskNotificationCandidates; Plat: Repair/CheckFlowTimerInvariants, Repair/Support/ApprovalTaskStores | internal |
| `Service/Case/CasePlanService.php` | 427 | Config: ES/ExampleSetCasePlans; + `ContractBindings` | internal |
| `Service/ContactMatchingService.php` | 413 | Core: Service/Object/ObjectCacheInvalidator; + `ContractBindings` | internal |
| `Listener/ListenerRegistrar.php` | 340 | Core: Listener/ListenerBootstrap | internal |
| `Service/Form/FormDestinationValidator.php` | 321 | Core: Service/Object/DraftStatusPolicy; + `ContractBindings` | internal |
| `Service/Party/PartyService.php` | 302 | Config: ES/ExampleSetAppointments, ES/ExampleSetAuditEntries, ES/ExampleSetCasePlans, ES/ExampleSetDeckCards, ES/ExampleSetEmailLinks, ES/ExampleSetGrants, ES/ExampleSetParties, ES/ExampleSetTalkConversations, N/ES/ExampleSetTimelineEntries; + `ContractBindings` | internal |
| `Service/Flow/FlowService.php` | 287 | Config: ES/ExampleSetFlows, Service/Config/Types/FlowShareableConfigType; + `ContractBindings` | internal |
| `Service/Flow/FlowVersionService.php` | 278 | Config: ES/ExampleSetFlows; + `ContractBindings` | internal |
| `Service/CalendarEventService.php` | 265 | Core: Service/ObjectRelationCleanupLinks, Service/ObjectSource/CalendarEventObjectSourceProvider, Service/ObjectSource/ObjectSourceWiring; Integ: Service/CalendarLinkService, Service/Integration/BuiltinIntegrationServices, Service/Integration/Providers/CalendarProviderServices; + `ContractBindings` | internal |
| `Service/Party/PartyRoleService.php` | 255 | Config: ES/ExampleSetParties; + `ContractBindings` | internal |
| `Service/Interaction/WatcherService.php` | 252 | Core: Service/Object/RenderObject; + `ContractBindings` | moves to Notifications |
| `Service/Task/TaskAuthorizationService.php` | 244 | Core: Controller/Support/TaskEventsControllerServices | internal |
| `Service/TaskService.php` | 241 | Config: Service/Configuration/ConfigurationServiceRegistrar, Service/Configuration/ImportHandler; Core: Service/ObjectSource/ObjectSourceWiring; Integ: Service/Integration/BuiltinIntegrationServices; + `ContractBindings` | internal |
| `Listener/ChangeGateListener.php` | 227 | Core: Controller/ObjectsController | internal |
| `Service/Calendar/ObjectDateDeclaration.php` | 224 | Integ: Calendar/DeclaredDateCalendars | internal |
| `Service/Flow/FlowTaskBridge.php` | 223 | Config: ES/ExampleSetFlowRuns; + `ContractBindings` | internal |
| `Service/Calendar/ObjectCalendarFeedService.php` | 220 | Integ: Calendar/DeclaredDateCalendars | internal |
| `Service/Lifecycle/AutoTransitionPass.php` | 201 | Core: Service/Object/Collaborators/ObjectHistoryServices, Service/ObjectService; + `ContractBindings` | internal |
| `Service/Lifecycle/GraphMoveCatalogue.php` | 196 | Compl: Db/AuditTrailMapper | internal |
| `Service/FlowLinkService.php` | 179 | Integ: Service/Integration/BuiltinIntegrationServices; + `ContractBindings` | internal |
| `Db/FlowMapper.php` | 153 | Config: ES/ExampleSetFlows | internal |
| `Service/Interaction/ReadHistoryService.php` | 146 | Core: Service/Object/GetObject, Service/Object/SearchLenses, Service/Object/SearchQueryHandler, Service/ObjectService | internal |
| `Service/ObjectAction/ObjectActionList.php` | 146 | Core: Service/Object/RenderObject; + `ContractBindings` | internal |
| `Listener/DecisionTableValidationListener.php` | 135 | Core: Listener/ListenerBootstrap | internal |
| `Service/Interaction/ReadStateService.php` | 135 | Core: Service/Object/RenderObject; + `ContractBindings` | internal |
| `Listener/DependentValueListener.php` | 116 | Core: Service/Object/ObjectSaveChecks | internal |
| `Db/Task.php` | 115 | Config: Command/RollbackApprovalMigrationCommand, ES/ExampleSetExternalTask, ES/ExampleSetTaskClaim, ES/ExampleSetTasks; Core: Event/AbstractTaskEvent, Service/Registers/EntityEventPublisher; Kernel: Event/TaskSequenceCompletedEvent, Event/TaskSequenceUndecidedEvent, Event/TaskTerminalEvent, Event/TaskTransitionedEvent; Notif: N/BackgroundJob/TaskScheduledNotificationJob, N/Service/Notification/TaskObjectAdapter, N/Service/Notification/TaskObjectAdapters, N/Service/Notification/TaskTransitionNotifier, N/Service/Notification/TaskTransitionNotifierInterface; Search: Search/Db/View | internal |
| `Service/Flow/FlowTriggerRenameCheck.php` | 114 | Core: Controller/SchemasController | internal |
| `Service/Party/PartySearchService.php` | 107 | Config: Service/Settings/ConfigurationSettingsHandler | internal |
| `Service/Dmn/DecisionTableBulkGuard.php` | 105 | Core: Service/Object/BulkRowRules | internal |
| `Service/Form/FormPayloadFindings.php` | 105 | Core: Service/Object/DraftActivation, Service/Object/DraftActivationChecks | internal |
| `Listener/ObjectQuotaListener.php` | 104 | Core: Service/Object/SaveObjects/BulkObjectQuota | internal |
| `Service/Lifecycle/LifecycleFinalStateResolver.php` | 104 | Compl: Service/Archival/ArchivalNominationService | internal |
| `Db/CaseItem.php` | 100 | Config: ES/ExampleSetCasePlans | internal |
| `Db/FlowRun.php` | 100 | Config: ES/ExampleSetFlowRunCheck, ES/ExampleSetFlowRuns; Core: Db/RunObjectLockMapper | internal |
| `Service/Party/PartyDefinition.php` | 97 | Core: Service/Relation/ObjectTreeService; Notif: N/Service/Party/PartyNotificationService | internal |
| `Db/FlowTimer.php` | 95 | Plat: Repair/CheckFlowTimerInvariants | internal |
| `Db/Flow.php` | 93 | Config: ES/ExampleSetFlowRuns, ES/ExampleSetFlows, ES/ExampleSetRunsWrite, Service/Config/Types/FlowShareableConfigType; Core: Repair/PerformerTyping; Integ: Db/Flow/FlowJson; Plat: Repair/MigrateRegisterFlowsToTable, Repair/PurgePhantomMigratedFlows, Repair/Support/EveryFlow, Repair/TypePerformerReferences | internal |
| `Service/Task/ApprovalPositionFormGuard.php` | 93 | Core: Controller/SchemasController | internal |
| `Service/Party/PartyIndicatorGuard.php` | 90 | Core: Service/File/FilePublishingHandler; + `ContractBindings` | internal |
| `Service/Task/TaskState.php` | 84 | Config: ES/ExampleSetTasks | internal |
| `Db/BulkJob.php` | 67 | Config: Service/OperationsConsoleService | internal |
| `Db/TaskInboxCriteria.php` | 66 | Notif: N/BackgroundJob/TaskScheduledNotificationJob; Search: Search/FlowTasksProvider | internal |
| `Service/Decision/ReviewAnnotationValidator.php` | 65 | Config: Service/Settings/FileSettingsHandler; + `ContractBindings` | internal |
| `Db/AutomationRecord.php` | 62 | Notif: N/Service/Notification/AnnotationNotificationDispatcher | internal |
| `Repair/TaskSubjectLocationBackfill.php` | 61 | Plat: Repair/BackfillTaskSubjectLocation | internal |
| `Service/Flow/FlowResumeState.php` | 61 | Config: ES/ExampleSetFlowRuns | internal |
| `Service/Lifecycle/LifecycleActionContext.php` | 61 | Compl: Service/Audit/AuditTrailCollaborators; Config: ES/ExampleSetStore; + `ContractBindings` | internal |
| `Service/Party/PartyAnnotationValidator.php` | 61 | Core: Db/SchemaMapper/AdvisoryAnnotationChecks | internal |
| `Service/Flow/FlowCaller.php` | 59 | Config: Service/Config/Types/FlowShareableConfigType | internal |
| `Service/AutomationRecordWriter.php` | 57 | Notif: N/Service/Notification/AnnotationNotificationDispatcher, N/Service/Notification/DeliveryLedger | internal |
| `Service/Decision/ReviewPolicy.php` | 57 | Config: Service/Settings/FileSettingsHandler | internal |
| `Db/CalendarFeedToken.php` | 56 | Integ: Calendar/DeclaredDateCalendars | internal |
| `Service/ObjectAction/ObjectActionElevationScope.php` | 53 | Compl: Service/Audit/AuditTrailCollaborators; Core: Service/Object/PermissionHandler | internal |
| `Db/AuditFlowAttribution.php` | 46 | Compl: Db/AuditTrailMapper | internal |
| `Db/FlowVersion.php` | 42 | Integ: Db/Flow/FlowJson | internal |
| `Service/Flow/FlowRunContext.php` | 42 | Core: Service/Object/BulkRowRules, Service/Object/RevertHandler, Service/Object/SaveObject, Service/Object/SaveObject/SaveObjectRuntime; + `ContractBindings` | internal |
| `Service/Task/TaskServiceInterface.php` | 42 | Config: ES/ExampleSetTasks; + `ContractBindings` | internal |
| `BackgroundJob/TranslationProjectionJob.php` | 41 | Core: BackgroundJob/QueuedJobClassesInterface | internal |
| `Db/FlowRunMapperInterface.php` | 38 | Compl: BackgroundJob/Support/FlowRunRetentionStores; Config: ES/ExampleSetFlowRuns; Integ: Mcp/BuiltIn/FlowMcpToolProvider, Mcp/BuiltIn/FlowToolServices; Plat: Repair/PurgePhantomMigratedFlows | internal |
| `Listener/Support/PushSlugResolver.php` | 37 | Plat: Listener/NotifyPushListener | moves to Platform |
| `Db/FlowLink.php` | 35 | Integ: Service/Integration/Providers/FlowProvider | internal |
| `Db/FlowClaim.php` | 33 | Compl: BackgroundJob/Support/AbandonedFlowClaim | internal |
| `Db/RuleRun.php` | 33 | Config: Service/OperationsConsoleService | internal |
| `BulkAction/BulkActionResult.php` | 32 | Config: BulkAction/ExportWholeSetAction | kept |
| `Db/RuleRunSummary.php` | 32 | Config: Service/OperationsConsoleService | internal |
| `Db/SchemaMapper/ReferenceLoopCheck.php` | 29 | Plat: Repair/ReportReferenceLoopSchemas | internal |
| `Service/Calendar/ObjectDateEvents.php` | 26 | Integ: Calendar/DeclaredDateCalendars | internal |
| `Service/Flow/Timer/SlaCalculatorInterface.php` | 26 | Notif: N/Service/Notification/NotificationReminderValidator; + `ContractBindings` | internal |
| `Listener/Support/HandlesObjectDeletion.php` | 25 | Compl: Listener/ContentReportRemovalListener | moves to Core |
| `Service/BulkJob/BulkJobServiceInterface.php` | 23 | Compl: BackgroundJob/Support/BulkJobServices; + `ContractBindings` | internal |
| `Service/Calculation/CalculationPayload.php` | 23 | Core: Service/Object/RenderObject | internal |
| `Repair/PhantomFlowPurgeRun.php` | 22 | Plat: Repair/PurgePhantomMigratedFlows | internal |
| `Service/Rules/ExpressionDefaultException.php` | 22 | Core: Controller/ObjectsController, Service/Object/SaveObjectInterface | kept |
| `Db/FlowMapperInterface.php` | 20 | Compl: BackgroundJob/Support/FlowRunRetentionStores; Config: Service/Config/Types/FlowShareableConfigType; Core: Repair/PerformerTyping; Plat: Repair/MigrateRegisterFlowsToTable, Repair/PurgePhantomMigratedFlows, Repair/Support/EveryFlow, Repair/TypePerformerReferences | internal |
| `Repair/V1ChainVerificationRun.php` | 20 | Compl: Repair/RechainAuditTrailForFlowAttribution | moves to Compliance |
| `Service/Flow/FlowRunServiceInterface.php` | 20 | Integ: Mcp/BuiltIn/FlowMcpToolProvider, Mcp/BuiltIn/FlowToolServices; + `ContractBindings` | internal |
| `Service/Lifecycle/KeepLifecycleStatesScope.php` | 20 | Core: Controller/RegistersController | internal |
| `Db/FlowTimerMapperInterface.php` | 19 | Plat: Repair/CheckFlowTimerInvariants | internal |
| `Service/Task/TaskInboxPage.php` | 19 | Search: Search/FlowTasksProvider | internal |
| `Service/Flow/FlowServiceInterface.php` | 18 | Integ: Mcp/BuiltIn/FlowMcpToolProvider, Mcp/BuiltIn/FlowToolServices; + `ContractBindings` | internal |
| `Service/FlowJsonColumn.php` | 18 | Integ: Service/Integration/Providers/FlowProvider | internal |
| `Db/CaseItemMapperInterface.php` | 17 | Config: ES/ExampleSetCasePlans | internal |
| `Service/Interaction/WatcherServiceInterface.php` | 17 | Notif: N/Controller/ObjectWatchersController, N/Controller/Support/ObjectWatchersControllerServices, N/Service/Notification/NotificationRecipientResolver, N/Service/Timeline/MentionPrincipals; + `ContractBindings` | moves to Notifications |
| `Service/Calendar/ObjectCalendarFeedSources.php` | 16 | Integ: Calendar/DeclaredDateCalendars | internal |
| `Db/BulkJobMapperInterface.php` | 15 | Compl: BackgroundJob/Support/BulkJobServices | internal |
| `Service/Calendar/CalendarFeedTokenServiceInterface.php` | 15 | Plat: Repair/ProtectStoredSecrets; + `ContractBindings` | internal |
| `Service/Interaction/ReadStateServiceInterface.php` | 15 | Core: Controller/ObjectsController, Controller/Support/ObjectReadStateControllerServices; + `ContractBindings` | internal |
| `Service/Party/PartyHolding.php` | 15 | Config: ES/ExampleSetParties | internal |
| `BulkAction/BulkStep.php` | 14 | Config: BulkAction/ExportWholeSetAction, BulkAction/WholeSetExportRun | kept |
| `Db/IdCursor.php` | 14 | Plat: Repair/CheckFlowTimerInvariants | internal |
| `Listener/Support/NotifyPushPlatform.php` | 14 | Plat: Listener/NotifyPushListener | moves to Platform |
| `Service/FlowLinkPlatform.php` | 14 | Integ: Service/Integration/BuiltinIntegrationServices | internal |
| `Service/ObjectTranslationRequest.php` | 14 | Core: Service/BulkTranslationServiceInterface | internal |
| `Service/OperationsConsoleNotifications.php` | 14 | Config: Service/OperationsConsoleService | moves to Configuration |
| `Service/Party/PartyServiceInterface.php` | 14 | Notif: N/Service/Party/PartyNotificationService; + `ContractBindings` | internal |
| `Service/Task/TaskImport.php` | 14 | Config: ES/ExampleSetTasks | internal |
| `Db/TaskInboxFilter.php` | 13 | Notif: N/BackgroundJob/TaskScheduledNotificationJob; Search: Search/FlowTasksProvider | internal |
| `Service/FlowLinkServiceInterface.php` | 13 | Integ: Controller/RelationsController; + `ContractBindings` | internal |
| `BulkAction/BulkActionInterface.php` | 12 | Kernel: Event/BulkActionRegistrationEvent | internal |
| `Db/DecisionMetadataEdit.php` | 12 | Core: Db/EntityRelationMapper; Files: Files/Db/EntityRelationMapperInterface | moves to Core |
| `Db/TaskInboxAudience.php` | 12 | Notif: N/BackgroundJob/TaskScheduledNotificationJob; Search: Search/FlowTasksProvider | internal |
| `Db/TaskInboxDueWindow.php` | 12 | Notif: N/BackgroundJob/TaskScheduledNotificationJob | internal |
| `Db/TaskInboxSelection.php` | 12 | Notif: N/BackgroundJob/TaskScheduledNotificationJob; Search: Search/FlowTasksProvider | internal |
| `Listener/Support/EventSchemaLookup.php` | 12 | Compl: Listener/ArchivalNominationListener, Listener/QualityScoreOnSaveListener | moves to Core |
| `Service/Flow/FlowNodeRightsGuardInterface.php` | 12 | Core: Controller/ActionRightsController; Plat: Repair/InitializeFlowNodeRights, Service/ActionRightsCatalogue; + `ContractBindings` | kept |
| `Service/Flow/FlowRunQueueOptions.php` | 12 | Integ: Mcp/BuiltIn/FlowMcpToolProvider | internal |
| `Service/Interaction/AddressedObject.php` | 12 | Core: Controller/ObjectReadStateController; Notif: N/Controller/ObjectWatchersController, N/Service/Timeline/EntryMentionService | moves to Core |
| `Service/OperationsConsoleJobs.php` | 12 | Config: Service/OperationsConsoleService | internal |
| `Service/OperationsConsoleRules.php` | 12 | Config: Service/OperationsConsoleService | internal |
| `Service/Task/TaskStateRules.php` | 12 | Config: Command/RollbackApprovalMigrationCommand; Notif: N/Service/Notification/AnnotationNotificationDispatcher | internal |
| `BackgroundJob/ProcessesJobHandlesInterface.php` | 11 | Config: Service/OperationsConsoleService | internal |
| `Db/SequenceMapperInterface.php` | 11 | Config: Db/ConfigurationMapperBindings, Db/SequenceMapper, Service/SequenceService | moves to Core |
| `Db/TaskInboxOrder.php` | 11 | Notif: N/BackgroundJob/TaskScheduledNotificationJob; Search: Search/FlowTasksProvider | internal |
| `Service/ObjectAction/ElevatedWrite.php` | 11 | Core: Service/Object/PermissionHandler | internal |
| `Service/Task/TaskVtodoWriteBackGateInterface.php` | 11 | Integ: Dav/TaskVtodoWriteBackPlugin; + `ContractBindings` | moves to Integrations |
| `BulkAction/BulkActionRegistrar.php` | 10 | Plat: AppInfo/Application | kept |
| `Db/FlowLinkMapperInterface.php` | 10 | Integ: Service/Integration/BuiltinIntegrationServices, Service/Integration/Providers/FlowProvider | internal |
| `Db/ProcessesMapperRegistrar.php` | 10 | Plat: AppInfo/Application | kept |
| `Db/TaskSequenceMapperInterface.php` | 10 | Config: Db/ConfigurationMapperBindings, Db/TaskSequenceMapper; Plat: Repair/Support/ApprovalTaskStores | internal |
| `Service/Flow/FlowNodePreflightInterface.php` | 10 | Integ: Mcp/BuiltIn/FlowMcpToolProvider, Mcp/BuiltIn/FlowToolServices; + `ContractBindings` | internal |
| `Service/Flow/FlowRegistrar.php` | 10 | Plat: AppInfo/Application | kept |
| `Service/Form/FormDependentsCheckInterface.php` | 10 | Core: Controller/SchemasController; + `ContractBindings` | internal |
| `Service/Interaction/ProcessingListRead.php` | 10 | Core: Service/ObjectService | internal |
| `Service/Party/PartyAddressSelection.php` | 10 | Notif: N/Service/Party/PartyNotificationService | internal |
| `Db/FlowRunStepMapperInterface.php` | 9 | Compl: BackgroundJob/Support/FlowRunRetentionStores | internal |
| `Service/BulkActionRegistryInterface.php` | 9 | Kernel: Event/BulkActionRegistrationEvent; + `ContractBindings` | internal |
| `Service/Calendar/CalendarDateDeclarationCheck.php` | 9 | Core: Db/Schema/ConfigurationValueRules | internal |
| `Service/Lifecycle/AutoTransitionPassInterface.php` | 9 | Compl: BackgroundJob/Support/AutoTransitionServices, Db/AuditTrailMapper; + `ContractBindings` | internal |
| `Service/OperationsConsoleServiceInterface.php` | 9 | Config: Controller/OperationsConsoleController, Controller/Support/OperationsConsoleControllerServices, Service/OperationsConsoleService; + `ContractBindings` | moves to Configuration |
| `Service/Task/TaskInboxServiceInterface.php` | 9 | Notif: N/BackgroundJob/TaskNotificationCandidates, N/Service/Notification/TaskTransitionNotifier; Search: Search/FlowTasksProvider; + `ContractBindings` | internal |
| `Db/TaskAuditMapperInterface.php` | 8 | Compl: Db/TaskAuditMapper; Config: ES/ExampleSetTaskClaim; Plat: Repair/Support/ApprovalTaskStores | moves to Compliance |
| `Kernel/Contracts/Processes/TranslationProjectionInterface.php` | 8 | Plat: Service/TranslationProjectionServiceInterface; + `ContractBindings` | kept |
| `Listener/DecisionTableValidationListenerInterface.php` | 8 | Core: Listener/ListenerBootstrap | internal |
| `Service/Calculation/CalculationEvaluatorInterface.php` | 8 | Config: Command/Support/RematerialiseEvaluation, Service/ManifestService; Core: Service/Object/RenderDerivations, Service/Object/VirtualCalculations; + `ContractBindings` | kept |
| `Service/Calculation/ReferenceResolverInterface.php` | 8 | Config: Command/Support/RematerialiseEvaluation; + `ContractBindings` | internal |
| `Service/Flow/IFlowNodeRequiresRight.php` | 8 | Core: Controller/ActionRightsController | internal |
| `Service/Quota/ObjectQuotaServiceInterface.php` | 8 | Core: Service/Object/SaveObjects/BulkObjectQuota; + `ContractBindings` | internal |
| `Service/SequenceServiceInterface.php` | 8 | Config: Service/SequenceService; Core: Listener/GeneratedIdentifierListener; + `ContractBindings` | moves to Core |
| `Repair/IntakeSourceRegisterSlugsInterface.php` | 7 | Core: Service/Hinge/IntakeSourceRegistry; Plat: Repair/SeedIntakeSourceRegister | moves to Core |
| `Service/Flow/FlowRunContextInterface.php` | 7 | Core: Controller/ObjectsController, Listener/ObjectStateDeleteGuardListener; + `ContractBindings` | internal |
| `Db/TaskCandidateMapperInterface.php` | 6 | Plat: Repair/Support/ApprovalTaskStores | internal |
| `Kernel/Contracts/Processes/WholeSetExportActionInterface.php` | 6 | Config: BulkAction/ExportWholeSetAction; + `ContractBindings` | kept |
| `Service/Calculation/AggregateReferenceResolverInterface.php` | 6 | Config: Command/Support/RematerialiseEvaluation; + `ContractBindings` | internal |
| `Service/Calculation/EvaluationException.php` | 6 | Config: Service/ManifestService | kept |
| `Service/Flow/Principal/PrincipalKindsInterface.php` | 6 | Core: Repair/PerformerTyping; Plat: Repair/TypePerformerReferences | internal |
| `Service/Party/PartySendRefusalInterface.php` | 6 | Notif: N/Service/Party/PartyNotificationService, N/Service/Party/PartyReachGates; + `ContractBindings` | internal |
| `Service/Task/TaskMetricsProviderInterface.php` | 6 | Integ: Service/Observability/OpenRegisterMetricsProvider; + `ContractBindings` | moves to Integrations |
| `Service/Task/TaskSubjectLocatorInterface.php` | 6 | Plat: Repair/BackfillTaskSubjectLocation; + `ContractBindings` | internal |

## 2. What outside callers actually need

The methods and constants they call, not the class surface. "Higher" callers may keep naming Processes; the
question there is only how narrow. "Lower" callers may not name Processes at all.

### From the higher areas (Configuration, Platform)

| Capability | Callers | What they call today |
|---|---|---|
| Write and remove example tasks | `ES/ExampleSetTasks`, `ExampleSetTaskClaim`, `ExampleSetExternalTask`, `ExampleSetAppointments` | `TaskMapper` (`findByUuid`, insert), `TaskServiceInterface`, `TaskImport`, `TaskState::normalise`, `Task` setters and `STATE_AVAILABLE`, `PERFORMER_EXTERNAL`, `EXTERNAL_PARTY_PREFIX`; `TaskAuditMapperInterface::insert` for a claim |
| Realise an example case plan | `ES/ExampleSetCasePlans` | `CasePlanService::ensureItems(...)`, `CaseItemMapperInterface::findByObject`, `CaseItem::REALISATION_TASK`, `getItemKey`, `getRealisationKind`, `getRealisationUuid`, `TaskMapper` |
| Install, update, publish and remove a flow definition | `ES/ExampleSetFlows`, `ExampleSetRunsWrite`, `Config/Types/FlowShareableConfigType` | `FlowMapper` `findByUuid`/`insert`/`update`, `Flow` setters, `FlowService` `find`/`flowToSave`/`deleteStored`, `FlowVersionService` `publish`/`publishShippedUpdate`, `FlowCaller::ownership` |
| Write an example flow run and check it is parked | `ES/ExampleSetFlowRuns`, `ExampleSetFlowRunCheck` | `FlowRun` setters, `STATUS_SUSPENDED`, `TERMINAL`; `FlowRunMapperInterface::insert`; `FlowTaskBridge::SLOT_*`; `FlowResumeState::CONTEXT_KEY` |
| Link and unlink example parties | `ES/ExampleSetParties` | `PartyRoleService::importParty`, `::removeParty(PartyHolding)`, `PartyService::findUnscoped` |
| Read a party object without scoping | eight example-set writers and `ExampleSetTimelineEntries` | `PartyService::findUnscoped`, which is only `ObjectService::find(..., _rbac: false, _multitenancy: false)` |
| Mark an example-set write as setting declared state | `ES/ExampleSetStore` | `LifecycleActionContext::enterDeclaredState`, `::leaveDeclaredState` |
| Operations console panes | `Service/OperationsConsoleService` | `BulkJobMapperInterface` counts and pages through the `OperationsConsoleJobs` bag, `RuleRunMapperInterface`/`RuleRunSummaryMapperInterface` through `OperationsConsoleRules`, `BulkJob::STATE_*`, `RuleRun`/`RuleRunSummary` getters, `ProcessesJobHandlesInterface::BULK_JOB_RUNNER` |
| Setting keys and defaults | `Settings/ConfigurationSettingsHandler`, `Settings/FileSettingsHandler`, `Repair/ProtectStoredSecrets` | `PartySearchService::CAP_KEY`, `DEFAULT_CAP`; `ReviewPolicy::ANNOTATION`, `SETTINGS_KEY`, `fromDeclaration`; `new ReviewAnnotationValidator`; `CalendarFeedTokenServiceInterface::DEFAULT_TTL_KEY`, `DEFAULT_TTL_SECONDS` |
| Calendar tasks for imported configuration | `Configuration/ImportHandler`, `ConfigurationServiceRegistrar` | `Service\TaskService` (the CalDAV task service) by class |
| Evaluate a calculation | `ManifestService`, `Command/Support/RematerialiseEvaluation` | `CalculationEvaluatorInterface::evaluate`, `EvaluationException` |
| A bulk action from another area | `BulkAction/ExportWholeSetAction`, `WholeSetExportRun` | implements `Kernel/Contracts/Processes/WholeSetExportActionInterface`; `BulkActionResult`, `BulkStep` |
| Flow node rights | `Repair/InitializeFlowNodeRights`, `Service/ActionRightsCatalogue` | `FlowNodeRightsGuardInterface::nodeRights` |
| Translation projection | `Service/TranslationProjectionServiceInterface` | extends `Kernel/Contracts/Processes/TranslationProjectionInterface` |
| Registration | `AppInfo/Application` | `FlowRegistrar`, `BulkActionRegistrar`, `ProcessesMapperRegistrar` `::register`; `ListenerBootstrap::register` (Core today) |
| Flow and task tables in repair steps and a rollback command | `Repair/CheckFlowTimerInvariants`, `PurgePhantomMigratedFlows`, `MigrateRegisterFlowsToTable`, `TypePerformerReferences`, `BackfillTaskSubjectLocation`, `ReportReferenceLoopSchemas`, `Repair/Support/ApprovalTaskStores`, `EveryFlow`; `Command/RollbackApprovalMigrationCommand` | the flow, run, timer, task, task-audit and task-sequence mappers and entities; these are Processes' own migrations (3.2) |
| The approval chain entity | Processes' approval chain services name Configuration's `Db/TaskSequence` (against the order) | the entity and its mapper; they are Processes' work (3.2) |

### From the lower areas (against the order today)

| Capability | Callers | What they call today |
|---|---|---|
| Rendering contributions of an object | Core `RenderObject` | `ReadStateService`, `ObjectActionList`, `new CalculationPayload` (watchers: D03's `ObjectFollowStateInterface`) |
| Recording and lensing reads | Core `GetObject`, `SearchLenses`, `SearchQueryHandler`, `ObjectService` | `ReadHistoryService::registerAuditRead`, `::resolveRecentLens`, `REASON_UNAVAILABLE`; `ProcessingListRead` |
| The automatic pass around a save | Core `ObjectService`, `ObjectHistoryServices`; Compliance `AuditTrailMapper` | `AutoTransitionPass::enter`, `::leave`, `::preferAutomatic`; `AutoTransitionPassInterface` by class; `GraphMoveCatalogue::REOPEN` |
| Checks on the save path | Core `ObjectSaveChecks`, `BulkRowRules`, `DraftActivation(Checks)`, `DraftStatusPolicy` | `DependentValueListener` (type), `DecisionTableBulkGuard::refusals`, `FormPayloadFindings::fromResult`/`fromThrowable`/`isPayloadRefusal`, `FormDestinationValidator::isServerFilled` |
| Is a flow run writing now | Core `SaveObject`, `RevertHandler`, `SaveObjectRuntime`, `BulkRowRules`, `ObjectsController`, `ObjectStateDeleteGuardListener` | `FlowRunContext::currentRunUuid` |
| Object quota | Core `BulkObjectQuota` | `ObjectQuotaServiceInterface::count`, `::limitFor`; `ObjectQuotaListener::ERROR_CODE`, `ERROR_CODE_UNCHECKED`, `exceededMessage`, `uncheckedMessage` |
| Schema change guards | Core `SchemasController` | `FlowTriggerRenameCheck`, `FormDependentsCheckInterface`, `ApprovalPositionFormGuard::declaresForms` |
| Schema declaration checks | Core `AdvisoryAnnotationChecks`, `ConfigurationValueRules` | `new PartyAnnotationValidator`, `CalendarDateDeclarationCheck::assertDeclarable` |
| Elevated writes and their attribution | Core `PermissionHandler`; Compliance `AuditTrailCollaborators` | `ObjectActionElevationScope::permits(ElevatedWrite)`, `::attributionFor`; `LifecycleActionContext::declaredFor`, `::systemAppFor` |
| Party rules | Core `ObjectTreeService`, `FilePublishingHandler` | `PartyDefinition::fromSchema`, `PartyIndicatorGuard` |
| Keep lifecycle states during a register import | Core `RegistersController` | `KeepLifecycleStatesScope::run(callable)` |
| Virtual values | Core `VirtualCalculations`, `RenderDerivations` | `CalculationEvaluatorInterface::evaluate` |
| Calendar events and tasks of an object | Core `ObjectSourceWiring`, `CalendarEventObjectSourceProvider`, `ObjectRelationCleanupLinks`; Integrations `CalendarLinkService`, `BuiltinIntegrationServices`, `CalendarProviderServices` | `CalendarEventService` `createEvent`/`getEventsForObject`/`unlinkEvent`/`getAllUserEvents`, `Service\TaskService` |
| Contact matching | Core `ObjectCacheInvalidator` | `ContactMatchingService` by class |
| Action rights | Core `ActionRightsController` | `FlowNodeRightsGuardInterface::adminMap`, `::setAdminMap`, `IFlowNodeRequiresRight::RIGHT_PREFIX` |
| Task events and tasks | Kernel `Event/Task*Event`; Core `AbstractTaskEvent`, `EntityEventPublisher`; Search `Db/View` | the `Task` entity as a type |
| Task notifications | Notifications `TaskNotificationCandidates`, `TaskScheduledNotificationJob`, `TaskTransitionNotifier`, `TaskObjectAdapter(s)`, `AnnotationNotificationDispatcher` | `TaskMapper`, `TaskInboxServiceInterface::inbox`/`enrich` with the seven `TaskInbox*` values, `Task` getters, `TaskStateRules::isRejectingOutcome` |
| Automation ledger | Notifications `AnnotationNotificationDispatcher`, `DeliveryLedger` | `AutomationRecordWriter::draft`, `AutomationRecord::KIND_NOTIFICATION` |
| Party addresses | Notifications `PartyNotificationService`, `PartyReachGates` | `PartyServiceInterface::find`, `definitionFor`, `addresses`, `addressesOfKind(PartyAddressSelection)`; `PartySendRefusalInterface` |
| Reminder units | Notifications `NotificationReminderValidator` | `SlaCalculatorInterface::UNITS` |
| Task search | Search `FlowTasksProvider` | `TaskInboxServiceInterface::inbox` with the `TaskInbox*` values and `TaskInboxPage` |
| Case transitions | Compliance `DsarCaseController`, `DsarCaseCases` | `TransitionEngine::transition` |
| Final lifecycle states | Compliance `ArchivalNominationService` | `LifecycleFinalStateResolver::isFinalByReference`, `::isReferenceForm` |
| Flow run retention | Compliance `FlowRunRetentionStores` (bag of the retention job) | `FlowRunMapperInterface`, `FlowMapperInterface`, `FlowRunStepMapperInterface` |
| Flow attribution in the audit trail | Compliance `AuditTrailMapper` | `new AuditFlowAttribution`, which already implements Compliance's `AuditFlowAttributionInterface` |
| Declared-date calendars | Integrations `Calendar/DeclaredDateCalendars` | `ObjectDateDeclaration::allFromConfig`, `ObjectCalendarFeedService::eventsFor`, `ObjectDateEvents`, `ObjectCalendarFeedSources`, `CalendarFeedToken::SCOPE_*` |
| Flow links of an object | Integrations `BuiltinIntegrationServices`, `Providers/FlowProvider`, `RelationsController` | `FlowLinkService`, `FlowLinkServiceInterface`, `FlowLinkPlatform`, `FlowLinkMapperInterface`, `FlowLink` getters, `FlowJsonColumn::decode` |
| Flows as agent tools | Integrations `Mcp/BuiltIn/FlowMcpToolProvider`, `FlowToolServices` | `FlowServiceInterface` `find`/`save`/`assertRunnable`, `FlowNodePreflightInterface::inspect`, `FlowRunServiceInterface::queueRun(FlowRunQueueOptions)`, `FlowRunMapperInterface::findByUuid` |
| CalDAV write-back and task metrics | Integrations `Dav/TaskVtodoWriteBackPlugin`, `Observability/OpenRegisterMetricsProvider` | `TaskVtodoWriteBackGateInterface::handleWrite`/`isProjected`, `TaskMetricsProviderInterface::metrics` |
| Listener registration | Core `ListenerBootstrap` | `ListenerRegistrar::register`; binds `DecisionTableValidationListenerInterface` |
| Bulk action registration | Kernel `Event/BulkActionRegistrationEvent` | `BulkActionInterface`, `BulkActionRegistryInterface::register` |

## 3. The proposed contract set

### 3.1 Wiring (BRIEF way 5): Processes binds its own classes

- A new `lib/Service/ProcessesServiceBindings.php` (csv row Processes) holds every `interface => class` entry of
  `ContractBindings::BINDINGS` whose class is Processes' (about 150 entries, 304 files, including the Kernel
  contracts `FlowTimerControlInterface`, `TaskManagerInterface`, `ContactMatcherInterface`,
  `ObjectCalendarEventsInterface`, `ObjectCalendarTasksInterface` and Core's `BulkTranslationServiceInterface`
  that Processes classes implement). A new `lib/Service/ProcessesServiceRegistrar.php` (about 12 lines) binds
  it through `DbRegistrar::bind`, exactly like `ComplianceServiceRegistrar` and `PlatformServiceRegistrar`.
  `Application::register()` calls it right after `ContractBindingsRegistrar::register()`. Same alias, same
  shared instance, same registration phase, so resolution is unchanged. The map stays hidden; only the
  registrar is named outside the area.
- Entries whose interface is Processes' but whose class is another area's go to that class's map:
  `OperationsConsoleServiceInterface => OperationsConsoleService` stays in Configuration's map (and the
  interface counts to Configuration, 3.2), `SequenceServiceInterface => SequenceService` follows the sequence
  rows to Core's map (D02's `CoreServiceBindings`).
- `Listener/ListenerBootstrap.php` counts to Processes (3.2). Its `PresencePushInterface => NotifyPushListener`
  binding is a Platform class and moves to `PlatformServiceBindings`, registered before the listeners as
  today. `DecisionTableValidationListenerInterface` stays with it. Then `ListenerRegistrar` (340 lines) and
  `DecisionTableValidationListener` (135) are no longer exposed, and Core no longer names Processes from the
  bootstrap. The listener sequence does not change: `listener-order.json` and the count and hash in
  `ListenerRegistrationOrderGoldenTest` stay as they are and are the proof.
- `Db/ConfigurationMapperBindings` stops binding `TaskSequenceMapperInterface`; `ProcessesMapperBindings` binds
  it, with the task-sequence rows (3.2). `SequenceMapperInterface` goes to Core's mapper map with the sequence
  rows.
- `ProcessesJobHandlesInterface::BULK_JOB_RUNNER` and the `AnnotationNotificationDispatchJob` and
  `TranslationProjectionJob` handles reach Configuration's console and Core's `QueuedJobClassesInterface`
  through the job-handle collection (decided; P46). No new contract here.

### 3.2 Classes that count to the area whose work they do (BRIEF way 3, csv rows only, no file moves)

Each row rests on what the class does and on its users, never on hiding a reference. Every row was checked in
the simulation for new references against the order: there are none.

**Into Processes (31 files, 1,443 lines):**

| Files (lines) | From | Why it is Processes' work |
|---|---|---|
| `Repair/CheckFlowTimerInvariants` (97), `PurgePhantomMigratedFlows` (160), `MigrateRegisterFlowsToTable` (204), `MigrateRegisterFlowsRun`, `TypePerformerReferences` (46), `BackfillTaskSubjectLocation` (63), `ReportReferenceLoopSchemas` (52); `Repair/Support/ApprovalTaskStores` (16), `EveryFlow` (25), `ChainMigrationRun` (10), `SequenceMigrationRun` (11) | Platform | Repair steps that check and migrate the flow, flow-timer, task and approval-chain tables, and their helpers. `info.xml` registers them; no Platform class names them. Processes' own repair steps (`BackfillFlowVersions`, `MigrateApprovalChainsToTasks`, `SeedTaskFixtures`) already use the helpers, against the order today. |
| `Repair/PerformerTyping` (162), `Repair/PerformerField` (10) | Core | Rewrites performer references inside flow definitions; its only user is `TypePerformerReferences` (above). D02 proposed Platform; with its user in Processes, Processes is the lowest consumer. |
| `Command/RollbackApprovalMigrationCommand` (134), `Command/Support/ApprovalRollbackRun` | Configuration | Rolls back the approval-chain-to-task migration (Processes' `MigrateApprovalChainsToTasks`); reads only tasks. |
| `Command/Support/RematerialiseEvaluation` (14), `RematerialiseStorage` (21) | Configuration | Bags whose only user is Processes' `RematerialiseCalculationsCommand` (the decided bag rule). |
| `Db/TaskSequence` (55), `Db/TaskSequenceMapper` (43) | Configuration | The approval chain entity and mapper. The mapper implements Processes' `TaskSequenceMapperInterface`; every service that uses them is Processes' (`TaskSequenceService`, `SequenceClosure`, `TaskSequenceDecisionGuard`, the chain listeners). Removes 8 Processes → Configuration references. |
| `BackgroundJob/Support/AbandonedFlowClaim` (30), `AutoTransitionServices` (14), `BulkJobServices` (12) | Compliance | Support bags whose only user is a Processes job (flow claim sweep, `AutoTransitionJob`, `BulkJobRunner`). |
| `Db/Flow/FlowJson` (25) | Integrations | JSON column handling of the `Flow` entity; its only user is Processes. |
| `Controller/Support/TaskEventsControllerServices`, `TranslationControllerServices`, `TranslationMappers`; `Service/BulkTranslationServiceInterface` | Core | Already decided in D02 (bag rule and interface of a Processes class); listed because the simulation needs them together. |
| `Controller/ObjectReadStateController` (108), `Controller/Support/ObjectReadStateControllerServices` | Core | The read-state endpoints. Read state is Interaction work (`ReadStateService`, `ReadStatePruneListener`). The read-state endpoint that sits in `ObjectsController` today moves into it (the decided rule for `ObjectsController` endpoints; URL and verb unchanged). |
| `Listener/ListenerBootstrap` (16) | Core | The entry of the listener sequence, whose list (`ListenerRegistrar`) is Processes'. After its Platform binding moves (3.1) it binds and registers only Processes classes. This amends D02's kept list (minus 16 Core lines). |
| `Event/BulkActionRegistrationEvent` (16) | Kernel | The collection event of Processes' bulk action registry. Processes dispatches it and every listener contributes to Processes' registry. Name and namespace unchanged (dossiq listens to it). Kernel loses 16 lines. |

**Out of Processes:**

| Files (lines) | To | Why |
|---|---|---|
| `Listener/Support/PushSlugResolver` (37), `NotifyPushPlatform` (14) | Platform | Helpers whose only user is Platform's `NotifyPushListener`. |
| `Service/OperationsConsoleNotifications` (14); `Service/OperationsConsoleServiceInterface` (9) | Configuration | The bag (as D03 decided) and the interface of Configuration's `OperationsConsoleService`, its implementation; every user is Configuration. Removes the Processes → Configuration reference to `NotificationTemplateRegistry`. |
| `Db/TaskAuditMapperInterface` (8) | Compliance | The interface of Compliance's `TaskAuditMapper`, its implementation (the D02 rule for mapper interfaces implemented by Compliance mappers). Processes names it downward. |
| `Repair/V1ChainVerificationRun` (20) | Compliance | Helper whose only user is Compliance's `RechainAuditTrailForFlowAttribution`. |
| `Service/Task/TaskVtodoWriteBackGateInterface` (11), `Service/Task/TaskMetricsProviderInterface` (6) | Integrations | Narrow plug-in interfaces that Integrations' DAV plugin and metrics provider declare they need; Processes implements them. |
| `Db/DecisionMetadataEdit` (12) | Core | The parameter value of Core's `EntityRelationMapper` (and Files' interface of it); its lowest user is Core. |
| `Repair/IntakeSourceRegisterSlugsInterface` (7) | Core | Constants Core's `Hinge/IntakeSourceRegistry` reads; Platform's seeding step implements it. Lowest user Core. |
| `Listener/Support/EventSchemaLookup` (12), `HandlesObjectDeletion` (25) | Core | Generic listener plumbing (resolve the schema of an object event; react to a deletion) used by Compliance and Processes listeners. Flagged for D02: Compliance is the lowest user, Core the natural owner. |
| `Db/SequenceMapperInterface` (11), `Service/SequenceServiceInterface` (8), with Configuration's `Db/Sequence`, `Db/SequenceMapper`, `Service/SequenceService`, `Service/SequenceScope` | Core | Numbering sequences. Their callers are Core's `GeneratedIdentifierListener` on the save path and Processes' calculations; the lowest user is Core. Today the interfaces are Processes', the implementation Configuration's, and both directions cross the order. Flagged for D02 and D09 (they own the Core and Configuration halves); the simulation counts it. |
| D03's rows: `Interaction/WatcherService`, `WatcherServiceInterface`, `WatcherStore`, `WatcherCaller`, `FavouriteService(Interface)`, `Controller/ObjectFavouriteController`, `BackgroundJob/ScheduledMessageSweepJob`, `AnnotationNotificationDispatchJob` → Notifications; `Interaction/AddressedObject` → Core | Notifications, Core | Decided in D03; counted here. |

### 3.3 Ports owned by Processes: `lib/Kernel/Contracts/Processes/`

For Configuration and Platform. Each is one capability, its methods are what the callers call today, and its
values are arrays, ids or existing Kernel contracts (`TaskInterface`, `CaseItemInterface`), never a Processes
entity. Implementations are small new classes that compose the existing services, so no large service grows.

| Port | Methods (one line each) | Implemented by | Lines |
|---|---|---|---|
| `TaskSeedInterface` | `public function seed(array $row): TaskInterface;` · `public function find(string $uuid): ?TaskInterface;` · `public function recordClaim(string $taskUuid, string $actor): void;` · `public function unseed(string $uuid): bool;` | new `Service/Task/TaskSeeds` over `TaskMapper`, `TaskImport`, `TaskState` and `TaskAuditMapperInterface`. The row carries state and performer as today's strings; the constants stay inside. | 9 |
| `CasePlanSeedInterface` | `public function realise(string $caseUuid, array $definition, string $app): array;` (item key => realised task uuid) | new `Service/Case/CasePlanSeeds` over `CasePlanService::ensureItems` and `CaseItemMapperInterface::findByObject` | 7 |
| `FlowDefinitionsInterface` | `public function find(string $uuid): ?array;` · `public function install(array $definition, string $owner): string;` · `public function update(string $uuid, array $definition): void;` · `public function publish(string $uuid, bool $shippedUpdate): void;` · `public function remove(string $uuid): bool;` | new `Service/Flow/FlowDefinitions` over `FlowMapper`, `FlowService` (`flowToSave`, `deleteStored`), `FlowVersionService` and `FlowCaller` | 10 |
| `FlowRunSeedInterface` | `public function seed(array $run): string;` · `public function isParked(string $runUuid): bool;` | new `Service/Flow/FlowRunSeeds` over `FlowRunMapperInterface`, holding the `FlowTaskBridge` slots and `FlowResumeState::CONTEXT_KEY` | 7 |
| `PartyLinkSeedInterface` | `public function link(ObjectEntity $object, array $payload, string $linkedBy): string;` · `public function unlink(string $objectUuid, array $holding): int;` | new `Service/Party/PartyLinkSeeds` over `PartyRoleService` (`importParty`, `removeParty`); `PartyHolding` stays inside | 7 |
| `DeclaredStateWritesInterface` | `public function enterDeclaredState(string $uuid): void;` · `public function leaveDeclaredState(string $uuid): void;` | `Lifecycle/LifecycleActionContext` (implements; same instance) | 6 |
| `OperationsRunsInterface` | `public function bulkJobCounts(): array;` · `public function bulkJobs(?string $state, int $limit): array;` · `public function ruleRuns(DateTimeInterface $since, int $limit): array;` · `public function failingRules(int $limit): array;` (rows as `jsonSerialize()` gives them today) | new `Service/OperationsRuns` over the two Processes bags `OperationsConsoleJobs`, `OperationsConsoleRules` | 9 |
| `ProcessSettingKeysInterface` | `PARTY_SEARCH_CAP_KEY`, `PARTY_SEARCH_DEFAULT_CAP`, `REVIEW_POLICY_SETTINGS_KEY`, `REVIEW_POLICY_ANNOTATION`, `FEED_TOKEN_TTL_KEY`, `FEED_TOKEN_TTL_SECONDS` (literals equal to today's values, asserted by a unit test) | `PartySearchService`, `ReviewPolicy` and `CalendarFeedTokenServiceInterface` keep their constants, defined as the port's | 8 |
| `ReviewPolicySettingsInterface` | `public function normalise(mixed $declaration): array;` (validates as `ReviewAnnotationValidator` does and returns `ReviewPolicy::fromDeclaration(...)` as an array) | `Decision/ReviewPolicyResolver` | 6 |
| **Total** | | | **69** |

Callers that take an existing lower contract instead, at no new cost:
- the eight example-set writers on `PartyService::findUnscoped` use the Kernel `ObjectOperationsInterface` read
  with rbac and multitenancy off (what `findUnscoped` does, including its "null on failure");
- `ImportHandler` and `ConfigurationServiceRegistrar` type `TaskService` as the Kernel
  `ObjectCalendarTasksInterface`, which it already implements.

Ports deliberately not added:
- a registrar port. `ProcessesServiceRegistrar`, `FlowRegistrar`, `BulkActionRegistrar`,
  `ProcessesMapperRegistrar` and `ListenerBootstrap` stay named by `Application`, as every area's registrar is.
- a bulk action port. `WholeSetExportActionInterface`, `BulkActionResult` and `BulkStep` already are the bulk
  action contract another area implements (46 lines plus 6); dossiq uses `BulkActionResult` and `BulkStep`.
- a calculation port for Configuration. `CalculationEvaluatorInterface::evaluate` (8) is already one method.

### 3.4 Ports owned by the lower areas, implemented by Processes

Lower areas may not name a Processes port. Where they need Processes' work, the lower area owns the port and
Processes implements and binds it in `ProcessesServiceBindings` (the presence and action-rights pattern, #10675,
#10688). These lines count to the owner's budget. The callers are already in wave 4 packages; the column
"Package" names the one that owns the caller, so it lands on this port and the Processes class ends up
internal. Where an existing mechanism already fits, it costs no new line.

| Owner | Port | Methods (one line each) | Implemented by | Package | Lines |
|---|---|---|---|---|---|
| Core | `ObjectRenderContributionInterface` | `public function contribute(ObjectEntity $object, array $rendered, RenderSelection $selection): array;` (a list in render order; read state, actions, calculations; watchers come through D03's `ObjectFollowStateInterface`) | `ReadStateService`, `ObjectActionList`, `CalculationEvaluator` (through small contribution classes) | P02 (`RenderObject` hotspot) | 7 |
| Core | `ReadHistoryInterface` | `public function recordRead(ObjectEntity $object, bool $audit): void;` · `public function recentLens(?string $userId): array;` · `public function processingListRead(ObjectEntity $object): void;` | `ReadHistoryService` | P14, P24, P26 | 8 |
| Core | `AutomaticWriteScopeInterface` | `public function enter(): void;` · `public function leave(): array;` · `public function preferAutomatic(ObjectEntity $saved, array $applied): ObjectEntity;` · `public function isAutomatic(): bool;` (Compliance's audit mapper reads the last, and the reopen move as a literal) | `AutoTransitionPass` | P24, P31, P45 | 7 |
| Core | `ObjectSaveCheckInterface` | `public function position(): int;` · `public function check(ObjectSaveCheck $check): array;` (a positioned collection like #10685, each contributor in its present slot) | `DependentValueListener`, `DecisionTableBulkGuard`, `FormPayloadFindings`, `FormDestinationValidator` contributions | P26 | 7 |
| Core | `FlowRunScopeInterface` | `public function currentRunUuid(): ?string;` | `FlowRunContext` (`FlowRunContextInterface extends` it) | P09, P21, P26, P45; `ObjectsController` is in no package (D02 hotspot) | 5 |
| Core | `ObjectQuotaInterface` | `ERROR_CODE`, `ERROR_CODE_UNCHECKED` (literals) · `public function count(int $registerId, Schema $schema, string $organisationUuid): int;` · `public function limitFor(Schema $schema): ?int;` · `public function exceededMessage(int $limit): string;` | `ObjectQuotaService` (`ObjectQuotaServiceInterface extends` it) | P40 | 9 |
| Core | `SchemaChangeCheckInterface` | `public function position(): int;` · `public function check(SchemaChange $change): array;` (rename and delete guards as a positioned collection) | `FlowTriggerRenameCheck`, `FormDependentsCheck`, `ApprovalPositionFormGuard` | P02 (`SchemasController` hotspot) | 6 |
| Core | `WriteElevationInterface` | `public function permits(string $objectUuid, string $verb): bool;` · `public function attributionFor(string\|int\|null $registerId, string\|int\|null $schemaId, string $verb): ?array;` · `public function declaredFor(string $uuid): ?string;` · `public function systemAppFor(string $uuid): ?string;` | new `Service/ObjectAction/WriteElevation` over `ObjectActionElevationScope` and `LifecycleActionContext` | P26, P39 | 7 |
| Core | `PartyRulesInterface` | `public function isPartySchema(Schema $schema): bool;` · `public function guardPublication(ObjectEntity $object): void;` | new `Service/Party/PartyRules` over `PartyDefinition::fromSchema`, `PartyIndicatorGuard` | P06, P45 | 7 |
| Core | `LifecycleStatesScopeInterface` | `public function run(callable $operation): mixed;` | `KeepLifecycleStatesScope` (its static `run` delegates) | P22 | 5 |
| Core | `VirtualValuesInterface` | `public function evaluate(array $object, mixed $expression): mixed;` | `CalculationEvaluator` | P26 | 6 |
| Core | existing: `SchemaDeclarationCheckInterface` through `SchemaDeclarationChecksEvent` | `position()`, `check()` | `PartyAnnotationValidator`, `CalendarDateDeclarationCheck` contributions, in their present slots | P37, P42 | 0 |
| Core | existing: `ActionRightsInterface` (decided: `ActionRightsController` stays Core) | as today | flow node rights | P31 | 0 |
| Kernel | existing: `ObjectCalendarEventsInterface`, `ObjectCalendarTasksInterface`, `ContactMatcherInterface`, `TaskInterface` | as today | `CalendarEventService`, `TaskService`, `ContactMatchingService`, `Task` | P12, P13, P17, P19, P26, P34, P42, P44, P45 | 0 |
| Compliance | `CaseTransitionsInterface` | `public function transition(string $objectId, string $action, array $data = []): ObjectEntity;` | `TransitionEngine` | P41, P42 | 5 |
| Compliance | `FinalStatesInterface` | `public function isReferenceForm(mixed $final): bool;` · `public function isFinalByReference(array $declaration, string $state): bool;` | `LifecycleFinalStateResolver` | P44 | 6 |
| Compliance | `RunRetentionInterface` | `public function purgeEndedBefore(DateTimeInterface $cutoff, int $batchSize): int;` | new `Service/Flow/FlowRunRetention` over the three run mappers | P27 | 6 |
| Compliance | existing: `AuditFlowAttributionInterface` | `apply(AuditTrail $auditTrail)` | `AuditFlowAttribution` (already) — `AuditTrailMapper` takes it injected instead of constructing the class | P31 | 0 |
| Notifications | `TaskNoticesInterface` | `public function dueIn(DateTimeInterface $from, DateTimeInterface $to, int $limit): array;` (of `TaskInterface`) · `public function enrich(TaskInterface $task, ?string $caller = null): array;` · `public function isRejectingOutcome(?string $outcome): bool;` | new `Service/Task/TaskNotices` over `TaskInboxService` and `TaskStateRules` | P02, P25 | 9 |
| Notifications | `AutomationLedgerInterface` | `public function draft(string $kind, mixed $key, string $name, ?ObjectEntity $object): array;` (`KIND_NOTIFICATION` as a literal) | `AutomationRecordWriter` | P02 | 6 |
| Notifications | `PartyAddressesInterface` | `public function find(string $partyUuid): ?ObjectEntity;` · `public function addresses(ObjectEntity $party): array;` · `public function addressesOfKind(ObjectEntity $party, string $kind, bool $primaryOnly): array;` · `public function refusalFor(ObjectEntity $party, string $channel): ?string;` | `PartyService` (`PartyServiceInterface extends` it) | P03 | 9 |
| Notifications | none: `SlaCalculatorInterface::UNITS` becomes a literal list on the reminder validator, asserted equal by a unit test | | | P02 | 0 |
| Search | `TaskSearchInterface` | `public function search(string $userId, string $term, int $limit, int $offset): array;` | new `Service/Task/TaskSearch` over `TaskInboxService::inbox` | P03 | 6 |
| Integrations | `DeclaredDateEventsInterface` | `public function calendarsFor(IUser $user, array $calendarConfig): array;` · `public function eventsFor(IUser $user, string $scopeType, string $scopeId): ?array;` | `ObjectCalendarFeedService` | P20 | 7 |
| Integrations | `ObjectFlowLinksInterface` | `public function linksFor(string $entityType, string $entityId): array;` · `public function link(array $link): array;` · `public function unlink(int $linkId): void;` | `FlowLinkService` (`FlowLinkServiceInterface extends` it) | P19, P33, P42 | 8 |
| Integrations | `FlowToolsInterface` | `public function find(string $uuid): array;` · `public function save(array $data, ?string $uuid = null): array;` · `public function inspect(array $flow): array;` · `public function queueRun(string $flowId, array $options): array;` · `public function run(string $runUuid): array;` | new `Service/Flow/FlowTools` over `FlowService`, `FlowNodePreflight`, `FlowRunService`, `FlowRunMapper` | P25 | 11 |

Lines owned by lower areas: Core 74, Compliance 17, Notifications 24, Search 6, Integrations 26.

The `TaskInbox*` values (`TaskInboxCriteria`, `Audience`, `DueWindow`, `Selection`, `Order`, `Filter`,
`TaskInboxPage`) and `PartyAddressSelection` stay inside Processes; the lower ports take scalars.

### 3.5 Entities that cross the boundary

| Entity | Crosses to today | After |
|---|---|---|
| `Db/Task` | Kernel and Core task events, Notifications, Search `View`, Configuration example sets | The Kernel `TaskInterface` (rule 4, exists; `Task` implements it). Example sets get it back from `TaskSeedInterface`. |
| `Db/TaskSequence` | Kernel `TaskSequenceCompletedEvent`, `TaskSequenceUndecidedEvent`, Core `AbstractTaskSequenceEvent` | Kept until D01 decides a Kernel `TaskSequenceInterface` that `TaskSequence` implements. buildiq, decidiq and dossiq call `getSequence()` and `getTask()` on these events, so the payload may only change to a Kernel contract the old object implements (decided). 55 lines, flagged. |
| `Db/Flow`, `FlowRun`, `FlowVersion`, `FlowTimer`, `FlowLink`, `FlowClaim`, `CaseItem`, `BulkJob`, `RuleRun`, `RuleRunSummary`, `AutomationRecord`, `CalendarFeedToken` | Configuration, Platform, Integrations, Compliance, Notifications, Core | Internal. Arrays from the ports of 3.3 and 3.4; repair steps that read them count to Processes (3.2). `CaseItem` callers that need an object get the Kernel `CaseItemInterface`. |
| `Db/ObjectEntity`, `Db/Schema` (Core) in Processes ports | | Stay: they point down. |

## 4. The budget

| Exposed after | Lines | Named by | Why it stays |
|---|---|---|---|
| The nine Processes ports (3.3) | 69 | Configuration, Platform | the contract |
| `Db/TaskSequence` | 55 | Kernel, Core task-sequence events | needs D01's Kernel `TaskSequenceInterface` (3.5) |
| `BulkAction/BulkActionResult`, `BulkStep`; `Kernel/Contracts/Processes/WholeSetExportActionInterface` | 52 | Configuration's whole-set export action | the bulk action contract (3.3) |
| `ProcessesServiceRegistrar`, `ListenerBootstrap`, `FlowRegistrar`, `BulkActionRegistrar`, `ProcessesMapperRegistrar` | 58 | Platform `Application` | entry points, thin by rule 5 |
| `Service/Rules/ExpressionDefaultException` | 22 | Core `ObjectsController`, `SaveObjectInterface` | left to the Kernel lane (L2, decision 3), as the L3 notes say |
| `Service/Flow/FlowNodeRightsGuardInterface` | 12 | Platform `InitializeFlowNodeRights`, `ActionRightsCatalogue` | already one capability (`nodeRights`) |
| `Service/Calculation/CalculationEvaluatorInterface`, `EvaluationException` | 14 | Configuration `ManifestService` | already one method |
| `Kernel/Contracts/Processes/TranslationProjectionInterface` | 8 | Platform | existing port |
| **Total** | **290** (23 files) | | |

Before: 32,009 lines in 413 files. After: 290 lines in 23 files.

Processes' share of the target. Kernel takes about 9,700 lines, which leaves about 9,200 for the nine other areas
at 4.0 and about 2,900 at 4.5. Processes is 85,705 of the 449,016 non-Kernel lines (19.1%), so its proportional
share is about 1,760 lines at 4.0 and about 550 at 4.5.
- **4.0:** 290 fits, with room.
- **4.5:** 290 fits. Two cuts would bring it to about 210, named and not designed further:
  - `ProcessesServiceRegistrar` calls the flow, bulk action and mapper registrars, so `Application` names two
    Processes files instead of five: −30. It needs a test that no container key is registered twice, because
    the registrars run at different points of `Application::register()` today.
  - D01's `TaskSequenceInterface`: −55 here, +about 8 in Kernel.

**Nothing here only fits by breaking a rule.** Two things are flagged:
- The 30 references against the order that remain out of Processes. 19 of them are translation:
  `BulkTranslationService`, `TranslationController`, `PropertyTranslation` and `TranslationProjectionJob`
  (Processes) name Configuration's `Db/Translation` and translation providers and Platform's `TranslationSlot`,
  `TranslationStores`, `TranslationStatusServiceInterface`. Core's `RenderObject` and `SaveObject` name the same
  classes. Translation is one capability spread over four areas; it should count to one, and its lowest user is
  Core. That is a decision across D02, D08, D09 and D10, and the wave 4 packages P20, P36, P40 and P41 own the
  files. This design does not move it. The others are three listener blocks in `ListenerRegistrar` that register
  Configuration and Platform listeners (`RedactionPolicyCacheListener`, `ShareableConfigTypeRegistrationListener`,
  `NotifyPushListener`), which take the positioned per-area registrar pattern of D02 and D03 in D09 and D10;
  `RepairPlatform` (2, P19); `FlowAccess` → `OpenRegisterActionAuthService`, `RulesController` → `OpenRegisterAdmin`
  and `ExampleSetDateValue`, `ReviewPolicyResolver` → `FileSettingsHandler`, `SeedFlowTimerRegister` →
  `AppRegisterDescriptor` (P28, P36, P44).
- The sequence rows to Core and the listener toolkit rows to Core (3.2) are Core-side decisions; the numbers
  count them, and without them about 75 lines stay exposed in Processes.

Effects on other areas, from the same simulation (including D02's and D03's rows that touch this area):

| Area | Exposed lines before | After |
|---|---|---|
| Kernel | 8,515 | 8,499 (`BulkActionRegistrationEvent` counts to Processes) |
| Core | 65,057 | 65,008 (11 Core-owned ports, 74 lines, become exposed; the bootstrap, performer typing and bags leave) |
| Notifications | 1,914 | 2,171 (D03's follow group arrives exposed; three ports, 24 lines) |
| Search | 2,072 | 2,078 (one port) |
| Compliance | 15,739 | 15,708 (three ports, 17 lines; three job bags leave) |
| Integrations | 18,785 | 18,796 (three ports, 26 lines; `FlowJson` leaves) |
| Configuration | 6,895 | 6,390 (`NotificationTemplateRegistry` no longer exposed; task-sequence and rematerialise rows leave) |
| Platform | 976 | 914 (repair helpers leave) |
| **Whole app** | **155,651** | **123,543** |

## 5. The build packages

Dependency order. Within a step, packages touch disjoint files. Three shared files are listed once here and not
per package: `scripts/component-map.csv` and its two generated files (the components file and
the deptrac areas file), regenerated by every package that touches the csv, and
`lib/Service/ProcessesServiceBindings.php`, the single Processes map, which R1 creates and R5a, R5b and every wave 4
package of section 3.4 add lines to. Packages that edit it are stacked, never in parallel.

**R1: Processes service map (3.1, first bullet).** Stacked after D02's C5-01 (both edit `ContractBindings`), or
before it with a rebase-free merge of main.
Files:
- new `lib/Service/ProcessesServiceBindings.php`, `lib/Service/ProcessesServiceRegistrar.php`
- `lib/Service/ContractBindings.php` (drop about 150 entries)
- `lib/AppInfo/Application.php` (one call)
- csv
- a unit test that every former entry resolves to the same shared instance

A second PR retargets the `tests/Unit/Architecture/*InterfaceBindingsTest` cases that read
`ContractBindings::BINDINGS` for Processes entries. Removes 20,829 exposed lines; no reference changes.

**R2: the listener bootstrap counts to Processes.**
Files:
- `lib/Listener/ListenerBootstrap.php` (drop the Platform binding)
- `lib/Service/PlatformServiceBindings.php` (add it)
- csv
- tests: `ListenerRegistrationOrderGoldenTest` unchanged (the proof), a binding test for `PresencePushInterface`

Removes 483 exposed lines and three Core → Processes references.

**R3: rows into Processes (3.2, first table, without D02's bags).**
Files:
- csv
- `lib/Db/ConfigurationMapperBindings.php`, `lib/Db/ProcessesMapperBindings.php` (task-sequence mapper)
- the area pins in `tests/Unit/Architecture`, if any name these files

No code changes. Removes the Platform and Configuration callers of the flow and task tables, 10 Processes →
Configuration references (task sequence, rematerialise bags) and 7 Processes → Platform references (repair helpers).

**R4: rows out of Processes (3.2, second table, without D03's rows).** Independent of R3.
Files:
- csv
- `lib/Db/ConfigurationMapperBindings.php` → `lib/Db/MapperWiring.php` for `SequenceMapperInterface` (stacked after R3, same file)
- `lib/Service/ContractBindings.php` → Core's map for `SequenceServiceInterface` (stacked after R1 and C5-01)

Removes 5 Processes → Configuration references (sequences, console bag). The sequence rows wait for D02's and
D09's agreement (3.2); without them R4 is csv only.

**R5a: Processes-owned seed ports (3.3: task, case plan, flow definitions, flow run, party link, declared state).**
Files:
- new `lib/Kernel/Contracts/Processes/TaskSeedInterface.php`, `CasePlanSeedInterface.php`, `FlowDefinitionsInterface.php`, `FlowRunSeedInterface.php`, `PartyLinkSeedInterface.php`, `DeclaredStateWritesInterface.php`
- new `lib/Service/Task/TaskSeeds.php`, `lib/Service/Case/CasePlanSeeds.php`, `lib/Service/Flow/FlowDefinitions.php`, `lib/Service/Flow/FlowRunSeeds.php`, `lib/Service/Party/PartyLinkSeeds.php`
- `lib/Service/Lifecycle/LifecycleActionContext.php` (implements)
- `lib/Service/ProcessesServiceBindings.php` (stacked after R1)
- csv, unit tests for each implementation

Nothing outside Processes names the ports yet, so nothing is newly exposed.

**R5b: operations, settings and review ports (3.3, the other three).**
Files:
- new `lib/Kernel/Contracts/Processes/OperationsRunsInterface.php`, `ProcessSettingKeysInterface.php`, `ReviewPolicySettingsInterface.php`
- new `lib/Service/OperationsRuns.php`
- `lib/Service/Party/PartySearchService.php`, `lib/Service/Decision/ReviewPolicy.php`, `lib/Service/Calendar/CalendarFeedTokenServiceInterface.php` (constants from the port), `lib/Service/Decision/ReviewPolicyResolver.php` (implements)
- `lib/Service/ProcessesServiceBindings.php` (stacked after R5a)
- csv, unit tests (one asserts every constant equals today's literal)

**R6a: example-set callers, tasks, case plans, parties, declared state.** After R5a.
Files:
- `lib/Service/ExampleSet/ExampleSetTasks.php`, `ExampleSetTaskClaim.php`, `ExampleSetExternalTask.php`, `ExampleSetAppointments.php`, `ExampleSetCasePlans.php`, `ExampleSetParties.php`, `ExampleSetStore.php`
- their tests, with a golden test of an installed example set written first

**R6b: example-set and shareable-config callers of flows and runs.** After R5a.
Files:
- `lib/Service/ExampleSet/ExampleSetFlows.php`, `ExampleSetRunsWrite.php`, `ExampleSetFlowRuns.php`, `ExampleSetFlowRunCheck.php`
- `lib/Service/Config/Types/FlowShareableConfigType.php`
- tests, with a golden export and import of a shared flow

**R6c: party reads and calendar tasks on existing Kernel contracts.** Independent of R5.
Files:
- `lib/Service/ExampleSet/ExampleSetAuditEntries.php`, `ExampleSetGrants.php`, `ExampleSetDeckCards.php`, `ExampleSetEmailLinks.php`, `ExampleSetTalkConversations.php`
- `lib/Service/Configuration/ImportHandler.php`, `ConfigurationServiceRegistrar.php`
- tests

`ExampleSetTimelineEntries` makes the same change inside D03's package N1b, which owns that file.

**R7: console, settings and stored-secret callers.** After R5b and P46 (job handles).
Files:
- `lib/Service/OperationsConsoleService.php`
- `lib/Service/Settings/ConfigurationSettingsHandler.php`, `FileSettingsHandler.php`
- `lib/Repair/ProtectStoredSecrets.php`
- tests

**Not in a D08 package.** The callers of section 3.4 are wave 4 files with owners (the "Package" column); this
design gives them their port. The Kernel task-sequence payload waits for D01. Translation waits for the
cross-design decision of section 4. D03's rows are D03's package N1b.

## 6. Fleet impact

Measured with `fleet_checkout.sh` and `fleet_surface.py` (6,873 references from 19 apps
to 433 concrete classes). 105 classes that this design touches or that count to Processes are named by fleet
apps. The ones that matter here:

| Class | Apps | This design |
|---|---|---|
| `Service\Lifecycle\TransitionEngine` | 7: buildiq, filinq, humaniq, learniq, opencatalogi, pipelinq, shillinq | Unchanged; it gains `CaseTransitionsInterface`. |
| Flow node plug-in API: `Service\Flow\IFlowNode`, `ICallFlowNode`, `FlowNodeCall`, `RegisterFlowNodesEvent`, `IFlowNodeConfigKeys`, `FlowNodeResumeState`, `FlowSuspension`, `RunSignal`, `FlowRunSignalService`, `IFlowNodeConfigForm`, `IFlowNodeLogActions`, `IFlowDirectlyInvokable`, `IFlowNodeTaxonomy` | up to 7: decidiq, dossiq, filinq, hermiq, humaniq, integriq, pipelinq | Untouched. |
| `Service\Flow\FlowService`, `FlowVersionService`, `Db\FlowMapper`, `Db\Flow`, `Db\FlowVersion` | 5, 5, 4, 2, 1 | Unchanged; `FlowService` gains `FlowToolsInterface` and `FlowDefinitions` composes them. |
| `Service\Form\FormSubmitter`, `FormSubmission`, `FormSubmitService(Interface)`, `FormDestination`, `FormDestinationValidator(Interface)` | up to 5: buildiq, dossiq, integriq, larpinq, pipelinq, portaliq | Unchanged; the validator contributes a save check. |
| `Service\Task\TaskService`, `Db\TaskMapper`, `Db\Task`, `Service\Task\TaskState`, the `TaskInbox*` values and `TaskInboxService` | 4, 1, 1, 2, 1 (dossiq) | Unchanged. `TaskInbox*` stay public classes; lower areas stop using them. |
| `Db\TaskSequenceMapper` | buildiq, filinq, opencatalogi | Counts to Processes (3.2). Name and namespace unchanged. |
| `Service\SequenceService`, `SequenceScope` | dossiq | Count to Core (3.2, flagged). Name and namespace unchanged. |
| `Event\TaskSequenceCompletedEvent`, `TaskSequenceUndecidedEvent`, `TaskTerminalEvent`, `TaskTransitionedEvent` | buildiq, decidiq, dossiq (`getTask()`, `getSequence()`) | Payload unchanged in this design; only a Kernel contract the old object implements may replace it (D01). |
| `Event\BulkActionRegistrationEvent`, `BulkAction\BulkActionResult`, `BulkStep`, `StepBulkActionInterface` | dossiq | The event counts to Processes; name, namespace and payload unchanged. |
| `Service\Dmn\DecisionTableEvaluator`, `DecisionTableStore`, `DecisionEvaluationException` | 4, 2, 2 | Untouched. |
| `Service\Flow\Timer\WorkingCalendarService`, `SlaCalculator(Interface)`, `FlowTimerService` and the timer values | up to 3 | Untouched. |
| `Service\CalendarEventService`, `Service\Lifecycle\KeepLifecycleStatesScope`, `Service\Interaction\ReadStateService`, `Service\Party\PartyIndicatorGuard`, `Service\Flow\FlowRunContext` | 1–2 each | Unchanged; each gains the lower port it implements. |

Nothing other apps use is renamed, moved or removed, and no event other apps read changes. Candidates for
promotion to `lib/Contract` (ADR-119 Decision 14), named here and not done:
- the flow node plug-in API (seven apps implement nodes against concrete Processes classes);
- `CaseTransitionsInterface` as a published lifecycle transition contract (seven apps name `TransitionEngine`);
- a form submission contract (`FormSubmitter`, `FormSubmission`, five apps);
- `FlowDefinitionsInterface`, which is what the five apps on `FlowService` and `FlowVersionService` amount to;
- the Kernel `TaskManagerInterface`, already the narrow task contract, for the four apps on `TaskService`.

## Notes for the other designs

- **D01 (Kernel):** `TaskSequenceInterface` for the task-sequence events (−55 here). `TaskManagerInterface`,
  `FlowTimerControlInterface`, `ObjectCalendarEventsInterface`, `ObjectCalendarTasksInterface`,
  `CaseItemInterface`, `TaskInterface`, `FlowTimerInterface`, `DecisionInterface` and `ReviewPolicyInterface`
  describe Processes capabilities but count to Kernel; moving them under `Kernel/Contracts/Processes/` would move
  their lines into this budget. That is D01's call and is not counted here. `BulkActionRegistrationEvent`
  leaves Kernel (3.2).
- **D02 (Core):** the 11 Core-owned ports of 3.4 (74 lines). `ListenerBootstrap` counts to Processes and
  `PerformerTyping` to Processes instead of Platform (3.2). The sequence classes and the two listener helpers
  are proposed to Core. `ContractBindings` is edited by both C5-01 and R1.
- **D03 (Notifications):** the three Notifications-owned ports of 3.4 (24 lines) cover the task, automation and
  party references D03 left to wave 4. `ExampleSetTimelineEntries` also drops `PartyService::findUnscoped`.
- **D04 (Search):** `TaskSearchInterface` for `FlowTasksProvider`. D04's rows A2 (view-alert delivery helpers
  into Processes) are not in this simulation; their only user is a Processes listener.
- **D05 (Compliance):** `CaseTransitionsInterface`, `FinalStatesInterface`, `RunRetentionInterface`; the three
  job bags leave Compliance; `TaskAuditMapperInterface` and `V1ChainVerificationRun` arrive.
- **D07 (Integrations):** `DeclaredDateEventsInterface`, `ObjectFlowLinksInterface`, `FlowToolsInterface`; the
  write-back gate and metrics interfaces arrive; `FlowJson` leaves.
- **D09 (Configuration), D10 (Platform):** the higher callers of 3.3; the sequence and task-sequence rows leave
  Configuration; the translation decision of section 4; positioned listener blocks for
  `RedactionPolicyCacheListener`, `ShareableConfigTypeRegistrationListener` and `NotifyPushListener`.

## How the numbers were made

The graph is `tool.php_dependencies` over `tool.production_files` with `component_of_factory` on
the generated components file, line counts with `tool.code_lines`, exactly as `modularity_check.measure()` does. The
simulation copies that graph and applies, in order: the csv rows of 3.2 (D02's and D03's included where they touch
Processes); the wiring of 3.1 (every `ContractBindings` edge to a Processes file moved to a new Processes file of
about 316 lines; the `ListenerBootstrap` and mapper-binding edges moved; the job-handle edges dropped); every port of
3.3 and 3.4 as a new file with its estimated lines, with an edge from each implementation and from each caller, and
the caller's edges to the Processes files it no longer needs removed; the callers on existing Kernel and Core
contracts rewired to them. Then `exposure()` and `against_order()` run again. The script also checks every row for
a new reference against the order; the first draft had one (`AuditFlowAttribution` to Compliance) and that row was
replaced by the existing `AuditFlowAttributionInterface`. Implementation classes of the ports are not added to the
graph; they are internal to the area that owns them, so they do not change any exposed number.

## Appendix A: files exposed only by `ContractBindings`

263 files, 20,860 lines. All become internal with R1.

| Directory | Files | Lines | Files (lines) |
|---|---|---|---|
| `Service/Flow/Nodes/` | 58 | 4,861 | ObjectWriteNode 461, UserTaskNode 388, DecisionTableNode 309, PortalTaskNode 301, ObjectReadNode 282, FlowStateNode 214, SetFieldsNode 213, AwaitSignalNode 197, SubFlowNode 193, LinkObjectNode 191, IterateNode 160, TriggerObjectNode 154, SendEmailNode 133, FilterNode 124, MergeNode 124, TriggerScheduleNode 120, MapNode 118, RouterNode 118, ExplodeNode 114, TransitionNode 114, WaitNode 110, UnlockObjectNode 104, LoopNode 81, EndNode 78, SendNotificationNode 69, SendTalkMessageNode 69, ActionInputsNode 59, TriggerManualNode 49, SwitchNode 40, ActionInputsNodeInterface 6, AwaitSignalNodeInterface 6, DecisionTableNodeInterface 6, EndNodeInterface 6, ExplodeNodeInterface 6, FilterNodeInterface 6, FlowStateNodeInterface 6, IterateNodeInterface 6, LinkObjectNodeInterface 6, LoopNodeInterface 6, MapNodeInterface 6, MergeNodeInterface 6, ObjectReadNodeInterface 6, ObjectWriteNodeInterface 6, PortalTaskNodeInterface 6, RouterNodeInterface 6, SendEmailNodeInterface 6, SendNotificationNodeInterface 6, SendTalkMessageNodeInterface 6, SetFieldsNodeInterface 6, SubFlowNodeInterface 6, SwitchNodeInterface 6, TransitionNodeInterface 6, TriggerManualNodeInterface 6, TriggerObjectNodeInterface 6, TriggerScheduleNodeInterface 6, UnlockObjectNodeInterface 6, UserTaskNodeInterface 6, WaitNodeInterface 6 |
| `Service/Flow/` | 46 | 3,528 | FlowRunService 656, FlowNodePreflight 280, FlowLocator 245, FlowRunMigrationService 231, FlowNodeRegistry 216, FlowScheduleService 177, FlowTriggerValidator 166, FlowNodeRightsGuard 136, FlowTriggerService 128, FlowTriggerIndex 115, FlowRunAdvancer 114, FlowRunVersionPin 107, EventCatalogService 106, FlowDefinitionPin 106, FlowRunSubjects 94, FlowSemanticVersion 93, FlowDryRunService 84, FlowRunAuthorization 83, FlowRunnableGuard 66, FlowTriggerSlugs 52, FlowPublishedGraph 49, FlowAccess 26, FlowTenantMembership 18, FlowVersionServiceInterface 16, FlowNodeRegistryInterface 13, FlowRunSubjectsInterface 10, FlowAccessInterface 9, FlowDryRunServiceInterface 8, FlowRunAdvancerInterface 8, FlowRunMigrationServiceInterface 8, FlowRunnableGuardInterface 8, FlowSemanticVersionInterface 8, FlowTaskBridgeInterface 8, FlowNodeCatalogueInterface 7, FlowScheduleServiceInterface 7, FlowTriggerIndexInterface 7, FlowTriggerServiceInterface 7, FlowTriggerSlugsInterface 7, FlowTriggerValidatorInterface 7, EventCatalogServiceInterface 6, FlowDefinitionPinInterface 6, FlowLocatorInterface 6, FlowPublishedGraphInterface 6, FlowRunAuthorizationInterface 6, FlowRunVersionPinInterface 6, FlowTenantMembershipInterface 6 |
| `Service/Calculation/` | 17 | 2,155 | CalculationEvaluator 948, CalculationAnnotationValidator 336, ReferencedCalculationRefresher 233, ReferenceResolver 150, AggregateReferenceResolver 103, CalculationPayloadBuilder 87, CalculationTrialService 77, PropertyCalculations 68, TemporalCalculationSweepService 66, OperatorCatalogue 34, CalculationPayloadBuilderInterface 10, PropertyCalculationsInterface 9, CalculationTrialServiceInterface 8, OperatorCatalogueInterface 7, ReferencedCalculationRefresherInterface 7, CalculationAnnotationValidatorInterface 6, TemporalCalculationSweepServiceInterface 6 |
| `Service/Task/` | 13 | 1,902 | TaskService 748, TaskSequenceService 311, TaskInboxService 230, TaskFormResolver 228, TaskSubjectLocator 100, TaskVtodoWriteBackGate 85, TaskFormCompletion 77, TaskProjectionService 61, TaskMetricsProvider 24, TaskSequenceServiceInterface 11, TaskFormCompletionInterface 10, TaskProjectionServiceInterface 10, TaskFormResolverInterface 7 |
| `Service/Lifecycle/` | 17 | 1,173 | StateFieldRuleResolver 250, LifecycleAnnotationValidator 238, AutoTransitionSelector 182, StateConditionEvaluator 151, LifecycleConditionEvaluator 114, LifecycleActionExecutor 93, LifecycleTransitionResolver 42, LifecycleGuardRegistry 30, LifecycleConditionEvaluatorInterface 18, StateFieldRuleResolverInterface 9, LifecycleGuardRegistryInterface 8, LifecycleActionExecutorInterface 7, LifecycleTransitionResolverInterface 7, AutoTransitionSelectorInterface 6, LifecycleActionContextInterface 6, LifecycleAnnotationValidatorInterface 6, StateConditionEvaluatorInterface 6 |
| `Service/Flow/Timer/` | 8 | 1,024 | FlowTimerService 554, SlaCalculator 194, FlowTimerSweep 98, WorkingCalendar 97, WorkingCalendarWriteGuard 52, WorkingCalendarInterface 12, WorkingCalendarWriteGuardInterface 10, FlowTimerSweepInterface 7 |
| `Service/Form/` | 10 | 976 | FormSubmitService 378, FormUploadStore 239, FormIdempotencyStore 151, FormDependentsCheck 111, FormDefinitionResolver 61, FormSubmitServiceInterface 9, FormUploadStoreInterface 8, FormDefinitionResolverInterface 7, FormDestinationValidatorInterface 6, FormIdempotencyStoreInterface 6 |
| `Service/Rules/` | 18 | 973 | ConditionTracer 145, RuleTrialService 128, RuleInventoryService 111, RuleReplayService 99, RuleRunRecorder 98, DependentValueTable 89, AdministeredValidationEnforcer 86, DependentValueValidator 80, RuleEnablementService 69, RuleInventoryServiceInterface 11, RuleTrialServiceInterface 9, DependentValueTableInterface 8, RuleRunRecorderInterface 8, AdministeredValidationEnforcerInterface 7, RuleEnablementServiceInterface 7, ConditionTracerInterface 6, DependentValueValidatorInterface 6, RuleReplayServiceInterface 6 |
| `Service/Case/` | 9 | 698 | ZaaktypeCaseSkeletonMapper 217, CasePlanItemTimers 213, CasePlanAuthorizationService 115, CasePlanTimerRearm 99, CasePlanServiceInterface 23, CasePlanItemTimersInterface 11, CasePlanAuthorizationServiceInterface 7, CasePlanTimerRearmInterface 7, ZaaktypeCaseSkeletonMapperInterface 6 |
| `Service/ObjectAction/` | 13 | 615 | ObjectActionRunner 182, ObjectActionDefinition 124, ObjectActionValidator 81, ObjectActionHistory 74, ObjectActionBulk 50, ObjectActionDefinitions 48, ObjectActionListInterface 10, ObjectActionBulkInterface 9, ObjectActionHistoryInterface 9, ObjectActionDefinitionsInterface 8, ObjectActionRunnerInterface 7, ObjectActionValidatorInterface 7, ObjectActionDefinitionInterface 6 |
| `Service/BulkJob/` | 5 | 599 | BulkJobService 281, ReversibilityAnnotationValidator 160, BulkJobReversal 143, BulkJobReversalInterface 9, ReversibilityAnnotationValidatorInterface 6 |
| `Service/Portal/` | 8 | 470 | PortalTaskService 222, PortalSubjectAssertion 111, PortalTaskDeliveryService 86, PortalTaskServiceInterface 16, PortalTaskDeliveryRequestInterface 9, PortalTaskDeliveryServiceInterface 9, PortalTaskReminderInterface 9, PortalSubjectAssertionInterface 8 |
| `Service/Calendar/` | 3 | 306 | CalendarFeedTokenService 153, AppointmentAttendeeService 143, AppointmentAttendeeServiceInterface 10 |
| `Service/` | 2 | 262 | BulkTranslationService 184, BulkActionRegistry 78 |
| `Service/Flow/Oversight/` | 6 | 260 | OrganisationHaltService 157, OrganisationHaltCheck 60, KillSwitchCheck 22, OrganisationHaltServiceInterface 9, KillSwitchCheckInterface 6, OrganisationHaltCheckInterface 6 |
| `Service/Decision/` | 5 | 257 | RelationDecisionService 128, DecisionVisibility 105, RelationDecisionServiceInterface 10, DecisionVisibilityInterface 8, ReviewAnnotationValidatorInterface 6 |
| `Service/Case/Nodes/` | 4 | 182 | CaseAdvanceNode 111, CaseOpenNode 59, CaseAdvanceNodeInterface 6, CaseOpenNodeInterface 6 |
| `Service/Interaction/` | 4 | 181 | SubstantiveChangeEvaluator 143, FavouriteService 21, FavouriteServiceInterface 10, SubstantiveChangeEvaluatorInterface 7 |
| `Service/Flow/Principal/` | 8 | 172 | PrincipalResolverRegistry 86, GroupPrincipalResolver 27, AgentPrincipalResolver 19, UserPrincipalResolver 18, PrincipalResolverRegistryInterface 7, AgentPrincipalResolverInterface 5, GroupPrincipalResolverInterface 5, UserPrincipalResolverInterface 5 |
| `Service/Dmn/` | 2 | 155 | DecisionTableValidator 149, DecisionTableValidatorInterface 6 |
| `Service/Quota/` | 1 | 51 | ObjectQuotaService 51 |
| `Service/Party/` | 4 | 31 | PartyRoleServiceInterface 12, TrustedPartyReaderInterface 7, PartyIndicatorGuardInterface 6, PartySchemaCatalogueInterface 6 |
| `Service/Flow/Bpmn/` | 2 | 29 | FlowBpmnInterchange 18, FlowBpmnInterchangeInterface 11 |
