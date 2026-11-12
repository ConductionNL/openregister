---
kind: code
depends_on: []
---

# Proposal: insert-race-runs-update-guard

## Summary

A create can find its identifier already taken when it writes. Two writers
created the same uuid at the same time, or a caller created an object that
already exists. `MagicMapper` then carries out the upsert and updates the
stored row. The create has dispatched `ObjectCreatingEvent`, but no
`ObjectUpdatingEvent` runs. A guard that listens for updates (the lifecycle
transition check, an app's guard) never sees a write that changes a stored
object.

This change sends that update through `updateObjectEntity()`. It dispatches
`ObjectUpdatingEvent` with the stored row as the old object. A stopped event
throws `HookStoppedException`, and the stored row stays as it was.

## Why

The review of the bulk-write events change (openregister#5376, design D-8)
named this path. It is the last single-save path that changes a stored
object without the update guard.

## What changes

- `MagicMapper::insertObjectEntity()` always writes with
  `failIfExists: true`. When the identifier is taken and the caller did not
  ask to fail, it updates through `updateObjectEntity()`, with the stored row
  as the old object.
- A caller that asked to fail (`failIfExists: true`) still gets
  `ObjectExistsException`, and no update event runs.
- A taken identifier is no longer logged as a failed write.

## What stays the same

- An insert that wins writes once, as before, with only `ObjectCreatingEvent`.
- The post-save event of `insert()` stays `ObjectCreatedEvent`, as it is today
  for this path.
