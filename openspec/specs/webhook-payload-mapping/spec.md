---
status: implemented
---
# Webhook Payload Mapping


## Purpose
Extend OpenRegister's existing CloudEvent-based event and webhook infrastructure with configurable payload mapping. The core webhook delivery (WebhookService, WebhookDeliveryJob, CloudEventFormatter) is already implemented. This spec focuses on the Mapping entity integration for payload transformation, advanced filtering, and delivery management. It documents the complete webhook lifecycle as already implemented: registration with URL/events/secret, payload format selection (standard, CloudEvents, Twig-mapped), delivery retry with exponential backoff, delivery logging, HMAC authentication, event filtering by register/schema/conditions, webhook management API, testing/dry-run, async delivery via background jobs, health monitoring through statistics, multi-tenant webhook isolation via organisation scoping, and request interception for pre-event webhooks. The Mapping entity reference allows any subscriber to receive events in whatever format they require (ZGW notifications, FHIR events, CloudEvents, VNG Notificaties API, custom formats) without any hardcoded format knowledge in OpenRegister.

## Relationship to Existing Implementation
This spec documents an already-implemented system and validates its behavior:

- **Webhook entity and delivery (fully implemented)**: `Webhook` entity with 23 fields including `mapping` reference, `WebhookMapper` with multi-tenancy and RBAC, `WebhookService` with `dispatchEvent()`, `deliverWebhook()`, `buildPayload()` (3-strategy priority), `sendRequest()` with HMAC signing.
- **CloudEvents formatting (fully implemented)**: `CloudEventFormatter` produces CloudEvents 1.0 compliant payloads as the second-priority format strategy.
- **Payload mapping via Twig (fully implemented)**: `WebhookService::applyMappingTransformation()` loads a Mapping entity and transforms payloads via `MappingService::executeMapping()`. This is the highest-priority format strategy.
- **Event listener (fully implemented)**: `WebhookEventListener` handles 36+ event types across 11 entity categories, extracting structured payloads.
- **Retry and async delivery (fully implemented)**: `WebhookDeliveryJob` (QueuedJob) and `WebhookRetryJob` (TimedJob, 5-minute interval) with exponential/linear/fixed backoff policies.
- **Delivery logging (fully implemented)**: `WebhookLog`/`WebhookLogMapper` with `findFailedForRetry()` and `getStatistics()`.
- **Management API (fully implemented)**: `WebhooksController` with full CRUD, test endpoint, event listing, log viewing, statistics, and manual retry.
- **Multi-tenancy (fully implemented)**: Organisation scoping via `MultiTenancyTrait` on WebhookMapper.
- **Database migration (fully implemented)**: `Version1Date20260308120000` adds nullable `mapping` column.
- **What could be extended**: Batch delivery (multiple events per HTTP request), dead-letter queue with admin UI, payload format versioning.

## Requirements

### Requirement: Webhook registration MUST capture URL, events, secret, and delivery configuration
The Webhook entity MUST store all information needed to deliver events to a subscriber, including the target URL, subscribed event classes, optional HMAC secret, HTTP method, custom headers, timeout, and retry policy.

#### Scenario: Create a minimal webhook subscription
- **GIVEN** an administrator wants to receive notifications for object changes
- **WHEN** they create a webhook via `POST /api/webhooks` with:
  ```json
  {
    "name": "Case notifications",
    "url": "https://external.example.nl/hooks/cases",
    "events": ["OCA\\OpenRegister\\Event\\ObjectCreatedEvent"]
  }
  ```
- **THEN** the system MUST create a `Webhook` entity with a generated UUID
- **AND** `method` MUST default to `POST`, `enabled` to `true`, `retryPolicy` to `exponential`, `maxRetries` to `3`, `timeout` to `30`
- **AND** the response MUST return HTTP 201 with the full webhook JSON including the generated `id` and `uuid`

#### Scenario: Create a webhook with full configuration
- **GIVEN** an administrator creates a webhook with all optional fields
- **WHEN** the request includes `secret`, `headers`, `filters`, `retryPolicy: "linear"`, `maxRetries: 5`, `timeout: 60`, `configuration: { "useCloudEvents": true }`
- **THEN** the `Webhook` entity MUST store all provided values
- **AND** the `secret` field MUST be stored as-is but serialized as `"***"` in JSON responses via `jsonSerialize()`

