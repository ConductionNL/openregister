# Tasks: object-presence

## 1. Server

- [x] 1.1 Migration: `openregister_presence` (user, object uuid, last seen) with a unique index on (user, object) and an index on last seen.
- [x] 1.2 `PresenceService`: heartbeat, depart, list, expire; pruning in the existing sweep.
- [x] 1.3 Routes `PUT`/`DELETE`/`GET .../presence` with the object's read RBAC.
- [x] 1.4 `presence` push on arrival, departure and expiry through `NotifyPushListener`, deduplicated on renewal.

## 2. Client

- [ ] 2.1 `presence(objectUuid)` in the live-updates plugin: heartbeat every 30 s, departure on unmount, reactive list from pushes.
- [ ] 2.2 A presence avatar component in the shared runtime.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/object-presence.spec.ts`: two browser contexts on one object see each other; one closes and disappears.
- [x] 3.2 Unit tests for the service with a clock and for the push dedup.

## What was built

Server only. `Version1Date20260918154500` creates `openregister_presence`,
`ObjectPresence` + `ObjectPresenceMapper` hold the rows, `PresenceService` owns
the window, `NotifyPushListener::pushPresence()` carries the event on the
object's own channel to the object's own readers, `PresenceExpiryJob` sweeps,
and `ObjectsController` answers `PUT`/`DELETE`/`GET .../presence`.

🔴 **AN EXPIRED ROW IS AN ARRIVAL, NOT A RENEWAL.** A reader whose laptop slept
had gone from everybody else's list and is now back on it. Read as a renewal
they would be permanently invisible to every client that was pushed their
departure, which looks exactly like working. `heartbeat()` reports which it was,
so the endpoint cannot get the push rule wrong, and there is a test with the
mutation to prove it.

🔴 **THE EXPIRY READS BEFORE IT DELETES.** A departure has to be pushed and a
deleted row cannot say who to push about, which is the whole reason the sweep
is not a one-line DELETE.

🔑 **THE WINDOW IS ONE NUMBER.** `WINDOW_SECONDS` is read by the list, the
sweep, the arrival test and the assertions; writing 90 down twice is how a
tuned window leaves a test asserting the old one while still passing.

🔑 **RBAC IS A REAL READ, NOT A SECOND QUESTION.** The three endpoints resolve
the object through `ObjectService` under the caller's own permissions, so the
check IS the read presence is served alongside. A caller who cannot read the
object gets 404 and learns nothing, including whether it exists.

## Not built here, and named rather than claimed

- **2.1 / 2.2, the client half.** `presence(objectUuid)` in the live-updates
  plugin and the avatar component are `@conduction/nextcloud-vue`, not this
  repo. The server contract they need is complete and stable: beat, depart,
  list, and a `presence` push on the existing `or-object-<uuid>` channel
  carrying `{action: 'presence', uuid, present}`.
- **3.1, the e2e.** Two browser contexts on one object need a live instance and
  the client component that does not exist yet. This lane writes no e2e it
  cannot run.
- **The push dedup test of 3.2.** The dedup rule lives in `heartbeat()`'s
  `arrived` flag and is tested there; a test of `pushPresence()` itself would
  need the notify_push queue and would assert the caller's branch, not the
  listener's.
