# Workflow Engine Abstraction

---
status: implemented
---

## Purpose

Provides an engine-agnostic interface for OpenRegister to interact with workflow engines (n8n, Windmill, and future engines), enabling the system to deploy, execute, monitor, and manage workflows without coupling to any specific engine's API. This is the foundation layer that other specs (Schema Hooks, Workflow-in-Import, Workflow Integration) build upon: every hook execution, import-time workflow deployment, and event-driven automation flows through the `WorkflowEngineInterface` and `WorkflowEngineRegistry` defined here. By abstracting engine specifics behind adapters, OpenRegister can support multiple simultaneous engines, allow engine migration without data loss, and extend to new engines via a single interface implementation.

## Context

OpenRegister needs to trigger external workflow engines for validation, enrichment, notifications, and automation. Currently n8n runs as a Nextcloud ExApp (FastAPI proxy to n8n at :5678) and Windmill exists as a separate ExApp. Rather than coupling to either engine, OpenRegister defines a shared interface (`WorkflowEngineInterface`) with per-engine adapters (`N8nAdapter`, `WindmillAdapter`). The `WorkflowEngineRegistry` service manages engine configurations, resolves the correct adapter for each request, encrypts credentials via `ICrypto`, and supports auto-discovery of installed ExApps via `IAppManager`.

Multiple engines can be active simultaneously. Each individual hook on a schema specifies which engine it uses, so a single schema can have hooks targeting different engines (e.g., hook 1 uses n8n for validation, hook 2 uses Windmill for enrichment).

## Requirements

### Requirement: Engine Interface Definition
Each engine adapter MUST implement the `WorkflowEngineInterface` PHP interface, providing a unified contract for workflow lifecycle management and execution. The interface MUST define methods for deploying, updating, retrieving, deleting, activating, deactivating, and executing workflows, as well as listing workflows, obtaining webhook URLs, and performing health checks. All adapters MUST accept configuration via a `configure(string $baseUrl, array $authConfig)` method that sets the engine connection parameters before any API calls.

#### Scenario: Interface defines complete workflow lifecycle methods
- **GIVEN** a class implements `WorkflowEngineInterface`
- **WHEN** the interface contract is checked
- **THEN** the class MUST implement: `deployWorkflow(array $workflowDefinition): string`, `updateWorkflow(string $workflowId, array $workflowDefinition): string`, `getWorkflow(string $workflowId): array`, `deleteWorkflow(string $workflowId): void`, `activateWorkflow(string $workflowId): void`, `deactivateWorkflow(string $workflowId): void`, `executeWorkflow(string $workflowId, array $data, int $timeout = 30): WorkflowResult`, `getWebhookUrl(string $workflowId): string`, `listWorkflows(): array`, `healthCheck(): bool`

#### Scenario: Deploy a workflow returns engine-specific ID
- **GIVEN** an adapter implements `WorkflowEngineInterface`
- **WHEN** `deployWorkflow(array $workflowDefinition)` is called with a valid engine-native workflow definition
- **THEN** the adapter MUST translate the definition to the engine's native API format
- **AND** POST it to the engine's workflow creation endpoint
- **AND** return the engine-specific workflow ID as a string (e.g., n8n numeric ID or Windmill flow path)

#### Scenario: Update an existing workflow preserves engine ID
- **GIVEN** a workflow with ID `"42"` was previously deployed
- **WHEN** `updateWorkflow("42", $updatedDefinition)` is called
- **THEN** the adapter MUST send the updated definition to the engine's update endpoint
- **AND** return the workflow ID (which MAY change on some engines but SHOULD remain the same)

#### Scenario: Get workflow retrieves full definition from engine
- **GIVEN** a workflow with ID `"42"` exists in the engine
- **WHEN** `getWorkflow("42")` is called
- **THEN** the adapter MUST return the full engine-native workflow definition as an associative array
- **AND** the returned definition MUST be re-deployable via `deployWorkflow()` (round-trip safe)

#### Scenario: Interface supports type-safe return values
- **GIVEN** any adapter method is called
- **WHEN** the method returns a value
- **THEN** `deployWorkflow()` and `updateWorkflow()` MUST return `string`, `getWorkflow()` MUST return `array`, `deleteWorkflow()`/`activateWorkflow()`/`deactivateWorkflow()` MUST return `void`, `executeWorkflow()` MUST return `WorkflowResult`, `getWebhookUrl()` MUST return `string`, `listWorkflows()` MUST return `array`, `healthCheck()` MUST return `bool`

### Requirement: n8n Adapter Implementation
The `N8nAdapter` class MUST implement `WorkflowEngineInterface` and translate all interface methods to n8n's REST API. The adapter MUST use Nextcloud's `IClientService` for HTTP communication and support routing through the ExApp proxy when n8n runs as a Nextcloud ExApp.

#### Scenario: Deploy workflow to n8n
- **GIVEN** an n8n engine is registered with base URL `http://localhost:5678`
- **WHEN** `deployWorkflow()` is called with n8n workflow JSON
- **THEN** the adapter MUST POST to `{baseUrl}/rest/workflows` with the workflow definition as JSON body
- **AND** include authentication headers built by `buildAuthHeaders()`
- **AND** return the n8n workflow ID from `$response['id']` as a string

#### Scenario: Execute workflow via n8n webhook
- **GIVEN** an n8n workflow with ID `"42"` has a webhook trigger
- **WHEN** `executeWorkflow("42", $data, 30)` is called
- **THEN** the adapter MUST POST the data to `{baseUrl}/webhook/42` (the webhook URL from `getWebhookUrl()`)
- **AND** pass the `timeout` parameter to the HTTP client
- **AND** parse the n8n response into a `WorkflowResult` via `parseWorkflowResponse()`

