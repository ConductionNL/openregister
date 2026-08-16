# Tasks: Action Registry

> **Status:** Shipped — all 14 tasks ticked. Schema-level Action entity + ActionLog ledger backing the workflow-engine dispatch path. Coexists with `HookListener` without conflict (inline propagation-stop on hooks short-circuits ActionListener too). Cross-app regression covered against opencatalogi + softwarecatalog.

- [x] Task 1: Database migration for oc_openregister_actions and oc_openregister_action_logs tables
  - Create `lib/Migration/Version1Date20260325000000.php`
  - Create `oc_openregister_actions` table with all columns from Requirement 1 (uuid, name, slug, description, version, status, event_type, engine, workflow_id, mode, execution_order, timeout, on_failure, on_timeout, on_engine_down, filter_condition, configuration, mapping, schemas, registers, schedule, max_retries, retry_policy, enabled, owner, application, organisation, last_executed_at, execution_count, success_count, failure_count, created, updated, deleted)
  - Create `oc_openregister_action_logs` table with all columns from Requirement 9 (action_id, action_uuid, event_type, object_uuid, schema_id, register_id, engine, workflow_id, status, duration_ms, request_payload, response_payload, error_message, attempt, created)
  - Add indexes: uuid (unique), slug (unique), status, event_type, schedule, enabled, deleted on actions table; action_id, action_uuid, object_uuid, status on action_logs table
  - Spec ref: Requirement 1 (Scenario: Action entity field definitions)

- [x] Task 2: Action entity and ActionMapper
  - Create `lib/Db/Action.php` extending Entity, implementing JsonSerializable
  - Define all typed properties matching the migration columns
  - Implement `jsonSerialize()` with full field serialization
  - Apply `MultiTenancyTrait` for owner/application/organisation scoping
  - Create `lib/Db/ActionMapper.php` extending QBMapper
  - Implement `findAll()`, `find()`, `findByUuid()`, `findBySlug()`, `findByEventType()`
  - Implement `findMatchingActions(string $eventType, ?string $schemaUuid, ?string $registerUuid): array` with event_type matching (exact + fnmatch wildcard), schema/register filtering, enabled/active/not-deleted checks
  - Apply `MultiTenancyTrait`
  - Spec ref: Requirement 1, Requirement 2, Requirement 13

- [x] Task 3: ActionLog entity and ActionLogMapper
  - Create `lib/Db/ActionLog.php` extending Entity, implementing JsonSerializable
  - Define all typed properties matching the migration columns
  - Create `lib/Db/ActionLogMapper.php` extending QBMapper
  - Implement `findByActionId(int $actionId, int $limit, int $offset): array`
  - Implement `findByActionUuid(string $actionUuid, int $limit, int $offset): array`
  - Implement `getStatsByActionId(int $actionId): array` (aggregate counts)
  - Spec ref: Requirement 9

- [x] Task 4: Action lifecycle events (ActionCreatedEvent, ActionUpdatedEvent, ActionDeletedEvent)
  - Create `lib/Event/ActionCreatedEvent.php` extending Event with `getAction(): Action`
  - Create `lib/Event/ActionUpdatedEvent.php` extending Event with `getAction(): Action`
  - Create `lib/Event/ActionDeletedEvent.php` extending Event with `getAction(): Action`
  - Follow existing event patterns (e.g., RegisterCreatedEvent, SchemaCreatedEvent)
  - Spec ref: Requirement 11

- [x] Task 5: ActionService business logic
  - Create `lib/Service/ActionService.php`
  - Implement `createAction(array $data): Action` -- validates required fields, generates UUID, sets defaults, persists via ActionMapper, dispatches ActionCreatedEvent
  - Implement `updateAction(int $id, array $data): Action` -- partial update, refreshes updated timestamp, dispatches ActionUpdatedEvent
  - Implement `deleteAction(int $id): Action` -- soft-delete (sets deleted timestamp, status to archived), dispatches ActionDeletedEvent
  - Implement `testAction(int $id, array $samplePayload): array` -- dry-run simulation (validates match, builds payload, optionally executes in dry-run mode, returns match result and payload without side effects)
  - Implement `migrateFromHooks(int $schemaId): array` -- reads Schema::getHooks(), creates Action entities for each hook, skips duplicates, returns migration report
  - Implement `updateStatistics(int $actionId, string $status): void` -- increments execution/success/failure counts, updates last_executed_at
  - Spec ref: Requirement 1, Requirement 8, Requirement 9, Requirement 12

