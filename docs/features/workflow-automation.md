# Workflow Automation

## Overview

OpenRegister integrates BPMN-style workflow automation with register operations via n8n (primary engine) and a pluggable interface for additional engines (Windmill, others). Register events trigger configurable workflows for process automation, data enrichment, validation, escalation, approval chains, and scheduled tasks — without requiring any code changes to OpenRegister itself.

**Tender demand**: 38% of analyzed government tenders require workflow/process automation capabilities.

## Architecture

The workflow automation system has three layers:

1. **Schema Hooks** — per-schema configuration of workflow callbacks on object lifecycle events
2. **Workflow Engine Abstraction** — engine-agnostic interface (`WorkflowEngineInterface`) with per-engine adapters
3. **Workflow Integration** — n8n as primary engine, auto-discovered when installed as a Nextcloud ExApp

## Schema Hooks

Hooks are defined in a schema's `hooks` JSON property:

```json
{
  "hooks": [
    {
      "id": "validate-bsn",
      "event": "object.creating",
      "engine": "n8n",
      "workflowId": "abc123",
      "mode": "sync",
      "timeout": 10,
      "onFailure": "reject",
      "onTimeout": "reject",
      "onEngineDown": "allow",
      "enabled": true
    },
    {
      "id": "enrich-address",
      "event": "object.created",
      "engine": "n8n",
      "workflowId": "def456",
      "mode": "async",
      "onFailure": "flag"
    }
  ]
}
```

### Hook Fields

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `id` | No | auto | Unique identifier within the schema |
| `event` | Yes | — | Lifecycle event to trigger on |
| `engine` | Yes | — | Engine key (e.g., `n8n`) |
| `workflowId` | Yes | — | Engine-specific workflow identifier |
| `mode` | Yes | — | `sync` (request-response) or `async` (fire-and-forget) |
| `order` | No | 0 | Execution order for multiple hooks on the same event |
| `timeout` | No | 30 | Seconds before timeout is declared |
| `onFailure` | No | `reject` | `reject`, `allow`, `flag`, or `queue` |
| `onTimeout` | No | `reject` | `reject`, `allow`, or `flag` |
| `onEngineDown` | No | `allow` | `reject` or `allow` |
| `filterCondition` | No | null | Condition that must be true for the hook to fire |
| `enabled` | No | true | Enable/disable without removing the hook |

### Lifecycle Events

| Event | Type | Description |
|-------|------|-------------|
| `object.creating` | Pre-mutation (stoppable) | Before object is inserted |
| `object.created` | Post-mutation | After successful insert |
| `object.updating` | Pre-mutation (stoppable) | Before object is updated |
| `object.updated` | Post-mutation | After successful update |
| `object.deleting` | Pre-mutation (stoppable) | Before object is deleted |
| `object.deleted` | Post-mutation | After successful delete |

Pre-mutation hooks in `sync` mode with `onFailure: reject` can stop an operation by returning an error response from the workflow.

### Hook Payload (CloudEvents v1.0)

All hooks receive the object data as a CloudEvents v1.0 payload:

```json
{
  "specversion": "1.0",
  "type": "nl.conduction.openregister.object.creating",
  "source": "https://nextcloud.example.nl/apps/openregister",
  "id": "hook-abc123",
  "time": "2026-03-21T12:00:00Z",
  "data": {
    "object": { ... },
    "schema": "meldingen",
    "register": "meldingen-register"
  }
}
```

Sync hooks can return a modified object payload to replace the incoming data (for enrichment/transformation).

## Workflow Engine Abstraction

The `WorkflowEngineInterface` defines a common contract for all engines:

```php
interface WorkflowEngineInterface {
    public function configure(string $baseUrl, array $authConfig): void;
    public function deployWorkflow(array $workflowDefinition): string;
    public function updateWorkflow(string $workflowId, array $workflowDefinition): string;
    public function getWorkflow(string $workflowId): array;
    public function deleteWorkflow(string $workflowId): void;
    public function activateWorkflow(string $workflowId): void;
    public function deactivateWorkflow(string $workflowId): void;
    public function executeWorkflow(string $workflowId, array $data, int $timeout = 30): WorkflowResult;
    public function getWebhookUrl(string $workflowId): string;
    public function listWorkflows(): array;
    public function healthCheck(): bool;
}
```

### Supported Engines

| Engine | Adapter | Notes |
|--------|---------|-------|
| n8n | `N8nAdapter` | Primary; runs as Nextcloud ExApp; routes through `/index.php/apps/app_api/proxy/n8n/` |
| Windmill | `WindmillAdapter` | Secondary; also runs as ExApp |
| Custom | Implement interface | Any HTTP-callable workflow system |

The `WorkflowEngineRegistry` service manages multiple simultaneous engine configurations. Each schema hook specifies which engine it uses, so a single schema can have hooks targeting different engines.

### n8n Auto-Discovery

When the n8n ExApp is installed and enabled in Nextcloud, it is automatically discovered:

```
GET /api/engines/available
```

Returns `{ "engineType": "n8n", "suggestedBaseUrl": "/index.php/apps/app_api/proxy/n8n/" }`.

Admins can register n8n with a single click using the pre-filled configuration. A health check is performed on registration.

## Workflow-in-Import

During bulk data imports, workflows can be triggered per-record:

- Import configuration specifies a `hookWorkflowId` per schema
- After each chunk is processed, the post-import hook fires for enrichment or validation
- Failed hook responses are included in the import error summary
- Supports async mode for high-throughput imports (hooks fire without blocking chunk processing)

## Workflow Management API

```
GET    /api/engines                               List registered workflow engines
POST   /api/engines                               Register a new workflow engine
GET    /api/engines/available                     Discover available ExApp engines
GET    /api/engines/{id}/health                   Health check for an engine
GET    /api/engines/{id}/workflows                List workflows deployed in an engine
POST   /api/engines/{id}/workflows                Deploy a workflow to an engine
GET    /api/engines/{id}/workflows/{workflowId}   Get a workflow
DELETE /api/engines/{id}/workflows/{workflowId}   Delete a workflow from an engine
```

## Declarative Lifecycle Transitions (`x-openregister-lifecycle`)

Beyond hook-driven automation, a schema can declare a **state machine** on a
single field via the `x-openregister-lifecycle` annotation (schema
`configuration`). The shared `TransitionEngine` interprets it centrally, so apps
get `/available-actions` and `/transition` without writing a controller per
schema.

```
GET  /api/objects/{id}/available-actions   List actions allowed from the current state
POST /api/objects/{id}/transition          Apply a named action ({ "action": "<name>" })
```

### Static mode (`transitions`)

`transitions` is a fixed `action → { from: [states], to: state }` map compared
against the object's current **literal** field value. The field must be a
`string` with an `enum` of the allowed states.

### Graph mode (`graph`)

For status graphs that are **data**, not a fixed enum — e.g. a `case.status`
that is a `$ref` UUID to a `statusType` object whose valid set differs per
parent `caseType` — declare a `graph` block instead. The engine derives the
available and target transitions **at runtime** from FK-scoped sibling objects.

```json
{
  "field": "status",
  "initial": { "from": "caseType", "field": "initialStatus" },
  "graph": {
    "schema": "statustype",
    "parentField": "caseType",
    "parentFrom": "caseType",
    "orderField": "order",
    "finalField": "isFinal",
    "allowedMoves": "forward"
  }
}
```

| `graph` field | Description |
|---------------|-------------|
| `schema` | Sibling schema slug that holds the status objects |
| `parentField` | FK property on the sibling that references the parent |
| `parentFrom` | Property on the transitioning object holding the parent reference |
| `orderField` | Numeric ordering property on the sibling (UUID tiebreak) |
| `finalField` | Boolean terminal-state property on the sibling |
| `allowedMoves` | `forward` (next only), `adjacent` (previous + next), or `any` (every other sibling) |

