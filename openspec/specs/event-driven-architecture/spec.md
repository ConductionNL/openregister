# Event-Driven Architecture

## Purpose
OpenRegister implements a comprehensive event-driven architecture built on Nextcloud's `IEventDispatcher` (OCP\EventDispatcher\IEventDispatcher) that enables loose coupling between internal components and external systems. Every mutation across all entity types -- Objects, Registers, Schemas, Sources, Configurations, Views, Agents, Applications, Conversations, and Organisations -- dispatches a typed PHP event that can be consumed by any Nextcloud app, delivered to external systems via webhooks in CloudEvents v1.0 format, or pushed to real-time subscribers via GraphQL SSE. The architecture distinguishes between pre-mutation events (ObjectCreatingEvent, ObjectUpdatingEvent, ObjectDeletingEvent) that implement `StoppableEventInterface` to allow hooks to reject or modify operations, and post-mutation events (ObjectCreatedEvent, ObjectUpdatedEvent, ObjectDeletedEvent) that notify downstream systems after persistence is complete.

**Source**: Gap identified in cross-platform analysis; four platforms implement event-driven architectures. Core implementation exists with 39+ typed event classes in `lib/Event/`, 8 event listeners in `lib/Listener/`, and webhook delivery infrastructure.

## Requirements

### Requirement: All entity mutations MUST dispatch typed PHP events via IEventDispatcher
Every create, update, and delete operation across all entity types MUST dispatch a typed event class extending `OCP\EventDispatcher\Event` through Nextcloud's `IEventDispatcher::dispatchTyped()`. This ensures all mutations are observable by any registered listener, whether internal or from another Nextcloud app.

#### Scenario: Object creation dispatches ObjectCreatingEvent then ObjectCreatedEvent
- **GIVEN** a schema `meldingen` in register `zaken`
- **WHEN** a new melding object is created via `MagicMapper::insert()`
- **THEN** `MagicMapper::insertObjectEntity()` MUST dispatch an `ObjectCreatingEvent` (pre-save) via `$this->eventDispatcher->dispatchTyped()`
- **AND** if no listener stops propagation via `StoppableEventInterface::isPropagationStopped()`, the object MUST be persisted to the database
- **AND** after successful persistence, `MagicMapper::insert()` MUST dispatch an `ObjectCreatedEvent` (post-save)
- **AND** both events MUST carry the full `ObjectEntity` instance accessible via `getObject()`

#### Scenario: Object update dispatches ObjectUpdatingEvent then ObjectUpdatedEvent with old and new state
- **GIVEN** melding `melding-1` exists in the database
- **WHEN** `melding-1` is updated via `MagicMapper::update()`
- **THEN** `MagicMapper::updateObjectEntity()` MUST dispatch an `ObjectUpdatingEvent` with both `$newObject` and `$oldObject` parameters
- **AND** after successful persistence, MUST dispatch an `ObjectUpdatedEvent` carrying both the new state (`getNewObject()`) and the previous state (`getOldObject()`)
- **AND** the old object state MUST be a snapshot taken before the update was applied

#### Scenario: Object deletion dispatches ObjectDeletingEvent then ObjectDeletedEvent
- **GIVEN** melding `melding-1` exists in the database
- **WHEN** `melding-1` is deleted via `MagicMapper::delete()`
- **THEN** `MagicMapper::deleteObjectEntity()` MUST dispatch an `ObjectDeletingEvent` before deletion
- **AND** after successful deletion, MUST dispatch an `ObjectDeletedEvent` with the full object snapshot
- **AND** the `ObjectDeletedEvent` MUST contain the complete entity data as it existed before deletion

#### Scenario: Non-object entity mutations dispatch corresponding typed events
- **GIVEN** a register `zaken` is being updated via `RegisterMapper`
- **WHEN** the update is persisted
- **THEN** a `RegisterUpdatedEvent` MUST be dispatched carrying the updated `Register` entity
- **AND** the same pattern MUST apply to all entity types: Register (Created/Updated/Deleted), Schema (Created/Updated/Deleted), Source (Created/Updated/Deleted), Configuration (Created/Updated/Deleted), View (Created/Updated/Deleted), Agent (Created/Updated/Deleted), Application (Created/Updated/Deleted), Conversation (Created/Updated/Deleted), Organisation (Created/Updated/Deleted)

#### Scenario: Lock and revert operations dispatch specialized events
- **GIVEN** an object `obj-1` exists and is unlocked
- **WHEN** an administrator locks `obj-1` via `MagicMapper::lockObjectEntity()`
- **THEN** an `ObjectLockedEvent` MUST be dispatched carrying the locked `ObjectEntity`
- **AND** when the object is later reverted to a previous state, an `ObjectRevertedEvent` MUST be dispatched with the object and the revert point (`DateTime` or audit trail ID) accessible via `getRevertPoint()`

