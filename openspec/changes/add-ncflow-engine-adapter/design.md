# Design: add-ncflow-engine-adapter

## Context: NC Flow vs. n8n / Windmill

| Dimension | NC Flow | n8n / Windmill |
|---|---|---|
| Installation | Ships in NC core — zero extra steps | ExApp, must be installed separately |
| Execution model | In-process, synchronous rule evaluation | Out-of-process, async/sync DAG runner |
| Persistence | Rule store in NC DB (`oc_flow_operations`) | Engine-specific DB / workspace |
| Workflow shape | Rule list: trigger → condition → action | Graph: multi-step DAG |
| Credential requirement | None (in-process) | API key / token required |
| Scheduled workflows | Not supported natively | Supported |
| Complex branching | Limited (conditions only) | Full DAG branching |
| **Best for** | Simple event-driven rules, zero-install fallback | Multi-step automation, integrations, enrichment |

**When to prefer NC Flow over ExApps:** the workflow maps to a single trigger + optional
condition + single action (notification, tag, log, webhook post). No external process
required. The Nextcloud instance does not have n8n or Windmill installed. The rule can
be authored in NC's own Settings > Flow UI.

**When to prefer n8n or Windmill:** the workflow is a multi-step DAG, involves external
API calls as intermediate steps, requires scheduled execution, or needs complex branching
logic that exceeds NC Flow's condition model.

---

## Architecture Overview

```
OpenRegister                               Nextcloud Core
  |                                            |
  WorkflowEngineRegistry                       |
  |-- resolveAdapter('ncflow') ---> NCFlowAdapter
          |                            |-- IManager::getRules()
          |                            |-- IManager::addOperation()
          |                            |-- IRuleMatcher::setOperation()
          |                            |-- IEventDispatcher::dispatch()
          |                            |-- OR webhook endpoint (synthesized)
```

The adapter is registered in `WorkflowEngineRegistry` as engine type `"ncflow"`. The
registry discovers the adapter using the same DI-based pattern used for n8n and Windmill,
but gated on NC Flow's `IManager` being resolvable.

---

## Interface Mapping Table

| `WorkflowEngineInterface` method | NC Flow API mapping | Notes |
|---|---|---|
| `deployWorkflow(array $def)` | `IManager::addOperation()` per rule in the definition | Each rule in `$def['rules']` becomes one NC Flow operation. Returns a composite rule ID string. |
| `updateWorkflow(string $id, array $def)` | Delete existing rules by ID, then re-deploy | NC Flow has no update-in-place on operations; delete+recreate is the canonical pattern. |
| `getWorkflow(string $id)` | Query `oc_flow_operations` by stored rule IDs | Returns the definition shape. Returns `null` + logs warning if rules were deleted via NC UI. |
| `deleteWorkflow(string $id)` | `IManager::deleteOperation()` for each rule ID in the composite | No-op per already-deleted rule (handles NC UI deletion gracefully). |
| `activateWorkflow(string $id)` | Set `active = true` on all rule rows | NC Flow has no concept of inactive rules natively; adapter manages an `active` flag in `authConfig` metadata stored alongside the engine record. |
| `deactivateWorkflow(string $id)` | Set `active = false` on all rule rows | See above. |
| `executeWorkflow(string $id, array $data)` | `IEventDispatcher::dispatch(WorkflowTriggerEvent)` then poll `IRuleMatcher` result | Dispatches a custom event; NC Flow evaluates matching rules synchronously in-process. Returns a `WorkflowResult`. |
| `getWebhookUrl(string $id)` | Returns `{ncBase}/apps/openregister/api/workflow-trigger/{id}` | OR-synthesized endpoint. HTTP POST to this URL dispatches `WorkflowTriggerEvent` and triggers NC Flow rule evaluation. |
| `listWorkflows()` | Query stored deployment metadata from `WorkflowEngine` entity's `authConfig` | Lists all workflows deployed through this adapter; does NOT list rules authored directly in NC Flow UI. |
| `healthCheck()` | Attempt to resolve `IManager` from DI container | Returns `true` when IManager is available, `false` otherwise. Never throws. |
| `configure(string $baseUrl, array $authConfig)` | No-op for `$baseUrl` (NC Flow is in-process); `$authConfig` may be empty | Accepts empty `$authConfig`. Stores any provided metadata in the engine entity. |

---

## Conditional Registration

NC Flow's `IManager` is part of NC core but may be disabled on some instances (e.g. when
the `workflowengine` app is explicitly disabled). The adapter MUST register only when
`IManager` is available.

