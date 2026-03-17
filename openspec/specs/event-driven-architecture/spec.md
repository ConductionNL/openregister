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

### Current Implementation Status
- **Partial:**
  - `CloudEventFormatter` (`lib/Service/Webhook/CloudEventFormatter.php`) formats webhook payloads as CloudEvents v1.0 format
  - `WebhookService` (`lib/Service/WebhookService.php`) with CloudEventFormatter integration for event delivery
  - `WebhookEventListener` (`lib/Listener/WebhookEventListener.php`) dispatches events to webhook service with payload
  - `Webhook` entity (`lib/Db/Webhook.php`) and `WebhookMapper` (`lib/Db/WebhookMapper.php`) for webhook subscription storage
  - `WebhookLog` entity (`lib/Db/WebhookLog.php`) and `WebhookLogMapper` (`lib/Db/WebhookLogMapper.php`) for delivery logging
  - `WebhookDeliveryJob` (`lib/BackgroundJob/WebhookDeliveryJob.php`) for async webhook delivery
  - `HookRetryJob` (`lib/BackgroundJob/HookRetryJob.php`) for retry with CloudEvent formatting
  - `HookExecutor` (`lib/Service/HookExecutor.php`) executes webhook deliveries with CloudEvent payloads
  - Internal event dispatching via Nextcloud's `IEventDispatcher` in multiple mappers (`ViewMapper`, `AgentMapper`)
  - `GraphQLSubscriptionListener` (`lib/Listener/GraphQLSubscriptionListener.php`) for real-time subscriptions
  - `WorkflowEngine` entity (`lib/Db/WorkflowEngine.php`) with `N8nAdapter` (`lib/WorkflowEngine/N8nAdapter.php`) and `WindmillAdapter` (`lib/WorkflowEngine/WindmillAdapter.php`)
  - `WorkflowEngineInterface` (`lib/WorkflowEngine/WorkflowEngineInterface.php`) for workflow engine abstraction
  - Frontend webhook management views at `src/views/webhooks/`
- **NOT implemented:**
  - Formal CloudEvents event type naming convention (`nl.openregister.object.created` etc.) — may differ from current implementation
  - Event subscription filtering by type, schema, or register (beyond basic webhook configuration)
  - Dead-letter queue with admin inspection and manual retry UI
  - Correlation identifiers for cascade events
  - Event history storage and query API (events are delivered but may not be retained for replay)
  - Configurable event retention period
  - Exponential backoff retry strategy (HookRetryJob exists but backoff strategy needs verification)
- **Partial:**
  - CloudEvents format is implemented for webhooks but may not cover all CRUD events
  - Webhook delivery with retry exists but dead-letter queue and correlation IDs are missing
  - Workflow engine integration exists (N8n, Windmill) but event subscription filtering may be limited

### Standards & References
- **CloudEvents v1.0 (CNCF)** — https://cloudevents.io/ — Event format specification
- **CloudEvents HTTP Protocol Binding** — HTTP delivery with `ce-*` headers
- **CloudEvents Subscriptions API** — Standard for managing event subscriptions
- **Nextcloud IEventDispatcher** — Internal PHP event system
- **Webhook (W3C WebSub)** — HTTP callback delivery pattern
- **AMQP / RabbitMQ** — Message queue integration (future transport option)
- **Notificatierouteringscomponent (NRC)** — VNG standard for notification routing in Dutch government

### Specificity Assessment
- The spec is comprehensive and well-structured with clear scenarios for each transport mechanism.
- Significant portion is already implemented (CloudEvents formatting, webhooks, retry, workflow engines).
- Missing: detailed configuration for event type filtering on subscriptions; dead-letter queue entity/table design; correlation ID implementation details; event storage schema for history queries.
- Ambiguous: whether internal PHP events and HTTP webhooks should use the same event type namespace; how workflow engine integration differs from webhook delivery.
- Open questions:
  - Should event history be stored in the database or an external system (e.g., Elasticsearch)?
  - What is the relationship between webhook events and the audit trail — are they the same or separate?
  - Should the system support event replay (re-delivering historical events to a new subscriber)?
  - How should correlation IDs be generated — request-scoped UUID or user-action-scoped?
