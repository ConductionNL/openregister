---
kind: code
---

## Why

OpenRegister's backend already pushes live-update events through
`@nextcloud/notify_push` — `or-object-{uuid}` on object mutations and
`or-collection-{register-slug}-{schema-slug}` on collection changes — and the
shared `@conduction/nextcloud-vue` library ships the matching client layer
(`liveUpdatesPlugin` for `createObjectStore`, a transport singleton with
visibility-gated polling fallback). Consumer apps (opencatalogi, docudesk)
already subscribe, but OpenRegister's OWN UI does not: the object list and the
object detail view only refresh on manual actions. A user looking at a list or
an open object never sees changes made by another user/session until they
refresh — the app that produces the events is the only one not consuming them.

## What Changes

- Install `liveUpdatesPlugin()` on the app's package object store
  (`src/store/modules/object.js`, Pinia id `openregister-objects`), exposing
  `subscribe(type, id?)` / `unsubscribe(handle)` and the
  `liveStatus`/`liveSubscriptions`/`liveLastEventAt` state. The plugin is inert
  until the first `subscribe()` call.
- Object LIST view (`src/views/object/ObjectsList.vue`): subscribe to the
  collection event for the currently scoped register+schema (the store's
  `currentType`), re-scope the subscription when the user switches
  register/schema, release it on unmount. On event the plugin re-runs
  `fetchCollection` with the last-used params — events are refetch hints,
  never data.
- Object DETAIL view (`src/views/object/ObjectDetails.vue`): subscribe to the
  object event for the opened object's uuid, re-scope when another object is
  opened, release on unmount. On event the plugin re-runs
  `fetchObject(type, uuid)`; a local watcher bridges the refreshed
  `objects[type][uuid]` cache entry into `objectStore.objectItem` so the
  Options-API template re-renders.
- No backend changes; no new endpoints. Transport degradation (notify_push →
  polling at 30s collections / 60s objects, visibility-gated) is owned by the
  shared library.

Out of scope (documented limitations):

- Dashboard widgets keep their existing refresh behaviour — they read from a
  separate dashboard store module and wiring them is not trivial.
- The search page's use of the same store is untouched.
- This change writes against the plugin's current opt-in API (explicit
  `liveUpdatesPlugin()` in the plugins array, `@conduction/nextcloud-vue`
  1.0.0-beta.206); it does not depend on the library's upcoming
  default-on behaviour.

## Capabilities

### Modified Capabilities

- `realtime-updates`: OpenRegister's own UI becomes a consumer of the live
  update events it already emits — list and detail views subscribe and treat
  events as refetch hints.

## Impact

- `src/store/modules/object.js` — plugin registration.
- `src/views/object/ObjectsList.vue` — collection subscription lifecycle.
- `src/views/object/ObjectDetails.vue` — object subscription lifecycle + cache
  bridge into `objectItem`.
- No API, schema, or PHP changes.