```php
// In WorkflowEngineRegistry::__construct or ::discoverEngines()
try {
    $manager = $this->container->get(\OCP\WorkflowEngine\IManager::class);
    $this->registerAdapter('ncflow', new NCFlowAdapter($manager, $dispatcher, $urlGenerator));
} catch (\Throwable $e) {
    // NC Flow unavailable — adapter not registered, no error
}
```

The `NCFlowAdapter` class itself declares its constructor dependencies on
`\OCP\WorkflowEngine\IManager`, `\OCP\EventDispatcher\IEventDispatcher`, and
`\OCP\IURLGenerator`. Nextcloud's DI container resolves these automatically when NC Flow
is active.

The `GET /api/engines/available` endpoint MUST include `"ncflow"` in its response only
when the adapter is successfully registered.

---

## Workflow-Definition JSON Shape

The definition accepted by `deployWorkflow($def)` maps to NC Flow's rule model.

### Minimal single-rule definition

```json
{
  "name": "Notify manager on procest case create",
  "rules": [
    {
      "trigger": {
        "eventClass": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
        "entity": "OCA\\OpenRegister\\Entity\\Object"
      },
      "conditions": [
        {
          "class": "OCA\\OpenRegister\\WorkflowEngine\\Check\\SchemaCheck",
          "operator": "is",
          "value": "procest-case"
        }
      ],
      "action": {
        "class": "OCA\\OpenRegister\\WorkflowEngine\\Operation\\NotifyOperation",
        "params": {
          "userGroup": "managers",
          "subject": "Nieuw procest dossier aangemaakt",
          "message": "Een nieuw dossier is aangemaakt door {initiator}."
        }
      }
    }
  ]
}
```

### Multi-rule definition (notification + tagging)

```json
{
  "name": "Tag and notify on high-priority object",
  "rules": [
    {
      "trigger": {
        "eventClass": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
        "entity": "OCA\\OpenRegister\\Entity\\Object"
      },
      "conditions": [
        {
          "class": "OCA\\OpenRegister\\WorkflowEngine\\Check\\ObjectPropertyCheck",
          "operator": "is",
          "value": "priority:high"
        }
      ],
      "action": {
        "class": "OCA\\OpenRegister\\WorkflowEngine\\Operation\\TagOperation",
        "params": {
          "tag": "urgent"
        }
      }
    },
    {
      "trigger": {
        "eventClass": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
        "entity": "OCA\\OpenRegister\\Entity\\Object"
      },
      "conditions": [
        {
          "class": "OCA\\OpenRegister\\WorkflowEngine\\Check\\ObjectPropertyCheck",
          "operator": "is",
          "value": "priority:high"
        }
      ],
      "action": {
        "class": "OCA\\OpenRegister\\WorkflowEngine\\Operation\\NotifyOperation",
        "params": {
          "userGroup": "administrators",
          "subject": "Urgent object ingediend",
          "message": "Prioriteit hoog object aangemaakt: {objectId}"
        }
      }
    }
  ]
}
```

**Composite ID:** When `deployWorkflow()` creates multiple rules, it returns a
composite ID string: `"ncflow:{ruleId1},{ruleId2}"`. All subsequent operations
(`updateWorkflow`, `deleteWorkflow`, `activateWorkflow`, `deactivateWorkflow`) parse
this composite ID and operate on each rule ID.

---

## Webhook Synthesis Design

NC Flow is in-process and does not natively expose webhook URLs. To honour the
`getWebhookUrl()` contract (required by `WorkflowEngineInterface`), the adapter synthesises
a stable URL using OR's own API layer.

**Endpoint:** `POST /apps/openregister/api/workflow-trigger/{workflowId}`

**Responsibilities:**
1. The `WorkflowTriggerController` receives the POST request (authenticated or via API token)
2. It constructs a `WorkflowTriggerEvent` carrying the `$workflowId` and POST body as payload
3. It dispatches the event via `IEventDispatcher::dispatch()`
4. NC Flow evaluates all rules whose trigger matches `WorkflowTriggerEvent` for this `$workflowId`
5. The controller collects evaluation results and returns them as a `WorkflowResult` JSON response

**Event class:** `OCA\OpenRegister\Event\WorkflowTriggerEvent` implements
`\OCP\EventDispatcher\Event`. NC Flow rules register their trigger using this class name;
they activate when the event is dispatched.

```php
class WorkflowTriggerEvent extends Event {
    public function __construct(
        private readonly string $workflowId,
        private readonly array  $payload,
    ) {}

    public function getWorkflowId(): string { return $this->workflowId; }
    public function getPayload(): array     { return $this->payload; }
}
```

**URL generation:**

