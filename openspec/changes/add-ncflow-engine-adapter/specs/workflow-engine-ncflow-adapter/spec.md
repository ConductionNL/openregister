---
status: draft
---
# Workflow Engine: NC Flow Adapter

## Purpose

This spec defines requirements for `NCFlowAdapter` — a third implementation of
`WorkflowEngineInterface` that targets Nextcloud's built-in `workflowengine` (NC Flow).
It provides a zero-install workflow engine available on every NC instance as a fallback
for simple event-driven rules, without requiring n8n or Windmill.

**Depends on:** `workflow-engine-abstraction` (implemented) — `WorkflowEngineInterface`,
`WorkflowResult`, `WorkflowEngineRegistry`.

**Relates to (parallel change, separate concern):** `integration-flow` — the visibility
tab for NC Flow rules in OR's object/schema UI.

## ADDED Requirements

### Requirement: NC Flow Adapter Implements WorkflowEngineInterface

`NCFlowAdapter` MUST implement every method defined on `WorkflowEngineInterface`:
`deployWorkflow`, `updateWorkflow`, `getWorkflow`, `deleteWorkflow`, `activateWorkflow`,
`deactivateWorkflow`, `executeWorkflow`, `getWebhookUrl`, `listWorkflows`, and
`healthCheck`. No method from the interface MAY be left unimplemented or throw a generic
`\BadMethodCallException`; unsupported semantics MUST throw a documented
`UnsupportedFeatureException` per the "Out-of-Scope Behaviours" requirement below.

#### Scenario: Adapter satisfies the interface contract

- GIVEN `NCFlowAdapter` is instantiated with a valid `IManager` and `IEventDispatcher`
- WHEN each `WorkflowEngineInterface` method is called with valid arguments
- THEN each call MUST return the type declared by the interface (`string`,
  `WorkflowResult`, `bool`, or `array`)
- AND no call MUST throw an unhandled exception

#### Scenario: Adapter is resolvable from the DI container

- GIVEN Nextcloud's DI container is booted on an instance where NC Flow is enabled
- WHEN `WorkflowEngineRegistry::resolveAdapter('ncflow')` is called
- THEN the registry MUST return a fully configured `NCFlowAdapter` instance
- AND the instance MUST be ready to call `deployWorkflow` without further configuration

---

### Requirement: Conditional Registration

The `NCFlowAdapter` SHALL register with `WorkflowEngineRegistry` only when
`\OCP\WorkflowEngine\IManager` is successfully resolvable from Nextcloud's DI container.
When NC Flow is disabled or unavailable, the adapter MUST NOT be registered, and no error
MUST be raised. The engine type `"ncflow"` MUST NOT appear in `GET /api/engines/available`
when the adapter is not registered.

#### Scenario: NC Flow is enabled — adapter is registered

- GIVEN Nextcloud has the `workflowengine` app enabled
- WHEN OR's `WorkflowEngineRegistry` initialises
- THEN `resolveAdapter('ncflow')` MUST return an `NCFlowAdapter` instance
- AND `GET /api/engines/available` MUST include `{"engineType": "ncflow"}` in its response

#### Scenario: NC Flow is disabled — adapter is absent

- GIVEN Nextcloud has the `workflowengine` app disabled (IManager not resolvable)
- WHEN OR's `WorkflowEngineRegistry` initialises
- THEN `resolveAdapter('ncflow')` MUST throw `\InvalidArgumentException`
- AND `GET /api/engines/available` MUST NOT include `"ncflow"` in its response
- AND no error log entry about NC Flow MUST appear at ERROR level (soft failure only)

---

### Requirement: Workflow Deployment Maps to NC Flow Rules

`deployWorkflow(array $definition)` MUST create one or more NC Flow rules via
`IManager::addOperation()` using the JSON definition shape documented in design.md.
The method MUST return a composite engine-specific ID string of the form
`"ncflow:{ruleId1},{ruleId2},..."` encoding all created rule IDs. Each entry in
`$definition['rules']` MUST become exactly one NC Flow operation.

#### Scenario: Single-rule deployment succeeds

- GIVEN a valid single-rule workflow definition with a `trigger`, zero or more
  `conditions`, and an `action`