### Requirement: Pre-mutation events MUST support rejection and data modification via StoppableEventInterface
Pre-mutation event classes (`ObjectCreatingEvent`, `ObjectUpdatingEvent`, `ObjectDeletingEvent`) MUST implement `Psr\EventDispatcher\StoppableEventInterface` to allow schema hooks and other listeners to reject operations or modify data before persistence.

#### Scenario: Hook rejects object creation via stopPropagation
- **GIVEN** schema `vergunningen` has a validation hook configured
- **WHEN** a new vergunning object is created and the `ObjectCreatingEvent` is dispatched
- **AND** the hook listener calls `$event->stopPropagation()` and `$event->setErrors(['validation' => 'BSN is invalid'])`
- **THEN** `MagicMapper::insertObjectEntity()` MUST check `$creatingEvent->isPropagationStopped()` and abort the insert
- **AND** the errors from `$event->getErrors()` MUST be returned to the caller as an exception or error response

#### Scenario: Hook modifies data before persistence
- **GIVEN** schema `contactmomenten` has a data enrichment hook
- **WHEN** the `ObjectCreatingEvent` is dispatched
- **AND** the hook listener calls `$event->setModifiedData(['enriched_field' => 'computed_value'])`
- **THEN** the modified data from `$event->getModifiedData()` MUST be merged into the object before persistence
- **AND** the final persisted object MUST contain the hook's modifications

#### Scenario: Hook rejects object update but allows original to remain unchanged
- **GIVEN** object `zaak-1` is being updated
- **WHEN** the `ObjectUpdatingEvent` is dispatched and a hook stops propagation
- **THEN** the update MUST be aborted and the object in the database MUST remain in its pre-update state
- **AND** the old object state from `$event->getOldObject()` MUST be preserved

### Requirement: Event listeners MUST be registered in Application.php via registerEventListener
All event listener bindings MUST be declared in `Application::registerEventListeners()` using `IRegistrationContext::registerEventListener()`. This ensures Nextcloud's lazy-loading mechanism defers listener instantiation until the event is actually dispatched.