- [x] Task 6: ActionExecutor for action execution orchestration
  - Create `lib/Service/ActionExecutor.php`
  - Inject WorkflowEngineRegistry, CloudEventFormatter, ActionLogMapper, ActionMapper, LoggerInterface
  - Implement `executeActions(array $actions, Event $event, array $payload): void`
    - Iterate over actions sorted by execution_order
    - For each action: buildCloudEventPayload(), resolve engine adapter, execute workflow, process WorkflowResult
    - Handle sync mode: process approved/rejected/modified responses, call event->stopPropagation() on rejection, merge modified data via event->setModifiedData()
    - Handle async mode: fire-and-forget execution, log delivery status
    - Apply failure modes: on_failure (reject/allow/flag/queue), on_timeout, on_engine_down
    - Create ActionLog entry for each execution
    - Update action statistics via ActionService::updateStatistics()
    - On queue mode: add ActionRetryJob to IJobList
  - Implement `buildCloudEventPayload(Action $action, Event $event, array $payload): array` -- delegates to CloudEventFormatter with action-specific extension attributes
  - Spec ref: Requirement 4, Requirement 5, Requirement 9, Requirement 10

- [x] Task 7: ActionListener event handler
  - Create `lib/Listener/ActionListener.php` implementing IEventListener
  - Inject ActionMapper, ActionExecutor, LoggerInterface
  - Implement `handle(Event $event): void`
    - Determine event type string from event class name
    - Check isPropagationStopped() early (respect inline hook rejections)
    - Extract payload from event (object data, register ID, schema UUID depending on event type)
    - Query ActionMapper::findMatchingActions() with event type, schema UUID, register UUID
    - Apply filter_condition matching against payload for each action
    - Delegate to ActionExecutor::executeActions()
    - Wrap in try/catch to prevent listener failures from affecting other listeners
  - Register in Application::registerEventListeners() for ALL event types (ObjectCreatingEvent, ObjectCreatedEvent, ..., RegisterCreatedEvent, ..., SchemaCreatedEvent, ..., etc.)
  - Spec ref: Requirement 3, Requirement 4, Requirement 5

- [x] Task 8: ActionsController with CRUD API
  - Create `lib/Controller/ActionsController.php` extending ApiController
  - Implement standard CRUD: index (GET /api/actions with pagination, search, filtering), show (GET /api/actions/{id}), create (POST /api/actions), update (PUT /api/actions/{id}), patch (PATCH /api/actions/{id}), destroy (DELETE /api/actions/{id})
  - Implement custom routes: test (POST /api/actions/{id}/test), logs (GET /api/actions/{id}/logs), migrateFromHooks (POST /api/actions/migrate-from-hooks/{schemaId})
  - Add routes to `appinfo/routes.php`: add 'Actions' to resources array, add PATCH route, add test/logs/migrate custom routes
  - Support query parameters: _search, status, event_type, engine, enabled, limit, offset, _order, _sort
  - Spec ref: Requirement 7, Requirement 8, Requirement 9 (logs endpoint), Requirement 12 (migration endpoint)

- [x] Task 9: ActionScheduleJob for cron-based scheduled actions
  - Create `lib/BackgroundJob/ActionScheduleJob.php` extending TimedJob
  - Set interval to 60 seconds
  - Implement `run($arguments)`:
    - Query ActionMapper for all actions where schedule IS NOT NULL AND enabled = true AND status = 'active' AND deleted IS NULL
    - For each action: evaluate cron expression against current time using dragonmantank/cron-expression (available in Nextcloud core dependencies)
    - Compare with action's last_executed_at to determine if the schedule is due
    - Execute via ActionExecutor with a synthetic scheduled event payload (type: 'nl.openregister.action.scheduled')
    - Update last_executed_at after execution
  - Register in Application boot or via IBootstrap::registerBackgroundJobs if available
  - Spec ref: Requirement 6

