# Tasks: workflow-engine-abstraction

## 1. Interface & Value Object

### Task 1.1: Create WorkflowEngineInterface
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-workflow-engine-interface`
- **files**: `openregister/lib/WorkflowEngine/WorkflowEngineInterface.php`
- **acceptance_criteria**:
  - GIVEN the interface exists WHEN an adapter class is created THEN it MUST implement `deployWorkflow()`, `deleteWorkflow()`, `activateWorkflow()`, `deactivateWorkflow()`, `executeWorkflow()`, `getWebhookUrl()`, `listWorkflows()`, and `healthCheck()`
  - GIVEN the interface WHEN `executeWorkflow()` is defined THEN it MUST accept `string $workflowId`, `array $data`, `int $timeout = 30` and return `WorkflowResult`
- [x] Implement
- [x] Test

### Task 1.2: Create WorkflowResult value object
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-workflowresult`
- **files**: `openregister/lib/WorkflowEngine/WorkflowResult.php`
- **acceptance_criteria**:
  - GIVEN a `WorkflowResult` is constructed WHEN `status` is provided THEN it MUST be one of: `approved`, `rejected`, `modified`, `error`
  - GIVEN status is `rejected` WHEN `errors` is accessed THEN it MUST be an array of objects with `field`, `message`, and optional `code`
  - GIVEN status is `modified` WHEN `data` is accessed THEN it MUST contain the modified object data
  - GIVEN any result WHEN `toArray()` is called THEN it MUST return a JSON-serializable array
- [x] Implement
- [x] Test

## 2. Entity & Database

### Task 2.1: Create WorkflowEngine entity
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-engine-configuration-entity`
- **files**: `openregister/lib/Db/WorkflowEngine.php`
- **acceptance_criteria**:
  - GIVEN the entity extends `OCP\AppFramework\Db\Entity` WHEN properties are defined THEN it MUST include: `uuid`, `name`, `engineType`, `baseUrl`, `authType`, `authConfig`, `enabled`, `defaultTimeout`, `healthStatus`, `lastHealthCheck`, `created`, `updated`
  - GIVEN `authConfig` WHEN serialized for API response THEN it MUST be excluded (via `jsonSerialize()` override)
- [x] Implement
- [x] Test

### Task 2.2: Create WorkflowEngineMapper
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-engine-registry`
- **files**: `openregister/lib/Db/WorkflowEngineMapper.php`
- **acceptance_criteria**:
  - GIVEN the mapper extends `QBMapper` WHEN `findAll()` is called THEN it MUST return all registered engines
  - GIVEN the mapper WHEN `findByType(string $engineType)` is called THEN it MUST return engines matching the given type
- [x] Implement
- [x] Test

### Task 2.3: Create database migration
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-engine-configuration-entity`
- **files**: `openregister/lib/Migration/VersionXXXXDate_CreateWorkflowEngines.php`
- **acceptance_criteria**:
  - GIVEN the migration runs WHEN `changeSchema()` executes THEN the `openregister_workflow_engines` table MUST be created with all required columns
  - GIVEN the migration WHEN rolled back THEN the table MUST be droppable without affecting other tables
- [x] Implement
- [x] Test

## 3. n8n Adapter

### Task 3.1: Implement N8nAdapter
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-n8n-adapter`
- **files**: `openregister/lib/WorkflowEngine/N8nAdapter.php`
- **acceptance_criteria**:
  - GIVEN an n8n engine configuration WHEN `deployWorkflow()` is called THEN it MUST POST to `{baseUrl}/rest/workflows` and return the n8n workflow ID
  - GIVEN an n8n workflow with webhook trigger WHEN `executeWorkflow()` is called THEN it MUST POST data to the webhook URL and return a `WorkflowResult`
  - GIVEN n8n runs as an ExApp WHEN API calls are made THEN they SHOULD route through `/index.php/apps/app_api/proxy/n8n/`
  - GIVEN the engine is unreachable WHEN `healthCheck()` is called THEN it MUST return `false` without throwing
  - GIVEN a timeout is configured WHEN `executeWorkflow()` exceeds it THEN it MUST return `WorkflowResult` with status `error`
- [x] Implement
- [x] Test

## 4. Windmill Adapter

