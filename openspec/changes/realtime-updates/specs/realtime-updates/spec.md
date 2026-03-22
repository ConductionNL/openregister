---
status: draft
---
# Realtime Updates

## Purpose
Provide live data synchronization to connected clients so that register object mutations (create, update, delete) are pushed immediately without manual page refresh. The system MUST offer Server-Sent Events (SSE) as the primary transport, with Nextcloud's notify_push integration as a complementary channel, and graceful fallback to polling. All realtime channels MUST be authorization-aware, meaning users only receive events for objects their RBAC permissions allow them to see, and MUST support topic-based subscriptions at the register, schema, and individual object level.

**Source**: Gap identified in cross-platform analysis; PocketBase provides SSE-based realtime subscriptions per collection/record with auth-aware filtering, Directus offers WebSocket connectivity with UID-based subscription management and permission-filtered broadcasts, and five platforms total offer real-time capabilities. See also: `event-driven-architecture` (CloudEvents format, event bus transports), `webhook-payload-mapping` (payload transformation via Twig mappings), `notificatie-engine` (notification channels and batching).

## ADDED Requirements

### Requirement: The system MUST provide a dedicated SSE endpoint for object change events
A Server-Sent Events endpoint MUST stream object change events (create, update, delete) to connected clients in real time. The endpoint MUST follow the W3C Server-Sent Events specification and use `text/event-stream` content type. The endpoint MUST be separate from the existing GraphQL subscription controller, providing a REST-native channel at `/api/sse/{register}/{schema}`.

#### Scenario: Client connects to SSE endpoint and receives create event
- **GIVEN** a client is connected to `GET /api/sse/zaken/meldingen` with `Accept: text/event-stream`
- **WHEN** another user creates a new melding object with UUID `melding-new-1`
- **THEN** the connected client MUST receive an SSE message with:
  - `id`: a monotonically increasing event ID (e.g., `evt_000042`)
  - `event`: `object.created`
  - `data`: a JSON object containing `uuid`, `register`, `schema`, `action`, `timestamp` (ISO 8601), and `object` (the full object data including all properties)

#### Scenario: Client receives update event with changed fields
- **GIVEN** a client is connected to `GET /api/sse/zaken/meldingen`
- **WHEN** melding `melding-1` is updated (status changed from `nieuw` to `in_behandeling`)
- **THEN** the client MUST receive an SSE message with:
  - `event`: `object.updated`
  - `data`: JSON containing the object UUID, full updated object data, and a `changed` array listing the modified field names (e.g., `["status"]`)

#### Scenario: Client receives delete event
- **GIVEN** a client is connected to `GET /api/sse/zaken/meldingen`
- **WHEN** object `melding-5` is deleted
- **THEN** the client MUST receive an SSE message with:
  - `event`: `object.deleted`
  - `data`: JSON containing only the deleted object's UUID, register, and schema (no full object data, as the object no longer exists)

#### Scenario: SSE response headers are correctly set
- **GIVEN** a client sends `GET /api/sse/zaken/meldingen`
- **WHEN** the server accepts the connection
- **THEN** the response MUST include headers:
  - `Content-Type: text/event-stream`
  - `Cache-Control: no-cache`
  - `Connection: keep-alive`
  - `X-Accel-Buffering: no` (to prevent nginx buffering)

### Requirement: The SSE endpoint MUST support topic-based channel subscriptions
Clients MUST be able to subscribe at three granularity levels: all changes in a register, all changes in a specific schema within a register, or changes to a single object. The URL pattern MUST determine the subscription scope.

#### Scenario: Subscribe to all changes in a register
- **GIVEN** the client connects to `GET /api/sse/zaken`
- **WHEN** objects are created in schemas `meldingen`, `vergunningen`, and `vertrouwelijk` within register `zaken`
- **THEN** the client MUST receive events for all three schemas (subject to RBAC filtering)

#### Scenario: Subscribe to a specific schema
- **GIVEN** the client connects to `GET /api/sse/zaken/meldingen`
- **WHEN** objects are created in both `meldingen` and `vergunningen`
- **THEN** the client MUST only receive events for `meldingen`
- **AND** events for `vergunningen` MUST NOT be delivered on this connection