Derivation: read the parent reference from `object.data[parentFrom]`, fetch the
sibling objects of `schema` where `parentField` equals it (ordered by
`orderField`), locate the object's current state, and offer candidate targets
per `allowedMoves`. Each derived action has a stable id `move-to-<targetUuid>`,
a `to` equal to the target UUID, and a `label` equal to the target's display
name. A **terminal** state (`finalField` true) is a sink under `forward` /
`adjacent`; `any` treats terminality as advisory and still offers the other
siblings. An **orphaned** current value (not among the siblings) recovers to the
first sibling. An object with an empty `parentFrom` yields no actions.

`availableActions()` and the validation inside `transition()` share the SAME
derivation, so a client can only apply a `move-to-<uuid>` the graph currently
allows; a non-candidate is rejected without mutating the object.

**Static precedence:** when a schema declares BOTH a non-empty `transitions` map
and a `graph` block, the static `transitions` map is used and the `graph` block
is ignored (no sibling fetch). Static-only schemas are unaffected.

### Auto-seed on create (object-form `initial`)

When `initial` is the object form `{ "from": "<property>", "field": "<property>" }`,
the create pipeline seeds the lifecycle field from the parent BEFORE schema
validation — so a `required` lifecycle `$ref` field passes on a seeded create.
It runs only on the create path (never on update), only when the lifecycle field
is absent/null/empty (a client-supplied value is never overwritten), and is a
fail-soft no-op when the parent reference is empty, the parent cannot be loaded,
or the parent's `field` value is empty. Seeding dispatches no
`ObjectTransitionedEvent` — it is an initialisation, not a transition. The legacy
literal-string `initial` keeps its static-mode semantics and is not auto-seeded.

### Conditions and refusal messages (`condition`, `message`)

A transition can also carry a `condition`: a declarative precondition on the
object's own data, evaluated by `FlowExpression`, the same JSONLogic engine
flows already use for router and switch edges. A one-line business rule no
longer needs a PHP guard class.

```json
"refuse": {
  "from": ["received", "verifying", "in-progress"],
  "to": "refused",
  "condition": {
    "and": [
      { "!!": { "var": "object.denialGround" } },
      { "!=": [{ "var": "object.denialGround" }, "not-applicable"] }
    ]
  },
  "message": {
    "nl": "Een afwijzing vereist een geldige weigeringsgrond.",
    "en": "A refusal requires a valid denial ground."
  }
}
```

This is the `dataSubjectRequest` schema's `refuse` transition, shipped in
`lib/Settings/openregister_mock_register.json`. Refusing a request requires a
`denialGround` that is set and is not `not-applicable`. Import the mock
register and the example is a real transition you can inspect and copy.

#### The condition document

`condition` is evaluated against a document with exactly four keys:

| Key | Contents |
|---|---|
| `object` | The incoming data (the object as it would be saved) |
| `previous` | The stored data (the object as it currently is) |
| `user` | `uid` and `groups` of the caller |
| `transition` | `action`, `from`, `to` of the matched transition |

This is deliberately not the flow engine's `json` / `binary` / `itemIndex`
shape. A schema author writing a lifecycle rule is looking at an object and a
transition, not at an item moving through a graph. `{"var":
"object.denialGround"}` reads the way the schema reads; naming the object
`json` would be a riddle at the point of authoring. The expression language is
shared with flows, but the document it reads is not.

> **A scalar `condition` is refused, and that is the point.** This annotation
> already carries a second `condition` key one level deeper, on
> `transitions.<action>.actions[]`, written in a different dialect: the string
> `@self.<field> == '<value>'`. Copying that form up to the transition level
> looks right and stores cleanly, but it fails open. `FlowExpression::isValid()`
> treats any scalar as a literal, so the string passes validation and then
> evaluates truthy at runtime, allowing every attempt it was written to block.
> `condition` on a transition must be a JSONLogic rule object. `condition`
> inside `actions[]` stays the `@self.field == 'value'` string. Same key name,
> two different dialects, one nesting level apart.