- WHEN `deployWorkflow($definition)` is called
- THEN `IManager::addOperation()` MUST be called once
- AND the returned ID MUST match the pattern `"ncflow:{ruleId}"`
- AND `getWorkflow($returnedId)` MUST return a representation of the deployed rule

#### Scenario: Multi-rule deployment succeeds

- GIVEN a valid workflow definition containing two `rules` entries
- WHEN `deployWorkflow($definition)` is called
- THEN `IManager::addOperation()` MUST be called twice
- AND the returned ID MUST encode both rule IDs: `"ncflow:{id1},{id2}"`

#### Scenario: Rule deleted via NC Settings UI after deployment

- GIVEN a workflow was deployed and its rule was subsequently deleted through NC's
  Settings > Flow UI
- WHEN `getWorkflow($id)` is called
- THEN the method MUST return `null`
- AND a WARNING-level log entry MUST record that the rule was externally deleted
- AND no exception MUST be thrown

---

### Requirement: Workflow Execution via Custom Event

`executeWorkflow(string $id, array $data, int $timeout = 30)` MUST dispatch an
`OCA\OpenRegister\Event\WorkflowTriggerEvent` via `IEventDispatcher::dispatch()`, causing
NC Flow to synchronously evaluate all rules whose trigger matches the event class. The
method MUST return a `WorkflowResult` reflecting the outcome of rule evaluation.

#### Scenario: Rules match and fire without error

- GIVEN a workflow with ID `"ncflow:42"` is deployed and active
- AND at least one NC Flow rule is registered for `WorkflowTriggerEvent`
- WHEN `executeWorkflow('ncflow:42', ['objectId' => 'abc123'])` is called
- THEN `IEventDispatcher::dispatch()` MUST be called with a `WorkflowTriggerEvent`
  carrying `workflowId='ncflow:42'` and the payload
- AND the returned `WorkflowResult` MUST have `status = 'approved'`
- AND `metadata` MUST contain at minimum the list of rule IDs that were evaluated

#### Scenario: No rules match — approved pass-through

- GIVEN a workflow ID for which no NC Flow rules currently match the event
- WHEN `executeWorkflow($id, $data)` is called
- THEN the method MUST still dispatch the event
- AND the returned `WorkflowResult` MUST have `status = 'approved'`
- AND the original `$data` MUST be returned unchanged in the result

#### Scenario: An action throws an exception during evaluation

- GIVEN a rule's action handler throws an exception during event dispatch
- WHEN `executeWorkflow($id, $data)` is called
- THEN the method MUST catch the exception
- AND return a `WorkflowResult` with `status = 'error'`
- AND the `errors` array MUST contain the exception message
- AND the exception MUST NOT propagate to the caller

---

### Requirement: Synthesized Webhook URL

`getWebhookUrl(string $workflowId)` MUST return an absolute URL pointing to OR's own
`WorkflowTriggerController` endpoint. An HTTP POST to this URL MUST dispatch a
`WorkflowTriggerEvent` and trigger NC Flow rule evaluation, returning the evaluation
outcome as a `WorkflowResult` JSON payload. The URL MUST be generated via
`IURLGenerator::linkToRouteAbsolute()` so it remains valid across NC base-URL changes.

#### Scenario: Webhook URL is returned for a deployed workflow

- GIVEN a workflow has been deployed with ID `"ncflow:42"`
- WHEN `getWebhookUrl('ncflow:42')` is called
- THEN the method MUST return a URL matching the pattern
  `{ncBase}/apps/openregister/api/workflow-trigger/ncflow%3A42`
- AND the URL MUST NOT be hardcoded (MUST use `IURLGenerator`)

#### Scenario: HTTP POST to webhook URL triggers NC Flow evaluation

- GIVEN the workflow-trigger endpoint is registered in `routes.php`
- WHEN an authenticated HTTP POST is made to `getWebhookUrl($id)` with a JSON body
- THEN OR MUST dispatch `WorkflowTriggerEvent` with the request body as payload
- AND return HTTP 200 with a `WorkflowResult` JSON body

#### Scenario: Unauthenticated POST to webhook URL is rejected

- GIVEN the workflow-trigger endpoint requires NC authentication
- WHEN an unauthenticated POST is made to the webhook URL
- THEN OR MUST return HTTP 401
- AND no event MUST be dispatched

---

### Requirement: Health Check

