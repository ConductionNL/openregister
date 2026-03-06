# Workflow Engine Abstraction -- Delta Spec

This is a delta spec for `openspec/specs/workflow-engine-abstraction/spec.md`. Since this spec is NEW (not modifying an existing shared spec), all requirements are listed under ADDED.

## ADDED Requirements

### Requirement: Engine Registry
OpenRegister MUST maintain a registry of available workflow engines. Multiple engines MAY be active simultaneously.

#### Scenario: Register a workflow engine
- GIVEN an admin user is authenticated
- WHEN they POST to `/api/engines/` with engine type, base URL, and credentials
- THEN the engine MUST be stored in OpenRegister's configuration
- AND a health check MUST be performed to confirm connectivity
- AND the response MUST include the created engine configuration with its assigned ID

#### Scenario: Multiple engines active simultaneously
- GIVEN two engines are registered (one n8n, one Windmill)
- WHEN a single schema has hook 1 referencing engine type "n8n" and hook 2 referencing engine type "windmill"
- THEN hook 1 MUST be routed to the n8n adapter
- AND hook 2 MUST be routed to the Windmill adapter
- AND engine selection MUST be per-hook, NOT per-schema

#### Scenario: List registered engines
- GIVEN one or more engines are registered
- WHEN an authenticated user sends `GET /api/engines/`
- THEN the response MUST include all registered engines with their type, name, enabled status, and last health check result
- AND credentials MUST NOT be included in the response

#### Scenario: Remove a registered engine
- GIVEN an engine is registered
- WHEN an admin sends `DELETE /api/engines/{id}`
- THEN the engine MUST be removed from the registry
- AND any hooks referencing this engine SHOULD receive a warning on next invocation

### Requirement: Engine Configuration Entity
Engine configuration MUST be stored as a persistent entity with the following properties.

#### Scenario: Required fields
- GIVEN an admin creates an engine configuration
- WHEN the request body is validated
- THEN the entity MUST require `name` (string), `engineType` (enum: "n8n", "windmill"), `baseUrl` (URI), and `enabled` (boolean)
- AND the entity MUST accept optional fields: `authType` (enum: "none", "basic", "bearer", "cookie"), `authConfig` (object), `defaultTimeout` (integer, default 30)

#### Scenario: Credential storage
- GIVEN an engine configuration includes `authConfig` with sensitive credentials
- WHEN the configuration is stored
- THEN credentials MUST be encrypted at rest using Nextcloud's `ICrypto` service
- AND credentials MUST NOT appear in API GET responses or logs

### Requirement: Workflow Engine Interface
Each engine adapter MUST implement `WorkflowEngineInterface` with the following methods.

#### Scenario: Deploy a workflow
- GIVEN an adapter implements `WorkflowEngineInterface`
- WHEN `deployWorkflow(array $workflowDefinition)` is called
- THEN the adapter MUST translate the definition to the engine's native format
- AND POST it to the engine's workflow creation endpoint
- AND return the engine-specific workflow ID as a string

#### Scenario: Execute a workflow synchronously
- GIVEN a workflow is deployed and active
- WHEN `executeWorkflow(string $workflowId, array $data, int $timeout = 30)` is called
- THEN the adapter MUST send the data to the workflow's trigger endpoint
- AND wait for the response up to `$timeout` seconds
- AND return a `WorkflowResult` object

#### Scenario: Execute with timeout exceeded
- GIVEN a workflow takes longer than the configured timeout
- WHEN `executeWorkflow()` is called
- THEN the adapter MUST return a `WorkflowResult` with status `"error"`
- AND the errors array MUST contain a timeout error message

#### Scenario: Health check
- GIVEN an adapter implements `WorkflowEngineInterface`
- WHEN `healthCheck()` is called
- THEN the adapter MUST verify connectivity to the engine
- AND return `true` if the engine is reachable and responsive, `false` otherwise
- AND the check MUST NOT throw exceptions

#### Scenario: List workflows
- GIVEN an adapter implements `WorkflowEngineInterface`
- WHEN `listWorkflows()` is called
- THEN the adapter MUST return an array of workflow summaries from the engine
- AND each entry MUST include at minimum an `id` and `name`

### Requirement: WorkflowResult
Synchronous workflow execution MUST return a `WorkflowResult` value object.

#### Scenario: Approved result
- GIVEN a workflow executes successfully and approves the data
- WHEN the result is returned
- THEN `status` MUST be `"approved"`
- AND `data` MAY be null (original data passes through unchanged)

#### Scenario: Rejected result
- GIVEN a workflow rejects the data due to validation failures
- WHEN the result is returned
- THEN `status` MUST be `"rejected"`
- AND `errors` MUST be an array of objects with `field`, `message`, and optional `code`

#### Scenario: Modified result
- GIVEN a workflow modifies/enriches the data
- WHEN the result is returned
- THEN `status` MUST be `"modified"`
- AND `data` MUST contain the modified object data

#### Scenario: Error result
- GIVEN a workflow execution fails (network error, timeout, engine error)
- WHEN the result is returned
- THEN `status` MUST be `"error"`
- AND `errors` MUST contain at least one error describing the failure
- AND `metadata` SHOULD include engine-specific error details

### Requirement: n8n Adapter
The n8n adapter MUST implement `WorkflowEngineInterface` using n8n's REST API.

#### Scenario: Deploy workflow to n8n
- GIVEN an n8n engine is registered with a valid base URL
- WHEN `deployWorkflow()` is called with n8n workflow JSON
- THEN the adapter MUST POST to `{baseUrl}/rest/workflows`
- AND return the n8n workflow ID from the response

#### Scenario: Execute workflow via webhook
- GIVEN an n8n workflow has a webhook trigger
- WHEN `executeWorkflow()` is called with object data
- THEN the adapter MUST POST the data to the workflow's webhook URL
- AND parse the n8n response into a `WorkflowResult`

#### Scenario: Route through ExApp proxy
- GIVEN n8n runs as a Nextcloud ExApp
- WHEN the adapter makes API calls
- THEN it SHOULD route through `/index.php/apps/app_api/proxy/n8n/`
- AND include proper Nextcloud authentication headers via `IAppApiService`

### Requirement: Windmill Adapter
The Windmill adapter MUST implement `WorkflowEngineInterface` using Windmill's REST API.

#### Scenario: Deploy workflow to Windmill
- GIVEN a Windmill engine is registered with a valid base URL and workspace
- WHEN `deployWorkflow()` is called with Windmill flow JSON
- THEN the adapter MUST POST to `{baseUrl}/api/w/{workspace}/flows/create`
- AND return the Windmill flow path

#### Scenario: Execute workflow synchronously
- GIVEN a Windmill flow exists
- WHEN `executeWorkflow()` is called with object data
- THEN the adapter MUST POST to `{baseUrl}/api/w/{workspace}/jobs/run_wait_result/f/{flowPath}`
- AND parse the response into a `WorkflowResult`

### Requirement: Engine Auto-Discovery
OpenRegister SHOULD auto-detect available engines from installed Nextcloud ExApps.

#### Scenario: n8n ExApp is installed
- GIVEN the n8n ExApp is enabled in Nextcloud
- WHEN OpenRegister checks for available engines via `GET /api/engines/available`
- THEN n8n MUST appear in the list of available engine types
- AND the base URL MUST be pre-filled from the ExApp configuration

#### Scenario: No ExApps installed
- GIVEN no workflow engine ExApps are installed
- WHEN OpenRegister checks for available engines
- THEN the list MUST be empty
- AND the system MUST NOT error
- AND manual engine configuration MUST still be possible