#### Scenario: Webhook with wildcard event subscription
- **GIVEN** a webhook with `events: ["OCA\\OpenRegister\\Event\\Object*"]`
- **WHEN** an `ObjectCreatedEvent`, `ObjectUpdatedEvent`, or `ObjectDeletedEvent` fires
- **THEN** the webhook MUST match all three events via `Webhook::matchesEvent()` using `fnmatch()` pattern matching
- **AND** non-object events like `RegisterCreatedEvent` MUST NOT match

#### Scenario: Webhook with empty events list subscribes to all events
- **GIVEN** a webhook with `events: []`
- **WHEN** any OpenRegister event fires (object, register, schema, application, agent, source, configuration, view, conversation, organisation)
- **THEN** the webhook MUST be triggered because `matchesEvent()` returns `true` for empty event lists

#### Scenario: Required fields validation
- **GIVEN** a request to create a webhook missing the `name` or `url` field
- **WHEN** `WebhooksController::create()` processes the request
- **THEN** it MUST return HTTP 400 with `{ "error": "Name and URL are required" }`

### Requirement: Webhook entity MUST support an optional mapping reference for payload transformation
The `Webhook` entity MUST have an optional `mapping` field (nullable integer) that references a `Mapping` entity by ID. When set, payloads SHALL be transformed through `MappingService.executeMapping()` before delivery.

#### Scenario: Webhook with mapping configured
- **GIVEN** a `Mapping` entity exists with ID `42` and a Twig-based transformation template
- **WHEN** a webhook is created or updated with `mapping: 42`
- **THEN** the webhook MUST store the mapping reference in `protected ?int $mapping`
- **AND** all subsequent deliveries MUST use the mapping to transform payloads before sending

#### Scenario: Webhook without mapping
- **GIVEN** a webhook with `mapping: null`
- **WHEN** an event triggers delivery
- **THEN** the payload MUST be delivered using either CloudEvents format (if `configuration.useCloudEvents` is `true`) or standard format (default)

#### Scenario: Webhook mapping takes precedence over CloudEvents
- **GIVEN** a webhook with both `mapping: 42` and `configuration.useCloudEvents: true`
- **WHEN** an event triggers delivery
- **THEN** `WebhookService::buildPayload()` MUST apply the mapping transformation as Strategy 1 (highest priority)
- **AND** CloudEvents formatting (Strategy 2) MUST only be used if no mapping is configured or mapping fails
- **AND** the raw event payload (not CloudEvents-formatted) MUST be the mapping input

### Requirement: Payload format MUST support three strategies with clear priority
`WebhookService::buildPayload()` MUST select the payload format in priority order: (1) Mapping transformation, (2) CloudEvents format, (3) Standard format.

#### Scenario: Strategy 1 - Mapping transformation produces custom format
- **GIVEN** a webhook with `mapping: 42` referencing a Mapping with:
  ```json
  {
    "mapping": {
      "channel": "{{ register.slug }}",
      "resource": "{{ schema.slug }}",
      "action": "{{ action }}",
      "resourceId": "{{ object.uuid }}",
      "timestamp": "{{ timestamp }}"
    }
  }
  ```
- **WHEN** an `ObjectCreatedEvent` fires for object UUID `abc-123` in schema `case` (register `procest`)
- **THEN** `MappingService.executeMapping()` MUST receive the event context merged with `event` and `timestamp` as input
- **AND** the HTTP POST body MUST be the mapping output:
  ```json
  {
    "channel": "procest",
    "resource": "case",
    "action": "create",
    "resourceId": "abc-123",
    "timestamp": "2026-03-19T10:00:00+01:00"
  }
  ```

#### Scenario: Strategy 2 - CloudEvents format when configured
- **GIVEN** a webhook with `mapping: null` and `configuration: { "useCloudEvents": true }`
- **WHEN** an event fires
- **THEN** `CloudEventFormatter::formatAsCloudEvent()` MUST produce a CloudEvents 1.0 compliant payload with:
  - `specversion`: `"1.0"`
  - `type`: the fully qualified event class name
  - `source`: configurable via `cloudEventSource` or defaulting to `"/apps/openregister"`
  - `id`: a unique UUID v4
  - `time`: ISO 8601 timestamp
  - `datacontenttype`: `"application/json"`
  - `data`: the enriched event payload including webhook metadata and attempt number
  - `openregister`: extension with `app` and `version`

#### Scenario: Strategy 3 - Standard format as default
- **GIVEN** a webhook with `mapping: null` and no CloudEvents configuration
- **WHEN** an event fires
- **THEN** the payload MUST use the standard format:
  ```json
  {
    "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
    "webhook": { "id": "<uuid>", "name": "<name>" },
    "data": { ... },
    "timestamp": "<ISO 8601>",
    "attempt": 1
  }
  ```