- [x] Task 10: ActionRetryJob for failed action retries
  - Create `lib/BackgroundJob/ActionRetryJob.php` extending QueuedJob
  - Implement `run($arguments)`:
    - Extract action_id, payload, attempt, max_retries, retry_policy from arguments
    - Load Action via ActionMapper::find()
    - Check if attempt >= max_retries; if so, log abandonment and create final ActionLog with status 'abandoned'
    - Otherwise, execute action via ActionExecutor
    - On failure: calculate next retry delay based on retry_policy (exponential: 2^attempt * 60s, linear: attempt * 300s, fixed: 300s)
    - Re-queue ActionRetryJob with incremented attempt
  - Spec ref: Requirement 10

- [x] Task 11: Application.php event listener and job registration
  - Modify `lib/AppInfo/Application.php`
  - Register ActionListener for ALL event types in registerEventListeners() (ObjectCreatingEvent, ObjectCreatedEvent, ObjectUpdatingEvent, ObjectUpdatedEvent, ObjectDeletingEvent, ObjectDeletedEvent, ObjectLockedEvent, ObjectUnlockedEvent, ObjectRevertedEvent, RegisterCreatedEvent, RegisterUpdatedEvent, RegisterDeletedEvent, SchemaCreatedEvent, SchemaUpdatedEvent, SchemaDeletedEvent, SourceCreatedEvent, SourceUpdatedEvent, SourceDeletedEvent, ConfigurationCreatedEvent, ConfigurationUpdatedEvent, ConfigurationDeletedEvent, ViewCreatedEvent, ViewUpdatedEvent, ViewDeletedEvent, AgentCreatedEvent, AgentUpdatedEvent, AgentDeletedEvent, ApplicationCreatedEvent, ApplicationUpdatedEvent, ApplicationDeletedEvent, ConversationCreatedEvent, ConversationUpdatedEvent, ConversationDeletedEvent, OrganisationCreatedEvent, OrganisationUpdatedEvent, OrganisationDeletedEvent, ActionCreatedEvent, ActionUpdatedEvent, ActionDeletedEvent)
  - Register ActionScheduleJob as a TimedJob
  - Spec ref: Requirement 4 (Scenario: ActionListener coexists with HookListener), Requirement 6

- [x] Task 12: Hook-to-Action migration utility
  - Implement `ActionService::migrateFromHooks(int $schemaId): array` in ActionService
  - Load schema via SchemaMapper::find()
  - Read hooks from Schema::getHooks()
  - For each hook: map event string to event class name, create Action entity with mapped fields, bind to schema UUID
  - Detect duplicates by checking existing actions with same name + schemas + event_type
  - Return migration report: { created: [...], skipped: [...], errors: [...] }
  - Spec ref: Requirement 12

- [x] Task 13: Unit tests for Action entity, mapper, and service
  - Test Action entity field serialization (jsonSerialize)
  - Test ActionMapper::findMatchingActions() with exact event_type matching
  - Test ActionMapper::findMatchingActions() with wildcard event_type matching (fnmatch)
  - Test ActionMapper::findMatchingActions() with schema filtering
  - Test ActionMapper::findMatchingActions() with register filtering
  - Test ActionMapper::findMatchingActions() skips disabled/draft/deleted actions
  - Test ActionService::createAction() sets defaults and dispatches event
  - Test ActionService::deleteAction() performs soft-delete
  - Test ActionService::testAction() returns match result without side effects
  - Test ActionService::migrateFromHooks() creates actions and skips duplicates
  - Test ActionExecutor pre-mutation rejection stops propagation
  - Test ActionExecutor post-mutation async does not affect persistence
  - Test ActionExecutor filter_condition matching (exact, array, nested dot-notation, empty)
  - Test ActionRetryJob respects max_retries and retry_policy
  - Test ActionScheduleJob evaluates cron expressions correctly
  - Spec ref: All requirements

- [x] Task 14: Integration tests with opencatalogi and softwarecatalog
  - Verify Action entity CRUD does not break existing schema hook processing
  - Verify HookListener and ActionListener coexist without conflicts
  - Verify inline hook propagation stop also prevents ActionListener execution
  - Verify Action events (ActionCreatedEvent, etc.) do not interfere with existing event listeners
  - Test with opencatalogi enabled to verify no regressions in catalog listing updates
  - Test with softwarecatalog enabled to verify no regressions in software catalog operations
  - Spec ref: Requirement 4 (Scenario: ActionListener coexists with HookListener)
