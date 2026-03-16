# event-driven-architecture Specification

## Purpose
Implement a standardized event bus using CloudEvents format for inter-application communication. All register mutations MUST publish events that can be consumed by other Nextcloud apps, external systems, and workflow engines. The event bus MUST support multiple transport mechanisms and enable loose coupling between components.

**Source**: Gap identified in cross-platform analysis; four platforms implement event-driven architectures.

## ADDED Requirements

### Requirement: All register mutations MUST publish CloudEvents
Every create, update, and delete operation on register objects MUST publish a standardized CloudEvents v1.0 event.

#### Scenario: Publish event on object creation
- GIVEN schema `meldingen` in register `zaken`
- WHEN a new melding object is created
- THEN a CloudEvent MUST be published with:
  - `specversion`: `1.0`
  - `type`: `nl.openregister.object.created`
  - `source`: `/registers/{registerId}/schemas/{schemaId}`
  - `id`: unique event UUID
  - `time`: ISO 8601 timestamp
  - `subject`: object UUID
  - `data`: the full object data
  - `datacontenttype`: `application/json`

#### Scenario: Publish event on object update
- GIVEN melding `melding-1` is updated
- THEN a CloudEvent MUST be published with:
  - `type`: `nl.openregister.object.updated`
  - `data`: containing both the updated object and the changed fields

#### Scenario: Publish event on object deletion
- GIVEN melding `melding-1` is deleted
- THEN a CloudEvent MUST be published with:
  - `type`: `nl.openregister.object.deleted`
  - `data`: containing the deleted object's UUID and a snapshot of its data before deletion

### Requirement: The event bus MUST support multiple transport mechanisms
Events MUST be deliverable via internal PHP events, HTTP webhooks, and message queue integration.

#### Scenario: Internal PHP event dispatch
- GIVEN another Nextcloud app registers a listener for `nl.openregister.object.created`
- WHEN a new object is created
- THEN the listening app MUST receive the event via Nextcloud's event dispatcher
- AND the event MUST be processed synchronously within the same request (or queued for async)

#### Scenario: HTTP webhook delivery
- GIVEN an external system subscribes to events via webhook URL
- WHEN an object is created
- THEN the system MUST POST the CloudEvent to the webhook URL
- AND the request MUST include CloudEvents HTTP headers (ce-type, ce-source, etc.)

#### Scenario: Workflow engine integration
- GIVEN a workflow engine is configured as an event consumer
- WHEN register events are published
- THEN the workflow engine MUST receive events and trigger matching workflow definitions
- AND the integration MUST support both push (webhook) and pull (polling) patterns

### Requirement: Event subscriptions MUST be configurable
Administrators MUST be able to configure which events are published to which consumers.

#### Scenario: Subscribe to specific event types
- GIVEN an external system only needs update events for schema `vergunningen`
- WHEN the admin creates a subscription:
  - Consumer: `https://external.example.nl/events`
  - Filter: `type == "nl.openregister.object.updated" AND schema == "vergunningen"`
- THEN only matching events MUST be delivered to that consumer

#### Scenario: Subscribe to all events for a register
- GIVEN an audit system needs all events for register `zaken`
- WHEN the admin creates a subscription with filter `register == "zaken"`
- THEN all create, update, and delete events for any schema in `zaken` MUST be delivered

### Requirement: Event delivery MUST be reliable
Events MUST be delivered at-least-once with retry on failure and dead-letter handling for undeliverable events.

#### Scenario: Retry failed webhook delivery
- GIVEN a webhook delivery fails with HTTP 503
- THEN the system MUST retry with exponential backoff (30s, 2m, 10m, 1h)
- AND after all retries are exhausted, the event MUST be moved to a dead-letter queue

#### Scenario: Dead-letter queue inspection
- GIVEN 5 events are in the dead-letter queue
- WHEN the admin views the dead-letter queue
- THEN each failed event MUST show: event data, consumer, failure count, last error
- AND the admin MUST be able to retry individual events or purge the queue

### Requirement: Events MUST include correlation identifiers
Events triggered by the same user action MUST share a correlation ID for tracing.

#### Scenario: Cascade events share correlation ID
- GIVEN deleting a person triggers CASCADE deletion of 3 related orders
- WHEN the 4 events are published (1 person delete + 3 order deletes)
- THEN all 4 events MUST share the same `correlationId` extension attribute
- AND the correlation ID MUST enable tracing the full cascade in logs

### Requirement: Event history MUST be queryable
Published events MUST be stored and queryable for replay and debugging purposes.

#### Scenario: Query event history
- GIVEN 1000 events published in the last 24 hours
- WHEN the admin queries events with filter `type == "nl.openregister.object.created" AND time > "2026-03-15T00:00:00Z"`
- THEN matching events MUST be returned in chronological order
- AND event retention MUST be configurable (default: 30 days)
