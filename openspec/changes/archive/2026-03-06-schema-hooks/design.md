# Design: schema-hooks

## Architecture Overview

Schema hooks integrate into the existing ObjectEntityMapper save flow, intercepting `*ing` (pre-save) events to run synchronous validation/enrichment workflows and `*ed` (post-save) events to fire async notifications. The HookExecutor service is the central orchestrator.

```
Controller                ObjectEntityMapper              HookExecutor              Engine
   │                            │                              │                      │
   ├─ save(object) ────────────►│                              │                      │
   │                            ├─ dispatch(ObjectCreatingEvent)│                      │
   │                            │       │                      │                      │
   │                            │       ├─ HookListener ──────►│                      │
   │                            │       │                      ├─ load hooks from Schema
   │                            │       │                      ├─ sort by order        │
   │                            │       │                      ├─ POST CloudEvent ────►│
   │                            │       │                      │◄── {status: approved} │
   │                            │       │                      ├─ POST CloudEvent ────►│
   │                            │       │                      │◄── {status: modified} │
   │                            │       │                      ├─ merge modified data  │
   │                            │       │◄─────────────────────┤                      │
   │                            ├─ isPropagationStopped()? ──► false                  │
   │                            ├─ DB INSERT (with merged data)│                      │
   │                            ├─ dispatch(ObjectCreatedEvent)│                      │
   │                            │       │                      │                      │
   │                            │       ├─ HookListener ──────►│                      │
   │                            │       │                      ├─ POST CloudEvent (async, fire-and-forget)
   │                            │       │◄─────────────────────┤                      │
   │◄──────────────────────────┤                              │                      │
```

**Rejection flow:**
```
   │                            ├─ dispatch(ObjectCreatingEvent)│                      │
   │                            │       │                      │                      │
   │                            │       ├─ HookListener ──────►│                      │
   │                            │       │                      ├─ POST CloudEvent ────►│
   │                            │       │                      │◄── {status: rejected} │
   │                            │       │                      ├─ event->stopPropagation()
   │                            │       │                      ├─ event->setErrors(...)│
   │                            │       │◄─────────────────────┤                      │
   │                            ├─ isPropagationStopped()? ──► true                   │
   │                            ├─ SKIP DB INSERT              │                      │
   │◄── return errors ─────────┤                              │                      │
```

## StoppableEventInterface Integration

The three `*ing` event classes gain PSR-14's `StoppableEventInterface`:

```php
use Psr\EventDispatcher\StoppableEventInterface;

class ObjectCreatingEvent extends Event implements StoppableEventInterface
{
    private bool $propagationStopped = false;
    private array $errors = [];
    private array $modifiedData = [];

    public function stopPropagation(): void {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool {
        return $this->propagationStopped;
    }

    public function setErrors(array $errors): void {
        $this->errors = $errors;
    }

    public function getErrors(): array {
        return $this->errors;
    }

    public function setModifiedData(array $data): void {
        $this->modifiedData = array_merge($this->modifiedData, $data);
    }

    public function getModifiedData(): array {
        return $this->modifiedData;
    }
}
```

The same pattern applies to `ObjectUpdatingEvent` and `ObjectDeletingEvent`. The `*ed` events (post-save) do NOT need `StoppableEventInterface` since they are fire-and-forget.

## HookExecutor Service

`HookExecutor` is responsible for:

1. Loading enabled hooks from the Schema entity for the current event type
2. Sorting hooks by `order` (ascending)
3. Evaluating `filterCondition` (JSON Logic) to decide if a hook should fire
4. Building CloudEvents payloads
5. Making sync HTTP calls with timeout handling
6. Processing sync responses (approved/rejected/modified)
7. Applying failure modes (reject/allow/flag/queue)
8. Logging all executions

### CloudEvents Payload Format

```json
{
  "specversion": "1.0",
  "type": "nl.openregister.object.creating",
  "source": "/apps/openregister/registers/1/schemas/5",
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "time": "2026-03-06T10:00:00Z",
  "datacontenttype": "application/json",
  "subject": "object:abc-123-def-456",
  "data": {
    "object": { "name": "Acme Corp", "kvkNumber": "12345678" },
    "schema": "organisation",
    "register": "zaak-register",
    "action": "creating",
    "hookMode": "sync"
  },
  "openregister": {
    "app": "openregister",
    "version": "1.0.0",
    "hookId": "hook-uuid-from-config",
    "expectResponse": true
  }
}
```

### Sync HTTP Call Flow

