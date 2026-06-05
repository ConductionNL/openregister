---
title: Collaborative Editing Semantics
sidebar_position: 1
description: Subscribe-on-view + lock-on-edit — the canonical pattern that ties OpenRegister's push events together with its pessimistic-lock APIs.
keywords:
  - collaborative editing
  - locks
  - notify_push
  - real-time
  - pessimistic lock
  - subscribe
---

# Collaborative Editing Semantics

OpenRegister gives consumer apps the primitives for collaborative editing without requiring a CRDT or a custom merge engine: a per-object **push channel** and a per-object **pessimistic lock**. Used together, they cover the 95% case — "two users opened the same record, who wins?" — with predictable, easy-to-reason-about UX.

This page is the canonical pattern doc. The lib (`@conduction/nextcloud-vue`) implements it as defaults so consumer apps inherit the right behavior without per-app code.

## The two primitives

### 1. Subscribe-on-view (push events)

When a user opens any detail page, the frontend subscribes to that object's `or-object-{uuid}` push channel. Every time the object changes — including when another user acquires or releases a lock — the subscriber's local cache invalidates and the page re-renders with the new state.

- **Wire format**: see [OpenRegister Push Events](../Integrations/OpenRegister.md).
- **Server side**: events fire from `OCA\OpenRegister\Listener\NotifyPushListener` on every `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectDeletedEvent`.
- **Lock state on the wire**: payloads are UUID-only; the client refetches through the normal REST path, which always returns the authoritative `@self.locked` block.

### 2. Lock-on-edit (pessimistic locks)

When a user enters edit mode, the frontend acquires a server-side lock with a short TTL (default 30 minutes, renewed every 10 while the user is active). Other users opening the same object see the locked state in real time via their subscription, and their Edit affordance is disabled with a "Locked by X" banner.

- **Wire format**: see [Object lifecycle — locking](../Features/objects.md#locking).
- **Endpoints**: `POST /apps/openregister/api/objects/{register}/{schema}/{id}/lock` to acquire; `DELETE` on the same path to release.
- **TTL safety net**: locks expire automatically if the holder's tab closes without a clean release.

## How they complement

The two primitives are independent — you can subscribe without locking (read-only viewer) or lock without subscribing (a bulk-edit script) — but the universal case wants both:

- **Subscribe alone**: you see remote changes, but two users can still simultaneously edit and silently overwrite each other.
- **Lock alone**: you block concurrent writes, but the second user only finds out when they hit Save (a poor UX).
- **Both**: the second user sees the lock the moment the first user clicks Edit, and the page disables its Edit affordance with a banner. No surprise, no overwrite.

This is why the lib enables both by default on every detail surface.

## Lib defaults (`@conduction/nextcloud-vue`)

The library's [`CnDetailPage`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/components/cn-detail-page.md) and [`CnObjectSidebar`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/components/cn-object-sidebar.md) auto-wire `useObjectSubscription` and (in v1) reactively read lock state from the cached `@self.locked` block. When a remote lock is detected, `CnDetailPage` mounts a `CnLockedBanner` automatically.

Two manifest opt-out flags on `pages[].config`:

```json
{
  "id": "MeetingDetail",
  "type": "detail",
  "config": {
    "register": "decidesk",
    "schema": "meeting",
    "subscribe": true,    // default; opt-out for read-only / archive views
    "lock": true          // default; opt-out for surfaces that don't acquire on edit
  }
}
```

In v1 the lock auto-acquire on Edit-mode toggle is intentionally NOT yet wired into the form dialogs — the composables (`useObjectLock`, `LockConflictError`, `PermissionError`) are public so early adopters can wire it themselves. The follow-up cycle integrates it into `CnAdvancedFormDialog` / `CnFormDialog`.

## End-to-end flow

```
User A opens detail page              User B opens detail page
            │                                       │
   useObjectSubscription                  useObjectSubscription
            │                                       │
            └──────────── notify_push WebSocket ────┘
                                  │
User A clicks Edit                │
            │                     │
   POST .../lock {duration:1800}  │
            │                     │
       (200 OK)                   │
            │                     │
       fires ObjectUpdatedEvent ──┤
            │                     │
                                  ▼
                        notify_push delivers
                        or-object-{uuid}
                                  │
                                  ▼
                          User B's plugin
                          refetches the object
                                  │
                                  ▼
                          @self.locked populated
                                  │
                                  ▼
                          locked.value flips true
                                  │
                                  ▼
                       CnLockedBanner mounts
                       Edit toggle disabled
```

## Failure modes

| Scenario | Detection | UX |
|---|---|---|
| `notify_push` unreachable | Plugin falls back to polling | Subscriptions still work, latency increases |
| Lock POST 401/403 | `useObjectLock.acquire()` rejects with `PermissionError` | Toast: "Concurrent edits are not blocked on this schema." Edit allowed without lock. |
| Lock POST 409/423 (conflict) | rejects with `LockConflictError` | Banner: "Locked by X until <expiry>." Edit disabled. |
| Network failure on release | `beforeunload` falls back to `navigator.sendBeacon`; OR's TTL expires the lock automatically | No UX impact. |
| Lock holder inactive | Renew skipped while document hidden | Lock TTL elapses; on next focus, `acquire()` runs again. |

## When NOT to use this pattern

- **Optimistic / CRDT editing.** OpenRegister is not a Yjs-style sync engine; pessimistic locks are deliberate.
- **Bulk import surfaces.** Hundreds of locks per second are wasteful — use the dedicated import endpoints which bypass the per-object event stream (see batch mode in [Push Events](../Integrations/OpenRegister.md#batch-mode-for-bulk-imports)).
- **Read-only audit / log views.** Set `subscribe: false` and `lock: false` on the manifest page.

## Related

- [OpenRegister Push Events](../Integrations/OpenRegister.md) — the wire format used by the subscription channel.
- [Object lifecycle — locking](../Features/objects.md) — the lock REST endpoints and behavior.
- [`useObjectSubscription`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/utilities/composables/use-object-subscription.md) — the lib composable that wires the subscription.
- [`useObjectLock`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/utilities/composables/use-object-lock.md) — the lib composable that wraps the lock endpoints.
- [`CnLockedBanner`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/components/cn-locked-banner.md) — the default "Locked by X" UI.