#### Scenario: Subscribe to a specific object
- **GIVEN** the client connects to `GET /api/sse/zaken/meldingen/melding-uuid-123`
- **WHEN** `melding-uuid-123` is updated and `melding-uuid-456` is also updated
- **THEN** the client MUST only receive the update event for `melding-uuid-123`
- **AND** this subscription level MUST be used for detail view real-time updates

#### Scenario: Subscribe to multiple topics via query parameter
- **GIVEN** the client connects to `GET /api/sse?topics=zaken/meldingen,zaken/vergunningen`
- **WHEN** events occur in both schemas
- **THEN** the client MUST receive events from both subscribed topics on a single SSE connection
- **AND** each event's data MUST include the source `register` and `schema` for client-side routing

### Requirement: SSE events MUST be authorization-aware via RBAC filtering
Clients MUST only receive events for objects they are authorized to access. The RBAC check MUST be performed server-side before event delivery, using the same `PermissionHandler.hasPermission()` logic used for REST API access control.

#### Scenario: Events filtered by schema-level read permission
- **GIVEN** user `medewerker-1` has read access to schema `meldingen` but NOT to schema `vertrouwelijk`
- **AND** user `medewerker-1` is connected to `GET /api/sse/zaken` (register-level subscription)
- **WHEN** an object is created in schema `vertrouwelijk`
- **THEN** `medewerker-1` MUST NOT receive the creation event
- **AND** no indication that the event occurred MUST be leaked (no empty event, no event count change)

#### Scenario: Events delivered for all authorized schemas
- **GIVEN** user `behandelaar-1` has read access to schemas `meldingen` and `vergunningen`
- **AND** user `behandelaar-1` is connected to `GET /api/sse/zaken`
- **WHEN** objects are created in both schemas simultaneously
- **THEN** `behandelaar-1` MUST receive events for both schemas

#### Scenario: Multi-tenancy filtering on events
- **GIVEN** multi-tenancy is enabled and user `org-a-user` belongs to organization `org-a`
- **AND** user `org-a-user` is connected to `GET /api/sse/zaken/meldingen`
- **WHEN** a melding owned by organization `org-b` is created
- **THEN** `org-a-user` MUST NOT receive the event
- **AND** events for `org-a` meldingen MUST be delivered normally

#### Scenario: Admin user receives all events regardless of RBAC
- **GIVEN** an admin user is connected to `GET /api/sse/zaken`
- **WHEN** objects are created across all schemas including restricted ones
- **THEN** the admin MUST receive events for all schemas without filtering

### Requirement: The SSE endpoint MUST support authentication
SSE connections MUST be authenticated using the same mechanisms as the REST API. The endpoint MUST support Nextcloud session cookies, Bearer token authentication, and Basic authentication for API consumers.

#### Scenario: Authenticate via Nextcloud session cookie
- **GIVEN** a user is logged into the Nextcloud web interface
- **WHEN** the frontend JavaScript creates an `EventSource` connection to `/api/sse/zaken/meldingen`
- **THEN** the browser MUST send the session cookie automatically
- **AND** the SSE endpoint MUST authenticate the user via the Nextcloud session

#### Scenario: Authenticate via Bearer token
- **GIVEN** an external client has a valid API token
- **WHEN** the client connects to the SSE endpoint with `Authorization: Bearer <token>`
- **THEN** the connection MUST be authenticated and events delivered according to the token's permissions
- **AND** if the `EventSource` API does not support custom headers, the token MUST be accepted as a query parameter `?token=<token>`

#### Scenario: Reject unauthenticated SSE connections
- **GIVEN** a client connects to `GET /api/sse/zaken/meldingen` without any authentication
- **WHEN** the server processes the connection
- **THEN** the server MUST respond with HTTP 401 Unauthorized
- **AND** no SSE stream MUST be opened

### Requirement: SSE connections MUST support automatic reconnection with event replay
The SSE client MUST automatically reconnect after connection drops and the server MUST replay missed events using the `Last-Event-ID` header, as specified by the W3C SSE standard.

#### Scenario: Reconnect and replay after network interruption
- **GIVEN** a client is connected to the SSE endpoint and has received events up to ID `evt_000042`
- **WHEN** the connection drops and the client reconnects
- **THEN** the client's `EventSource` MUST automatically send `Last-Event-ID: evt_000042`
- **AND** the server MUST replay all buffered events after `evt_000042` that match the subscription filter
- **AND** the server MUST then resume live streaming