#### Scenario: n8n response parsing maps status values
- **GIVEN** n8n returns a JSON response with `{"status": "modified", "data": {"enriched": true}}`
- **WHEN** `parseWorkflowResponse()` processes the response
- **THEN** it MUST return `WorkflowResult::modified(data: ["enriched" => true], metadata: ["engine" => "n8n"])`
- **AND** for `null` responses, the adapter MUST default to `WorkflowResult::approved(metadata: ["engine" => "n8n"])`
- **AND** for `"rejected"` status, errors and metadata from the response MUST be passed through
- **AND** for `"error"` status, the first error message MUST be extracted

#### Scenario: n8n timeout detected from exception message
- **GIVEN** an n8n workflow execution exceeds the timeout
- **WHEN** the HTTP client throws an exception containing `"timed out"` or `"timeout"`
- **THEN** the adapter MUST return `WorkflowResult::error(message: "Workflow execution timed out after {timeout} seconds", metadata: ["engine" => "n8n", "workflowId" => $workflowId])`
- **AND** the error MUST be logged at ERROR level with `[N8nAdapter]` prefix

#### Scenario: Route through ExApp proxy
- **GIVEN** n8n runs as a Nextcloud ExApp
- **WHEN** the adapter is configured with `baseUrl` pointing to `/index.php/apps/app_api/proxy/n8n/`
- **THEN** all API calls MUST route through the Nextcloud ExApp proxy
- **AND** the adapter MUST include proper authentication headers via the `authConfig` provided during `configure()`

### Requirement: Windmill Adapter Implementation
The `WindmillAdapter` class MUST implement `WorkflowEngineInterface` and translate all interface methods to Windmill's REST API, including workspace-scoped endpoint paths.

#### Scenario: Deploy workflow to Windmill
- **GIVEN** a Windmill engine is registered with a base URL and workspace `"main"`
- **WHEN** `deployWorkflow()` is called with Windmill flow JSON
- **THEN** the adapter MUST POST to `{baseUrl}/api/w/{workspace}/flows/create`
- **AND** return the Windmill flow path from `$response['path']` (or `$response['id']` as fallback)

#### Scenario: Execute workflow synchronously via Windmill
- **GIVEN** a Windmill flow exists at path `"f/validate-bsn"`
- **WHEN** `executeWorkflow("f/validate-bsn", $data, 30)` is called
- **THEN** the adapter MUST POST to `{baseUrl}/api/w/{workspace}/jobs/run_wait_result/f/f/validate-bsn`
- **AND** parse the response into a `WorkflowResult` using the same status mapping as the n8n adapter

#### Scenario: Windmill activate/deactivate are no-ops
- **GIVEN** a Windmill adapter instance
- **WHEN** `activateWorkflow()` or `deactivateWorkflow()` is called
- **THEN** the adapter MUST perform no operation (Windmill flows are always active once created)
- **AND** no API calls MUST be made to the engine

#### Scenario: Windmill health check uses version endpoint
- **GIVEN** a Windmill engine is registered
- **WHEN** `healthCheck()` is called
- **THEN** the adapter MUST GET `{baseUrl}/api/version` with a 5-second timeout
- **AND** return `true` if the response status code is 200, `false` otherwise
- **AND** exceptions MUST be caught and logged at DEBUG level, returning `false`

### Requirement: Engine Registration and Discovery
OpenRegister MUST maintain a persistent registry of available workflow engines via the `WorkflowEngineRegistry` service and `WorkflowEngineMapper`. The registry MUST support manual registration via the REST API and auto-discovery of installed Nextcloud ExApps.

#### Scenario: Register a workflow engine via API
- **GIVEN** an admin user is authenticated
- **WHEN** they POST to the engines endpoint with `name`, `engineType` (enum: `"n8n"`, `"windmill"`), `baseUrl`, and optional `authType`, `authConfig`, `enabled`, `defaultTimeout`
- **THEN** `WorkflowEngineController::create()` MUST validate the engine type against the allowed list
- **AND** `WorkflowEngineRegistry::createEngine()` MUST encrypt `authConfig` via `ICrypto::encrypt()` before storage
- **AND** an initial `healthCheck()` MUST be performed on the newly created engine
- **AND** the response MUST include the created engine configuration with its assigned ID (HTTP 201)

#### Scenario: List registered engines excludes credentials
- **GIVEN** two engines are registered (one n8n, one Windmill)
- **WHEN** an authenticated user sends `GET` to the engines endpoint
- **THEN** the response MUST include all registered engines serialized via `jsonSerialize()`
- **AND** `authConfig` MUST NOT be included in the serialized output (the `WorkflowEngine::jsonSerialize()` method excludes it)
- **AND** each engine MUST include `id`, `uuid`, `name`, `engineType`, `baseUrl`, `authType`, `enabled`, `defaultTimeout`, `healthStatus`, `lastHealthCheck`, `created`, `updated`

#### Scenario: Auto-discover engines from installed ExApps
- **GIVEN** the `app_api` app is enabled and n8n ExApp is installed
- **WHEN** `WorkflowEngineRegistry::discoverEngines()` is called (exposed via `WorkflowEngineController::available()`)
- **THEN** it MUST check `IAppManager::isEnabledForUser()` for known engine app IDs (`"n8n"`, `"windmill"`)
- **AND** return discovered engines with `engineType`, `suggestedBaseUrl` (e.g., `http://localhost:5678` for n8n), and `installed: true`

#### Scenario: No ExApps installed returns empty discovery
- **GIVEN** no workflow engine ExApps are installed (or `app_api` is not enabled)
- **WHEN** `discoverEngines()` is called
- **THEN** the result MUST be an empty array
- **AND** no exceptions MUST be thrown
- **AND** manual engine configuration via the CRUD API MUST still work

