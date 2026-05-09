---
title: OpenRegister Push Events
sidebar_position: 1
description: OpenRegister's own notify_push events emitted on object lifecycle changes
keywords:
  - OpenRegister
  - notify_push
  - real-time
  - WebSocket
  - SSE
  - push events
---

# OpenRegister Push Events

OpenRegister emits its own `notify_custom` push events on every object lifecycle event so frontend consumers can subscribe and refresh affected views without polling. This document describes the event shapes, fan-out semantics, and how to subscribe.

For Deck-, Calendar-, or other integration-emitted events, see the per-integration pages (e.g. [Deck](./Deck.md)).

## Event constants

All event-string prefixes live in `OCA\OpenRegister\Push\PushEvents`:

```php
namespace OCA\OpenRegister\Push;

class PushEvents
{
    public const OR_OBJECT     = 'or-object';     // suffixed with -{uuid}
    public const OR_COLLECTION = 'or-collection'; // suffixed with -{register-slug}-{schema-slug}
}
```

The constants are mirrored — by convention — in any frontend client that subscribes (consumers should not hardcode the strings).

## Events emitted

| Event string | Fired on | Payload | Used by |
|---|---|---|---|
| `or-object-{uuid}` | every `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` | `{action, register, schema, uuid, version}` | Detail-view subscribers watching one object |
| `or-collection-{register-slug}-{schema-slug}` | `ObjectCreatedEvent` and `ObjectDeletedEvent` only — **NOT** on field edits | `{action, register, schema, uuid, version}` | List-view subscribers watching a whole collection |

**Why no collection event on update?** A field edit on one object should not cause every list watcher to refetch its entire list — that's the "list-refetch storm" failure mode. Field edits emit only `or-object-{uuid}`. Frontends with a list re-render the changed item from the per-object event without a refetch.

### Payload schema

```json
{
  "action":   "create | update | delete",
  "register": "zaken",
  "schema":   "meldingen",
  "uuid":     "550e8400-e29b-41d4-a716-446655440000",
  "version":  3
}
```

The payload deliberately excludes the full object body. Two reasons:

1. **Permission drift** — between the moment OR builds the payload and the moment the client receives it, ACLs may change. Sending the full object risks leaking data to a user whose access was just revoked. Sending only the UUID forces the client to refetch through the normal RBAC-aware REST path, which always returns the current authoritative permissions.
2. **Payload size** — `notify_push` is a thin notification channel, not a data bus. Large payloads bloat the WebSocket frame budget. UUID-only stays well under the limit regardless of object size.

Clients refetch via the standard REST endpoints when a payload arrives.

## Fan-out: per-user routing

Pushes are emitted **per authorised user**, not broadcast. The listener resolves the list of users with read access to the object via `PermissionHandler::getReadableByUsers(ObjectEntity $object)` and, per user, calls `IQueue::push('notify_custom', ['user' => $user, 'message' => $eventName, 'body' => $payload])` — matching the canonical `notify_custom` wire format used by Deck, Calendar, and other NC apps. The `message` field is the event name clients filter on (e.g. `or-object-{uuid}`); `body` is the payload object.

```
ObjectUpdatedEvent (fires once)
        │
        ▼
NotifyPushListener::handle()
        │
        ▼
PermissionHandler::getReadableByUsers($object)
        │
        ▼
[user-a, user-b, user-c]
        │
        ▼
3 × IQueue::push('notify_custom', [
        'user'    => $userId,
        'message' => 'or-object-' . $uuid,
        'body'    => ['action' => …, 'register' => …, 'schema' => …, 'uuid' => …, 'version' => …],
    ])
```

This means an object change costs `N` pushes where `N` = the number of users who can read it. For typical small-to-medium organisations this is correct and efficient. For installations where a single object has more than ~1,000 readers, consider a broadcast-channel approach (out of scope for v1; see the [realtime-updates spec](../../openspec/specs/realtime-updates/spec.md)).

### Open / public schemas

