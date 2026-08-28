# Design: Add Live Updates (notify_push backend)

## Architecture Overview

### Two-Transport Architecture

OpenRegister's real-time delivery is split across two complementary transports, both hanging off the same three internal OR events (`ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`):

```
                    ObjectCreatedEvent
                    ObjectUpdatedEvent
                    ObjectDeletedEvent
                           │
              ┌────────────┴────────────┐
              ▼                         ▼
  NotifyPushListener            GraphQLSubscriptionListener
  (this change)                 (already implemented)
              │                         │
              ▼                         ▼
   IQueue::push()               SubscriptionService::pushEvent()
   (notify_push sidecar)        (APCu / SSE buffer)
              │                         │
              ▼                         ▼
  @nextcloud/notify_push        GraphQLSubscriptionController
  WebSocket bus                 SSE endpoint (text/event-stream)
              │                         │
              ▼                         ▼
  REST clients                  GraphQL clients
  (@conduction/nextcloud-vue    (GraphQL subscriptions)
   useLiveUpdates plugin)
```

**Rule:** both listeners hang off the **same internal OR event**. No event duplication at source. Future transport additions MUST follow this pattern — they add a listener; they do NOT add a new event dispatch point in `ObjectService`.

### Event String Convention

Two event strings per object change:

| Event string | Fires on | Subscribers |
|---|---|---|
| `or-object-{uuid}` | create, update, delete | Detail-view subscribers watching one object |
| `or-collection-{register}-{schema}` | create, delete only | List-view subscribers watching a collection |

Collection events intentionally do NOT fire on field edits. A field edit emits only `or-object-{uuid}`. Frontends with a list of objects re-render the changed item from the per-object event, without refetching the whole list.

### Push Payload

```json
{
  "action": "create|update|delete",
  "register": "zaken",
  "schema":   "meldingen",
  "uuid":     "550e8400-e29b-41d4-a716-446655440000",
  "version":  3
}
```

No full object body is included. Rationale: full bodies risk leaking data to users whose permissions changed between write and push delivery; they also exceed notify_push's payload budget. Clients refetch via REST.

### Per-User Fan-out

Pushes are per-user. The listener:

1. Resolves the list of users authorized to read the object using `PermissionHandler`.
2. For each authorized user, calls `IQueue::push('notify_custom', ['userId' => $user, 'data' => $payload])`.

Fan-out is **N pushes per change where N = number of readers**. This is correct and efficient for small-to-medium organizations. The ceiling is documented in the performance section below.

### Constants Class

`lib/Push/PushEvents.php` — follows the Deck pattern (`OCA\Deck\NotifyPushEvents`):

```php
namespace OCA\OpenRegister\Push;

class PushEvents {
    public const OR_OBJECT     = 'or-object';     // suffixed with -{uuid}
    public const OR_COLLECTION = 'or-collection'; // suffixed with -{register}-{schema}
}
```

The full event string is composed at dispatch time:
```php
PushEvents::OR_OBJECT . '-' . $uuid
PushEvents::OR_COLLECTION . '-' . $register . '-' . $schema
```

### Soft-Fail Pattern

`NotifyPushListener` resolves `IQueue` from the DI container inside a try/catch. When `notify_push` is not installed, the container throws `QueryException`. The catch block logs a one-time `DEBUG`-level message (not warning, not error) and returns without pushing.

```php
try {
    $queue = $this->container->get(IQueue::class);
    // ... push logic
} catch (\Throwable $e) {
    // notify_push not installed or IQueue not resolvable — silent soft-fail
    $this->logger->debug('notify_push unavailable, skipping push: ' . $e->getMessage());
}
```

An AppConfig key `app.push_available` is set to `true` at first successful push and read by the admin settings page to distinguish "installed but unreachable" from "active".

### Batch-Mode Flag

When OR runs a bulk import (e.g., data import via `DataImportService`), per-object pushes MUST be suppressed to avoid write amplification. The import caller:

1. Calls `NotifyPushListener::setBatchMode(true)` before the import loop.
2. Runs the import — the listener accumulates `(register, schema)` pairs that changed.
3. Calls `NotifyPushListener::setBatchMode(false)` after the loop.
4. The listener emits one `or-collection-{register}-{schema}` event per affected collection.

`setBatchMode` is a static method on the listener class (not a service setter) so that it is accessible without DI gymnastics from the import context.

### Deduplication

A per-request static accumulator in `NotifyPushListener` prevents write amplification when save logic touches an object multiple times in one HTTP request (e.g., save + recalculate computed fields + re-save):

```
static Map<(uuid, action), bool> $seen = []
if $seen[(uuid, action)] is set: return
$seen[(uuid, action)] = true
// ... emit push
```

The accumulator is a request-scoped static; it resets between PHP requests (no explicit clear needed).

---

## Capability Designs

### `realtime-updates` capability — notify_push transport (this change extends)

**PHP files:**
- `lib/Push/PushEvents.php` — constants
- `lib/Listener/NotifyPushListener.php` — `IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent>`
- Registration in `lib/AppInfo/Application.php` alongside the existing `GraphQLSubscriptionListener` registrations (lines 744-745)

**Listener skeleton:**