#### Scenario: Event buffer retention window
- **GIVEN** the server maintains an event buffer for reconnection support
- **THEN** the buffer MUST retain events for at least 5 minutes (configurable via `app_config` key `sse_buffer_ttl`)
- **AND** the buffer MUST hold at most 1000 events (configurable via `app_config` key `sse_buffer_max_size`)
- **AND** when both limits are reached, the oldest events MUST be evicted first

#### Scenario: Reconnection beyond buffer window triggers full refresh signal
- **GIVEN** a client reconnects with `Last-Event-ID: evt_000010`
- **AND** `evt_000010` is older than the buffer retention window (no longer in the buffer)
- **WHEN** the server processes the reconnection
- **THEN** the server MUST send a special event with `event: refresh` and `data: {"reason": "buffer_expired"}`
- **AND** the client MUST perform a full data refresh by re-fetching the object list from the REST API

#### Scenario: Monotonically increasing event IDs
- **GIVEN** events are published to the buffer
- **THEN** each event ID MUST be monotonically increasing within the buffer lifetime
- **AND** the ID format MUST be a string sortable by lexicographic order (e.g., zero-padded numeric or timestamp-based: `evt_1710849600_000042`)

### Requirement: The system MUST support connection health via heartbeat
The SSE endpoint MUST send periodic heartbeat comments to detect stale connections and prevent intermediary proxies from closing idle connections.

#### Scenario: Regular heartbeat during idle periods
- **GIVEN** a client is connected to the SSE endpoint
- **AND** no object change events have occurred for 15 seconds
- **WHEN** the heartbeat interval elapses
- **THEN** the server MUST send an SSE comment line `: heartbeat\n\n`
- **AND** the heartbeat interval MUST be configurable (default: 15 seconds)

#### Scenario: Server detects client disconnection
- **GIVEN** a client connected to the SSE endpoint disconnects (closes browser tab, network failure)
- **WHEN** the server attempts to write the next heartbeat or event
- **THEN** the server MUST detect the broken connection via `connection_aborted()`
- **AND** the server MUST terminate the SSE loop and release resources (PHP process, memory)

#### Scenario: Connection duration limit for PHP process management
- **GIVEN** the SSE endpoint runs as a long-lived PHP process
- **WHEN** the connection has been open for 30 seconds (default, configurable via `sse_max_duration`)
- **THEN** the server MUST gracefully close the connection by stopping the event loop
- **AND** the client's `EventSource` MUST automatically reconnect (per W3C SSE spec)
- **AND** the reconnection MUST use `Last-Event-ID` to resume without data loss

### Requirement: The system MUST debounce and batch rapid changes
When multiple mutations happen in rapid succession (e.g., bulk imports, batch updates), the system MUST debounce events to prevent flooding connected clients with hundreds of individual events.

#### Scenario: Debounce rapid updates to the same object
- **GIVEN** a client is connected to `GET /api/sse/zaken/meldingen`
- **AND** object `melding-1` is updated 5 times within 500ms (e.g., by a bulk update script)
- **WHEN** the debounce window (500ms, configurable) closes
- **THEN** the client MUST receive a single `object.updated` event containing the final state of the object
- **AND** the event's `data.batchedCount` field MUST indicate `5` to show updates were coalesced

#### Scenario: Batch multiple object creations into a digest event
- **GIVEN** a client is connected to `GET /api/sse/zaken/meldingen`
- **AND** 50 meldingen are created in a single bulk import within 2 seconds
- **WHEN** the batch window closes
- **THEN** the client MUST receive a single `objects.batch` event with:
  - `data.action`: `created`
  - `data.count`: `50`
  - `data.objects`: array of UUIDs
- **AND** the client SHOULD refresh its list view by re-fetching from the REST API

#### Scenario: Individual events for low-frequency changes
- **GIVEN** a client is connected to `GET /api/sse/zaken/meldingen`
- **AND** two meldingen are created 10 seconds apart
- **WHEN** each creation occurs
- **THEN** each MUST be delivered as an individual `object.created` event (no batching)

### Requirement: The event payload format MUST follow CloudEvents conventions
SSE event payloads MUST be structured following the CloudEvents v1.0 conventions established in the `event-driven-architecture` spec, ensuring consistency across SSE, webhooks, and internal event dispatch.

