# Workflow Automation

## Overview

OpenRegister integrates BPMN-style workflow automation with register operations via n8n (primary engine) and a pluggable interface for additional engines (Windmill, others). Register events trigger configurable workflows for process automation, data enrichment, validation, escalation, approval chains, and scheduled tasks. None of it requires a code change to OpenRegister itself.

**Tender demand**: 38% of analyzed government tenders require workflow/process automation capabilities.

## Architecture

The workflow automation system has three layers:

1. **Schema Hooks**: per-schema configuration of workflow callbacks on object lifecycle events
2. **Workflow Engine Abstraction**: engine-agnostic interface (`WorkflowEngineInterface`) with per-engine adapters
3. **Workflow Integration**: n8n as primary engine, auto-discovered when installed as a Nextcloud ExApp

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
| `event` | Yes | n/a | Lifecycle event to trigger on |
| `engine` | Yes | n/a | Engine key (e.g., `n8n`) |
| `workflowId` | Yes | n/a | Engine-specific workflow identifier |
| `mode` | Yes | n/a | `sync` (request-response) or `async` (fire-and-forget) |
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

Some status graphs are **data**, not a fixed enum. A `case.status` may be a
`$ref` UUID to a `statusType` object whose valid set differs per parent
`caseType`. Declare a `graph` block for those instead. The engine derives the
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
validation, so a `required` lifecycle `$ref` field passes on a seeded create.
It runs only on the create path (never on update), only when the lifecycle field
is absent/null/empty (a client-supplied value is never overwritten), and is a
fail-soft no-op when the parent reference is empty, the parent cannot be loaded,
or the parent's `field` value is empty. Seeding dispatches no
`ObjectTransitionedEvent`, because it is an initialisation, not a transition. The legacy
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

Two transitions may share the same `from` and `to`. When you call one by name,
its own guards and actions apply, not those of the other. When you edit the
lifecycle field directly, no transition is named, so the first matching one in
the schema applies.

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

### Automatic transitions (`autoWhen`, `executionMode`)

A transition can also carry an `autoWhen`. It is a JSONLogic rule. When the
rule holds after a write, the transition fires on its own. No one calls
`/transition`. A schema author can make a status follow the object's own
data. No PHP and no listener are needed in the consuming app.

```json
"beslissen": {
  "from": ["in-behandeling"],
  "to": "besloten",
  "autoWhen": { "!!": { "var": "object.motivering" } },
  "executionMode": "sync"
}
```

This transition fires the moment an object in `in-behandeling` is saved with
a non-empty `motivering`. The response to that save already carries
`besloten`.

#### The autoWhen document

`autoWhen` reads the same four-key document as `condition`: `object`,
`previous`, `user`, `transition`. One key means something different here.

| Key | In `condition` | In `autoWhen` |
|---|---|---|
| `object` | The incoming data, the state being entered | The stored object after the write, the state being left |
| `previous` | The stored data before this write | The same: the object before the write that started the pass, or empty on create |
| `user` | The caller's `uid` and `groups` | The same |
| `transition` | `action`, `from`, `to` of the matched transition | The same |

`autoWhen` is decided after the triggering write finishes. It reads the
object as stored, not the incoming payload. Computed fields, defaults, and
file ids are already present. An author writes `object.motivering` the same
way a `condition` author would. Only the moment of the read differs.

#### An automatic move obeys every gate a manual move obeys

An automatic transition runs through the same `TransitionEngine::transition()`
a named call uses. Every gate that can refuse a manual transition can refuse
it. That includes the object `update` permission, `authorization`,
`condition`, `requires`, and any approval-chain gate. Its declared
`actions[]` run exactly as they do for a manual transition. An automatic move
can never do what a manual move could not.

#### `executionMode`: sync or async

| Value | Applies when | What happens |
|---|---|---|
| `sync` (default) | At the outermost write boundary of the triggering save | The triggering response already carries the new state |
| `async` | Decided at the same moment as `sync`, then queued | A background job re-checks the stored object and applies the move only if nothing changed |

`sync` is the default, the opposite of a flow's `async` default. An automatic
transition is one bounded write on a path the caller already waits on. It is
not an arbitrary graph that must stay off the save's critical path.

An `async` move does not re-evaluate `autoWhen` inside the job. It is decided
once, at drain time, with the full document. That decision travels to the job
as a queued entry. The job checks whether the object's version and `updated`
timestamp still match the decision. If they match, it applies the move. If
they do not, a newer write has already decided, and the queued move is
dropped silently.

A write outside a request has no moment when a sync move could land in the
response. A bulk save, a deferred create event, and a revert are all outside
a request. Their automatic transitions are always queued, whatever mode they
declare.

