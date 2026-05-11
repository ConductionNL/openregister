# Tasks: add-ncflow-engine-adapter

## Component A — NCFlowAdapter class skeleton (S)

### A-1. Create `NCFlowAdapter` class skeleton

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-nc-flow-adapter-implements-workflowengineinterface`
- **files**: `lib/WorkflowEngine/NCFlowAdapter.php`
- **acceptance_criteria**:
  - GIVEN `NCFlowAdapter` is defined WHEN PHP linting runs THEN it MUST declare
    `implements WorkflowEngineInterface` with all ten methods stubbed
  - GIVEN the class WHEN `composer check:strict` runs THEN PHPCS, PHPMD, and PHPStan
    MUST report zero new violations in the file
  - GIVEN the constructor WHEN resolved from Nextcloud's DI container THEN it MUST
    accept `\OCP\WorkflowEngine\IManager`, `\OCP\EventDispatcher\IEventDispatcher`, and
    `\OCP\IURLGenerator` without manual `registerService()` calls
- [ ] Implement
- [ ] Test

### A-2. Create `UnsupportedFeatureException`

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-out-of-scope-behaviours-return-documented-errors`
- **files**: `lib/WorkflowEngine/Exception/UnsupportedFeatureException.php`
- **acceptance_criteria**:
  - GIVEN `UnsupportedFeatureException` is thrown WHEN the caller catches `\Throwable`
    THEN the message MUST include both the unsupported feature name and an engine
    recommendation
- [ ] Implement
- [ ] Test

---

## Component B — deployWorkflow: rule creation (M)

### B-1. Implement `deployWorkflow()` and `updateWorkflow()`

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-workflow-deployment-maps-to-nc-flow-rules`
- **files**: `lib/WorkflowEngine/NCFlowAdapter.php`
- **acceptance_criteria**:
  - GIVEN a single-rule definition WHEN `deployWorkflow()` is called THEN
    `IManager::addOperation()` MUST be called once and a `"ncflow:{id}"` string returned
  - GIVEN a two-rule definition WHEN `deployWorkflow()` is called THEN
    `IManager::addOperation()` MUST be called twice and a `"ncflow:{id1},{id2}"` string
    returned
  - GIVEN a definition with `"schedule"` key WHEN `deployWorkflow()` is called THEN
    `UnsupportedFeatureException` MUST be thrown
  - GIVEN a definition with `"type": "dag"` WHEN `deployWorkflow()` is called THEN
    `UnsupportedFeatureException` MUST be thrown
  - GIVEN `updateWorkflow()` is called WHEN it runs THEN it MUST delete existing rules
    and re-deploy from the new definition
- [ ] Implement
- [ ] Test

### B-2. Implement `getWorkflow()`, `deleteWorkflow()`, `activateWorkflow()`, `deactivateWorkflow()`, `listWorkflows()`

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-workflow-deployment-maps-to-nc-flow-rules`
- **files**: `lib/WorkflowEngine/NCFlowAdapter.php`
- **acceptance_criteria**:
  - GIVEN a deployed workflow WHEN `getWorkflow($id)` is called THEN the rule
    representation MUST be returned
  - GIVEN a rule deleted via NC UI WHEN `getWorkflow($id)` is called THEN `null` MUST
    be returned and a WARNING logged
  - GIVEN `deleteWorkflow($id)` WHEN a rule ID is already absent THEN the call MUST
    be a no-op (no exception)
  - GIVEN `listWorkflows()` WHEN called THEN it MUST return only workflows deployed
    through this adapter (not rules authored directly in NC Settings > Flow)
- [ ] Implement
- [ ] Test

---

## Component C — executeWorkflow: event dispatch (M)

