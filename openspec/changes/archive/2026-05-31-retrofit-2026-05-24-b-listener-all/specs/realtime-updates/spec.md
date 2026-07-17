---
status: proposed
retrofit_extensions:
  - The system MUST manage NotifyPushListener static state and resolve slugs at system level
  - The system MUST record object lifecycle events as CloudEvents in the realtime log
---

# Realtime Updates — listener plumbing (delta)

**Cross-references**: [realtime-updates main spec](../../../../specs/realtime-updates/spec.md), in-flight change `add-live-updates` (NotifyPushListener fan-out / dedup / soft-fail / batch mode — already specced there), `event-driven-architecture` (CloudEvents payload format).

## Purpose of this delta

The `add-live-updates` change already specifies `NotifyPushListener`'s per-object / per-collection fan-out, deduplication, soft-fail contract, and batch mode (`handle`, `dispatchPushes`, `setBatchMode`, `flushBatch`). This delta retroactively captures two pieces of reactive plumbing not covered there:

1. `NotifyPushListener`'s static-state lifecycle (`resetStaticState()`), the queue-unavailable single-DEBUG latch (`resolveQueue()`), and the system-level UUID→slug resolvers (`resolveRegisterSlug()` / `resolveSchemaSlug()`) that deliberately bypass RBAC + multitenancy.
2. `RealtimeEventListener`, the recorder that translates object lifecycle events into CloudEvent rows in the realtime event log consumed by the SSE controller. This recorder-listener was dropped from `nested-aggregations#NAG-005` and is not specced as a listener elsewhere.

## ADDED Requirements

### Requirement: The system MUST manage NotifyPushListener static state and resolve slugs at system level

`NotifyPushListener` carries request-scoped static state (batch mode, batched-collection accumulator, per-request dedup set, queue-unavailable latch). The system MUST provide a reset entry point for test isolation, MUST soft-fail at most once per request when `IQueue` cannot be resolved, and MUST resolve register/schema slugs at the system level (bypassing RBAC and multitenancy) so push payloads carry correct slug fields even for cross-tenant lifecycle events.

#### Scenario: Static-state reset for test isolation
- **GIVEN** `NotifyPushListener` static state has been mutated (batch mode on, accumulated collections, seen dedup keys, queue-unavailable latched)
- **WHEN** `NotifyPushListener::resetStaticState()` is called
- **THEN** `$batchMode` MUST be reset to `false`
- **AND** `$batchedCollections` MUST be cleared to an empty array
- **AND** `$seen` (dedup set) MUST be cleared
- **AND** `$queueUnavailable` MUST be reset to `false`
- **AND** the method MUST be `@internal` (intended for unit-test isolation only)

#### Scenario: IQueue unavailable logs at most one DEBUG per request
- **GIVEN** the `OCA\NotifyPush\Queue\IQueue` service cannot be resolved from the container (notify_push not installed or config drift)
- **WHEN** `resolveQueue()` is called for the first time in a request
- **THEN** it MUST return null
- **AND** it MUST emit exactly one DEBUG log entry (never WARNING or ERROR)
- **AND** it MUST set the `$queueUnavailable` latch
- **WHEN** `resolveQueue()` is called again within the same request
- **THEN** it MUST return null immediately without emitting a further log entry

#### Scenario: Register slug resolved at system level, bypassing RBAC + multitenancy
- **GIVEN** a lifecycle event for an object whose register is owned by a tenant other than the request user's tenant
- **WHEN** `resolveRegisterSlug($registerUuid)` is called
- **THEN** it MUST call `RegisterMapper::find($registerUuid, _rbac: false, _multitenancy: false)`
- **AND** it MUST return the register's slug
- **AND** a null/empty UUID or a lookup failure MUST yield null (the listener degrades gracefully, leaving the slug field null)

#### Scenario: Schema slug resolved at system level, bypassing RBAC + multitenancy
- **GIVEN** a lifecycle event for an object whose schema is owned by another tenant
- **WHEN** `resolveSchemaSlug($schemaUuid)` is called
- **THEN** it MUST call `SchemaMapper::find($schemaUuid, _rbac: false, _multitenancy: false)`
- **AND** it MUST return the schema's slug
- **AND** a null/empty UUID or a lookup failure MUST yield null

#### Notes
- The RBAC + multitenancy bypass in the two slug resolvers is attributed in-code to issue #1454: without it, cross-tenant lifecycle events leave the push payload's `register` / `schema` slug fields null whenever the request user's tenant does not own the register/schema. This is a deliberate system-level-listener design choice but a multitenancy-boundary concern flagged for follow-up review — it is described here as observed behaviour, not changed in this retrofit.

### Requirement: The system MUST record object lifecycle events as CloudEvents in the realtime log

The system MUST record every object lifecycle event as a CloudEvent in the realtime event log that backs the SSE controller. `RealtimeEventListener` MUST subscribe to `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, and `ObjectTransitionedEvent` and forward each to `RealtimeService::record()` with the correct event type, the affected `ObjectEntity`, and (for transitions) the transition metadata.

#### Scenario: Record a create event
- **GIVEN** an `ObjectCreatedEvent` carrying an `ObjectEntity`
- **WHEN** `RealtimeEventListener::handle()` processes it
- **THEN** it MUST call `RealtimeService::record(RealtimeService::TYPE_OBJECT_CREATED, $object)`

#### Scenario: Record an update event using the new object state
- **GIVEN** an `ObjectUpdatedEvent`
- **WHEN** `handle()` processes it
- **THEN** it MUST read the new state via `getNewObject()`
- **AND** call `RealtimeService::record(RealtimeService::TYPE_OBJECT_UPDATED, $object)`

#### Scenario: Record a delete event
- **GIVEN** an `ObjectDeletedEvent`
- **WHEN** `handle()` processes it
- **THEN** it MUST call `RealtimeService::record(RealtimeService::TYPE_OBJECT_DELETED, $object)`

#### Scenario: Record a transition event with transition metadata
- **GIVEN** an `ObjectTransitionedEvent` carrying `action`, `from`, and `to`
- **WHEN** `handle()` processes it
- **THEN** it MUST call `RealtimeService::record(RealtimeService::TYPE_OBJECT_TRANSITIONED, $object, ['action' => ..., 'from' => ..., 'to' => ...])`

#### Scenario: Non-ObjectEntity payload is ignored
- **GIVEN** a lifecycle event whose payload is not an `ObjectEntity`
- **WHEN** `handle()` processes it
- **THEN** no `record()` call MUST be made (the `instanceof ObjectEntity` guard short-circuits)

#### Notes
- This recorder feeds the same downstream consumer (the SSE controller's event buffer) as the GraphQL subscription path, in the CloudEvents payload format established by the `event-driven-architecture` spec. It was dropped from `nested-aggregations#NAG-005` and is specced here as the standalone realtime recorder listener.