#### Refusal messages (`message`)

`message` pairs with `condition` to explain a refusal in words. It accepts
either a plain string, or a per-locale map with an optional `defaultLocale`:

```json
"message": {
  "nl": "Een afwijzing vereist een geldige weigeringsgrond.",
  "en": "A refusal requires a valid denial ground.",
  "defaultLocale": "nl"
}
```

Resolution order when a caller hits the refusal: the caller's own language
(`IL10N::getLanguageCode()`), then `defaultLocale`, then `en`, then the first
declared locale. That order only picks between locales the author already
wrote: the author's text is never translated by the engine. Only the engine's
generic fallback message, used when a transition declares no `message` at all,
is translated.

#### Evaluation order

A transition checks its guards in a fixed order: `authorization`, then
`condition`, then `requires`. A caller who fails authorization never reaches
the condition, and a refused condition never resolves the `requires` guard.
Cheap, in-process checks run before anything that reads external state.

> **`user` is empty under `occ`.** The CLI has no session, so `user.uid` is an
> empty string and `user.groups` an empty list on every `occ`-driven call.
> Because a condition is fail-closed, one that reads `user.uid` refuses every
> CLI transition unless it is written to allow for that. Put identity checks in
> `authorization`, which exists for exactly that, and keep `condition` reading
> `object` and `previous`.

#### Failure is closed, not open

An expression that cannot be evaluated for the object at hand refuses the
transition; it never allows one. That is the safe default for a gate, but it
has a sharp edge: a mistyped `var` path, `object.denialGrund` instead of
`object.denialGround`, resolves to `null`, which is a legal JSONLogic
evaluation, not an error. Nothing catches that at schema-save time, because the
expression is well-formed. When a condition does not hold, the listener logs a
debug line naming the schema, the transition action and the field, so a
mistyped path is diagnosable from the log instead of reported as "the button
does nothing."

#### Graph mode refuses `condition`

A `graph`-mode lifecycle (see above) does not support `condition` at all. The
schema-save validator refuses it with `lifecycle-condition-graph-unsupported`
instead of silently ignoring it. Graph-mode moves are derived and enforced
inside `TransitionEngine`, not on the ordinary object-save path, so a condition
declared on a `graph` block would hold on one route and not the other: an
author who wrote it would believe a state is unreachable when it is one direct
write away. Refusing the annotation is safer than a gate that only sometimes
gates. Enforcing `condition` in graph mode is pending save-path enforcement for
graph transitions.

#### Error codes

| Code | Fires when |
|---|---|
| `lifecycle-condition-malformed` | `condition` is not a JSONLogic rule object (a scalar included), is an empty array, or fails `FlowExpression::isValid()` |
| `lifecycle-message-malformed` | `message` is neither a non-empty string nor a valid per-locale map: no locale keys, an empty locale value, or a `defaultLocale` not present in the map |
| `lifecycle-condition-unmet` | A matched transition's `condition` evaluates false, or cannot be evaluated, at save time. The response carries the resolved `message` |

## Standards

| Standard | Role |
|----------|------|
| CloudEvents v1.0 | Hook payload format |
| BPMN 2.0 | Workflow definition format (n8n/Windmill native) |
| `StoppableEventInterface` | Pre-mutation rejection via Nextcloud PSR-14 events |

## Related Features

- [Event-Driven Architecture](event-driven-architecture.md) — hooks consume PSR-14 lifecycle events
- [Registers & Schemas](registers-and-schemas.md) — hooks are configured on schema definitions
- [Data Import & Export](data-import-export.md) — workflow-in-import triggers
- [Webhooks & Notifications](webhooks-and-notifications.md) — complementary outbound delivery system