### C-1. Implement `executeWorkflow()` via `WorkflowTriggerEvent`

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-workflow-execution-via-custom-event`
- **files**: `lib/WorkflowEngine/NCFlowAdapter.php`, `lib/Event/WorkflowTriggerEvent.php`
- **acceptance_criteria**:
  - GIVEN a workflow ID and payload WHEN `executeWorkflow()` is called THEN
    `IEventDispatcher::dispatch()` MUST be called with a `WorkflowTriggerEvent`
    carrying the workflow ID and payload
  - GIVEN rules match and fire without error WHEN `executeWorkflow()` returns THEN
    `WorkflowResult::status` MUST be `'approved'`
  - GIVEN no rules match WHEN `executeWorkflow()` returns THEN status MUST be
    `'approved'` and original `$data` returned unchanged
  - GIVEN an action handler throws WHEN `executeWorkflow()` catches it THEN status
    MUST be `'error'` and errors array populated; exception MUST NOT propagate
- [ ] Implement
- [ ] Test

---

## Component D — Webhook synthesis endpoint (M)

### D-1. Implement `getWebhookUrl()` using `IURLGenerator`

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-synthesized-webhook-url`
- **files**: `lib/WorkflowEngine/NCFlowAdapter.php`
- **acceptance_criteria**:
  - GIVEN `getWebhookUrl($id)` WHEN called THEN the returned URL MUST use
    `IURLGenerator::linkToRouteAbsolute()` and reference the
    `openregister.WorkflowTrigger.trigger` route
  - GIVEN the NC base URL changes WHEN `getWebhookUrl()` is called THEN the returned
    URL MUST reflect the current base URL (not hardcoded)
- [ ] Implement
- [ ] Test

### D-2. Create `WorkflowTriggerController` and register route

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-synthesized-webhook-url`
- **files**: `lib/Controller/WorkflowTriggerController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `POST /apps/openregister/api/workflow-trigger/{workflowId}` is called by an
    authenticated user THEN `WorkflowTriggerEvent` MUST be dispatched and the response
    MUST be a `WorkflowResult` JSON body with HTTP 200
  - GIVEN an unauthenticated POST THEN the endpoint MUST return HTTP 401 without
    dispatching any event
  - GIVEN the route is registered WHEN `routes.php` loads THEN it MUST appear before
    any wildcard `{catalogSlug}` routes (per CLAUDE.md route ordering rule)
- [ ] Implement
- [ ] Test

---

## Component E — healthCheck and conditional registration (S)

### E-1. Implement `healthCheck()` and `configure()`

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-health-check`
- **files**: `lib/WorkflowEngine/NCFlowAdapter.php`
- **acceptance_criteria**:
  - GIVEN NC Flow is available WHEN `healthCheck()` is called THEN `true` MUST be returned
  - GIVEN NC Flow is unavailable WHEN `healthCheck()` is called THEN `false` MUST be
    returned and no exception thrown
  - GIVEN `configure($baseUrl, [])` WHEN called with an empty `authConfig` THEN no error
    MUST occur and `$baseUrl` MUST be silently ignored
- [ ] Implement
- [ ] Test

### E-2. Add conditional registration to `WorkflowEngineRegistry`

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-conditional-registration`
- **files**: `lib/Service/WorkflowEngineRegistry.php`
- **acceptance_criteria**:
  - GIVEN NC Flow is enabled WHEN the registry initialises THEN
    `resolveAdapter('ncflow')` MUST return an `NCFlowAdapter`
  - GIVEN NC Flow is disabled WHEN the registry initialises THEN the adapter MUST NOT
    be registered and no ERROR-level log entry MUST be written
  - GIVEN `GET /api/engines/available` WHEN NC Flow is enabled THEN the response MUST
    include `{"engineType": "ncflow"}`
- [ ] Implement
- [ ] Test

---

## Component F — Contract tests: WorkflowEngineInterface applied to NCFlowAdapter (M)