#### Scenario: SolrEventListener registers for object and schema lifecycle events
- **GIVEN** the OpenRegister app boots
- **WHEN** `Application::registerEventListeners()` is called
- **THEN** `SolrEventListener::class` MUST be registered for `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, `SchemaCreatedEvent`, `SchemaUpdatedEvent`, and `SchemaDeletedEvent`
- **AND** these registrations MUST use `$context->registerEventListener(EventClass::class, ListenerClass::class)` to enable lazy instantiation

#### Scenario: HookListener registers for both pre and post mutation events
- **GIVEN** the OpenRegister app boots
- **WHEN** `Application::registerEventListeners()` is called
- **THEN** `HookListener::class` MUST be registered for all six object lifecycle events: `ObjectCreatingEvent`, `ObjectUpdatingEvent`, `ObjectDeletingEvent`, `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`
- **AND** the HookListener MUST delegate execution to `HookExecutor` which loads hooks from the schema's `getHooks()` configuration

#### Scenario: WebhookEventListener registers for object creation events
- **GIVEN** the OpenRegister app boots
- **WHEN** `Application::registerEventListeners()` is called
- **THEN** `WebhookEventListener::class` MUST be registered for `ObjectCreatedEvent`
- **AND** it MUST dispatch events to `WebhookService::dispatchEvent()` with the extracted payload

#### Scenario: Multiple listeners on the same event type
- **GIVEN** `ObjectCreatedEvent` has listeners registered for `SolrEventListener`, `ObjectChangeListener`, `HookListener`, `WebhookEventListener`, and `GraphQLSubscriptionListener`
- **WHEN** an `ObjectCreatedEvent` is dispatched
- **THEN** all five listeners MUST be invoked by Nextcloud's event dispatcher
- **AND** each listener MUST execute independently -- a failure in one MUST NOT prevent others from executing

### Requirement: WebhookEventListener MUST extract structured payloads from all event types
The `WebhookEventListener` MUST handle all 39+ event types by extracting a structured payload containing `objectType`, `action`, and the serialized entity data. This payload is then forwarded to `WebhookService` for delivery to configured webhook endpoints.

#### Scenario: Object event payload includes register and schema context
- **GIVEN** a webhook is configured to receive `ObjectCreatedEvent`
- **WHEN** an object is created in register `5` with schema `3`
- **THEN** the `WebhookEventListener::extractPayload()` MUST return a payload containing:
  - `objectType`: `'object'`
  - `action`: `'create'`
  - `object`: the full `jsonSerialize()` output of the ObjectEntity
  - `objectUuid`: the object's UUID
  - `register`: the register ID (`5`)
  - `schema`: the schema ID (`3`)
  - `timestamp`: ISO 8601 timestamp

#### Scenario: Register event payload includes serialized register
- **GIVEN** a webhook listens for `RegisterUpdatedEvent`
- **WHEN** a register is updated
- **THEN** the extracted payload MUST contain `objectType: 'register'`, `action: 'updated'`, and the register's `jsonSerialize()` output under the `register` key

#### Scenario: Unknown event type returns null payload
- **GIVEN** a new event type is dispatched that WebhookEventListener does not recognize
- **WHEN** `extractPayload()` is called
- **THEN** it MUST return `null`
- **AND** the listener MUST log a warning and skip webhook delivery for that event

### Requirement: Webhook delivery MUST support CloudEvents v1.0 format with configurable payload strategies
The `WebhookService` MUST support three payload strategies in priority order: (1) Mapping transformation via a referenced `Mapping` entity, (2) CloudEvents v1.0 format via `CloudEventFormatter` when `useCloudEvents` is enabled, (3) Standard format with event name, webhook metadata, data, and timestamp.

#### Scenario: Webhook configured with CloudEvents format
- **GIVEN** a webhook entity has `configuration.useCloudEvents` set to `true`
- **WHEN** an event is delivered to this webhook
- **THEN** `CloudEventFormatter::formatAsCloudEvent()` MUST produce a payload with:
  - `specversion`: `'1.0'`
  - `type`: the fully qualified event class name
  - `source`: defaults to `'/apps/openregister'` or a custom `cloudEventSource` from webhook configuration
  - `id`: a unique UUID v4 generated via `Symfony\Component\Uid\Uuid::v4()`
  - `time`: ISO 8601 timestamp
  - `datacontenttype`: `'application/json'`
  - `data`: the enriched event payload including webhook metadata and attempt number
  - `openregister.app`: `'openregister'`
  - `openregister.version`: the app version string

#### Scenario: Webhook configured with Mapping transformation
- **GIVEN** a webhook entity references `mapping` ID `7`
- **AND** Mapping `7` defines a Twig-based transformation template
- **WHEN** an event is delivered
- **THEN** `WebhookService::applyMappingTransformation()` MUST load the Mapping entity via `MappingMapper::find(7)`
- **AND** execute the mapping via `MappingService::executeMapping()` with the event payload merged with `event` (short class name) and `timestamp`
- **AND** if the mapping fails or the Mapping entity does not exist, MUST fall through to CloudEvents or standard format

#### Scenario: Webhook with standard format (no CloudEvents, no Mapping)
- **GIVEN** a webhook with no mapping reference and `useCloudEvents` set to `false` (or unset)
- **WHEN** an event is delivered
- **THEN** the payload MUST be structured as: `{ event, webhook: { id, name }, data, timestamp, attempt }`

#### Scenario: HMAC signature generation for webhook security
- **GIVEN** a webhook has a `secret` configured
- **WHEN** the webhook payload is sent
- **THEN** the HTTP request MUST include an `X-Webhook-Signature` header containing the `sha256` HMAC of the JSON-encoded payload using the webhook's secret
- **AND** the receiving system can verify the signature to ensure payload integrity

### Requirement: Webhook delivery MUST support filtering by event payload attributes
Administrators MUST be able to configure filters on webhook entities using dot-notation keys to match against event payload values. Only events whose payload matches all configured filters SHALL be delivered.

#### Scenario: Filter webhook by register ID
- **GIVEN** a webhook has filters `{ "register": "5" }`
- **WHEN** an ObjectCreatedEvent fires for an object in register `5`
- **THEN** the webhook MUST receive the delivery
- **AND** when an ObjectCreatedEvent fires for register `8`, the webhook MUST NOT receive the delivery

#### Scenario: Filter webhook by schema and action
- **GIVEN** a webhook has filters `{ "schema": "3", "action": "create" }`
- **WHEN** an ObjectUpdatedEvent fires for schema `3`
- **THEN** the webhook MUST NOT be delivered (action is `'update'`, not `'create'`)

#### Scenario: Filter with array values for multi-match
- **GIVEN** a webhook has filters `{ "action": ["create", "update"] }`
- **WHEN** an ObjectCreatedEvent fires
- **THEN** the webhook MUST be delivered because `'create'` is in the filter array `["create", "update"]`
- **AND** when an ObjectDeletedEvent fires (action `'delete'`), the webhook MUST NOT be delivered

#### Scenario: Empty filters match all events
- **GIVEN** a webhook has no filters configured (empty array or null)
- **WHEN** any event fires that the webhook is subscribed to
- **THEN** the webhook MUST be delivered regardless of payload content

### Requirement: Webhook delivery MUST implement retry with configurable backoff strategies
Failed webhook deliveries MUST be retried up to `maxRetries` times using the configured `retryPolicy` (exponential, linear, or fixed). The `WebhookRetryJob` cron job MUST poll for failed deliveries every 5 minutes and re-attempt delivery for entries whose `next_retry_at` timestamp has passed.

#### Scenario: Exponential backoff retry
- **GIVEN** a webhook with `retryPolicy: 'exponential'` and `maxRetries: 5`
- **WHEN** the first delivery attempt fails
- **THEN** the retry delay MUST be calculated as `2^attempt * 60` seconds (attempt 1 = 2 min, attempt 2 = 4 min, attempt 3 = 8 min, attempt 4 = 16 min, attempt 5 = 32 min)
- **AND** the `next_retry_at` timestamp MUST be stored in the `WebhookLog` entity
- **AND** the `WebhookRetryJob` (a `TimedJob` running every 300 seconds) MUST pick up the entry and call `WebhookService::deliverWebhook()` with the incremented attempt number

#### Scenario: Linear backoff retry
- **GIVEN** a webhook with `retryPolicy: 'linear'`
- **WHEN** retry is needed
- **THEN** the delay MUST be calculated as `attempt * 300` seconds (5 min, 10 min, 15 min, etc.)

#### Scenario: Max retries exceeded
- **GIVEN** a webhook with `maxRetries: 3` has failed 3 times
- **WHEN** the `WebhookRetryJob` evaluates the failed log entry
- **THEN** it MUST skip the entry with a warning log indicating retry limit exceeded
- **AND** the `WebhookLog` entry MUST remain in the database with `success: false` for admin inspection

#### Scenario: Webhook delivery statistics tracking
- **GIVEN** a webhook entity tracks `totalDeliveries`, `successfulDeliveries`, `failedDeliveries`, `lastTriggeredAt`, `lastSuccessAt`, `lastFailureAt`
- **WHEN** a delivery succeeds or fails
- **THEN** `WebhookMapper::updateStatistics()` MUST increment the appropriate counter and update the corresponding timestamp

### Requirement: Cross-app event consumption MUST work via standard Nextcloud IEventListener registration
Other Nextcloud apps (opencatalogi, docudesk, zaakafhandelapp, pipelinq, procest) MUST be able to listen for OpenRegister events by registering event listeners in their own `Application::register()` method using `IRegistrationContext::registerEventListener()`.

#### Scenario: OpenCatalogi listens for ObjectCreatedEvent
- **GIVEN** the `opencatalogi` app wants to update its catalog when a new listing object is created in OpenRegister
- **WHEN** `opencatalogi` registers `$context->registerEventListener(ObjectCreatedEvent::class, CatalogUpdateListener::class)` in its `Application::register()`
- **THEN** whenever OpenRegister dispatches an `ObjectCreatedEvent`, opencatalogi's `CatalogUpdateListener::handle()` MUST be invoked
- **AND** the listener MUST receive the full `ObjectEntity` via `$event->getObject()`

#### Scenario: Docudesk listens for ObjectUpdatedEvent to regenerate documents
- **GIVEN** docudesk generates PDF documents from register objects
- **WHEN** an `ObjectUpdatedEvent` is dispatched by OpenRegister
- **THEN** docudesk's registered listener MUST receive both the old and new object state via `$event->getOldObject()` and `$event->getNewObject()`
- **AND** can determine whether to regenerate the document based on which fields changed

#### Scenario: External app registration does not affect OpenRegister boot
- **GIVEN** three external apps each register listeners for OpenRegister events
- **WHEN** OpenRegister dispatches an event
- **THEN** Nextcloud's event dispatcher MUST invoke all registered listeners
- **AND** OpenRegister MUST NOT need any configuration or awareness of which external apps are listening
- **AND** listener instantiation MUST be lazy (deferred until event dispatch)

### Requirement: GraphQL subscription listeners MUST push events for real-time SSE delivery
The `GraphQLSubscriptionListener` MUST listen for `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectDeletedEvent` and push event data to the `SubscriptionService` buffer for Server-Sent Events (SSE) delivery to connected GraphQL subscription clients.

#### Scenario: Object creation pushed to SSE buffer
- **GIVEN** a GraphQL client has an active subscription for object mutations
- **WHEN** an `ObjectCreatedEvent` is dispatched
- **THEN** `GraphQLSubscriptionListener::handle()` MUST call `$this->subscriptionService->pushEvent('create', $event->getObject())`
- **AND** the SSE stream MUST deliver the event to connected clients

#### Scenario: Subscription listener failure does not block other listeners
- **GIVEN** the `SubscriptionService` throws an exception (e.g., no active subscriptions)
- **WHEN** `GraphQLSubscriptionListener::handle()` catches the exception
- **THEN** it MUST log a warning via `$this->logger->warning()` and return gracefully
- **AND** other listeners (Solr, webhook, hook) MUST still execute normally

#### Scenario: Delete events include full object snapshot for client reconciliation
- **GIVEN** a client subscribes to delete events
- **WHEN** an `ObjectDeletedEvent` is dispatched
- **THEN** the subscription service MUST receive the full object entity (pre-deletion snapshot) via `pushEvent('delete', $event->getObject())`
- **AND** the client MUST be able to identify which object was deleted and update its local state

### Requirement: Event listener isolation MUST prevent cascading failures
Each event listener MUST handle its own exceptions internally. A failure in one listener (e.g., Solr indexing error, webhook delivery timeout, subscription push failure) MUST NOT prevent other listeners from executing or cause the original database operation to fail.

#### Scenario: Solr indexing failure does not block webhook delivery
- **GIVEN** `SolrEventListener` and `WebhookEventListener` are both registered for `ObjectCreatedEvent`
- **WHEN** Solr is unreachable and `SolrEventListener` throws an exception
- **THEN** `WebhookEventListener` MUST still execute and deliver the webhook
- **AND** the object MUST still be persisted in the database

#### Scenario: WebhookEventListener catches and logs delivery errors
- **GIVEN** a webhook URL is unreachable
- **WHEN** `WebhookService::dispatchEvent()` encounters a `RequestException`
- **THEN** the error MUST be logged with full context (webhook ID, event name, error details, attempt number)
- **AND** the listener MUST return normally without throwing

#### Scenario: ObjectCleanupListener failure does not prevent deletion
- **GIVEN** `ObjectCleanupListener` fails to delete associated notes or tasks
- **WHEN** an `ObjectDeletedEvent` is dispatched
- **THEN** the object deletion MUST already be committed (the event is post-mutation)
- **AND** the cleanup failure MUST be logged but MUST NOT cause a rollback

### Requirement: Webhook entities MUST support event subscription configuration with wildcard matching
The `Webhook` entity's `events` field MUST store a JSON array of event class names or wildcard patterns. The `matchesEvent()` method MUST support exact class name matching and `fnmatch()` pattern matching. An empty events array MUST match all events.

#### Scenario: Webhook subscribes to specific event classes
- **GIVEN** a webhook with events `["OCA\\OpenRegister\\Event\\ObjectCreatedEvent", "OCA\\OpenRegister\\Event\\ObjectUpdatedEvent"]`
- **WHEN** an `ObjectCreatedEvent` fires
- **THEN** `Webhook::matchesEvent()` MUST return `true`
- **AND** when an `ObjectDeletedEvent` fires, it MUST return `false`

#### Scenario: Webhook uses wildcard pattern
- **GIVEN** a webhook with events `["OCA\\OpenRegister\\Event\\Object*Event"]`
- **WHEN** any object event fires (Created, Updated, Deleted, Locked, Reverted, etc.)
- **THEN** `matchesEvent()` MUST return `true` via `fnmatch()` matching
- **AND** when a `RegisterCreatedEvent` fires, it MUST return `false`

#### Scenario: Webhook with empty events array matches all events
- **GIVEN** a webhook with events `[]`
- **WHEN** any event type fires
- **THEN** `matchesEvent()` MUST return `true` (empty means "subscribe to all")

### Requirement: Schema hooks MUST be executed via HookListener and HookExecutor on object lifecycle events
The `HookListener` MUST load the schema for the object being mutated, check for configured hooks via `Schema::getHooks()`, and delegate execution to `HookExecutor::executeHooks()`. Hooks MUST run on both pre-mutation events (Creating, Updating, Deleting) and post-mutation events (Created, Updated, Deleted).

#### Scenario: Pre-mutation hook executes before persistence
- **GIVEN** schema `vergunningen` has hooks configured with `engine: 'n8n'` and `workflowId: 'wf-123'`
- **WHEN** an `ObjectCreatingEvent` is dispatched
- **THEN** `HookListener::handle()` MUST extract the object via `$event->getObject()`
- **AND** load the schema via `SchemaMapper::find()` using the object's schema ID
- **AND** call `HookExecutor::executeHooks()` with the event and schema
- **AND** if the hook calls `$event->stopPropagation()`, the object MUST NOT be persisted

#### Scenario: Post-mutation hook executes after persistence
- **GIVEN** schema `meldingen` has a notification hook configured for `after` events
- **WHEN** an `ObjectCreatedEvent` is dispatched
- **THEN** `HookListener::handle()` MUST still execute because HookListener is registered for post-mutation events too
- **AND** the hook can trigger external workflows (e.g., send notification via n8n) without affecting the already-persisted object

#### Scenario: Schema without hooks skips HookListener execution
- **GIVEN** schema `eenvoudig` has no hooks configured (empty `getHooks()` array)
- **WHEN** any object lifecycle event fires for an object with this schema
- **THEN** `HookListener::handle()` MUST return early after checking `empty($hooks)` without calling HookExecutor

### Requirement: HookRetryJob MUST re-execute failed hooks with exponential backoff and CloudEvents payload
When a schema hook fails because the workflow engine is unreachable (engine-down scenario with `onEngineDown: 'queue'`), the `HookRetryJob` MUST re-queue the hook execution as a `QueuedJob` with incrementing attempt numbers up to `MAX_RETRIES` (5).

#### Scenario: Failed hook is re-queued with incremented attempt
- **GIVEN** hook `validate-bsn` for object `obj-1` fails on attempt 1 because n8n is unreachable
- **WHEN** `HookRetryJob::run()` catches the exception
- **THEN** it MUST check `$attempt >= MAX_RETRIES` (5)
- **AND** if not exceeded, MUST call `$this->jobList->add(HookRetryJob::class, ...)` with `attempt: 2`

#### Scenario: Successful hook retry updates object validation status
- **GIVEN** hook retry for `obj-1` succeeds on attempt 3 with `WorkflowResult::isApproved()` returning true
- **WHEN** the hook result is processed
- **THEN** the object's `_validationStatus` MUST be set to `'passed'`
- **AND** `_validationErrors` MUST be removed from the object data
- **AND** the object MUST be updated via `MagicMapper::update()`

#### Scenario: Hook retry payload uses CloudEvents format
- **GIVEN** a hook retry is executing
- **WHEN** the payload is built for the workflow engine
- **THEN** `CloudEventFormatter::formatAsCloudEvent()` MUST produce a payload with `type: 'nl.openregister.object.hook-retry'` and `source: '/apps/openregister/schemas/{schemaId}'` and `subject: 'object:{objectUuid}'`

### Requirement: Event dispatch MUST be suppressible for bulk operations
When performing bulk imports or data migrations, the system MUST support suppressing event dispatch to avoid overwhelming listeners and maintain acceptable performance. The `MagicMapper::insertObjectEntity()` and `deleteObjectEntity()` methods MUST accept a `$dispatchEvents` parameter that defaults to `true`.

#### Scenario: Bulk import suppresses events
- **GIVEN** an admin imports 10,000 objects via the import API
- **WHEN** `MagicMapper::insertObjectEntity()` is called with `dispatchEvents: false`
- **THEN** no `ObjectCreatingEvent` or `ObjectCreatedEvent` MUST be dispatched
- **AND** the objects MUST still be persisted normally
- **AND** Solr indexing, webhook delivery, and hook execution MUST be skipped

#### Scenario: Individual operations always dispatch events by default
- **GIVEN** a user creates a single object via the API
- **WHEN** `MagicMapper::insert()` calls `insertObjectEntity()` with `dispatchEvents: true` (default)
- **THEN** all registered listeners MUST receive the events normally

#### Scenario: Bulk delete suppresses events for performance
- **GIVEN** an admin deletes all objects in a register
- **WHEN** `MagicMapper::deleteObjectEntity()` is called with `dispatchEvents: false` for each object
- **THEN** no `ObjectDeletingEvent` or `ObjectDeletedEvent` MUST be dispatched
- **AND** cleanup operations (notes, tasks, Solr removal) MUST be handled separately by the caller

### Requirement: Event payloads for webhook delivery MUST include register and schema context for object events
All object-related event payloads extracted by `WebhookEventListener` MUST include the `register` ID and `schema` ID alongside the serialized object data. This enables webhook consumers to route and filter events by register or schema without needing to parse the object data.

#### Scenario: ObjectCreatedEvent payload structure
- **GIVEN** an object is created in register `5`, schema `3`
- **WHEN** `WebhookEventListener::extractPayload()` processes the `ObjectCreatedEvent`
- **THEN** the payload MUST contain `register: 5`, `schema: 3`, `objectUuid: '{uuid}'`, and `timestamp: '{iso8601}'`

#### Scenario: ObjectUpdatingEvent payload includes old and new object
- **GIVEN** object `zaak-1` is being updated
- **WHEN** `WebhookEventListener::extractPayload()` processes the `ObjectUpdatingEvent`
- **THEN** the payload MUST contain `newObject` (serialized new state) and `oldObject` (serialized old state, nullable)
- **AND** the `register` and `schema` MUST be extracted from the new object

#### Scenario: Non-object event payloads use their entity's serialization
- **GIVEN** a Schema is deleted
- **WHEN** `WebhookEventListener::extractPayload()` processes the `SchemaDeletedEvent`
- **THEN** the payload MUST contain `objectType: 'schema'`, `action: 'deleted'`, and the schema's `jsonSerialize()` output under the `schema` key

### Requirement: Request interception MUST support pre-mutation webhook notifications
The `WebhookService::interceptRequest()` method MUST find webhooks configured with `configuration.interceptRequests: true`, format the incoming HTTP request as a CloudEvents payload using `CloudEventFormatter::formatRequestAsCloudEvent()`, and deliver it to the configured endpoint before the controller processes the request.

#### Scenario: Pre-request webhook intercepts object creation
- **GIVEN** a webhook is configured with `interceptRequests: true` and listens for `object.creating`
- **WHEN** a POST request to `/api/objects/{register}/{schema}` is received
- **THEN** `WebhookService::interceptRequest()` MUST find the matching webhook
- **AND** deliver a CloudEvents payload containing the request method, path, query params, headers, and body
- **AND** the `subject` field MUST be extracted from the request path (e.g., `object:5/3/uuid`)

#### Scenario: Multiple interception webhooks are processed independently
- **GIVEN** three webhooks are configured for request interception
- **WHEN** a request is intercepted
- **THEN** each webhook MUST be delivered independently in a loop
- **AND** if one webhook fails, the others MUST still be processed (`continue` on exception)

#### Scenario: No interception webhooks means passthrough
- **GIVEN** no webhooks are configured with `interceptRequests: true`
- **WHEN** `interceptRequest()` is called
- **THEN** it MUST return the original request params immediately without any HTTP calls

## Current Implementation Status
- **Implemented:**
  - 39+ typed event classes in `lib/Event/` covering all entity types with Created/Updated/Deleted patterns, plus specialized events (ObjectCreatingEvent, ObjectUpdatingEvent, ObjectDeletingEvent with StoppableEventInterface; ObjectLockedEvent, ObjectUnlockedEvent, ObjectRevertedEvent; ToolRegistrationEvent, DeepLinkRegistrationEvent, UserProfileUpdatedEvent)
  - 8 event listeners in `lib/Listener/`: WebhookEventListener, HookListener, ObjectChangeListener, ObjectCleanupListener, FileChangeListener, GraphQLSubscriptionListener, CommentsEntityListener, ToolRegistrationListener
  - 3 event listeners in `lib/EventListener/`: SolrEventListener, AbstractNodeFolderEventListener, AbstractNodesFolderEventListener
  - Full event registration in `Application::registerEventListeners()` with lazy loading via `IRegistrationContext`
  - `CloudEventFormatter` (`lib/Service/Webhook/CloudEventFormatter.php`) producing CloudEvents v1.0 payloads with UUID v4 IDs
  - `WebhookService` with three payload strategies (Mapping, CloudEvents, Standard), HMAC signing, filter matching with dot-notation
  - `Webhook` entity with events, filters, retry policy (exponential/linear/fixed), max retries, timeout, HMAC secret, mapping reference, and delivery statistics
  - `WebhookLog` entity for delivery logging with attempt tracking and `next_retry_at`
  - `WebhookRetryJob` (TimedJob, 5-min interval) for cron-based retry of failed deliveries
  - `WebhookDeliveryJob` (QueuedJob) for async webhook delivery
  - `HookRetryJob` (QueuedJob) for retrying failed schema hooks with CloudEvents payload
  - `HookListener` delegating to `HookExecutor` for schema hook execution
  - Pre-mutation events with `StoppableEventInterface` for rejection and data modification
  - `dispatchEvents` parameter on `insertObjectEntity()` and `deleteObjectEntity()` for bulk operation suppression
  - `GraphQLSubscriptionListener` pushing events to SSE buffer
  - Request interception via `WebhookService::interceptRequest()` with CloudEvents formatting
  - `ObjectCleanupListener` for deleting notes/tasks on object deletion
  - `ObjectChangeListener` for text extraction queueing (immediate, background, cron, manual modes)
- **NOT implemented:**
  - Correlation identifiers for cascade operations (threading a request-scoped UUID through events triggered by the same user action)
  - Dead-letter queue entity with admin inspection UI and manual retry capability
  - Event history storage and query API for replay and debugging (events are delivered but not retained for replay)
  - Configurable event retention period
  - Formal `nl.openregister.object.created` event type naming convention for CloudEvents `type` field (currently uses fully qualified PHP class names)
  - WebhookEventListener only registered for `ObjectCreatedEvent` -- other event types (Updated, Deleted, schema events, etc.) are handled by the listener's `extractPayload()` method but not explicitly registered in `Application.php`

## Standards & References
- **CloudEvents v1.0 (CNCF)** -- https://cloudevents.io/ -- Event format specification
- **CloudEvents HTTP Protocol Binding** -- HTTP delivery with `ce-*` headers
- **Nextcloud IEventDispatcher** -- `OCP\EventDispatcher\IEventDispatcher` for typed event dispatch
- **Nextcloud IEventListener** -- `OCP\EventDispatcher\IEventListener` interface for listener implementation
- **PSR-14 StoppableEventInterface** -- `Psr\EventDispatcher\StoppableEventInterface` for pre-mutation event rejection
- **Nextcloud IBootstrap** -- `IRegistrationContext::registerEventListener()` for lazy listener registration
- **Webhook HMAC Signatures** -- SHA-256 HMAC for payload integrity verification
- **Notificatierouteringscomponent (NRC)** -- VNG standard for notification routing in Dutch government

## Cross-References
- **notificatie-engine** -- Uses the event bus to trigger notification workflows; consumes ObjectCreatedEvent/ObjectUpdatedEvent
- **webhook-payload-mapping** -- The Mapping entity referenced by `Webhook.mapping` enables custom payload transformations via `MappingService::executeMapping()`
- **schema-hooks** -- Schema-level hooks are executed by `HookListener` on object lifecycle events; hook configuration in `Schema::getHooks()` drives `HookExecutor`
- **workflow-integration** -- `WorkflowEngineRegistry`, `N8nAdapter`, `WindmillAdapter` provide the execution backends for hooks; `HookRetryJob` uses these adapters for retry

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: The event-driven architecture is built on 39+ custom typed event classes in `lib/Event/` covering Object, Register, Schema, Source, Configuration, View, Agent, Application, Conversation, and Organisation lifecycle operations. Eleven listeners handle these events across two namespaces (`lib/Listener/` and `lib/EventListener/`) for webhooks, Solr indexing, schema hook execution, text extraction, GraphQL subscriptions, note/task cleanup, file change detection, and tool registration. All event listener bindings are declared in `Application::registerEventListeners()` using Nextcloud's lazy-loading `IRegistrationContext`. Pre-mutation events (ObjectCreatingEvent, ObjectUpdatingEvent, ObjectDeletingEvent) implement `StoppableEventInterface` to allow hooks to reject or modify operations before persistence. Post-mutation events carry the full entity state for downstream consumption.

**Nextcloud Core Integration**: All custom events extend `OCP\EventDispatcher\Event` and are dispatched via `IEventDispatcher::dispatchTyped()`. Listeners implement `OCP\EventDispatcher\IEventListener`. This makes every OpenRegister event natively consumable by any other Nextcloud app by simply registering a listener in their `Application::register()`. The typed event approach ensures compile-time type safety and IDE discoverability. Webhook delivery uses Nextcloud's `IJobList` with `QueuedJob` (WebhookDeliveryJob) and `TimedJob` (WebhookRetryJob) for async processing. The pre-mutation pattern (Creating/Updating/Deleting events with StoppableEventInterface) follows PSR-14 and integrates cleanly with Nextcloud's event propagation model.

**Recommendation**: The event system is production-ready and well-integrated with Nextcloud's core infrastructure. Key improvements to consider: (1) Register WebhookEventListener for all event types in Application.php, not just ObjectCreatedEvent, to ensure webhook delivery for updates, deletes, and non-object events. (2) Add correlation IDs by generating a request-scoped UUID in middleware and threading it through all events dispatched within the same request. (3) Standardize the CloudEvents `type` field to use `nl.openregister.{entity}.{action}` format instead of PHP class names. (4) Implement a dead-letter queue entity for failed webhook deliveries with an admin-facing UI for inspection and manual retry.