#### The loop cap

An automatic move's own write can make another automatic transition hold.
Everything one write triggers, directly or automatically, is one pass. A
pass is bounded by two limits, both per object per pass:

- **No revisit.** A move into a state the object already occupied in this
  pass is not made. That includes the state the object started in. A to B to
  A stops at the second move.
- **A ceiling of 10.** At most 10 automatic transitions apply to one object
  in one pass. The ceiling is fixed and cannot be configured per schema or
  instance.

Reaching either limit cuts the pass. OpenRegister logs one error-level line.
It names the schema, the object, the limit, and every transition applied so
far. Then it stops. The cut never throws. The triggering write already
succeeded, and the cut is not the caller's fault.

The cap survives an async hop. A queued move carries the pass's hop count
and visited states. A chain cannot escape the ceiling by crossing into a
background job.

#### Ambiguity and shadowing

When two transitions from the same state both hold, OpenRegister fires
neither. It logs a warning naming both. Declaration order is deliberately
not a tie-breaker. JSON key order is not preserved by every database
OpenRegister supports. The same schema could pick different winners on
different installations.

A transition is also refused when it is shadowed. An earlier-declared
transition shares its `from` and `to` pair. The save path matches a
transition by its values, and the first match wins. Firing the shadowed one
would run the earlier transition's gates and actions, not its own.
OpenRegister refuses to fire it and logs a warning naming both.

#### Refusal is logged, not retried

When any gate refuses an automatic move, OpenRegister logs a warning. The
warning names the schema, the object, the transition, and the refusal code.
The triggering write still succeeds. The refusal is never retried and never
reaches the caller. A later write that finds the rule still holding tries
again.

#### Create, update, and system operations

`autoWhen` is checked after both a create and an update. A rule describes
the state an object is in, not how it arrived there. "Created complete, so
it skips straight to review" is a valid rule. `previous` resolves to an
empty object on create.

A write inside a system operation dispatches no object events. Configuration
imports, repair steps, and seeding all count as system operations. No
automatic transition fires on any of them. Seeding is not a user action, and
an automatic move needs someone to act as.

#### Identity

A sync automatic move acts as the identity whose write triggered it. Its
`authorization` list, its `update` permission check, and its audit row all
see that identity. Nothing is ever swapped in. An automatic transition never
runs as a system principal.

An async move carries that identity into the queued job. If the identity no
longer resolves, or resolves to a disabled account, the move is not applied
and a warning is logged.

A session-less write, such as an `occ` command with no session, gets a
session-less automatic move. Its `authorization` list refuses it unless RBAC
admits anonymous updates on that schema. That is the same rule any other
write follows.

#### Graph mode refuses `autoWhen`

A `graph`-mode lifecycle refuses `autoWhen`, for the same reason it refuses
`condition`. Graph-mode moves are derived and enforced inside
`TransitionEngine`, not on the ordinary save path. A rule declared on a
`graph` block would hold on one route and not the other. The schema-save
validator refuses it with `lifecycle-autowhen-graph-unsupported`.

#### Error codes

| Code | Fires when |
|---|---|
| `lifecycle-autowhen-malformed` | `autoWhen` is not a non-empty JSONLogic rule object, or the expression engine cannot validate it. A scalar, including the `@self.<field> == '<value>'` string an `actions[]` entry uses, is refused with this code |
| `lifecycle-execution-mode-malformed` | `executionMode` is present and is not exactly `sync` or `async`. Case variants are refused, not normalised |
| `lifecycle-autowhen-requires-input` | A transition declares `autoWhen` and an `inputs` entry with `required: true`. An automatic move carries no payload, so it would be refused on every attempt |
| `lifecycle-autowhen-graph-unsupported` | A `graph` block declares `autoWhen` |

Each of these refuses the schema save, exactly as `lifecycle-condition-malformed`
does. A stored value that skipped validation is not trusted at runtime
either. A non-rule `autoWhen` counts as not holding, and logs a warning
instead of firing.

## Standards

| Standard | Role |
|----------|------|
| CloudEvents v1.0 | Hook payload format |
| BPMN 2.0 | Workflow definition format (n8n/Windmill native) |
| `StoppableEventInterface` | Pre-mutation rejection via Nextcloud PSR-14 events |

## Related Features

- [Event-Driven Architecture](event-driven-architecture.md): hooks consume PSR-14 lifecycle events
- [Registers & Schemas](registers-and-schemas.md): hooks are configured on schema definitions
- [Data Import & Export](data-import-export.md): workflow-in-import triggers
- [Webhooks & Notifications](webhooks-and-notifications.md): complementary outbound delivery system