### F-1. PHPUnit unit tests for `NCFlowAdapter`

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md` (all requirements)
- **files**: `tests/Unit/WorkflowEngine/NCFlowAdapterTest.php`
- **acceptance_criteria**:
  - GIVEN mocked `IManager` and `IEventDispatcher` WHEN each method is exercised THEN
    the corresponding NC Flow API calls are made as specified
  - GIVEN `deployWorkflow()` with a schedule key THEN `UnsupportedFeatureException`
    MUST be thrown
  - GIVEN `deployWorkflow()` with a dag type THEN `UnsupportedFeatureException` MUST
    be thrown
  - GIVEN `healthCheck()` with available IManager THEN `true` returned
  - GIVEN `healthCheck()` with unavailable IManager THEN `false` returned, no throw
  - GIVEN `executeWorkflow()` with a throwing action THEN status `'error'` returned
  - All tests MUST pass under `composer check:strict` with zero new PHPCS/PHPStan issues
- [ ] Implement
- [ ] Test

### F-2. Integration test: NC Flow adapter contract vs. interface

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-nc-flow-adapter-implements-workflowengineinterface`
- **files**: `tests/Integration/NCFlowAdapterIntegrationTest.php`
- **acceptance_criteria**:
  - GIVEN a running NC test environment with `workflowengine` enabled WHEN the full
    deploy → execute → list → delete lifecycle is exercised THEN each step MUST
    succeed and return the correct types
  - GIVEN the webhook endpoint WHEN an authenticated POST is made THEN a `WorkflowResult`
    JSON response MUST be returned
- [ ] Implement
- [ ] Test

---

## Component G — Seed fixtures (S)

### G-1. Ship three workflow-definition fixtures

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md#requirement-seed-workflow-definitions`
- **files**:
  - `tests/fixtures/workflows/ncflow-notification-rule.json`
  - `tests/fixtures/workflows/ncflow-tagging-rule.json`
  - `tests/fixtures/workflows/ncflow-custom-event-rule.json`
- **acceptance_criteria**:
  - GIVEN each fixture is passed to `NCFlowAdapter::deployWorkflow()` in the unit test
    suite (using a mocked `IManager`) THEN the call MUST succeed without exception
  - GIVEN the notification fixture WHEN deployed THEN the returned ID MUST match
    `"ncflow:{ruleId}"`
  - GIVEN the custom-event fixture WHEN deployed THEN `getWebhookUrl($id)` MUST return
    a non-empty absolute URL
  - All three files MUST be valid JSON
- [ ] Implement
- [ ] Test

---

## Component H — Documentation (S)

### H-1. Document the workflow-definition JSON shape and engine selection guidance

- **spec_ref**: `specs/workflow-engine-ncflow-adapter/spec.md` (all requirements)
- **files**: `docs/workflow-engines/ncflow-adapter.md`
- **acceptance_criteria**:
  - GIVEN the documentation THEN it MUST include:
    - The workflow-definition JSON shape with field definitions
    - A comparison table: when to prefer NC Flow vs. n8n vs. Windmill
    - The `configure()` call example showing empty `authConfig`
    - The webhook synthesis pattern with a `curl` example
    - The `UnsupportedFeatureException` trigger conditions
  - Documentation MUST be in both English and Dutch headings where user-facing
    (developer docs are English-only per i18n spec)
- [ ] Implement
- [ ] Test

---

## Verification Checklist

- [ ] `composer check:strict` passes in openregister (PHPCS, PHPMD, PHPStan zero new issues)
- [ ] All unit tests pass: `composer test` green
- [ ] Conditional registration: resolves adapter when NC Flow enabled, absent when disabled
- [ ] `deployWorkflow()` creates NC Flow rules; composite ID encodes all rule IDs
- [ ] `executeWorkflow()` dispatches `WorkflowTriggerEvent`; returns correct `WorkflowResult`
- [ ] `getWebhookUrl()` returns absolute URL via `IURLGenerator`
- [ ] HTTP POST to webhook URL returns `WorkflowResult` JSON, rejects unauthenticated
- [ ] `healthCheck()` returns `true`/`false` without throwing
- [ ] `UnsupportedFeatureException` thrown for schedule and DAG definitions
- [ ] Three fixture files present and valid; used in unit tests
- [ ] Route ordering verified: `WorkflowTrigger` route precedes any wildcard routes