#### Scenario: Mapping produces ZGW notification format (configured by consuming app, not OpenRegister)
- **GIVEN** a webhook with a Mapping configured by Procest app:
  ```json
  {
    "mapping": {
      "kanaal": "zaken",
      "hoofdObject": "{{ baseUrl }}/zaken/v1/zaken/{{ object.uuid }}",
      "resource": "{{ schema.slug }}",
      "resourceUrl": "{{ baseUrl }}/zaken/v1/{{ schema.slug }}en/{{ object.uuid }}",
      "actie": "{{ action }}",
      "aanmaakdatum": "{{ timestamp }}",
      "kenmerken": {}
    }
  }
  ```
- **WHEN** an `ObjectCreatedEvent` fires
- **THEN** the payload MUST be a valid ZGW/VNG Notificaties API format
- **AND** OpenRegister has zero knowledge of the ZGW format -- it just executes the Twig mapping

### Requirement: Event payload input MUST include full context for mapping templates
The input array passed to `MappingService.executeMapping()` MUST include all available event context so Twig templates can reference any field.

#### Scenario: Event payload structure for object lifecycle events
- **GIVEN** any object lifecycle event fires (created, updated, deleted)
- **WHEN** the event payload is prepared by `WebhookEventListener::extractPayload()`
- **THEN** the input MUST include at minimum:
  - `objectType`: `"object"`
  - `action`: normalized action string (`"create"`, `"update"`, `"delete"`)
  - `object`: the full object data array via `jsonSerialize()`
  - `objectUuid`: the object's UUID
  - `register`: register ID
  - `schema`: schema ID
  - `timestamp`: ISO 8601 timestamp
- **AND** when passed to `applyMappingTransformation()`, the input MUST be enriched with:
  - `event`: the short event class name (e.g., `"ObjectCreatedEvent"`) via `getShortEventName()`
  - `timestamp`: current ISO 8601 timestamp via `date('c')`

#### Scenario: Object data includes all properties
- **GIVEN** an object with properties `title`, `status`, `assignee`, `metadata.priority`
- **WHEN** the event payload is prepared
- **THEN** `object.title`, `object.status`, `object.assignee` MUST all be accessible in Twig templates
- **AND** nested properties MUST be accessible via dot notation in Twig (e.g., `{{ object.metadata.priority }}`)

#### Scenario: Update events include both old and new object states
- **GIVEN** an `ObjectUpdatedEvent` fires
- **WHEN** `WebhookEventListener` extracts the payload
- **THEN** the payload MUST include `object` (the new state via `getNewObject()`) and optionally the old state accessible through the event

#### Scenario: Non-object events provide entity-specific context
- **GIVEN** a `RegisterCreatedEvent` fires
- **WHEN** the payload is extracted
- **THEN** it MUST include `objectType: "register"`, `action: "created"`, and `register` with the full register data
- **AND** webhooks subscribing to register events MUST receive this payload in the same delivery pipeline

### Requirement: Webhook authentication MUST support HMAC-SHA256 signatures
When a webhook has a `secret` configured, all deliveries MUST include an HMAC-SHA256 signature computed from the final payload.

#### Scenario: HMAC signing with standard payload
- **GIVEN** a webhook with `secret: "my-webhook-secret"` and no mapping
- **WHEN** a notification is delivered
- **THEN** `WebhookService::generateSignature()` MUST compute `hash_hmac('sha256', json_encode($payload), $secret)`
- **AND** the result MUST be sent in the `X-Webhook-Signature` header

#### Scenario: HMAC signing with mapped payload
- **GIVEN** a webhook with both a `mapping` and a `secret` configured
- **WHEN** the notification is delivered
- **THEN** the `X-Webhook-Signature` MUST be computed from the mapped (transformed) payload, not the raw input
- **AND** this is guaranteed because `buildPayload()` returns the mapped payload before `sendRequest()` computes the signature

#### Scenario: No signature when no secret
- **GIVEN** a webhook with `secret: null`
- **WHEN** a delivery is sent
- **THEN** the `X-Webhook-Signature` header MUST NOT be included

#### Scenario: Subscriber verifies signature
- **GIVEN** an external system receives a webhook with `X-Webhook-Signature: <hex-digest>`
- **WHEN** it computes `HMAC-SHA256(request_body, shared_secret)`
- **THEN** the computed digest MUST match the header value, confirming payload integrity and authenticity

