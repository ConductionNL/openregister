# Realtime Updates — delta

## MODIFIED Requirements

### Requirement: The system MUST support a batch-mode flag to suppress per-object pushes during bulk import

Callers running bulk import operations MUST suppress per-object push delivery by
setting batch mode. On import completion — including the failure path, since
partial saves may already have happened — the import caller MUST flush the
accumulated collection events: a single `or-collection-{register-slug}-{schema-slug}`
event per affected `(register, schema)` pair, deduplicated across all saved objects.

The batch flush is an untargeted broadcast: the pushed message carries no
per-user targeting and its payload contains only the register slug, the schema
slug, and the action `batch` — never object data. Authorization is enforced at
refetch time: clients receiving the event re-query the RBAC-filtered REST API,
so subscribers without access simply get an empty page.

The flush MUST soft-fail when notify_push is not installed: no events are
accumulated in that case (the listener never reaches its accumulator), so the
flush is a silent no-op without touching the container; a queue-resolution
failure with pending events logs at most one DEBUG entry and MUST NOT interrupt
the import.

#### Scenario: Bulk import with batch mode

- **GIVEN** batch mode is enabled via `NotifyPushListener::setBatchMode(true)`
- **WHEN** 500 objects in schema `meldingen` (register `zaken`) are saved in a loop
- **THEN** `IQueue::push()` MUST NOT be called during the loop
- **WHEN** `NotifyPushListener::flushBatch($queue)` is called
- **THEN** `IQueue::push('notify_custom', ...)` MUST be called exactly once with
  message `or-collection-zaken-meldingen`
- **AND** the push payload MUST NOT contain a `user` key (broadcast)
- **AND** the payload body MUST be exactly `{action: "batch", register: "zaken", schema: "meldingen"}`
- **AND** per-object `or-object-{uuid}` events MUST NOT be emitted for any of the 500 objects

#### Scenario: Import service flushes on completion

- **GIVEN** `ImportService` runs a bulk save (`processSpreadsheetBatch` or `processCsvSheet`)
- **WHEN** the save completes (normally or by throwing)
- **THEN** the `finally` block MUST call the flush BEFORE `setBatchMode(false)`
  (disabling batch mode clears the accumulator)
- **AND** one collection event per affected `(register, schema)` pair MUST be pushed
- **AND** subsequent single-object saves in the same request MUST emit per-object
  events again (batch mode is off)

#### Scenario: Collection hint derived from the save result, not from lifecycle events

- **GIVEN** a default import (`events=false` throughout the import call chain,
  as sent by the UI and `RegistersController`), so `NotifyPushListener::handle()`
  never fires during the bulk save
- **WHEN** the bulk save returns with a non-empty `saved` or `updated` set
- **THEN** the import MUST queue the `(register-slug, schema-slug)` pair
  directly from its own register/schema entities
  (`NotifyPushListener::addBatchedCollection()`), independent of event dispatch
- **AND** when the bulk save throws (partial saves may have landed), the pair
  MUST be queued conservatively
- **AND** when every row is `unchanged` (smart dedup skipped all writes), NO
  collection event MUST be emitted
- **AND** when lifecycle events ARE enabled, listener accumulation and the
  result-derived hint MUST collapse onto the same accumulator key (no double
  emit)

#### Scenario: Flush soft-fails without notify_push

- **GIVEN** notify_push is not installed (`IQueue` not resolvable)
- **WHEN** a bulk import completes
- **THEN** the flush MUST be a silent no-op (nothing was accumulated, so the
  container is not even queried)
- **AND** a queue-resolution failure with pending events MUST log at most one
  DEBUG entry (never WARNING or ERROR)
- **AND** the import result MUST be unaffected

#### Scenario: Import without batch mode causes write amplification (anti-pattern)

- **GIVEN** batch mode is NOT enabled
- **WHEN** 500 objects in schema `meldingen` are saved in a loop
- **THEN** `IQueue::push()` MUST be called up to `500 × N_readers` times
- **AND** this MUST be documented as the rationale for batch mode

## REMOVED Requirements

### Requirement: The system MUST record object lifecycle events as CloudEvents in the realtime log

**Reason**: The DB-backed realtime event log (`RealtimeEventListener` →
`RealtimeService::record()` → `openregister_realtime_events`) wrote a row on
every object lifecycle event but had zero consumers — the cursor-polling
endpoints it fed (`/api/realtime/events`, `/api/realtime/cursor`) were never
called by any frontend or sibling app (verified by grepping the entire
apps-extra workspace). The notify_push transport (`NotifyPushListener`) and the
GraphQL SSE subscription path cover realtime delivery without per-save DB write
amplification. The subsystem is removed; migration `Version1Date20260714120000`
drops the orphaned `openregister_realtime_events` table (and its indexes) on
instances that still carry it.

**Migration**: None required — no consumers existed. Clients wanting a change
feed subscribe to `or-object-{uuid}` / `or-collection-{register}-{schema}`
notify_push events and refetch via the RBAC-filtered REST API.
