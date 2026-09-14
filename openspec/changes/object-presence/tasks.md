# Tasks: object-presence

## 1. Server

- [ ] 1.1 Migration: `openregister_presence` (user, object uuid, last seen) with a unique index on (user, object) and an index on last seen.
- [ ] 1.2 `PresenceService`: heartbeat, depart, list, expire; pruning in the existing sweep.
- [ ] 1.3 Routes `PUT`/`DELETE`/`GET .../presence` with the object's read RBAC.
- [ ] 1.4 `presence` push on arrival, departure and expiry through `NotifyPushListener`, deduplicated on renewal.

## 2. Client

- [ ] 2.1 `presence(objectUuid)` in the live-updates plugin: heartbeat every 30 s, departure on unmount, reactive list from pushes.
- [ ] 2.2 A presence avatar component in the shared runtime.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/object-presence.spec.ts`: two browser contexts on one object see each other; one closes and disappears.
- [ ] 3.2 Unit tests for the service with a clock and for the push dedup.