```php
class NotifyPushListener implements IEventListener {
    private static bool $batchMode = false;
    private static array $batchedCollections = [];
    private static array $seen = [];

    public function handle(Event $event): void {
        // resolve IQueue, soft-fail if absent
        // determine action/uuid/register/schema from event
        // check dedup: if ($seen["{uuid}:{action}"] ?? false) return;
        // set $seen["{uuid}:{action}"] = true
        // if batchMode: accumulate collection, return early
        // resolve authorized users via PermissionHandler
        // for each user: $queue->push('notify_custom', [...])
        // if create or delete: push collection event too
    }

    public static function setBatchMode(bool $enabled): void { ... }
    public static function flushBatch(IQueue $queue, PermissionHandler $ph): void { ... }
}
```

### `admin-settings` capability (extension)

Add a "Push notifications" section to the existing `lib/Settings/OpenRegisterAdmin.php` settings page. The section probes:

1. `IAppManager::isInstalled('notify_push')` — is the sidecar app installed?
2. AppConfig key `app.push_available` — has at least one push been successfully delivered?

Three rendered states:

| State | Label | Action offered |
|---|---|---|
| Not installed | "Realtime push not available — notify_push app is not installed" | Link to Nextcloud App Store |
| Installed, unreachable | "notify_push is installed but not yet configured" | Link to `occ notify_push:setup` docs |
| Active | "Realtime push active" | Status indicator (green) |

**No new PHP class is required** — extend the existing `OpenRegisterAdmin::getForm()` template data to include a `pushStatus` key, and update the settings Vue component to render the new section.

### Documentation: `docs/Integrations/Deck.md`

A docs-only addition handled inline (not a capability — just files added under `docs/`):

- `docs/Integrations/Deck.md` documents the Deck integration: `DeckCardService`/`DeckController` overview, linked-entity operations, and Deck's `notify_custom` push events (`deck_board_update`, `deck_card_update`) so `@conduction/nextcloud-vue` consumers can subscribe.
- `docs/Integrations/index.md` gains a "Nextcloud-native integrations" subsection linking to Deck.md. Becomes the template for future integration docs (Calendar, Talk, Contacts).
- Format mirrors the existing `n8n.md` / `ollama.md` etc.

### Descoped: `pushEvents()` on `IntegrationProvider`

Originally part of this change; descoped to a follow-up. The `pluggable-integration-registry` change (introducing the `IntegrationProvider` interface) is still in flight — implementing a method on an interface that does not yet exist creates a hard ordering dependency that delays this PR unnecessarily. Once the registry change merges, a follow-up `extend-integration-registry-push-events` change adds:

- `pushEvents(): array` on `IntegrationProvider` returning event declarations (event string, description, payload shape)
- `AbstractIntegrationProvider::pushEvents()` default returning `[]` (back-compat)
- JS registration accepts `pushEvents: []`
- `pushEvents` field included in the integrations API response

Until that follow-up lands, frontend consumers consume the push-event documentation from `docs/Integrations/<app>.md` directly.

---

## Seed Data

No new schemas are introduced by this change. No new registers are required. **No seed data is required.**

---

## Performance

### In-flight Dedup

A static per-request accumulator in `NotifyPushListener` coalesces multiple pushes for the same `(uuid, action)` pair within one HTTP request. Even if `ObjectService::saveObject()` is called multiple times for the same object during a single request (e.g., save → recalculate computed fields → re-save), only one push is emitted.

Worst case without dedup: `k` saves × `N` users = `kN` `IQueue::push()` calls per request. With dedup: `1 push × N users`.

### Bulk-Import Write Amplification

A `setBatchMode(true)` flag on the listener suppresses per-object pushes during bulk import. A 1,000-row import without batch mode would emit up to `1000 × (N_collection + N_object_per_row × N_readers)` IQueue calls. With batch mode, the same import emits one collection event per affected `(register, schema)` pair — typically 1–3 events total.

Callers MUST set batch mode around any loop that saves more than one object.

### Refetch Storm Avoidance

Collection events (`or-collection-{register}-{schema}`) fire on object **create/delete only** — not on field edits. A field edit emits only `or-object-{uuid}`. This means:

- 10 users simultaneously editing 10 different objects in the same schema generates 10 `or-object-*` events and 0 `or-collection-*` events. Each user's list view ignores the per-object event for objects they are not currently viewing.
- A single new object appearing fires one `or-collection-*` event and one `or-object-*` event. The list-view subscriber refetches its list; the detail-view subscriber (if any) updates the new object's panel.

This eliminates the "every keystroke causes every connected list to refetch" failure mode.

### Per-User Fan-out Cost

Fan-out = N `IQueue::push()` calls per object change, where N = number of users authorized to read the object.

| Organization size | Typical N | `IQueue::push()` calls/change | Assessment |
|---|---|---|---|
| Small (< 50 users) | 1–50 | 1–50 | Negligible |
| Medium (< 500 users) | 10–200 | 10–200 | Acceptable |
| Large (> 1,000 readers per object) | 500–5,000 | 500–5,000 | Consider broadcast channel |

For the current target deployment profile (small-to-medium government organisations), per-user fan-out is correct. Installations where a single object has more than 1,000 authorized readers should evaluate a notify_push broadcast-channel approach, which would emit one push that the server delivers to all connected clients. This is a future change; it requires notify_push to support broadcast, which is not currently in its API.

### Reconnect Thundering Herd

When a notify_push sidecar restarts and clients reconnect simultaneously, all reconnected clients may simultaneously query the OR REST API to refresh their state. This is a **frontend concern** (handled by `useLiveUpdates` with jittered reconnect backoff). OR's backend is stateless and scales horizontally; the thundering herd is bounded by the number of connected clients.

Calling this out here as a frontend dependency: `useLiveUpdates` MUST implement reconnect jitter before it can be considered production-safe.