1. Build CloudEvents JSON payload
2. Resolve engine name to webhook URL via the workflow engine adapter (from Workflow Engine Abstraction)
3. Send HTTP POST with `Content-Type: application/cloudevents+json` and configured timeout
4. **On success (2xx):** Parse response body as JSON, validate `status` field
   - `approved`: continue to next hook
   - `rejected`: apply failure mode
   - `modified`: merge `data` into object, continue to next hook
5. **On timeout:** Apply `onTimeout` failure mode
6. **On connection error:** Apply `onEngineDown` failure mode
7. **On non-2xx response:** Apply `onFailure` failure mode

### Failure Mode Application

| Mode | Action |
|------|--------|
| `reject` | Call `event->stopPropagation()`, set errors on event, skip remaining hooks |
| `allow` | Log warning, continue to next hook or proceed with save |
| `flag` | Set `_validationStatus = "failed"` and `_validationErrors` on object metadata, continue with save |
| `queue` | Set `_validationStatus = "pending"` on object metadata, schedule `HookRetryJob` background job, continue with save |

## Database Changes

### Schema Entity: `hooks` JSON Field

A single JSON column added to the `oc_openregister_schemas` table. No new tables required.

```sql
ALTER TABLE oc_openregister_schemas ADD COLUMN hooks JSON DEFAULT NULL;
```

Example stored value:
```json
[
  {
    "id": "auto-generated-uuid",
    "event": "creating",
    "engine": "n8n",
    "workflowId": "workflow-abc-123",
    "mode": "sync",
    "order": 1,
    "timeout": 30,
    "onFailure": "reject",
    "onTimeout": "reject",
    "onEngineDown": "allow",
    "filterCondition": null,
    "enabled": true
  },
  {
    "id": "auto-generated-uuid-2",
    "event": "created",
    "engine": "n8n",
    "workflowId": "workflow-def-456",
    "mode": "async",
    "order": 0,
    "enabled": true
  }
]
```

### Metadata Fields on ObjectEntity

The `_validationStatus` and `_validationErrors` fields are stored in the object's existing JSON `object` column as top-level metadata keys (prefixed with `_` to distinguish from user data). No schema changes needed for objects.

- `_validationStatus`: `"passed"` | `"failed"` | `"pending"` | `null`
- `_validationErrors`: array of `{ field, message, code }` or `null`

## Event Listener Registration

A new `HookListener` is registered for all `*ing` and `*ed` events:

```php
// lib/AppInfo/Application.php
$context->registerEventListener(ObjectCreatingEvent::class, HookListener::class);
$context->registerEventListener(ObjectUpdatingEvent::class, HookListener::class);
$context->registerEventListener(ObjectDeletingEvent::class, HookListener::class);
$context->registerEventListener(ObjectCreatedEvent::class, HookListener::class);
$context->registerEventListener(ObjectUpdatedEvent::class, HookListener::class);
$context->registerEventListener(ObjectDeletedEvent::class, HookListener::class);
```

The `HookListener` delegates to `HookExecutor`, passing the event and the schema (retrieved from the event's object).

## File Structure

```
openregister/lib/
  Event/
    ObjectCreatingEvent.php    # Modified — add StoppableEventInterface
    ObjectUpdatingEvent.php    # Modified — add StoppableEventInterface
    ObjectDeletingEvent.php    # Modified — add StoppableEventInterface
  Db/
    Schema.php                 # Modified — add hooks JSON field
  Service/
    HookExecutor.php           # New — hook orchestration, CloudEvents, HTTP calls
  Listener/
    HookListener.php           # New — event listener that delegates to HookExecutor
  BackgroundJob/
    HookRetryJob.php           # New — re-runs queued hooks
  Db/
    ObjectEntityMapper.php     # Modified — check isPropagationStopped() after event dispatch
```

## Security Considerations

- Sync hook HTTP calls use the configured engine adapter, which handles authentication (API keys, tokens)
- Hook configurations are only editable by users with schema management permissions
- Timeout enforcement prevents slow/malicious workflows from blocking the system indefinitely
- The `onEngineDown` default of `"allow"` prevents a down engine from blocking all saves
- Hook payloads contain full object data — ensure engines are trusted

## Trade-offs

| Alternative | Why not |
|---|---|
| Separate hooks table | Extra complexity, joins. JSON on Schema keeps hooks co-located with their schema and simplifies CRUD. |
| Global hook configuration | Less flexible. Schema-level hooks let different schemas have different validation workflows. |
| Use Nextcloud's built-in event system only | Cannot do request-response (sync) — Nextcloud events are fire-and-forget. |
| Middleware pattern instead of events | Would bypass the existing event system. Events are already wired up for webhooks; extending them is cleaner. |
| Store validation metadata in a separate table | Over-engineering. The `_` prefix convention keeps metadata in the object JSON without schema pollution. |