```php
public function getWebhookUrl(string $workflowId): string {
    return $this->urlGenerator->linkToRouteAbsolute(
        'openregister.WorkflowTrigger.trigger',
        ['workflowId' => $workflowId]
    );
}
```

This makes the webhook URL stable across NC instance URL changes (uses `IURLGenerator`
rather than a hardcoded base URL), and lets external systems POST to OR's API to trigger
NC Flow rules without knowing NC Flow internals.

---

## Execution Flow (`executeWorkflow`)

```
caller: executeWorkflow('ncflow:42,43', ['objectId' => 'abc'])
  |
  NCFlowAdapter::executeWorkflow()
    |-- dispatch WorkflowTriggerEvent(workflowId='ncflow:42,43', payload=[...])
    |-- IEventDispatcher::dispatch() triggers synchronous NC Flow rule evaluation
    |-- NC Flow: evaluate rule 42 → action fires (e.g. notification sent)
    |-- NC Flow: evaluate rule 43 → action fires (e.g. tag applied)
    |-- collect outcomes from event listeners
    |-- return WorkflowResult(status='approved', data=payload, metadata=[rule outcomes])
```

**Return values:**
- No rules match: `WorkflowResult(status='approved')` — no rules blocked the event; data
  passes through unchanged. This mirrors n8n/Windmill behaviour when no workflow is
  triggered.
- All rules fire without error: `WorkflowResult(status='approved', metadata=[outcomes])`
- An action throws: `WorkflowResult(status='error', errors=[...])`
- A blocking action (e.g. a future `RejectOperation`) returns rejected: `WorkflowResult(status='rejected', errors=[...])`

**Note on synchrony:** NC Flow rule evaluation is synchronous in-process. There is no
timeout risk from network I/O. The `$timeout` parameter accepted by the interface is
stored but not enforced for NC Flow (in-process execution completes in < 1ms for typical
rules). The adapter documents this in its PHPDoc.

---

## Failure Modes

| Scenario | Adapter behaviour |
|---|---|
| `IManager` unavailable at registration time | Adapter not registered; `GET /api/engines/available` excludes `ncflow` |
| `IManager` becomes unavailable after registration (workflowengine app disabled) | `healthCheck()` returns `false`; other methods throw `\RuntimeException` with message "NC Flow IManager is not available" |
| Rules deleted via NC Settings UI after `deployWorkflow()` | `getWorkflow()` returns `null` and logs a warning; `deleteWorkflow()` is a no-op for missing rule IDs; `executeWorkflow()` dispatches the event, NC Flow simply matches no rules, returns `WorkflowResult(status='approved')` |
| `executeWorkflow()` called with a composite ID containing mixed valid/deleted rules | Partial match: surviving rules fire, deleted rule IDs are skipped with a log entry |
| `deployWorkflow()` receives unsupported definition fields (e.g. `"schedule"`) | Throws `UnsupportedFeatureException` with hint: "Scheduled workflows are not supported by NC Flow; use n8n or Windmill" |
| `deployWorkflow()` receives a definition with `"type": "dag"` | Throws `UnsupportedFeatureException` with hint: "Multi-step DAG workflows are not supported by NC Flow; use n8n or Windmill" |

---

## File Structure

```
openregister/lib/
  WorkflowEngine/
    NCFlowAdapter.php                        # NEW — WorkflowEngineInterface impl
    Exception/
      UnsupportedFeatureException.php        # NEW — thrown for DAG / scheduled features

  Event/
    WorkflowTriggerEvent.php                 # NEW — custom event for webhook synthesis

  Controller/
    WorkflowTriggerController.php            # NEW — POST /api/workflow-trigger/{id}

openregister/appinfo/routes.php              # MODIFIED — add WorkflowTrigger route

openregister/tests/
  Unit/WorkflowEngine/
    NCFlowAdapterTest.php                    # NEW — unit tests for adapter
  Integration/
    NCFlowAdapterIntegrationTest.php         # NEW — contract tests vs interface

openregister/tests/fixtures/workflows/
  ncflow-notification-rule.json             # NEW — notification rule fixture
  ncflow-tagging-rule.json                  # NEW — file-tagging rule fixture
  ncflow-custom-event-rule.json             # NEW — custom-event trigger fixture
```

---

## Seed Data

Per ADR-001, three example workflow-definition payloads are shipped as fixtures for tests
and developer reference. These are NOT loaded into production; they live in
`tests/fixtures/workflows/`.

### ncflow-notification-rule.json

Notification sent to a user group when a procest case object is created.