#### Scenario: SSE event payload structure
- **GIVEN** a client is connected to the SSE endpoint
- **WHEN** an `object.created` event is delivered
- **THEN** the `data` field MUST be a JSON object with:
  - `specversion`: `"1.0"`
  - `type`: `"nl.openregister.object.created"`
  - `source`: `"/registers/{registerId}/schemas/{schemaId}"`
  - `id`: the event's unique ID (same as the SSE `id` field)
  - `time`: ISO 8601 timestamp
  - `subject`: the object UUID
  - `datacontenttype`: `"application/json"`
  - `data`: the object data (properties, metadata)

#### Scenario: Webhook mapping transformation applies to SSE payloads
- **GIVEN** a schema has a configured Mapping entity for payload transformation (per `webhook-payload-mapping` spec)
- **WHEN** an SSE event is prepared for delivery
- **THEN** the SSE payload MUST use the raw CloudEvents format (mappings are for webhook delivery only)
- **AND** the SSE `data` field MUST always contain the canonical CloudEvents structure

#### Scenario: Event includes correlation ID for cascade operations
- **GIVEN** deleting a person triggers CASCADE deletion of 3 related orders (per `event-driven-architecture` spec)
- **WHEN** the 4 events are pushed to the SSE buffer
- **THEN** all 4 events MUST share the same `correlationId` extension attribute
- **AND** the client MUST be able to group related events by correlation ID

### Requirement: The system SHOULD integrate with Nextcloud notify_push for native push delivery
As a complementary channel to SSE, the system SHALL publish object change events through Nextcloud's notify_push app (when installed) to deliver instant notifications to Nextcloud desktop and mobile clients via WebSocket.

#### Scenario: Push notification via notify_push on object creation
- **GIVEN** the notify_push app is installed and configured
- **AND** user `behandelaar-1` is connected to Nextcloud via the desktop client
- **WHEN** a melding assigned to `behandelaar-1` is created
- **THEN** a push notification MUST be sent via notify_push
- **AND** the Nextcloud desktop client MUST display the notification

#### Scenario: Graceful degradation without notify_push
- **GIVEN** the notify_push app is NOT installed
- **WHEN** object change events occur
- **THEN** SSE delivery MUST function normally without errors
- **AND** no push notifications MUST be attempted
- **AND** no error logs MUST be generated about missing notify_push

#### Scenario: Notification includes deep link to object
- **GIVEN** a push notification is delivered via notify_push
- **WHEN** the user clicks the notification
- **THEN** the user MUST be navigated to the object's detail view in the OpenRegister UI
- **AND** the deep link MUST follow the pattern `/apps/openregister/#/registers/{register}/schemas/{schema}/objects/{objectUuid}`

### Requirement: The system MUST support fallback to polling when SSE is unavailable
When SSE connections cannot be established (corporate proxies, browser limitations, PHP configuration), the client MUST gracefully fall back to periodic polling of the REST API.

#### Scenario: Automatic fallback after SSE connection failure
- **GIVEN** the client attempts to connect to the SSE endpoint
- **AND** the connection fails 3 consecutive times (timeout, HTTP error, or `EventSource.onerror`)
- **WHEN** the third failure occurs
- **THEN** the client MUST switch to polling mode
- **AND** the polling interval MUST be 30 seconds (configurable)
- **AND** a console warning MUST be logged: `"SSE unavailable, falling back to polling"`

#### Scenario: Polling detects changes via ETag or Last-Modified
- **GIVEN** the client is in polling fallback mode
- **WHEN** the client polls `GET /api/objects/{register}/{schema}` with `If-None-Match: "<previous-etag>"`
- **THEN** the server MUST respond with HTTP 304 Not Modified if no changes occurred
- **AND** the server MUST respond with HTTP 200 and the updated object list if changes occurred

#### Scenario: Automatic SSE reconnection attempt after polling period
- **GIVEN** the client is in polling fallback mode
- **WHEN** 5 minutes have elapsed since the last SSE failure
- **THEN** the client MUST attempt to re-establish the SSE connection
- **AND** if successful, polling MUST stop and SSE MUST resume

### Requirement: The frontend MUST auto-refresh views when realtime events arrive
List views, detail views, and dashboard widgets MUST automatically update their displayed data when relevant SSE events are received, without requiring a manual page refresh.