### Task 4.1: Implement WindmillAdapter
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-windmill-adapter`
- **files**: `openregister/lib/WorkflowEngine/WindmillAdapter.php`
- **acceptance_criteria**:
  - GIVEN a Windmill engine configuration WHEN `deployWorkflow()` is called THEN it MUST POST to `{baseUrl}/api/w/{workspace}/flows/create` and return the flow path
  - GIVEN a Windmill flow WHEN `executeWorkflow()` is called THEN it MUST POST to `{baseUrl}/api/w/{workspace}/jobs/run_wait_result/f/{flowPath}` and return a `WorkflowResult`
  - GIVEN the engine is unreachable WHEN `healthCheck()` is called THEN it MUST return `false` without throwing
- [x] Implement
- [x] Test

## 5. Registry Service

### Task 5.1: Create WorkflowEngineRegistry
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-engine-registry`
- **files**: `openregister/lib/Service/WorkflowEngineRegistry.php`
- **acceptance_criteria**:
  - GIVEN engines are registered WHEN `resolveAdapter(string $engineType)` is called with `"n8n"` THEN it MUST return a configured `N8nAdapter` instance
  - GIVEN engines are registered WHEN `resolveAdapter(string $engineType)` is called with `"windmill"` THEN it MUST return a configured `WindmillAdapter` instance
  - GIVEN an unknown engine type WHEN `resolveAdapter()` is called THEN it MUST throw an `\InvalidArgumentException`
  - GIVEN multiple engines of the same type WHEN `getEnginesByType('n8n')` is called THEN it MUST return all n8n engines
  - GIVEN the registry WHEN credentials are passed to adapters THEN they MUST be decrypted via `ICrypto` before use
- [x] Implement
- [x] Test

## 6. API Endpoints

### Task 6.1: Create WorkflowEngineController
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-engine-registry`
- **files**: `openregister/lib/Controller/WorkflowEngineController.php`, `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN `POST /api/engines/` is called with valid engine config THEN the engine MUST be created and a health check MUST run
  - GIVEN an authenticated user WHEN `GET /api/engines/` is called THEN all engines MUST be returned with credentials redacted
  - GIVEN an admin WHEN `PUT /api/engines/{id}` is called THEN the engine MUST be updated
  - GIVEN an admin WHEN `DELETE /api/engines/{id}` is called THEN the engine MUST be removed
  - GIVEN a non-admin user WHEN `POST /api/engines/` is called THEN the response MUST be 403
- [x] Implement
- [x] Test

### Task 6.2: Register routes in routes.php
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-engine-registry`
- **files**: `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN routes.php is updated WHEN the app loads THEN routes for `GET/POST /api/engines/`, `GET/PUT/DELETE /api/engines/{id}`, `POST /api/engines/{id}/health`, and `GET /api/engines/available` MUST be registered
  - GIVEN route ordering WHEN engine routes are defined THEN they MUST appear before any wildcard `{catalogSlug}` routes
- [x] Implement
- [x] Test

## 7. Health Check

### Task 7.1: Add engine health check endpoint
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-engine-registry` (health check scenario)
- **files**: `openregister/lib/Controller/WorkflowEngineController.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN `POST /api/engines/{id}/health` is called THEN the registry MUST resolve the adapter and call `healthCheck()`
  - GIVEN the health check succeeds WHEN the response is returned THEN it MUST include `healthy: true` and `responseTime` in milliseconds
  - GIVEN the health check fails WHEN the response is returned THEN it MUST include `healthy: false` and an error message
  - GIVEN a health check is performed WHEN it completes THEN `healthStatus` and `lastHealthCheck` MUST be updated on the engine entity
- [x] Implement
- [x] Test

## 8. ExApp Auto-Discovery

### Task 8.1: Implement auto-discovery of workflow engine ExApps
- **spec_ref**: `specs/workflow-engine-abstraction/spec.md#requirement-engine-auto-discovery`
- **files**: `openregister/lib/Service/WorkflowEngineRegistry.php`, `openregister/lib/Controller/WorkflowEngineController.php`
- **acceptance_criteria**:
  - GIVEN the n8n ExApp is installed WHEN `GET /api/engines/available` is called THEN n8n MUST appear with a pre-filled base URL
  - GIVEN no workflow engine ExApps are installed WHEN `GET /api/engines/available` is called THEN the response MUST be an empty array (no error)
  - GIVEN `app_api` is not installed WHEN auto-discovery runs THEN it MUST gracefully return an empty list
  - GIVEN an ExApp is discovered WHEN an admin creates an engine from it THEN the base URL and auth config SHOULD be pre-populated
- [x] Implement
- [x] Test

## 9. DI Registration

### Task 9.1: Register services in Application.php
- **spec_ref**: design.md (Nextcloud Integration section)
- **files**: `openregister/lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN DI container is built THEN `WorkflowEngineRegistry`, `N8nAdapter`, `WindmillAdapter`, `WorkflowEngineMapper`, and `WorkflowEngineController` MUST be resolvable
- [x] Implement (auto-wired by Nextcloud DI — no manual registration needed)
- [x] Test

## Verification
- [ ] All tasks checked off
- [ ] `composer check:strict` passes in openregister
- [ ] Database migration runs without errors
- [ ] Engine CRUD endpoints work via curl
- [ ] Health check returns correct status for reachable/unreachable engines
- [ ] Credentials are encrypted in DB and never exposed in API responses
- [ ] Code review against spec requirements
