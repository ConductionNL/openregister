# Tasks: bulk-writes-fire-save-events

## 1. Setting

- [x] 1.1 `BulkWriteEvents::appliesTo()`: schema, register, instance, then the lifecycle default.
- [x] 1.2 `fireEventsOnBulkWrites` kept by `Schema::validateConfigurationArray()`.

## 2. Bulk save

- [x] 2.1 `BulkWriteEvents::screen()` as the last step of `SaveObjects::screenResolvedRow()`: creating or updating event, refusal per row, hook data written.
- [x] 2.2 Post-save events for the register and schema pairs with the setting on, when the caller did not pass `_events: true`.
- [x] 2.3 The row's register kept on `BulkSafeguardRun`.

## 3. Tests

- [x] 3.1 `BulkWriteEventsTest`: the order of the setting, the lifecycle default, a stopped update and a stopped create refusing only their row, hook data written, events off writing every row.
- [x] 3.2 Capped mutants on `BulkWriteEvents` and the new `SaveObjects` code, with a no-op control.

## 4. Follow-up

- [ ] 4.1 The insert-race fallback in `MagicMapper` runs `ObjectUpdatingEvent` (design D-6), as its own change.