#### Scenario: Remove a registered engine
- **GIVEN** an engine with ID 5 is registered
- **WHEN** an admin sends `DELETE` to the engines endpoint for ID 5
- **THEN** `WorkflowEngineRegistry::deleteEngine()` MUST remove the engine from the database via the mapper
- **AND** return the deleted engine configuration in the response
- **AND** any hooks referencing this engine type SHOULD still be configurable but will fail on execution (handled by `HookExecutor`'s `onEngineDown` failure mode)

### Requirement: Workflow Execution API (Sync and Async)
The `WorkflowEngineInterface::executeWorkflow()` method MUST support synchronous execution that blocks and returns a `WorkflowResult`. Async execution is handled at the `HookExecutor` layer where `mode: "async"` hooks call `executeWorkflow()` but treat the result as fire-and-forget for the purpose of the save operation.

#### Scenario: Synchronous execution returns structured result
- **GIVEN** a workflow is deployed and active in an engine
- **WHEN** `executeWorkflow(workflowId, data, timeout)` is called
- **THEN** the adapter MUST send the data to the workflow's trigger endpoint
- **AND** wait for the response up to `$timeout` seconds
- **AND** return a `WorkflowResult` object with one of four statuses: `approved`, `rejected`, `modified`, `error`

#### Scenario: Async execution at HookExecutor layer
- **GIVEN** a hook is configured with `mode: "async"`
- **WHEN** `HookExecutor::executeSingleHook()` detects async mode
- **THEN** it MUST delegate to `executeAsyncHook()` which calls `adapter->executeWorkflow()` in a try/catch
- **AND** the result MUST only be used for logging (`deliveryStatus: "delivered"` or `"failed"`)
- **AND** the save operation MUST NOT be affected by the async hook's outcome

#### Scenario: Execution with data payload
- **GIVEN** a workflow expects object data as input
- **WHEN** `executeWorkflow()` is called with a CloudEvent-formatted payload
- **THEN** the adapter MUST POST the entire payload as the JSON body to the engine's trigger endpoint
- **AND** the engine receives the full object data, schema context, register reference, event type, and hook metadata

### Requirement: Execution Status Tracking via WorkflowResult
Synchronous workflow execution MUST return a `WorkflowResult` value object (implementing `JsonSerializable`) that encapsulates the outcome status, optional modified data, validation errors, and engine-specific metadata.

#### Scenario: Approved result indicates data passes unchanged
- **GIVEN** a workflow validates data and approves it
- **WHEN** `WorkflowResult::approved(metadata: ["engine" => "n8n"])` is constructed
- **THEN** `getStatus()` MUST return `"approved"`, `isApproved()` MUST return `true`
- **AND** `getData()` MUST return `null` (original data passes through unchanged)
- **AND** `getErrors()` MUST return an empty array

#### Scenario: Rejected result carries field-level validation errors
- **GIVEN** a workflow rejects the data with validation errors
- **WHEN** `WorkflowResult::rejected(errors: [["field" => "kvkNumber", "message" => "Invalid KvK", "code" => "INVALID_KVK"]], metadata: [])` is constructed
- **THEN** `getStatus()` MUST return `"rejected"`, `isRejected()` MUST return `true`
- **AND** `getErrors()` MUST return the array of error objects with `field`, `message`, and optional `code`

#### Scenario: Modified result carries enriched data
- **GIVEN** a workflow enriches the data with geocoding results
- **WHEN** `WorkflowResult::modified(data: ["lat" => 52.37, "lng" => 4.89], metadata: ["engine" => "n8n"])` is constructed
- **THEN** `getStatus()` MUST return `"modified"`, `isModified()` MUST return `true`
- **AND** `getData()` MUST return the modified object data array

#### Scenario: Error result from workflow failure
- **GIVEN** a workflow execution fails due to a network error or internal workflow error
- **WHEN** `WorkflowResult::error(message: "Connection refused", metadata: ["engine" => "n8n", "workflowId" => "42"])` is constructed
- **THEN** `getStatus()` MUST return `"error"`, `isError()` MUST return `true`
- **AND** `getErrors()` MUST contain `[["message" => "Connection refused"]]`
- **AND** `getMetadata()` MUST include the engine name and workflow ID for debugging

#### Scenario: Invalid status throws exception
- **GIVEN** a `WorkflowResult` is constructed with an invalid status string
- **WHEN** `new WorkflowResult("invalid_status")` is called
- **THEN** an `InvalidArgumentException` MUST be thrown with message listing valid statuses: `approved`, `rejected`, `modified`, `error`

### Requirement: Result Callback Handling by HookExecutor
The `HookExecutor::processWorkflowResult()` method MUST map each `WorkflowResult` status to the appropriate action on the lifecycle event: approved continues, modified merges data, rejected and error apply the configured failure mode.

#### Scenario: Approved result continues the save chain
- **GIVEN** `processWorkflowResult()` receives a `WorkflowResult` with `isApproved() === true`
- **WHEN** the result is processed
- **THEN** the hook execution MUST be logged as successful with `responseStatus: "approved"`
- **AND** no event propagation is stopped
- **AND** the next hook in the chain (if any) MUST execute

#### Scenario: Modified result merges data into the event
- **GIVEN** `processWorkflowResult()` receives a `WorkflowResult` with `isModified() === true` and `getData()` returns `["enriched" => true]`
- **WHEN** the result is processed
- **THEN** `setModifiedDataOnEvent()` MUST call `$event->setModifiedData(data)` on `ObjectCreatingEvent`, `ObjectUpdatingEvent`, or `ObjectDeletingEvent`
- **AND** the modified data will be merged into the object by `MagicMapper` via `array_merge()` before persistence
- **AND** subsequent hooks in the chain MUST receive the modified object data

#### Scenario: Rejected result applies onFailure mode
- **GIVEN** `processWorkflowResult()` receives a `WorkflowResult` with `isRejected() === true`
- **WHEN** the result is processed
- **THEN** `applyFailureMode()` MUST be called with the `onFailure` value from the hook configuration (default `"reject"`)
- **AND** the validation errors from `result->getErrors()` MUST be passed through

#### Scenario: Error result falls back to onFailure mode
- **GIVEN** `processWorkflowResult()` receives a `WorkflowResult` with `isError() === true`
- **WHEN** the result is processed
- **THEN** `applyFailureMode()` MUST be called with the `onFailure` value
- **AND** the error details from `result->getErrors()` MUST be included

### Requirement: Engine Configuration Entity
Engine configuration MUST be stored as a persistent Nextcloud database entity (`WorkflowEngine`) extending `OCP\AppFramework\Db\Entity` with `JsonSerializable` support. The entity MUST be persisted via `WorkflowEngineMapper` (extending `QBMapper`) to the `oc_openregister_workflow_engines` table.

#### Scenario: Required entity fields
- **GIVEN** an admin creates an engine configuration
- **WHEN** the entity is validated
- **THEN** the entity MUST support fields: `uuid` (string, auto-generated UUID v4), `name` (string), `engineType` (string, enum: `"n8n"`, `"windmill"`), `baseUrl` (string, URI), `authType` (string, enum: `"none"`, `"basic"`, `"bearer"`, `"cookie"`, default `"none"`), `authConfig` (string, encrypted JSON), `enabled` (boolean, default `true`), `defaultTimeout` (integer, default 30), `healthStatus` (boolean nullable), `lastHealthCheck` (datetime nullable), `created` (datetime), `updated` (datetime)

#### Scenario: Credential encryption at rest
- **GIVEN** an engine configuration includes `authConfig` with sensitive credentials (tokens, passwords)
- **WHEN** `WorkflowEngineRegistry::createEngine()` or `updateEngine()` is called
- **THEN** `authConfig` MUST be encrypted via `ICrypto::encrypt(json_encode($authConfig))` before database storage
- **AND** `decryptAuthConfig()` MUST decrypt via `ICrypto::decrypt()` when resolving an adapter
- **AND** if decryption fails (e.g., key rotation), a warning MUST be logged and a fallback config with `authType` only MUST be returned

#### Scenario: Credentials excluded from JSON serialization
- **GIVEN** an engine entity is serialized for API response
- **WHEN** `jsonSerialize()` is called
- **THEN** the `authConfig` field MUST NOT appear in the serialized output
- **AND** all other fields (`id`, `uuid`, `name`, `engineType`, `baseUrl`, `authType`, `enabled`, `defaultTimeout`, `healthStatus`, `lastHealthCheck`, `created`, `updated`) MUST be included
- **AND** datetime fields MUST be formatted as ISO 8601 strings via `->format('c')`

#### Scenario: Entity hydration from array
- **GIVEN** an array of engine configuration data
- **WHEN** `WorkflowEngine::hydrate($data)` is called
- **THEN** only recognized field names MUST be set via their corresponding setter methods
- **AND** unknown keys MUST be silently ignored

### Requirement: Multi-Engine Support
OpenRegister MUST support multiple engines of different types (and potentially multiple instances of the same type) running simultaneously. Engine selection MUST be per-hook, NOT per-schema or per-register.

#### Scenario: Two engines active simultaneously
- **GIVEN** two engines are registered: an n8n instance (ID 1) and a Windmill instance (ID 2)
- **WHEN** a schema has hook 1 referencing engine type `"n8n"` and hook 2 referencing engine type `"windmill"`
- **THEN** `HookExecutor::executeSingleHook()` MUST call `WorkflowEngineRegistry::getEnginesByType("n8n")` for hook 1 and `getEnginesByType("windmill")` for hook 2
- **AND** `resolveAdapter()` MUST configure the `N8nAdapter` for hook 1 and `WindmillAdapter` for hook 2
- **AND** each adapter receives the correct `baseUrl` and `authConfig` from its respective engine entity

#### Scenario: Multiple instances of same engine type
- **GIVEN** two n8n engines are registered (production at `https://n8n.prod.nl` and staging at `https://n8n.staging.nl`)
- **WHEN** `WorkflowEngineRegistry::getEnginesByType("n8n")` is called
- **THEN** it MUST return both engine entities from `WorkflowEngineMapper::findByType("n8n")`
- **AND** `HookExecutor` currently uses `$engines[0]` (the first match) for hook execution

#### Scenario: Engine type mismatch handled gracefully
- **GIVEN** a hook references engine type `"unknown_engine"` for which no adapter exists
- **WHEN** `WorkflowEngineRegistry::resolveAdapter()` is called with an engine entity of that type
- **THEN** a `match` expression MUST throw `InvalidArgumentException` with message `"Unsupported engine type: 'unknown_engine'"`

### Requirement: Engine Health Monitoring
The registry MUST support health checking engines on demand and tracking health status over time. Health checks verify connectivity without executing workflows.

#### Scenario: Health check updates engine entity
- **GIVEN** an engine with ID 3 is registered
- **WHEN** `WorkflowEngineRegistry::healthCheck(3)` is called
- **THEN** the adapter's `healthCheck()` method MUST be called (e.g., n8n GETs `/rest/settings`, Windmill GETs `/api/version`)
- **AND** the engine entity MUST be updated with `healthStatus` (boolean), `lastHealthCheck` (current DateTime), and `updated` (current DateTime)
- **AND** the response MUST include `healthy` (bool) and `responseTime` (integer, milliseconds, measured via `hrtime(true)`)

#### Scenario: n8n health check verifies settings endpoint
- **GIVEN** an n8n adapter is configured
- **WHEN** `healthCheck()` is called
- **THEN** it MUST GET `{baseUrl}/rest/settings` with a 5-second timeout
- **AND** return `true` if response status is 200, `false` otherwise
- **AND** exceptions MUST be caught (not re-thrown) and logged at DEBUG level

#### Scenario: Health check on engine registration
- **GIVEN** a new engine is created via `WorkflowEngineController::create()`
- **WHEN** the engine is successfully stored
- **THEN** an initial `healthCheck()` MUST be attempted in a try/catch block
- **AND** if the health check fails, the engine MUST still be created (health check failure is non-fatal)
- **AND** the health check failure MUST be logged as a WARNING

#### Scenario: Health check API endpoint
- **GIVEN** an admin wants to check engine health
- **WHEN** they call the health endpoint for engine ID 3
- **THEN** `WorkflowEngineController::health(3)` MUST delegate to `WorkflowEngineRegistry::healthCheck(3)`
- **AND** return the health result as JSON with `healthy` and `responseTime`
- **AND** if the engine ID does not exist, return HTTP 404

### Requirement: Error Handling and Failure Mode Application
When workflow execution fails at the adapter level (network errors, timeouts, engine unavailability), the `HookExecutor` MUST apply the appropriate failure mode from the hook configuration. The `determineFailureMode()` method MUST inspect exception messages to select among `onFailure`, `onTimeout`, and `onEngineDown` configuration values.

#### Scenario: Timeout exception applies onTimeout mode
- **GIVEN** a hook configured with `onTimeout: "allow"` and `timeout: 10`
- **WHEN** the workflow exceeds 10 seconds and throws an exception containing `"timeout"` or `"timed out"`
- **THEN** `determineFailureMode()` MUST return the value of `$hook['onTimeout']` (`"allow"`)
- **AND** `applyFailureMode("allow", ...)` MUST log a WARNING and allow the save to proceed

#### Scenario: Connection error applies onEngineDown mode
- **GIVEN** a hook configured with `onEngineDown: "queue"`
- **WHEN** the engine is unreachable and throws an exception containing `"connection"`, `"unreachable"`, or `"refused"`
- **THEN** `determineFailureMode()` MUST return `$hook['onEngineDown']` (`"queue"`)
- **AND** `applyFailureMode("queue", ...)` MUST set `_validationStatus` to `"pending"` and schedule a `HookRetryJob`

#### Scenario: Generic failure applies onFailure mode
- **GIVEN** a hook configured with `onFailure: "flag"`
- **WHEN** the workflow fails with an error not matching timeout or connection patterns
- **THEN** `determineFailureMode()` MUST return `$hook['onFailure']` (`"flag"`)
- **AND** `applyFailureMode("flag", ...)` MUST set `_validationStatus` to `"failed"` and `_validationErrors` on the object, then allow the save

#### Scenario: No engine found for type triggers onEngineDown
- **GIVEN** a hook references engine type `"n8n"` but no n8n engine is registered
- **WHEN** `HookExecutor::executeSingleHook()` calls `getEnginesByType("n8n")` and gets an empty array
- **THEN** `applyFailureMode()` MUST be called with the hook's `onEngineDown` value (default `"allow"`)
- **AND** the failure MUST be logged with message `"No engine found for type 'n8n'"`

### Requirement: Retry and Background Recovery
When a hook fails with `onEngineDown: "queue"`, the system MUST schedule a `HookRetryJob` (extending Nextcloud's `QueuedJob`) via `IJobList` for background retry with a maximum of 5 attempts (`MAX_RETRIES`).

#### Scenario: Failed hook queued for background retry
- **GIVEN** a sync hook fails because n8n is unreachable and `onEngineDown: "queue"` is configured
- **WHEN** `HookExecutor::scheduleRetryJob()` is called
- **THEN** `$this->jobList->add(HookRetryJob::class, ...)` MUST be called with `objectId`, `schemaId`, full `hook` configuration, and `attempt: 1`
- **AND** the object's `_validationStatus` MUST be set to `"pending"`

#### Scenario: Successful retry clears validation metadata
- **GIVEN** `HookRetryJob::run()` executes on attempt 3 and the workflow returns `approved` or `modified`
- **WHEN** the retry succeeds
- **THEN** `_validationStatus` MUST be set to `"passed"` and `_validationErrors` MUST be removed via `unset()`
- **AND** if the result is `modified`, the modified data MUST be merged via `array_merge()`
- **AND** the updated object MUST be persisted via `MagicMapper::update()`

#### Scenario: Max retries exceeded stops re-queuing
- **GIVEN** a hook retry reaches attempt 5 (equal to `MAX_RETRIES`)
- **WHEN** the retry fails again
- **THEN** an ERROR log MUST indicate max retries reached with the hook ID and object ID
- **AND** no further `HookRetryJob` MUST be scheduled
- **AND** the object remains with `_validationStatus: "pending"` for admin inspection

#### Scenario: Incremental retry re-queues with attempt counter
- **GIVEN** `HookRetryJob` fails on attempt 2 (below `MAX_RETRIES`)
- **WHEN** the exception is caught
- **THEN** a new `HookRetryJob` MUST be added to `IJobList` with `attempt: 3`
- **AND** all original arguments (`objectId`, `schemaId`, `hook`) MUST be preserved

### Requirement: Execution Timeout Configuration
Each hook MUST support a configurable `timeout` value (in seconds, default 30) that is passed to the engine adapter's `executeWorkflow()` method as the third parameter. Engine-level `defaultTimeout` serves as a fallback for hooks that do not specify their own timeout.

#### Scenario: Hook with custom timeout
- **GIVEN** a hook configured with `timeout: 60`
- **WHEN** `HookExecutor::executeSingleHook()` reads `$hook['timeout'] ?? 30`
- **THEN** the adapter's `executeWorkflow()` MUST receive `60` as the timeout parameter

#### Scenario: Default timeout applied when not specified
- **GIVEN** a hook with no `timeout` field
- **WHEN** `executeSingleHook()` reads the hook configuration
- **THEN** the default of `30` seconds MUST be used (from the `?? 30` fallback)

#### Scenario: Engine-level default timeout
- **GIVEN** a `WorkflowEngine` entity with `defaultTimeout: 45`
- **WHEN** the adapter is configured
- **THEN** the `defaultTimeout` from the engine entity SHOULD be available for hooks that want to inherit the engine default
- **AND** hook-level timeout MUST take precedence over engine-level default

### Requirement: Workflow Variable Injection (Object Context)
When executing a workflow, the adapter MUST receive the full object context as a CloudEvent-formatted payload built by `HookExecutor::buildCloudEventPayload()`. This payload MUST include the object data, schema reference, register ID, event type, hook mode, and OpenRegister extension attributes.

#### Scenario: CloudEvent payload includes full object context
- **GIVEN** a sync hook fires for object UUID `"abc-123"` on schema `"organisation"` in register ID `5`
- **WHEN** `buildCloudEventPayload()` constructs the payload
- **THEN** the payload MUST include: `data.object` (full object data including computed fields), `data.schema` (schema slug or title), `data.register` (register ID), `data.action` (event type string), `data.hookMode` (`"sync"` or `"async"`)
- **AND** `openregister.hookId` MUST be set to the hook's ID
- **AND** `openregister.expectResponse` MUST be `true` for sync, `false` for async

#### Scenario: Retry payload uses special event type
- **GIVEN** a hook is being retried via `HookRetryJob`
- **WHEN** the retry job constructs its CloudEvent payload
- **THEN** `CloudEventFormatter::formatAsCloudEvent()` MUST use `type: "nl.openregister.object.hook-retry"` and `data.action: "retry"`

#### Scenario: Object data includes computed field values
- **GIVEN** a schema has a computed field `volledigeNaam` and a sync hook on `creating`
- **WHEN** the hook fires
- **THEN** the CloudEvent payload's `data.object` MUST include the already-evaluated computed field values (computed fields run before hooks in the SaveObject pipeline)

### Requirement: Engine-Specific Credential Management
Engine credentials MUST be securely managed through the `WorkflowEngineRegistry` using Nextcloud's `ICrypto` service. Different auth types (none, basic, bearer, cookie) MUST be supported, and adapters MUST build appropriate HTTP headers based on the decrypted auth configuration.

#### Scenario: Bearer token authentication
- **GIVEN** an engine configured with `authType: "bearer"` and `authConfig: {"token": "secret-api-key"}`
- **WHEN** the adapter builds request options via `buildAuthHeaders()`
- **THEN** the HTTP request MUST include header `Authorization: Bearer secret-api-key`

#### Scenario: Basic authentication
- **GIVEN** an engine configured with `authType: "basic"` and `authConfig: {"username": "admin", "password": "secret"}`
- **WHEN** the adapter builds authentication headers
- **THEN** the HTTP request MUST include header `Authorization: Basic {base64("admin:secret")}`

#### Scenario: No authentication
- **GIVEN** an engine configured with `authType: "none"`
- **WHEN** the adapter builds request options
- **THEN** no `Authorization` header MUST be set
- **AND** only `Accept: application/json` MUST be included as a header

#### Scenario: Credential decryption failure handled gracefully
- **GIVEN** an engine's `authConfig` was encrypted with a previous Nextcloud instance secret
- **WHEN** `decryptAuthConfig()` calls `ICrypto::decrypt()` and it throws an exception
- **THEN** a WARNING log MUST be emitted with the engine ID and error message
- **AND** a fallback config containing only `authType` MUST be returned (no credentials)

### Requirement: Execution Audit Trail
All hook executions MUST be logged via `HookExecutor::logHookExecution()` with structured context data for debugging and audit purposes. Logs MUST use Nextcloud's `LoggerInterface` with appropriate log levels.

#### Scenario: Successful hook logged at INFO level
- **GIVEN** a sync hook executes successfully
- **WHEN** `logHookExecution()` is called with `success: true`
- **THEN** `$this->logger->info()` MUST be called with a message including hook ID, event type, object UUID, and duration in milliseconds
- **AND** context MUST include: `hookId`, `eventType`, `objectUuid`, `engine`, `workflowId`, `durationMs`, and `responseStatus`

#### Scenario: Failed hook logged at ERROR level with payload
- **GIVEN** a sync hook fails (rejection, timeout, or engine down)
- **WHEN** `logHookExecution()` is called with `success: false`
- **THEN** `$this->logger->error()` MUST be called with the standard fields plus `error` (message string)
- **AND** if a request `payload` was provided, it MUST be included in the log context for debugging

#### Scenario: Async hook delivery logged with status
- **GIVEN** an async hook fires
- **WHEN** `executeAsyncHook()` completes (success or failure)
- **THEN** a log entry MUST include `deliveryStatus` set to either `"delivered"` or `"failed"`

#### Scenario: Duration tracked via high-resolution timer
- **GIVEN** any hook execution starts
- **WHEN** `hrtime(true)` is called at the start and end of execution
- **THEN** `durationMs` MUST be calculated as `(int)((hrtime(true) - $startTime) / 1_000_000)`
- **AND** included in every log entry for performance monitoring

### Requirement: Engine Migration Support
The system MUST support migrating workflows between engines without losing hook configurations or deployed workflow tracking. The `DeployedWorkflow` entity and hash-based versioning enable idempotent re-deployment to new engines.

#### Scenario: Switch engine type on a hook
- **GIVEN** a schema hook currently references engine type `"n8n"` with `workflowId: "42"`
- **WHEN** the admin updates the hook to reference engine type `"windmill"` with a new `workflowId`
- **THEN** the hook configuration on the schema MUST be updated
- **AND** the next execution MUST route through `WindmillAdapter` instead of `N8nAdapter`
- **AND** no previously persisted objects are affected

#### Scenario: Re-deploy workflows to new engine via import
- **GIVEN** a set of workflows was originally imported targeting n8n
- **WHEN** the import JSON is updated to target Windmill and re-imported
- **THEN** `ImportHandler` MUST deploy the workflows to Windmill via `WindmillAdapter::deployWorkflow()`
- **AND** `DeployedWorkflow` records MUST be updated with the new engine type and engine workflow ID
- **AND** schema hooks MUST be updated to reference the new engine type

#### Scenario: Engine removal does not break existing hook configurations
- **GIVEN** an n8n engine is removed via `DELETE /api/engines/{id}`
- **WHEN** a hook still references engine type `"n8n"`
- **THEN** the hook configuration on the schema remains intact
- **AND** on next execution, `getEnginesByType("n8n")` returns empty and the `onEngineDown` failure mode applies
- **AND** once a new n8n engine is registered, hooks automatically resume working

### Requirement: Deployed Workflow Tracking
Workflows deployed through the import pipeline MUST be tracked via the `DeployedWorkflow` entity for versioning, update detection, and export round-tripping. A SHA-256 hash of the workflow definition enables idempotent re-imports.

#### Scenario: Track deployed workflow with metadata
- **GIVEN** a workflow `"Validate Organisation KvK"` is deployed via import
- **WHEN** a `DeployedWorkflow` record is created
- **THEN** it MUST store: `uuid` (auto-generated UUID v4), `name`, `engine` (type string), `engineWorkflowId` (ID returned by the engine), `sourceHash` (SHA-256 of workflow definition), `attachedSchema` (slug if hook was wired), `attachedEvent` (event type if hooked), `importSource` (filename), `version` (integer starting at 1), `created`, `updated`

#### Scenario: Hash comparison enables idempotent re-import
- **GIVEN** a workflow was previously deployed with hash `"abc123"`
- **WHEN** the same import is re-run with an identical workflow definition
- **THEN** the computed SHA-256 hash matches the stored hash
- **AND** `updateWorkflow()` MUST NOT be called (no redundant deployment)
- **AND** the import summary MUST report the workflow as `"unchanged"`

#### Scenario: Updated workflow increments version
- **GIVEN** a workflow was previously deployed at version 1
- **WHEN** the import file contains a modified workflow definition (different hash)
- **THEN** `WorkflowEngineInterface::updateWorkflow()` MUST be called with the existing engine workflow ID
- **AND** the `DeployedWorkflow` version MUST be incremented to 2
- **AND** the stored `sourceHash` MUST be updated to the new hash value

#### Scenario: Find deployed workflows by schema
- **GIVEN** three deployed workflows are attached to schema `"organisation"`
- **WHEN** `DeployedWorkflowMapper::findBySchema("organisation")` is called
- **THEN** all three workflows MUST be returned for export purposes

## Non-Requirements
- This spec does NOT define how workflows are triggered by object lifecycle events (see Schema Hooks spec)
- This spec does NOT define the import format for bundling workflows with schemas (see Workflow-in-Import spec)
- This spec does NOT handle workflow UI/editing within OpenRegister (use engine's native UI -- n8n editor, Windmill IDE)
- This spec does NOT define approval chain state machines or notification workflows (see Workflow Integration spec)
- This spec does NOT define the CloudEvents wire format (see Schema Hooks spec for `CloudEventFormatter`)

## Dependencies
- n8n-nextcloud ExApp (existing)
- Windmill ExApp (existing)
- OpenRegister event system (`IEventDispatcher`, lifecycle events)
- Nextcloud `ICrypto` service for credential encryption
- Nextcloud `IAppManager` for ExApp auto-discovery
- Nextcloud `IClientService` for HTTP communication
- Nextcloud `QueuedJob` and `IJobList` for background retry

## Cross-References
- **schema-hooks** -- Schema hooks consume the `WorkflowEngineInterface` as their execution backend. `HookExecutor` resolves adapters from `WorkflowEngineRegistry` and calls `executeWorkflow()` for each hook.
- **workflow-in-import** -- The import pipeline deploys workflows to engines via `deployWorkflow()` and tracks them via `DeployedWorkflow`. Export retrieves definitions via `getWorkflow()`.
- **workflow-integration** -- The broader workflow automation spec covers event-workflow connections, approval chains, and monitoring that build on top of this engine abstraction layer.

### Current Implementation Status

**Fully implemented.** All core requirements are in place:

- `lib/WorkflowEngine/WorkflowEngineInterface.php` -- PHP interface with methods: `deployWorkflow()`, `updateWorkflow()`, `getWorkflow()`, `deleteWorkflow()`, `activateWorkflow()`, `deactivateWorkflow()`, `executeWorkflow()`, `getWebhookUrl()`, `listWorkflows()`, `healthCheck()`
- `lib/WorkflowEngine/N8nAdapter.php` -- n8n adapter implementing `WorkflowEngineInterface`; routes through ExApp proxy; supports bearer and basic auth; parses n8n responses into `WorkflowResult`; detects timeouts from exception messages
- `lib/WorkflowEngine/WindmillAdapter.php` -- Windmill adapter implementing `WorkflowEngineInterface`; workspace-scoped API paths; activate/deactivate as no-ops; version endpoint for health checks
- `lib/WorkflowEngine/WorkflowResult.php` -- Structured result value object implementing `JsonSerializable`; four statuses: `STATUS_APPROVED`, `STATUS_REJECTED`, `STATUS_MODIFIED`, `STATUS_ERROR`; factory methods (`approved()`, `rejected()`, `modified()`, `error()`); type-safe accessors (`isApproved()`, `isRejected()`, etc.); validates status in constructor with `InvalidArgumentException`
- `lib/Db/WorkflowEngine.php` -- Entity for engine configuration storage (uuid, name, engineType, baseUrl, authType, authConfig, enabled, defaultTimeout, healthStatus, lastHealthCheck, created, updated); `jsonSerialize()` excludes `authConfig`
- `lib/Db/WorkflowEngineMapper.php` -- Database mapper for `oc_openregister_workflow_engines` table; `find()`, `findAll()`, `findByType()`, `createFromArray()`, `updateFromArray()`; auto-generates UUID v4 on create
- `lib/Db/DeployedWorkflow.php` -- Entity tracking deployed workflows with uuid, name, engine, engineWorkflowId, sourceHash, attachedSchema, attachedEvent, importSource, version
- `lib/Db/DeployedWorkflowMapper.php` -- Mapper for `oc_openregister_deployed_workflows`; `findByNameAndEngine()`, `findBySchema()`, `findByImportSource()`
- `lib/Service/WorkflowEngineRegistry.php` -- Registry service; `resolveAdapter()` with `match` expression; `createEngine()`/`updateEngine()` encrypt `authConfig` via `ICrypto`; `healthCheck()` measures response time via `hrtime(true)` and updates entity; `discoverEngines()` checks `IAppManager` for installed ExApps; `decryptAuthConfig()` with graceful fallback on failure
- `lib/Controller/WorkflowEngineController.php` -- REST API controller; `index()`, `show()`, `create()`, `update()`, `destroy()`, `health()`, `available()`; validates engine type on creation; runs initial health check on create
- `lib/Service/HookExecutor.php` -- Integrates with WorkflowEngineRegistry to resolve adapters per hook; processes `WorkflowResult` statuses; applies failure modes (reject/allow/flag/queue); supports async execution; structured logging with duration tracking
- `lib/BackgroundJob/HookRetryJob.php` -- `QueuedJob` for `"queue"` failure mode; max 5 retries; incremental attempt counter; updates `_validationStatus` on success
- `lib/AppInfo/Application.php` -- Registers workflow engine services in DI container
- `lib/Service/Configuration/ImportHandler.php` -- Deploys workflows via interface, tracks via `DeployedWorkflow`, hash-based idempotent re-import
- `lib/Service/Configuration/ExportHandler.php` -- Exports deployed workflows by fetching definitions from engines

**What is NOT yet implemented:**
- Connection pooling or rate limiting to engines (no specification for throttling high-frequency hook executions)
- Engine version compatibility checks (no validation that deployed workflow format matches engine version)
- Credential rotation notifications (no mechanism to alert when engine credentials are about to expire)
- Engine failover (when multiple instances of the same type are registered, only `$engines[0]` is used -- no round-robin or health-based selection)
- Execution log persistence in database (currently logged to Nextcloud's log file only, not queryable)

### Standards & References
- Adapter pattern (Gang of Four design patterns) -- `N8nAdapter` and `WindmillAdapter` implement `WorkflowEngineInterface`
- n8n REST API (https://docs.n8n.io/api/) -- workflow CRUD at `/rest/workflows`, webhook triggers at `/webhook/{id}`, health at `/rest/settings`
- Windmill REST API (https://app.windmill.dev/openapi.html) -- workspace-scoped flows at `/api/w/{workspace}/flows/*`, sync execution at `/api/w/{workspace}/jobs/run_wait_result/f/{path}`, health at `/api/version`
- Nextcloud ExApp API proxy (`IAppApiService`) -- routes requests through Nextcloud authentication layer
- Nextcloud `ICrypto` -- symmetric encryption for credential storage at rest
- Nextcloud `IAppManager` -- app installation detection for engine auto-discovery
- Nextcloud `IClientService` -- HTTP client factory for outbound API calls
- Nextcloud `QBMapper` / `Entity` -- ORM layer for engine configuration persistence
- Dependency Injection (Nextcloud DI container via `IBootstrap::register()`)
- CloudEvents 1.0 (https://cloudevents.io/) -- payload format used by `HookExecutor` when calling engine adapters

### Specificity Assessment
- **Specific enough to implement?** Yes -- the interface, entity schema, adapter scenarios, credential management, and registry are all well-defined and fully implemented.
- **Missing/ambiguous:**
  - No specification for credential rotation or expiry handling
  - No specification for engine version compatibility checks
  - No specification for connection pooling or rate limiting to engines
  - No specification for engine failover when multiple instances of the same type exist
  - No specification for execution log persistence in a queryable database table
- **Open questions:**
  - Should additional engine types beyond n8n and Windmill be pluggable via a dynamic adapter registration mechanism (instead of hardcoded `match` expression)?
  - How should engine failover work when multiple instances of the same type are registered (round-robin, health-based, manual selection)?
  - Should execution logs be stored in the database for queryable metrics, or is Nextcloud's log file sufficient?

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: `WorkflowEngineInterface` defines the engine-agnostic PHP interface. `N8nAdapter` and `WindmillAdapter` implement it. `WorkflowResult` provides structured responses (approved/rejected/modified/error). `WorkflowEngine` entity stores engine configuration. `WorkflowEngineRegistry` manages adapter resolution with `ICrypto` credential encryption, `IAppManager` engine discovery, and health checking with response time measurement. `WorkflowEngineController` exposes REST API with CRUD, health, and discovery endpoints. `DeployedWorkflow` tracks imported workflows. `HookRetryJob` handles background retry.
- **Nextcloud Core Integration**: All services registered via DI container in `IBootstrap::register()` (`Application.php`). The `WorkflowEngine` entity extends NC's `Entity` base class, `WorkflowEngineMapper` extends `QBMapper`. Credential storage uses NC's `ICrypto` for encryption at rest. The n8n adapter routes through NC's `IAppApiService` ExApp proxy. Engine auto-discovery leverages `IAppManager::isEnabledForUser()`. Background retry uses NC's `QueuedJob` and `IJobList`. HTTP communication via NC's `IClientService`. Logging via PSR-3 `LoggerInterface`.
- **Recommendation**: Mark as implemented. All 15 requirements are covered by the existing codebase. Future enhancements: (1) implement engine failover/load balancing for multiple instances of the same type, (2) add dynamic adapter registration for third-party engine plugins, (3) persist execution logs in a database table for queryable metrics.
