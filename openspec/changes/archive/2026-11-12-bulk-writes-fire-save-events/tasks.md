# Tasks: bulk-writes-fire-save-events

## 1. Setting

- [x] 1.1 `BulkWriteEvents::appliesTo()`: schema, register, instance, then the lifecycle default.
- [x] 1.2 `fireEventsOnBulkWrites` kept by `Schema::validateConfigurationArray()`.

## 2. Bulk save

- [x] 2.1 `BulkWriteEvents::screen()` as the last step of `SaveObjects::screenResolvedRow()`: creating or updating event, refusal per row, hook data written.
- [x] 2.2 Post-save events for the register and schema pairs with the setting on, when the caller did not pass `_events: true`.
- [x] 2.3 The row's register kept on `BulkSafeguardRun`.
- [x] 2.5 Post-save targets keyed on the written table (caller's register and schema first), so a row naming another register keeps its created or updated event.
- [x] 2.4 Rows screened against the caller's register and schema, as the writer stores them; `@self` decides only in mixed-schema mode.

## 3. Tests

- [x] 3.1 `BulkWriteEventsTest`: the order of the setting, the lifecycle default, a stopped update and a stopped create refusing only their row, hook data written, events off writing every row.
- [x] 3.1b Tests for a row naming another register, the route's register, `_events: true` with the setting on, a system operation, and the old object on the updated event; golden cases re-recorded for the register and schema precedence.
- [x] 3.2 Capped mutants on `BulkWriteEvents` and the new `SaveObjects` code, with a no-op control.

## 4. Follow-up

- [x] 4.1 The insert-race fallback in `MagicMapper` runs `ObjectUpdatingEvent` (design D-8): change `insert-race-runs-update-guard`.