### Requirement: Event filtering MUST support register, schema, and property-level conditions
Webhooks MUST support filters that restrict delivery to events matching specific criteria, evaluated before payload transformation.

#### Scenario: Filter by register
- **GIVEN** a webhook with `filters: { "register": 5 }`
- **WHEN** an object event fires for register ID `5`
- **THEN** the webhook MUST be triggered
- **AND** events for register ID `7` MUST NOT trigger this webhook

#### Scenario: Filter by nested property using dot notation
- **GIVEN** a webhook with `filters: { "object.status": "open" }`
- **WHEN** `WebhookService::passesFilters()` evaluates the payload
- **THEN** `getNestedValue()` MUST traverse the payload using dot-separated keys
- **AND** only events where `object.status` equals `"open"` MUST pass

#### Scenario: Filter with array of allowed values
- **GIVEN** a webhook with `filters: { "action": ["create", "update"] }`
- **WHEN** an event with `action: "create"` fires
- **THEN** the webhook MUST be triggered because the value is in the allowed array
- **AND** an event with `action: "delete"` MUST NOT trigger the webhook

#### Scenario: Empty filters match all events
- **GIVEN** a webhook with `filters: null` or `filters: {}`
- **WHEN** any event fires
- **THEN** `passesFilters()` MUST return `true` without evaluating conditions

#### Scenario: Filtering happens before mapping
- **GIVEN** a webhook with `events: ["ObjectCreatedEvent"]` and a mapping configured
- **WHEN** an `ObjectUpdatedEvent` fires
- **THEN** the webhook MUST NOT be triggered (event matching is evaluated first)
- **AND** the mapping transformation MUST NOT execute (no wasted computation)

### Requirement: Delivery retry MUST use configurable backoff policies
Failed webhook deliveries MUST be retried according to the webhook's `retryPolicy` up to `maxRetries` attempts, with retry timestamps tracked in `WebhookLog`.

#### Scenario: Exponential backoff retry
- **GIVEN** a webhook with `retryPolicy: "exponential"` and `maxRetries: 3`
- **WHEN** delivery attempt 1 fails
- **THEN** `calculateRetryDelay()` MUST compute `2^attempt * 60` seconds
- **AND** attempt 1 retry delay MUST be 120 seconds (2 minutes)
- **AND** attempt 2 retry delay MUST be 240 seconds (4 minutes)
- **AND** the `WebhookLog.nextRetryAt` MUST be set to `now + delay`

#### Scenario: Linear backoff retry
- **GIVEN** a webhook with `retryPolicy: "linear"` and `maxRetries: 5`
- **WHEN** delivery attempt 2 fails
- **THEN** `calculateRetryDelay()` MUST compute `attempt * 300` seconds (attempt * 5 minutes)
- **AND** retry delay MUST be 600 seconds (10 minutes)

#### Scenario: Fixed delay retry
- **GIVEN** a webhook with `retryPolicy: "fixed"`
- **WHEN** any delivery fails
- **THEN** `calculateRetryDelay()` MUST always return 300 seconds (5 minutes)

#### Scenario: Retry limit exceeded
- **GIVEN** a webhook with `maxRetries: 3` and a failed delivery at attempt 3
- **WHEN** `deliverWebhook()` processes the failure
- **THEN** no further retry MUST be scheduled (because `attempt >= maxRetries`)
- **AND** the `WebhookLog` MUST record the final failure without a `nextRetryAt`

#### Scenario: WebhookRetryJob processes pending retries
- **GIVEN** the `WebhookRetryJob` cron runs every 300 seconds (5 minutes)
- **WHEN** it finds `WebhookLog` entries with `success: false` and `nextRetryAt <= now`
- **THEN** it MUST call `WebhookService::deliverWebhook()` with `attempt: log.attempt + 1`
- **AND** skip any logs where the webhook is disabled or retry limit is exceeded

### Requirement: Delivery logging MUST capture full request/response details
Every webhook delivery attempt MUST create a `WebhookLog` entry with payload, status, response, and error information.

#### Scenario: Successful delivery log
- **GIVEN** a webhook delivery succeeds with HTTP 200
- **WHEN** the `WebhookLog` is created
- **THEN** it MUST record: `webhook` (ID), `eventClass`, `payload` (the mapped/formatted payload), `url`, `method`, `success: true`, `statusCode: 200`, `responseBody`, `attempt`, `created` timestamp
- **AND** `WebhookMapper::updateStatistics()` MUST increment `totalDeliveries` and `successfulDeliveries` and update `lastSuccessAt`