When `PermissionHandler::getReadableByUsers()` returns `[]` (the schema's permission model is open or anonymous), no per-user fan-out is performed. A future broadcast-channel emission may cover this case; for now, open-schema changes are not pushed.

## Soft-fail when notify_push is absent

`notify_push` is an optional Nextcloud app. When it is not installed (or `IQueue` cannot be resolved from the container), `NotifyPushListener::handle()` returns silently:

- No exception propagates.
- No `WARNING` or `ERROR` log entry.
- At most one `DEBUG` log per request when `IQueue` resolution fails partway (e.g. partial install).
- The object save flow completes normally.

This means OR works identically with or without `notify_push`. Clients that do not see push events fall back to polling (handled by the `useLiveUpdates` plugin in `@conduction/nextcloud-vue`).

The admin settings page surfaces the live status of `notify_push` so administrators can confirm the transport is healthy. See **Settings → Administration → OpenRegister → Push notifications** for the three-state status badge (not installed / installed but unreachable / active).

## Per-request deduplication

If a single HTTP request causes the same `(uuid, action)` pair to fire more than once (e.g. save logic calls `saveObject()` twice for the same object), the listener emits only **one** push per authorised user for that pair. The deduplication is per-request and resets on the next request.

Worst case without dedup: `k` saves × `N` users = `kN` pushes per request. With dedup: `1 push × N users` per request.

## Batch mode for bulk imports

Bulk-import paths in OR (`ImportService`) wrap their save loop in batch mode:

```php
NotifyPushListener::setBatchMode(true);
try {
    foreach ($rows as $row) {
        $this->objectService->saveObject($row);
    }
} finally {
    NotifyPushListener::flushBatch($queue, $permissionHandler);
    NotifyPushListener::setBatchMode(false);
}
```

In batch mode:
- Per-object `or-object-{uuid}` pushes are **suppressed** for the duration of the batch.
- The listener accumulates the set of `(register-slug, schema-slug)` pairs touched during the batch.
- `flushBatch()` emits **one** `or-collection-{register}-{schema}` event per affected pair, per authorised user.

This turns a 1,000-row import from `1000 × (N_object_per_row × N_readers + N_collection_readers)` pushes into typically 1–3 collection pushes total.

## Subscribing from the frontend

The full subscription API ships with the `add-live-updates-plugin` change in `@conduction/nextcloud-vue`. Quick reference:

```js
import { useObjectStore } from '@conduction/nextcloud-vue'

const store = useObjectStore()

// Subscribe to a single object
const unsubscribe = store.subscribe('zaken/meldingen', uuid)
// Triggers store.fetchObject(...) on receipt of `or-object-{uuid}`

// Subscribe to a collection
const unsubscribe = store.subscribe('zaken/meldingen')
// Triggers store.fetchCollection(...) on receipt of `or-collection-zaken-meldingen`

// Cleanup is automatic via `tryOnScopeDispose` when the Vue scope tears down
// (manual cleanup also available)
```

The plugin maintains a single `notify_push` WebSocket connection per browser tab and dispatches incoming events to all interested subscribers. Multiple components subscribing to the same event key share one network listener and one refetch via in-flight deduplication.

When `notify_push` is unavailable, the same `subscribe()` API silently falls back to a coalesced polling timer keyed on `(type, paramsHash)` — one timer per unique query, not per widget.

## Two-transport architecture

OpenRegister ships **two complementary realtime transports**, both hanging off the same three internal events:

```
ObjectCreatedEvent / ObjectUpdatedEvent / ObjectDeletedEvent (single internal source)
    │
    ├── NotifyPushListener           → IQueue::push('notify_custom', …)   → notify_push WebSocket  [REST clients]
    └── GraphQLSubscriptionListener  → SubscriptionService (APCu/Redis)   → SSE /api/graphql/subscribe  [GraphQL clients]
```

REST clients (the default for `@conduction/nextcloud-vue`'s object store) consume `notify_push`. GraphQL clients use the existing SSE transport from the [graphql-api capability](../../openspec/specs/graphql-api/spec.md). Both transports receive the same logical change at the same instant; consumers should pick one transport per page, not both.

A third transport MUST NOT be added without extending the [realtime-updates spec](../../openspec/specs/realtime-updates/spec.md).

## Operational

| Concern | Where it lives |
|---|---|
| Listener implementation | `lib/Listener/NotifyPushListener.php` |
| Constants class | `lib/Push/PushEvents.php` |
| Permission resolution | `lib/Service/PermissionHandler.php` |
| Listener registration | `lib/AppInfo/Application.php` |
| Admin status probe | `lib/Settings/OpenRegisterAdmin.php` |
| Status badge UI | `src/views/settings/sections/PushNotificationsConfiguration.vue` |
| Bulk-import batch wrap | `lib/Service/ImportService.php` |
| Spec (canonical) | `openspec/specs/realtime-updates/spec.md` |
| Spec (change delta) | `openspec/changes/add-live-updates/specs/realtime-updates/spec.md` |

## Configuration

OpenRegister push events require:

1. **`notify_push` Nextcloud app installed** — install via `occ app:install notify_push` and configure with `occ notify_push:setup`.
2. **`OCA\NotifyPush\Queue\IQueue` reachable** — verify via Settings → Administration → OpenRegister → Push notifications. The badge shows `Realtime push active` once the first push has been delivered.
3. **No additional config in OpenRegister** — push delivery is automatic for every object lifecycle event when notify_push is reachable.

## Related documentation

- [Realtime Updates capability spec](../../openspec/specs/realtime-updates/spec.md)
- [Deck Integration](./Deck.md) — Deck's own push events that complement OR events
- [Custom Webhooks](./custom-webhooks.md) — push to external HTTP endpoints (different mechanism)
- [notify_push on GitHub](https://github.com/nextcloud/notify_push)
- [`@nextcloud/notify_push` JS client](https://www.npmjs.com/package/@nextcloud/notify_push)
