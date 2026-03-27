# Workflow Engine Abstraction

---
status: implemented
---

## Purpose
Provides an engine-agnostic interface for OpenRegister to interact with workflow engines (n8n, Windmill, and future engines). This is the foundation layer that other specs (Schema Hooks, Workflow-in-Import) build upon.

## Context
OpenRegister needs to trigger external workflow engines for validation, enrichment, notifications, and automation. Currently n8n runs as a Nextcloud ExApp (FastAPI proxy to n8n at :5678) and Windmill exists as a separate ExApp. Rather than coupling to either engine, OpenRegister defines a shared interface with per-engine adapters.

Multiple engines can be active simultaneously. Each individual hook on a schema specifies which engine it uses, so a single schema can have hooks targeting different engines (e.g., hook 1 uses n8n for validation, hook 2 uses Windmill for enrichment).

## Requirements

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

#### Schema: WorkflowEngine
```json
{
  "type": "object",
  "required": ["name", "engineType", "baseUrl", "enabled"],
  "properties": {
    "name": {
      "type": "string",
      "description": "Human-readable engine name"
    },
    "engineType": {
      "type": "string",
      "enum": ["n8n", "windmill"],
      "description": "Engine type, determines which adapter is used"
    },
    "baseUrl": {
      "type": "string",
      "format": "uri",
      "description": "Base URL of the engine API (e.g., http://localhost:5678 for n8n)"
    },
    "authType": {
      "type": "string",
      "enum": ["none", "basic", "bearer", "cookie"],
      "default": "none",
      "description": "Authentication method"
    },
    "authConfig": {
      "type": "object",
      "description": "Auth-specific configuration (credentials, token, etc.)"
    },
    "enabled": {
      "type": "boolean",
      "default": true
    },
    "defaultTimeout": {
      "type": "integer",
      "default": 30,
      "description": "Default timeout in seconds for sync calls"
    }
  }
}
```

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
Each engine adapter MUST implement a common PHP interface.

```php
interface WorkflowEngineInterface
{
    /** Deploy a workflow definition to the engine, returns engine-specific workflow ID */
    public function deployWorkflow(array $workflowDefinition): string;

    /** Remove a workflow from the engine */
    public function deleteWorkflow(string $workflowId): void;

    /** Activate a workflow so it can receive triggers */
    public function activateWorkflow(string $workflowId): void;

    /** Deactivate a workflow */
    public function deactivateWorkflow(string $workflowId): void;

    /** Execute a workflow synchronously and return the response */
    public function executeWorkflow(string $workflowId, array $data, int $timeout = 30): WorkflowResult;

    /** Get the webhook URL that triggers a specific workflow */
    public function getWebhookUrl(string $workflowId): string;

    /** List all workflows in the engine */
    public function listWorkflows(): array;

    /** Check engine health/connectivity */
    public function healthCheck(): bool;
}
```

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

### Requirement: Workflow Result
Synchronous workflow execution MUST return a structured result.

#### Schema: WorkflowResult
```json
{
  "type": "object",
  "required": ["status"],
  "properties": {
    "status": {
      "type": "string",
      "enum": ["approved", "rejected", "modified", "error"],
      "description": "Outcome of the workflow execution"
    },
    "data": {
      "type": "object",
      "description": "Modified object data (when status is 'modified')"
    },
    "errors": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "field": { "type": "string" },
          "message": { "type": "string" },
          "code": { "type": "string" }
        }
      },
      "description": "Validation errors (when status is 'rejected')"
    },
    "metadata": {
      "type": "object",
      "description": "Engine-specific metadata (execution ID, duration, etc.)"
    }
  }
}
```

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
The n8n adapter MUST translate the interface to n8n's REST API.

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
The Windmill adapter MUST translate the interface to Windmill's REST API.

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

## Non-Requirements
- This spec does NOT define how workflows are triggered (see Schema Hooks spec)
- This spec does NOT define import format (see Workflow-in-Import spec)
- This spec does NOT handle workflow UI/editing (use engine's native UI)

## Dependencies
- n8n-nextcloud ExApp (existing)
- Windmill ExApp (existing)
- OpenRegister event system (existing)

### Current Implementation Status

**Fully implemented.** All core requirements are in place:

- `lib/WorkflowEngine/WorkflowEngineInterface.php` -- PHP interface with methods: `deployWorkflow()`, `deleteWorkflow()`, `activateWorkflow()`, `deactivateWorkflow()`, `executeWorkflow()`, `getWebhookUrl()`, `listWorkflows()`, `healthCheck()`
- `lib/WorkflowEngine/N8nAdapter.php` -- n8n adapter implementing `WorkflowEngineInterface`, routes through ExApp proxy
- `lib/WorkflowEngine/WindmillAdapter.php` -- Windmill adapter implementing `WorkflowEngineInterface`
- `lib/WorkflowEngine/WorkflowResult.php` -- Structured result class with statuses: `STATUS_APPROVED`, `STATUS_REJECTED`, `STATUS_MODIFIED`, `STATUS_ERROR`; implements `JsonSerializable`
- `lib/Db/WorkflowEngine.php` -- Entity for engine configuration storage (name, engineType, baseUrl, authType, authConfig, enabled, defaultTimeout)
- `lib/Db/WorkflowEngineMapper.php` -- Database mapper for WorkflowEngine entities
- `lib/Service/WorkflowEngineRegistry.php` -- Registry service for managing and resolving engine adapters
- `lib/Controller/WorkflowEngineController.php` -- REST API controller for CRUD on engine configurations
- `lib/Service/HookExecutor.php` -- Integrates with WorkflowEngineRegistry to resolve adapters per hook
- `lib/AppInfo/Application.php` -- Registers workflow engine services in DI container

**What is NOT yet implemented:**
- Engine auto-discovery from installed ExApps (`GET /api/engines/available`)
- Credential encryption at rest via `ICrypto` (needs verification)
- Health check on engine registration

### Standards & References
- Adapter pattern (Gang of Four design patterns)
- n8n REST API (https://docs.n8n.io/api/)
- Windmill REST API (https://app.windmill.dev/openapi.html)
- Nextcloud ExApp API proxy (`IAppApiService`)
- Dependency Injection (Nextcloud DI container)

### Specificity Assessment
- **Specific enough to implement?** Yes -- the interface, entity schema, and adapter scenarios are all well-defined and implemented.
- **Missing/ambiguous:**
  - No specification for credential rotation or expiry handling
  - No specification for engine version compatibility checks
  - No specification for connection pooling or rate limiting to engines
- **Open questions:**
  - Should additional engine types beyond n8n and Windmill be pluggable via a registration mechanism?
  - How should engine failover work when multiple instances of the same type are registered?

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: `WorkflowEngineInterface` defines the engine-agnostic PHP interface. `N8nAdapter` and `WindmillAdapter` implement it. `WorkflowResult` provides structured responses (approved/rejected/modified/error). `WorkflowEngine` entity stores engine configuration. `WorkflowEngineRegistry` manages adapter resolution. `WorkflowEngineController` exposes REST API.
- **Nextcloud Core Integration**: All services registered via DI container in `IBootstrap::register()` (`Application.php`). The `WorkflowEngine` entity extends NC's `Entity` base class, `WorkflowEngineMapper` extends `QBMapper`. Credential storage should use NC's `ICrypto` for encryption at rest. The n8n adapter routes through NC's `IAppApiService` ExApp proxy. Engine auto-discovery should leverage `IAppManager` to detect installed ExApps.
- **Recommendation**: Mark as implemented. Consider verifying `ICrypto` credential encryption and implementing engine auto-discovery via `IAppManager` for installed ExApps.