#### Scenario: Failed delivery log with error details
- **GIVEN** a delivery fails with a `RequestException` containing an HTTP 503 response
- **WHEN** the `WebhookLog` is created
- **THEN** it MUST record `success: false`, `statusCode: 503`, `errorMessage` with the exception message
- **AND** `requestBody` MUST store the payload JSON for retry purposes
- **AND** `WebhookMapper::updateStatistics()` MUST increment `failedDeliveries` and update `lastFailureAt`

#### Scenario: Connection error without HTTP response
- **GIVEN** a delivery fails with a connection timeout (no HTTP response available)
- **WHEN** the `WebhookLog` is created
- **THEN** `statusCode` MUST be `null` and `errorMessage` MUST capture the connection error details

### Requirement: Mapping failure MUST NOT block webhook delivery
If the mapping transformation fails (invalid Twig template, missing data, deleted mapping), the webhook MUST fall back to the next payload strategy rather than failing silently.

#### Scenario: Mapping throws Twig exception
- **GIVEN** a webhook with mapping that references `{{ nonexistent.field }}` causing a Twig error
- **WHEN** `applyMappingTransformation()` catches the exception
- **THEN** a warning MUST be logged with `[WebhookService] Mapping transformation failed, falling back to raw payload`
- **AND** the method MUST return `null`, causing `buildPayload()` to fall through to CloudEvents or standard format

#### Scenario: Referenced mapping entity deleted
- **GIVEN** a webhook references mapping ID `42` but the mapping has been deleted
- **WHEN** `applyMappingTransformation()` catches `DoesNotExistException`
- **THEN** a warning MUST be logged with `[WebhookService] Webhook references missing mapping`
- **AND** delivery MUST proceed with the fallback payload format

#### Scenario: Mapping entity load failure
- **GIVEN** a database error occurs when loading the mapping entity
- **WHEN** `applyMappingTransformation()` catches the generic `\Exception`
- **THEN** a warning MUST be logged and delivery MUST continue with the fallback format

### Requirement: Webhook management API MUST provide full CRUD plus operational endpoints
`WebhooksController` MUST expose REST endpoints for creating, reading, updating, deleting webhooks, plus operational endpoints for testing, viewing logs, and retrieving statistics.

#### Scenario: List all webhooks with pagination
- **GIVEN** 15 webhooks exist in the current organisation
- **WHEN** `GET /api/webhooks?_limit=10&_offset=0` is called
- **THEN** the response MUST return `{ "results": [...10 webhooks...], "total": 15 }` with HTTP 200
- **AND** results MUST be filtered by the current user's organisation via `MultiTenancyTrait::applyOrganisationFilter()`

#### Scenario: Get a single webhook by ID
- **GIVEN** webhook with ID `7` exists
- **WHEN** `GET /api/webhooks/7` is called
- **THEN** the response MUST return the full webhook JSON with HTTP 200
- **AND** the `secret` field MUST be masked as `"***"` in the response

#### Scenario: Update a webhook
- **GIVEN** webhook with ID `7` exists
- **WHEN** `PUT /api/webhooks/7` is called with `{ "enabled": false }`
- **THEN** the webhook MUST be updated via `WebhookMapper::updateFromArray()`
- **AND** the `updated` timestamp MUST be refreshed

#### Scenario: Delete a webhook
- **GIVEN** webhook with ID `7` exists
- **WHEN** `DELETE /api/webhooks/7` is called
- **THEN** the webhook MUST be deleted and HTTP 204 returned
- **AND** RBAC permissions MUST be verified via `MultiTenancyTrait::verifyRbacPermission('delete', 'webhook')`

#### Scenario: List available event types
- **GIVEN** an administrator wants to know which events can be subscribed to
- **WHEN** `GET /api/webhooks/events` is called
- **THEN** the response MUST list all 36+ event classes with `class`, `name`, `description`, `category`, `type` (before/after), and `properties`

### Requirement: Webhook testing MUST support dry-run delivery
Administrators MUST be able to test a webhook configuration by sending a test payload without requiring a real event to fire.

#### Scenario: Successful test delivery
- **GIVEN** webhook ID `7` exists and points to a reachable URL
- **WHEN** `POST /api/webhooks/7/test` is called
- **THEN** `WebhookService::deliverWebhook()` MUST be called with event name `OCA\OpenRegister\Event\TestEvent` and a test payload containing `{ "test": true, "message": "This is a test webhook from OpenRegister", "timestamp": "<ISO 8601>" }`
- **AND** the response MUST return `{ "success": true, "message": "Test webhook delivered successfully" }`