#### Scenario: Auto-refresh list view on object creation
- **GIVEN** the user is viewing the meldingen list showing 10 objects
- **AND** the list view is connected to the SSE endpoint for schema `meldingen`
- **WHEN** another user creates a new melding
- **THEN** the list MUST add the new melding to the displayed results without manual refresh
- **AND** a subtle highlight animation SHOULD indicate the newly added entry
- **AND** the list's total count MUST update accordingly

#### Scenario: Auto-refresh detail view on object update
- **GIVEN** the user is viewing the detail of `melding-1`
- **AND** the detail view is connected to the SSE endpoint for object `melding-1`
- **WHEN** another user updates `melding-1`'s status from `nieuw` to `in_behandeling`
- **THEN** the detail view MUST update the status field in place
- **AND** a brief banner SHOULD appear: `"Dit object is bijgewerkt door [user]"` (translated)
- **AND** if the user has unsaved local edits, a conflict dialog MUST appear instead of silently overwriting

#### Scenario: Handle deleted object in active detail view
- **GIVEN** the user is viewing the detail of `melding-5`
- **WHEN** `melding-5` is deleted by another user
- **THEN** the UI MUST display a notice: `"Dit object is verwijderd"` (translated via i18n)
- **AND** all editing controls MUST be disabled
- **AND** a button MUST offer to navigate back to the list view

#### Scenario: Dashboard widget updates in real time
- **GIVEN** a dashboard widget displays the count of open meldingen (currently 42)
- **WHEN** a new melding is created
- **THEN** the widget MUST update the count to 43 without page refresh

### Requirement: The frontend MUST use a reactive store pattern for realtime state management
The frontend SSE integration MUST be implemented as a composable or store that manages the EventSource connection lifecycle, dispatches events to the correct Vue components, and handles cross-tab coordination.

#### Scenario: Composable manages EventSource lifecycle
- **GIVEN** a Vue component mounts and calls `useRealtimeUpdates('zaken', 'meldingen')`
- **WHEN** the component is mounted
- **THEN** the composable MUST open an `EventSource` connection to `/api/sse/zaken/meldingen`
- **AND** when the component is unmounted, the composable MUST close the `EventSource` connection
- **AND** if multiple components subscribe to the same topic, a single `EventSource` connection MUST be shared

#### Scenario: Cross-tab event coordination via BroadcastChannel
- **GIVEN** the user has the OpenRegister app open in 3 browser tabs
- **AND** each tab has an SSE connection to the same endpoint
- **WHEN** a realtime event arrives
- **THEN** only ONE tab MUST maintain the active SSE connection (leader election)
- **AND** the leader tab MUST forward events to other tabs via `BroadcastChannel` API
- **AND** if the leader tab is closed, another tab MUST take over the SSE connection

#### Scenario: Connection shared across components via reference counting
- **GIVEN** component A subscribes to `zaken/meldingen` and component B also subscribes to `zaken/meldingen`
- **WHEN** component A unmounts
- **THEN** the SSE connection MUST remain open (component B still needs it)
- **AND** when component B also unmounts, the SSE connection MUST be closed

### Requirement: The system MUST perform acceptably under concurrent connection load
The SSE implementation MUST handle a reasonable number of concurrent connections without degrading server performance. Given PHP's process-per-request model, specific limits and mitigations MUST be defined.

#### Scenario: Concurrent connection limit per server
- **GIVEN** the server is configured with Apache/PHP-FPM with 50 worker processes
- **WHEN** 20 users each have an active SSE connection (20 long-lived PHP processes)
- **THEN** the remaining 30 worker processes MUST be available for regular API requests
- **AND** the system MUST enforce a configurable maximum SSE connection limit (default: 50% of worker pool)

#### Scenario: Event buffer uses Redis when available for cross-process consistency
- **GIVEN** the Nextcloud instance runs with multiple PHP-FPM worker processes
- **AND** Redis is configured as the Nextcloud cache backend (`OCP\ICache`)
- **WHEN** an object mutation occurs in worker process A
- **THEN** the event MUST be written to the Redis-backed event buffer
- **AND** worker process B serving an SSE connection MUST see the new event on its next poll cycle
- **AND** if Redis is not available, the system MUST fall back to APCu (current behavior, with the known limitation that events may be missed across processes)

