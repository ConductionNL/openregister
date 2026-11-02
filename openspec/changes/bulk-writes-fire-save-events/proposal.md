---
kind: code
depends_on: []
---

# Proposal: bulk-writes-fire-save-events

## Summary

A single save runs the pre-save events. A guard on `ObjectCreatingEvent` or
`ObjectUpdatingEvent` can stop the write, and the caller gets a 422. A bulk
write runs none of them. The bulk route, the CSV and Excel import, the import
preview and the flow write node all go through `SaveObjects::saveObjects()`,
and that path writes every row without asking a guard. So a status that a
lifecycle or an app guard refuses on a single save lands without a check
when it arrives in a batch.

This change makes the events on bulk writes a setting. When it is on for a
schema, every row of a bulk write to that schema dispatches the same pre-save
event as a single save, and the post-save event after the write. A stopped
event refuses that row only. The refusal is reported in the result, and the
other rows are written.

## Why

Ruben decided on 3 November 2026: "firing the events on bulk updates should
be configuration". The gap was found in the review of dossiq#5085. Its
deliberation guard holds on every single save and on none of the bulk paths.
OpenRegister's own `LifecycleValidationListener` has the same gap, and says
so in its docblock: an import that bypasses `saveObject()` "can persist an
invalid state value silently".

Firing every event on every bulk write is not the answer either. Dispatch per
row costs time, and an import of 20,000 rows into a schema without guards
gains nothing from it. So the choice is per schema, with a register and an
instance default above it.

## What changes

- A `fireEventsOnBulkWrites` boolean in a schema's `configuration`, in a
  register's `configuration`, and as the app config value
  `fire_events_on_bulk_writes` for the whole instance.
- The first explicit value wins, in the order schema, register, instance.
- With no explicit value anywhere, a schema that declares
  `x-openregister-lifecycle` fires the events, and any other schema does not.
- When the setting is on, each row of `SaveObjects::saveObjects()` dispatches
  `ObjectCreatingEvent` or `ObjectUpdatingEvent` before the write, after the
  permission and validation checks. A stopped event refuses that row into
  `invalid`, with the hook's message and the row's position. Data that a hook
  sets is written, as on a single save.
- After the write, the same rows dispatch the created or updated event,
  exactly as the caller's `_events: true` already does today.

## What stays the same

- A schema without the setting and without a lifecycle is written exactly as
  today: no pre-save event, and post-save events only when the caller asks.
- `appendObjectsRaw()` stays raw. Its caller declares that it takes the
  authorisation responsibility, and it skips audit, relations and validation
  by contract.
- A single create that loses a race in `MagicMapper` and is applied as an
  update still skips `ObjectUpdatingEvent`. That is a concurrency path of the
  single save, not a bulk write, and it gets its own change.
- A `SystemOperationContext` still withholds the post-save events, as it does
  on the single path. The pre-save events run there too, as on the single path.

## Ledger rows

None. This closes a gap found in review, not a ledger row.