#### Scenario: Failed test delivery with error details
- **GIVEN** webhook ID `7` points to an unreachable URL
- **WHEN** `POST /api/webhooks/7/test` is called
- **THEN** the response MUST return HTTP 500 with `{ "success": false, "message": "<error>", "error_details": { "status_code": <code>, "response_body": "<body>" } }`
- **AND** the error details MUST be retrieved from the most recent `WebhookLog` entry

#### Scenario: Test non-existent webhook
- **GIVEN** no webhook exists with ID `999`
- **WHEN** `POST /api/webhooks/999/test` is called
- **THEN** the response MUST return HTTP 404 with `{ "error": "Webhook not found" }`

### Requirement: Webhook delivery MUST support async processing via background jobs
Webhook retries MUST be processed asynchronously via Nextcloud's `QueuedJob` and `TimedJob` background job system.

#### Scenario: WebhookDeliveryJob processes async delivery
- **GIVEN** a `WebhookDeliveryJob` is queued with arguments `{ "webhook_id": 7, "event_name": "...", "payload": {...}, "attempt": 2 }`
- **WHEN** the background job runs
- **THEN** it MUST load the webhook via `WebhookMapper::find()`, call `WebhookService::deliverWebhook()`, and log success or failure

#### Scenario: WebhookDeliveryJob with invalid arguments
- **GIVEN** a `WebhookDeliveryJob` is queued with missing `webhook_id` or `event_name`
- **WHEN** the job runs
- **THEN** it MUST log an error and return without attempting delivery

#### Scenario: WebhookRetryJob runs on a 5-minute interval
- **GIVEN** the `WebhookRetryJob` is registered as a `TimedJob` with interval 300 seconds
- **WHEN** the Nextcloud cron executes
- **THEN** `WebhookRetryJob::run()` MUST call `WebhookLogMapper::findFailedForRetry(now)` to find eligible retries
- **AND** for each eligible log, it MUST re-deliver using the stored event class and payload

### Requirement: Webhook health monitoring MUST track delivery statistics
Each `Webhook` entity MUST maintain counters and timestamps for monitoring delivery health.

#### Scenario: Statistics updated on successful delivery
- **GIVEN** a webhook with `totalDeliveries: 10`, `successfulDeliveries: 8`
- **WHEN** a delivery succeeds
- **THEN** `WebhookMapper::updateStatistics(webhook, success: true)` MUST set `totalDeliveries: 11`, `successfulDeliveries: 9`, `lastTriggeredAt` and `lastSuccessAt` to current timestamp

#### Scenario: Statistics updated on failed delivery
- **GIVEN** a webhook with `failedDeliveries: 2`
- **WHEN** a delivery fails
- **THEN** `updateStatistics(webhook, success: false)` MUST set `failedDeliveries: 3` and update `lastFailureAt`

#### Scenario: Log statistics endpoint
- **GIVEN** webhook ID `7` has delivery history
- **WHEN** `GET /api/webhooks/7/logs/stats` is called
- **THEN** the response MUST include `total`, `successful`, `failed`, and `pendingRetries` counts
- **AND** `pendingRetries` MUST be computed from `WebhookLogMapper::findFailedForRetry(now)`

#### Scenario: Manual retry of a failed delivery
- **GIVEN** a failed `WebhookLog` entry with ID `42`
- **WHEN** `POST /api/webhooks/logs/42/retry` is called
- **THEN** the controller MUST verify the log has `success: false` (reject retrying successful deliveries with HTTP 400)
- **AND** extract the payload from `requestBody` or `payload` field
- **AND** call `deliverWebhook()` with `attempt: log.attempt + 1`

### Requirement: Multi-tenant webhook isolation MUST scope webhooks to organisations
In a multi-tenant deployment, webhooks MUST be scoped to the user's organisation so tenants cannot see or modify each other's webhook subscriptions.

#### Scenario: Organisation filter applied on listing
- **GIVEN** organisation A has 5 webhooks and organisation B has 3 webhooks
- **WHEN** a user from organisation A calls `GET /api/webhooks`
- **THEN** only the 5 webhooks from organisation A MUST be returned
- **AND** this is enforced by `WebhookMapper` using `MultiTenancyTrait::applyOrganisationFilter()`