#### Scenario: APCu fallback with documented limitations
- **GIVEN** Redis is NOT configured and APCu is used for the event buffer
- **WHEN** the SSE endpoint documentation is rendered
- **THEN** the admin settings page MUST display a warning: `"APCu event buffer is per-process; consider configuring Redis for reliable cross-process SSE delivery"`

### Requirement: The SSE event payload MUST support subscription filtering via query parameters
Beyond URL-path-based topic selection, clients MUST be able to filter events by event type, property conditions, or object attributes using query parameters on the SSE endpoint.

#### Scenario: Filter by event type
- **GIVEN** a client connects to `GET /api/sse/zaken/meldingen?events=object.created,object.updated`
- **WHEN** a delete event occurs for a melding
- **THEN** the client MUST NOT receive the delete event
- **AND** create and update events MUST be delivered normally

#### Scenario: Filter by object property value
- **GIVEN** a client connects to `GET /api/sse/zaken/meldingen?filter[status]=in_behandeling`
- **WHEN** a melding with `status=nieuw` is created
- **THEN** the client MUST NOT receive the event
- **AND** when a melding with `status=in_behandeling` is created, the client MUST receive the event

#### Scenario: No filters delivers all events
- **GIVEN** a client connects to `GET /api/sse/zaken/meldingen` without any query parameters
- **WHEN** create, update, and delete events occur
- **THEN** all events MUST be delivered (no filtering applied)

## Current Implementation Status

**Partially implemented via GraphQL Subscriptions:**
- `lib/Controller/GraphQLSubscriptionController.php` -- SSE-based subscription controller with 30-second polling loop, heartbeat comments, `Last-Event-ID` reconnection support, schema/register query parameter filtering
- `lib/Service/GraphQL/SubscriptionService.php` -- Event buffer in APCu with 5-minute TTL, 1000-event max buffer, RBAC filtering via `PermissionHandler.hasPermission()`, `filterEventStream()` for schema/register filtering, `formatAsSSE()` for SSE message formatting
- `lib/Listener/GraphQLSubscriptionListener.php` -- Listens to `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` and pushes to APCu buffer via `SubscriptionService.pushEvent()`
- Registered in `lib/AppInfo/Application.php` lines 744-745 for ObjectCreated and ObjectUpdated events

**What IS implemented:**
- SSE streaming endpoint with `text/event-stream` content type and correct headers
- APCu-based event buffer with TTL (300s) and max size (1000) eviction
- RBAC filtering: `verifyEventRBAC()` checks `PermissionHandler.hasPermission()` per event
- Schema and register filtering via query parameters
- `Last-Event-ID` reconnection with event replay from buffer
- Heartbeat comments every poll cycle (1 second)
- Connection abort detection via `connection_aborted()`
- 30-second max connection duration to manage PHP process lifecycle
- Event payload includes object UUID, register, schema, owner, and full object data (for create/update)

**What is NOT implemented:**
- Dedicated `/api/sse/{register}/{schema}` REST endpoints (current endpoint is GraphQL-specific at a different route)
- Monotonically increasing event IDs (current uses `uniqid('gql_', true)` which is not monotonic)
- Topic-based URL pattern subscriptions (register-level, schema-level, object-level)
- Multi-topic subscription via query parameters (`?topics=...`)
- Event type filtering via query parameters (`?events=...`)
- Property-based subscription filtering (`?filter[status]=...`)
- Debouncing/batching of rapid changes
- CloudEvents payload format (current payload is custom, not CloudEvents v1.0)
- Correlation IDs for cascade operations
- Redis-backed event buffer for cross-process consistency (APCu only)
- Nextcloud notify_push integration
- Frontend composable/store for EventSource lifecycle management
- Cross-tab coordination via BroadcastChannel
- Polling fallback logic in the frontend
- Auto-refresh of list views, detail views, and dashboard widgets
- Conflict detection for concurrent edits in detail view
- `objects.batch` digest events for bulk operations
- Configurable heartbeat interval (hardcoded at 1 second)
- Admin settings page warning for APCu vs Redis
- Bearer token authentication support for SSE (query parameter token)
- Connection limit enforcement