`healthCheck()` MUST return `true` when `\OCP\WorkflowEngine\IManager` is available and
responsive, and `false` in all other cases. The method MUST NEVER throw an exception;
failures MUST be caught internally and logged at DEBUG level.

#### Scenario: NC Flow is available — health check passes

- GIVEN NC Flow is enabled and `IManager` is resolvable
- WHEN `healthCheck()` is called
- THEN the method MUST return `true`
- AND no exception MUST be thrown

#### Scenario: NC Flow is unavailable — health check fails gracefully

- GIVEN the `workflowengine` app was disabled after the adapter was registered
- WHEN `healthCheck()` is called
- THEN the method MUST return `false`
- AND a DEBUG-level log entry SHOULD record the availability failure
- AND no exception MUST propagate

---

### Requirement: No External Credentials Required

`configure(string $baseUrl, array $authConfig)` MUST accept an empty `$authConfig` array
without error. The `$baseUrl` parameter is accepted but not used (NC Flow is in-process).
No credentials MUST be required, stored, or encrypted for the NC Flow adapter. The engine
record's `authType` MUST default to `"none"` for engine configurations of type `"ncflow"`.

#### Scenario: Engine is registered with empty authConfig

- GIVEN an admin POSTs to `POST /api/engines/` with `engineType: "ncflow"` and no
  `authConfig`
- WHEN the request is processed
- THEN the engine MUST be created successfully
- AND `healthCheck()` MUST be called as part of registration
- AND the response MUST include the created engine with `authType: "none"`

#### Scenario: Engine configuration with authConfig is silently accepted

- GIVEN an admin provides an `authConfig` object when registering an ncflow engine
- WHEN the request is processed
- THEN the engine MUST be created without error
- AND the `authConfig` MUST be stored (for forward compatibility) but not used by the
  adapter for authentication

---

### Requirement: Out-of-Scope Behaviours Return Documented Errors

Calls to workflow features that NC Flow does not support MUST throw
`OCA\OpenRegister\WorkflowEngine\Exception\UnsupportedFeatureException` with a message
that names the unsupported feature and recommends an alternative engine. They MUST NOT
silently succeed or return a misleading result.

#### Scenario: Scheduled workflow definition is rejected

- GIVEN a workflow definition contains a `"schedule"` key (cron-style scheduling)
- WHEN `deployWorkflow($definition)` is called against the NC Flow adapter
- THEN the method MUST throw `UnsupportedFeatureException`
- AND the exception message MUST include a recommendation to use n8n or Windmill for
  scheduled workflows

#### Scenario: DAG-type workflow definition is rejected

- GIVEN a workflow definition contains `"type": "dag"` or a multi-step `"steps"` array
  (Windmill/n8n DAG format)
- WHEN `deployWorkflow($definition)` is called against the NC Flow adapter
- THEN the method MUST throw `UnsupportedFeatureException`
- AND the exception message MUST state that multi-step DAG workflows require n8n or
  Windmill

---

### Requirement: Seed Workflow Definitions

OR MUST ship at least three example workflow-definition payloads in the NC Flow adapter's
JSON format as test fixtures under `tests/fixtures/workflows/`. These fixtures MUST be
valid inputs to `deployWorkflow()` and MUST be used in the adapter's PHPUnit tests.
They MUST NOT be loaded into production registers.

#### Scenario: Notification rule fixture is valid

- GIVEN `tests/fixtures/workflows/ncflow-notification-rule.json` exists
- WHEN it is passed to `NCFlowAdapter::deployWorkflow()`
- THEN the deployment MUST succeed (no exception thrown)
- AND the returned ID MUST match the `"ncflow:{ruleId}"` pattern

#### Scenario: Tagging rule fixture is valid

- GIVEN `tests/fixtures/workflows/ncflow-tagging-rule.json` exists
- WHEN it is passed to `NCFlowAdapter::deployWorkflow()`
- THEN the deployment MUST succeed

#### Scenario: Custom-event rule fixture is valid

- GIVEN `tests/fixtures/workflows/ncflow-custom-event-rule.json` exists and uses
  `WorkflowTriggerEvent` as its trigger event class
- WHEN it is passed to `NCFlowAdapter::deployWorkflow()`
- THEN the deployment MUST succeed
- AND `getWebhookUrl($returnedId)` MUST return a non-empty URL