#### Scenario: Organisation auto-assigned on creation
- **GIVEN** a user from organisation A creates a webhook
- **WHEN** `WebhookMapper::insert()` is called
- **THEN** `setOrganisationOnCreate()` MUST automatically set the `organisation` field based on the active session
- **AND** the `organisation` field from the request data MUST be stripped by the controller to prevent spoofing

#### Scenario: RBAC permission check on mutation operations
- **GIVEN** a user attempts to update a webhook
- **WHEN** `WebhookMapper::update()` is called
- **THEN** `verifyRbacPermission('update', 'webhook')` MUST verify the user has the required role
- **AND** `verifyOrganisationAccess()` MUST confirm the webhook belongs to the user's organisation

### Requirement: Request interception MUST support pre-event webhooks
`WebhookService::interceptRequest()` MUST allow webhooks to be notified before a controller action executes, enabling pre-processing and validation by external systems.

#### Scenario: Webhook configured for request interception
- **GIVEN** a webhook with `configuration: { "interceptRequests": true }` and events matching `ObjectCreatingEvent`
- **WHEN** an object creation request arrives
- **THEN** `findWebhooksForInterception()` MUST find this webhook among enabled webhooks
- **AND** `interceptRequest()` MUST deliver the request data as a CloudEvent-formatted payload

#### Scenario: Interception event type to class conversion
- **GIVEN** an interception event type `"object.creating"`
- **WHEN** `eventTypeToEventClass()` converts it
- **THEN** the result MUST be `"OCA\OpenRegister\Event\ObjectCreatingEvent"`

#### Scenario: Multiple intercepting webhooks processed independently
- **GIVEN** two webhooks configured for request interception on the same event
- **WHEN** one webhook delivery fails
- **THEN** the error MUST be logged but processing MUST continue for the remaining webhook
- **AND** the original request data MUST be returned unchanged

### Requirement: Webhook entity MUST include mapping field in database migration
The `mapping` column MUST be added to the `oc_openregister_webhooks` table via migration `Version1Date20260308120000`.

#### Scenario: Migration adds nullable mapping column
- **GIVEN** the existing webhooks table without a `mapping` column
- **WHEN** the migration runs
- **THEN** a nullable integer column `mapping` MUST be added
- **AND** existing webhooks MUST have `mapping = null` (no change to existing behavior)

#### Scenario: Migration is idempotent
- **GIVEN** the `mapping` column already exists
- **WHEN** the migration runs again
- **THEN** it MUST return `null` without modifying the schema (checked via `$table->hasColumn('mapping')`)

#### Scenario: Migration handles missing table gracefully
- **GIVEN** the `openregister_webhooks` table does not exist (fresh install before webhooks migration)
- **WHEN** the mapping migration runs
- **THEN** it MUST return `null` without error (checked via `$schema->hasTable()`)

### Requirement: Existing webhook features MUST work with mapped payloads
All existing webhook delivery features (signing, retry, logging, filtering) MUST remain fully functional when a mapping transformation is applied.

#### Scenario: Retry with mapped payload uses same payload
- **GIVEN** a mapped webhook delivery fails
- **WHEN** the retry policy triggers via `WebhookRetryJob`
- **THEN** the same mapped payload MUST be retried (mapping is applied once during `buildPayload()`, not re-executed on retry)
- **AND** this is guaranteed because the `WebhookLog.payload` stores the final payload and `requestBody` stores the JSON for retry

#### Scenario: Webhook logging records mapped payload
- **GIVEN** a mapped webhook is delivered
- **THEN** the `WebhookLog.payload` MUST contain the mapped payload (what was actually sent to the subscriber)

## Current Implementation Status

**Fully implemented.** All core requirements are in place:

- `lib/Db/Webhook.php` -- Webhook entity with 23 fields including `protected ?int $mapping = null` for optional mapping reference, `retryPolicy`, `maxRetries`, `secret`, `filters`, `configuration`, organisation scoping, UUID, and delivery statistics counters
- `lib/Db/WebhookMapper.php` -- Mapper with multi-tenancy via `MultiTenancyTrait`, RBAC verification, `findForEvent()` matching, `findEnabled()`, `updateStatistics()`, `createFromArray()`, `updateFromArray()`, and table existence checks
- `lib/Db/WebhookLog.php` -- Log entity with `webhook`, `eventClass`, `payload`, `url`, `method`, `success`, `statusCode`, `requestBody`, `responseBody`, `errorMessage`, `attempt`, `nextRetryAt`, `created`
- `lib/Db/WebhookLogMapper.php` -- Mapper with `findByWebhook()`, `findFailedForRetry()`, `getStatistics()`
- `lib/Service/WebhookService.php` -- Core service with `dispatchEvent()`, `deliverWebhook()`, `buildPayload()` (3-strategy priority), `applyMappingTransformation()`, `passesFilters()` with dot-notation, `sendRequest()` with HMAC signing, `interceptRequest()` for pre-event webhooks, retry scheduling with exponential/linear/fixed backoff
- `lib/Service/Webhook/CloudEventFormatter.php` -- CloudEvents 1.0 formatter for both events (`formatAsCloudEvent()`) and requests (`formatRequestAsCloudEvent()`)
- `lib/Service/MappingService.php` -- Twig-based mapping engine with `executeMapping()`, supports dot-notation, casting, passThrough, unset
- `lib/Listener/WebhookEventListener.php` -- Event listener handling 36+ event types across 11 entity categories (object, register, schema, application, agent, source, configuration, view, conversation, organisation), extracting structured payloads
- `lib/BackgroundJob/WebhookDeliveryJob.php` -- Async delivery via Nextcloud's `QueuedJob`
- `lib/Cron/WebhookRetryJob.php` -- Retry processing via `TimedJob` with 5-minute interval
- `lib/Controller/WebhooksController.php` -- Full REST API: `index()`, `show()`, `create()`, `update()`, `destroy()`, `test()`, `events()`, `logs()`, `logStats()`, `allLogs()`, `retry()`
- `lib/Migration/Version1Date20260308120000.php` -- Database migration adding nullable `mapping` column
- `lib/Twig/MappingExtension.php` and `lib/Twig/MappingRuntime.php` -- Twig runtime functions for mapping templates

## Standards & References
- CloudEvents 1.0 Specification (https://cloudevents.io/) -- used for `specversion`, `type`, `source`, `id`, `time`, `datacontenttype`, `subject`, `dataschema`
- Twig Template Engine (https://twig.symfony.com/) -- used for mapping transformations via `MappingService`
- HMAC-SHA256 (RFC 2104) -- used for webhook signature verification via `hash_hmac('sha256', ...)`
- HTTP Webhooks pattern (industry convention) -- POST with JSON body, signature header, retry with backoff
- VNG Notificaties API (https://notificaties-api.vng.cloud/) -- compatible via Twig mapping (not hardcoded)
- Nextcloud IEventDispatcher -- used for internal PHP event dispatch
- Nextcloud QueuedJob / TimedJob -- used for async delivery and retry processing

## Cross-References
- **event-driven-architecture** spec -- defines the CloudEvents event bus that webhooks deliver; webhooks are the HTTP transport mechanism for the event bus
- **notificatie-engine** spec -- webhooks are one of the notification channels (alongside email and in-app); notification rules can trigger webhook delivery
- **workflow-integration** spec -- n8n workflows can be triggered via webhook URLs; `N8nAdapter::executeWorkflow()` sends data to n8n webhook endpoints, and OpenRegister webhooks can POST events to n8n webhook triggers

## Specificity Assessment
- **Specific enough to implement?** Yes -- every requirement has concrete scenarios with exact method names, field names, and expected behaviors grounded in the actual codebase.
- **Missing/ambiguous:** Batch delivery (sending multiple events in a single HTTP request) is not yet specified or implemented. Dead-letter queue handling after all retries are exhausted is referenced in event-driven-architecture but not yet implemented in webhook service.
- **Open questions:** Whether webhook versioning (payload format versioning) should be supported as a separate configuration option.

## Nextcloud Integration Analysis

- **Status**: Fully implemented in OpenRegister
- **Nextcloud Core Integration**: `WebhookDeliveryJob` extends `QueuedJob` and `WebhookRetryJob` extends `TimedJob` for Nextcloud's background job system. Events are dispatched via `IEventDispatcher`. Multi-tenancy uses `IUserSession` and `IGroupManager` for RBAC. HTTP client uses GuzzleHttp. Webhook entity uses `OCP\AppFramework\Db\Entity` base class. Controller extends `OCP\AppFramework\Controller` with `#[NoAdminRequired]` and `#[NoCSRFRequired]` attributes.
- **Recommendation**: Mark as implemented. The architecture provides dual delivery paths: OpenRegister's own webhook system (this spec) and Nextcloud's native webhook forwarding via `IWebhookCompatibleEvent`.