## Standards & References
- **W3C Server-Sent Events specification** -- https://html.spec.whatwg.org/multipage/server-sent-events.html
- **EventSource Web API** -- https://developer.mozilla.org/en-US/docs/Web/API/EventSource
- **CloudEvents v1.0 (CNCF)** -- https://cloudevents.io/ (payload format, per `event-driven-architecture` spec)
- **BroadcastChannel API** -- https://developer.mozilla.org/en-US/docs/Web/API/BroadcastChannel (cross-tab coordination)
- **Nextcloud notify_push** -- https://github.com/nextcloud/notify_push (WebSocket push for NC clients)
- **Nextcloud INotificationManager** -- `OCP\Notification\IManager` (in-app notification integration)
- **PocketBase Realtime** -- SSE subscriptions per collection/record with auth-aware filtering, 5-min idle timeout, client chunking (competitor reference)
- **Directus WebSockets** -- UID-based subscription management, permission-filtered broadcasts, heartbeat configuration (competitor reference)
- **GraphQL Subscriptions over SSE** -- Current partial implementation pattern in OpenRegister

## Specificity Assessment
- **Specific enough to implement?** Yes -- all 14 requirements have concrete scenarios with GIVEN/WHEN/THEN, specific URL patterns, payload structures, and configuration keys.
- **Builds on existing code:** The GraphQL subscription infrastructure (`SubscriptionService`, `GraphQLSubscriptionListener`, `GraphQLSubscriptionController`) provides a working foundation. The primary work is: (1) extract the SSE logic from the GraphQL-specific controller into a dedicated REST endpoint, (2) switch from `uniqid()` to monotonic IDs, (3) add Redis backend option alongside APCu, (4) implement frontend composable with cross-tab coordination.
- **Dependencies:** Requires `event-driven-architecture` spec for CloudEvents format and correlation IDs. References `webhook-payload-mapping` for payload transformation distinction (SSE always uses raw CloudEvents, mappings are webhook-only).
- **Open questions resolved:**
  - GraphQL subscription infrastructure SHOULD be extended (not replaced) -- the dedicated REST SSE endpoint reuses `SubscriptionService` internally
  - WebSocket support is deferred to notify_push integration rather than a custom implementation (PHP is not suited for persistent WebSocket connections)
  - ExApp sidecar deployment: SSE endpoints run in the PHP process; ExApp Python sidecars can proxy SSE via reverse proxy or consume the SSE endpoint as a client

## Nextcloud Integration Analysis

**Status**: Partially Implemented

**Existing Implementation**: `GraphQLSubscriptionController` provides a functional SSE endpoint with APCu-buffered events, RBAC filtering via `PermissionHandler`, and `Last-Event-ID` reconnection support. `SubscriptionService` manages the event buffer with 5-minute TTL and 1000-event cap. `GraphQLSubscriptionListener` captures `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectDeletedEvent` via Nextcloud's `IEventDispatcher` and pushes them to the APCu buffer. The `NotificationService` already integrates with Nextcloud's `INotificationManager` for in-app notifications, providing a foundation for notify_push integration.

**Nextcloud Core Integration**: The SSE implementation works within Nextcloud's PHP request model, though long-lived PHP processes are resource-intensive. The 30-second connection limit is a pragmatic mitigation. For production deployments, the event buffer SHOULD use Nextcloud's `OCP\ICacheFactory` with a Redis backend (`\OC\Memcache\Redis`) for cross-process event sharing, replacing the per-process APCu buffer. The `INotificationManager` integration in `NotificationService` can be extended to fire push notifications alongside SSE events, giving Nextcloud desktop and mobile clients native realtime awareness via the notify_push app. Authentication SHOULD use Nextcloud's `IRequest` session validation (already in place via the controller's `@NoAdminRequired` annotation) and extend to support API token validation for headless clients.

**Recommendation**: Extract the SSE streaming logic from `GraphQLSubscriptionController` into a new `SseController` that registers dedicated REST routes (`/api/sse/{register}`, `/api/sse/{register}/{schema}`, `/api/sse/{register}/{schema}/{objectId}`). Reuse `SubscriptionService` as the event buffer backend, adding a `ICache`-based implementation alongside APCu. Add a frontend composable (`useRealtimeUpdates`) that manages `EventSource` lifecycle with BroadcastChannel-based cross-tab leader election. Implement debouncing in `SubscriptionService.pushEvent()` by coalescing same-object events within a configurable window. For CloudEvents payload format, reuse `CloudEventFormatter` from the webhook system to format SSE `data` fields consistently.
