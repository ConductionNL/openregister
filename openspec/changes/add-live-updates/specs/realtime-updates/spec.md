---
status: proposed
---

# Realtime Updates — notify_push transport (delta)

**OpenSpec changes**: `add-live-updates` (in-progress)

**Cross-references**: [realtime-updates main spec](../../../../specs/realtime-updates/spec.md), [graphql-api spec](../../../../specs/graphql-api/spec.md), ADR-019 integration registry.

## Purpose of this delta

The `realtime-updates` capability already specifies SSE as the primary transport (status `implemented` via `GraphQLSubscriptionController`) and includes a `SHOULD` requirement that the system integrate with Nextcloud `notify_push` for native push delivery. The notify_push transport itself was deferred in `RealtimeService` v1 (see `lib/Service/RealtimeService.php:20`).

This delta promotes the notify_push integration from `SHOULD` to `MUST`, fully specifies the event-string conventions, fan-out logic, deduplication, batch-mode behaviour, soft-fail contract, and the constants-class pattern that the `add-live-updates` change implements.

REST clients (in particular `@conduction/nextcloud-vue`'s object store) consume notify_push. GraphQL clients continue to use SSE via the existing `GraphQLSubscriptionController`. Both transports hang off the same three OR object lifecycle events — no event duplication at source.

---

## MODIFIED Requirements

### Requirement: The system MUST integrate with Nextcloud notify_push for native push delivery

(Was: `SHOULD integrate ... as a complementary channel`. Promoted to `MUST` because this change ships the implementation.)

The system MUST publish object change events through Nextcloud's notify_push app to deliver instant notifications to authorised users via WebSocket. The system MUST soft-fail (continue without exception, no WARNING/ERROR logs) when the notify_push app is not installed.

#### Scenario: Push notification via notify_push on object creation
- **GIVEN** the notify_push app is installed and `IQueue` is resolvable
- **AND** user `behandelaar-1` is authorised to read schema `meldingen`
- **AND** user `behandelaar-1` is connected to Nextcloud via the desktop client or web UI
- **WHEN** a melding is created
- **THEN** `IQueue::push('notify_custom', [...])` MUST be called with `userId = 'behandelaar-1'`
- **AND** the message body MUST contain `{action, register, schema, uuid, version}` only
- **AND** the message body MUST NOT contain the full object body

#### Scenario: Graceful degradation without notify_push
- **GIVEN** the notify_push app is NOT installed
- **AND** `IQueue` is not registered in the DI container
- **WHEN** an object is created, updated, or deleted
- **THEN** SSE delivery MUST continue to function normally
- **AND** `NotifyPushListener::handle()` MUST return without throwing
- **AND** no WARNING or ERROR log MUST be emitted
- **AND** OR's normal object-save flow MUST complete successfully

#### Scenario: notify_push installed but `IQueue` not resolvable
- **GIVEN** notify_push is installed but `IQueue` resolution throws (partial install / config drift)
- **WHEN** an object is saved
- **THEN** at most one DEBUG log entry MUST be emitted (not WARNING, not ERROR)
- **AND** OR's save flow MUST NOT be interrupted

---

## ADDED Requirements

### Requirement: The system MUST emit per-object push events on every lifecycle event

On every `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectDeletedEvent`, the system MUST emit a `notify_custom` push with event string `or-object-{uuid}` to every user authorised to read the object.

#### Scenario: Object update emits per-object event to authorised users
- **GIVEN** users `behandelaar-1` and `behandelaar-2` both have read access to object `melding-uuid-123` in schema `meldingen` (register `zaken`)
- **WHEN** `melding-uuid-123` is updated
- **THEN** `IQueue::push('notify_custom', ['userId' => 'behandelaar-1', 'data' => $payload])` MUST be called
- **AND** `IQueue::push('notify_custom', ['userId' => 'behandelaar-2', 'data' => $payload])` MUST be called
- **AND** `$payload` JSON MUST contain `action='updated'`, `register='zaken'`, `schema='meldingen'`, `uuid='melding-uuid-123'`, `version`

#### Scenario: Unauthorised users do not receive push events
- **GIVEN** user `external-1` does NOT have read access to object `melding-uuid-123`
- **WHEN** `melding-uuid-123` is updated
- **THEN** `IQueue::push()` MUST NOT be called with `userId = 'external-1'`

#### Scenario: Push payload contains no full object body
- **GIVEN** an object with 50 fields is updated
- **WHEN** the push event is emitted
- **THEN** the `data` JSON MUST contain only: `action`, `register`, `schema`, `uuid`, `version`
- **AND** NO other object fields MUST be included in the payload

---

### Requirement: The system MUST emit per-collection push events on create and delete only

On `ObjectCreatedEvent` and `ObjectDeletedEvent`, the system MUST additionally emit a `notify_custom` push with event string `or-collection-{register-slug}-{schema-slug}` to every user authorised to read the collection. On `ObjectUpdatedEvent` (field edits), the collection event MUST NOT be emitted — frontends with a list re-render the changed item from the per-object event, avoiding list-refetch storms.

#### Scenario: Object creation emits both object and collection events
- **GIVEN** user `behandelaar-1` has read access to schema `meldingen` in register `zaken`
- **WHEN** a new melding is created with UUID `melding-new-1`
- **THEN** `IQueue::push()` MUST be called with event string `or-object-melding-new-1` for `behandelaar-1`
- **AND** `IQueue::push()` MUST be called with event string `or-collection-zaken-meldingen` for `behandelaar-1`

#### Scenario: Object field edit does NOT emit collection event
- **GIVEN** user `behandelaar-1` is authorised to read schema `meldingen`
- **WHEN** an existing melding's `status` field is changed from `nieuw` to `in_behandeling`
- **THEN** `IQueue::push()` MUST be called with `or-object-{uuid}` only
- **AND** `IQueue::push()` MUST NOT be called with `or-collection-zaken-meldingen`

#### Scenario: Object deletion emits both object and collection events
- **GIVEN** melding `melding-5` is deleted
- **WHEN** the delete event fires
- **THEN** `IQueue::push()` MUST be called with `or-object-melding-5` for each authorised user
- **AND** `IQueue::push()` MUST be called with `or-collection-zaken-meldingen` for each authorised user

#### Scenario: Collection event uses register and schema slugs, not numeric IDs
- **GIVEN** register `zaken` with database id 7 contains schema `meldingen` with database id 42
- **WHEN** a new object in that schema is created
- **THEN** the emitted event string MUST be `or-collection-zaken-meldingen`
- **AND** MUST NOT be `or-collection-7-42`

---

### Requirement: The system MUST deduplicate pushes within a single HTTP request

When the same `(uuid, action)` pair would be pushed multiple times within one PHP request (e.g., save logic calls `saveObject()` more than once), only one push per authorised user MUST be emitted.

#### Scenario: Double-save dedup
- **GIVEN** an HTTP request calls `ObjectService::saveObject()` twice for the same object with the same action
- **WHEN** the request completes
- **THEN** `IQueue::push()` MUST have been called exactly once per authorised user for that `(uuid, action)` pair

---

### Requirement: The system MUST support a batch-mode flag to suppress per-object pushes during bulk import

Callers running bulk import operations MUST be able to suppress per-object push delivery by setting batch mode. At the end of the batch, a single collection event per affected `(register, schema)` pair MUST be flushed.

#### Scenario: Bulk import with batch mode
- **GIVEN** batch mode is enabled via `NotifyPushListener::setBatchMode(true)`
- **WHEN** 500 objects in schema `meldingen` are saved in a loop
- **THEN** `IQueue::push()` MUST NOT be called during the loop
- **WHEN** `NotifyPushListener::flushBatch()` is called
- **THEN** `IQueue::push()` MUST be called with `or-collection-zaken-meldingen` for each authorised user exactly once
- **AND** per-object `or-object-{uuid}` events MUST NOT be emitted for any of the 500 objects

#### Scenario: Import without batch mode causes write amplification (anti-pattern)
- **GIVEN** batch mode is NOT enabled
- **WHEN** 500 objects in schema `meldingen` are saved in a loop
- **THEN** `IQueue::push()` MUST be called up to `500 × N_readers` times
- **AND** this MUST be documented as the rationale for batch mode

---

### Requirement: The system MUST resolve authorised users via `PermissionHandler`

Per-user fan-out MUST use `OCA\OpenRegister\Service\PermissionHandler` to determine which users can read each object. If `PermissionHandler` does not yet expose a "list readers of this object" method, this change MUST add one (`getReadableByUsers(ObjectEntity $object): array<string>`).

#### Scenario: Reader resolution uses PermissionHandler
- **GIVEN** an object update fires
- **WHEN** the listener determines fan-out targets
- **THEN** the user list MUST be obtained from `PermissionHandler::getReadableByUsers($object)` (or equivalent authoritative method)
- **AND** the listener MUST NOT iterate over all Nextcloud users and per-user check (slow at scale)

---

### Requirement: Push event strings MUST be defined in a constants class

All `notify_custom` event strings used by OR MUST be composed using constants from `OCA\OpenRegister\Push\PushEvents`, not inline string literals. This mirrors the Deck app pattern (`OCA\Deck\NotifyPushEvents`) and prevents drift between PHP emitters and JS consumers.

#### Scenario: Constants define canonical event prefixes
- **GIVEN** `lib/Push/PushEvents.php` exists
- **THEN** `PushEvents::OR_OBJECT` MUST equal `'or-object'`
- **AND** `PushEvents::OR_COLLECTION` MUST equal `'or-collection'`
- **AND** the per-object event string MUST be: `PushEvents::OR_OBJECT . '-' . $uuid`
- **AND** the collection event string MUST be: `PushEvents::OR_COLLECTION . '-' . $registerSlug . '-' . $schemaSlug`

---

## Implementation notes (non-normative)

- **PHP class**: `OCA\OpenRegister\Listener\NotifyPushListener` implementing `IEventListener`
- **Constants**: `OCA\OpenRegister\Push\PushEvents`
- **Permission resolution**: `OCA\OpenRegister\Service\PermissionHandler`
- **Registration**: in `lib/AppInfo/Application.php` alongside `GraphQLSubscriptionListener`
- **Soft-fail pattern**: lazy `IQueue` resolution from container in try/catch on every event
- **Fan-out model**: one `IQueue::push()` per authorised user per event string. Small-org assumption documented in design.md; >1000 readers per object should revisit with broadcast channel.

## Two-transport architecture (non-normative)

```
ObjectCreatedEvent / ObjectUpdatedEvent / ObjectDeletedEvent (single internal source)
    ├── GraphQLSubscriptionListener  → SubscriptionService (APCu / Redis) → SSE (/api/graphql/subscribe)   [GraphQL clients]
    └── NotifyPushListener           → IQueue::push('notify_custom', …)   → notify_push WebSocket (/push) [REST clients]
```

A third transport MUST NOT be added without extending this spec.