```json
{
  "name": "Notificeer managers bij nieuw procest dossier",
  "engineType": "ncflow",
  "rules": [
    {
      "trigger": {
        "eventClass": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
        "entity": "OCA\\OpenRegister\\Entity\\Object"
      },
      "conditions": [
        {
          "class": "OCA\\OpenRegister\\WorkflowEngine\\Check\\SchemaCheck",
          "operator": "is",
          "value": "procest-case"
        }
      ],
      "action": {
        "class": "OCA\\OpenRegister\\WorkflowEngine\\Operation\\NotifyOperation",
        "params": {
          "userGroup": "managers",
          "subject": "Nieuw procest dossier aangemaakt",
          "message": "Dossier {objectId} is aangemaakt door {initiator}."
        }
      }
    }
  ]
}
```

### ncflow-tagging-rule.json

Applies a tag to an object when it is updated with status "archived".

```json
{
  "name": "Tag object als gearchiveerd bij statuswijziging",
  "engineType": "ncflow",
  "rules": [
    {
      "trigger": {
        "eventClass": "OCA\\OpenRegister\\Event\\ObjectUpdatedEvent",
        "entity": "OCA\\OpenRegister\\Entity\\Object"
      },
      "conditions": [
        {
          "class": "OCA\\OpenRegister\\WorkflowEngine\\Check\\ObjectPropertyCheck",
          "operator": "is",
          "value": "status:archived"
        }
      ],
      "action": {
        "class": "OCA\\OpenRegister\\WorkflowEngine\\Operation\\TagOperation",
        "params": {
          "tag": "gearchiveerd"
        }
      }
    }
  ]
}
```

### ncflow-custom-event-rule.json

Rule triggered by an external system via the synthesized webhook URL. Fires a
`WorkflowTriggerEvent` and notifies an admin group.

```json
{
  "name": "Webhook-triggered beheerder notificatie",
  "engineType": "ncflow",
  "rules": [
    {
      "trigger": {
        "eventClass": "OCA\\OpenRegister\\Event\\WorkflowTriggerEvent",
        "entity": "OCA\\OpenRegister\\Entity\\Object"
      },
      "conditions": [],
      "action": {
        "class": "OCA\\OpenRegister\\WorkflowEngine\\Operation\\NotifyOperation",
        "params": {
          "userGroup": "admin",
          "subject": "Externe trigger ontvangen",
          "message": "Workflow {workflowId} getriggerd via webhook met payload: {payload}."
        }
      }
    }
  ]
}
```

---

## Security Considerations

- **No external credentials:** NC Flow is in-process. The adapter's `configure()` accepts
  an empty `authConfig`; no credentials are stored or encrypted. This distinguishes it
  from n8n/Windmill adapters that use `ICrypto` for credential storage.
- **Webhook endpoint authentication:** `POST /api/workflow-trigger/{id}` uses standard NC
  user authentication or a shared API token (configurable). It does NOT accept anonymous
  requests by default. This is enforced at the route level in `routes.php`.
- **Event payload sanitization:** The `WorkflowTriggerEvent` payload is passed through as
  an array. NC Flow rule conditions evaluate fields from this array; no arbitrary code
  execution occurs in the event itself.
- **Admin-only rule deployment:** `deployWorkflow()` calls `IManager::addOperation()`,
  which requires admin-level NC permissions. The adapter does not bypass this check.

---

## ADR References

- **ADR-022:** RBAC/auth model — the webhook endpoint and rule deployment respect NC's
  existing auth model; no new privilege escalation paths are introduced.
- **ADR-019:** External integration patterns — NC Flow is in-process (not external), but
  the adapter follows ADR-019's "fail gracefully on unavailability" principle for the
  conditional registration pattern.
- **ADR-031:** Optional integration registration — the adapter's conditional registration
  mechanism is exactly the pattern described in ADR-031 for optional integrations.

---

## Trade-offs

| Alternative | Why not |
|---|---|
| Register NC Flow as a permanent adapter regardless of IManager availability | Would throw on first call in installations where workflowengine app is disabled; conditional registration is safer and follows ADR-031 |
| Use NC Flow's `IRuleMatcher` directly (pull-based evaluation) | `IManager` is the canonical write API; `IRuleMatcher` is for per-event check evaluation in operation handlers, not for OR-initiated execution |
| Store NC Flow rule IDs in a separate DB table | The existing `WorkflowEngine` entity's `authConfig` field (encrypted JSON) is sufficient for storing the composite rule ID map; avoids a new migration |
| Return `WorkflowResult(status='error')` when no rules match | n8n and Windmill return `approved` when no workflow is triggered; NC Flow should be consistent |
| Throw `NotImplementedException` for `getWebhookUrl()` | Breaks the interface contract for callers that assume the method always returns a URL; webhook synthesis is a better approach |
