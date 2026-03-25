# realtime-updates Specification

## Purpose
Implement Server-Sent Events (SSE) push updates for register objects so that connected clients receive immediate notifications when data changes. Updates MUST be authorization-aware (users only receive events for objects they can access), support auto-refresh of list and detail views, and enable collaborative editing without manual page reload.

**Source**: Gap identified in cross-platform analysis; five platforms offer real-time capabilities.

## ADDED Requirements

### Requirement: The system MUST provide an SSE endpoint for object change events
A Server-Sent Events endpoint MUST stream object change events to connected clients in real time.

#### Scenario: Receive create event via SSE
- GIVEN a client is connected to the SSE endpoint for schema `meldingen`
- WHEN another user creates a new melding object
- THEN the connected client MUST receive an SSE event with:
  - `event`: `object.created`
  - `data`: JSON containing the new object's UUID, title, and key properties
  - `id`: monotonically increasing event ID for reconnection

#### Scenario: Receive update event via SSE
- GIVEN a client is connected to the SSE endpoint for schema `meldingen`
- WHEN melding `melding-1` is updated (status changed from `nieuw` to `in_behandeling`)
- THEN the client MUST receive an SSE event with:
  - `event`: `object.updated`
  - `data`: JSON containing the object UUID and changed fields

#### Scenario: Receive delete event via SSE
- GIVEN a client is connected to the SSE endpoint
- WHEN object `melding-5` is deleted
- THEN the client MUST receive an SSE event with:
  - `event`: `object.deleted`
  - `data`: JSON containing the deleted object's UUID

### Requirement: SSE events MUST be authorization-aware
Clients MUST only receive events for objects they are authorized to access based on RBAC policies.

#### Scenario: Filtered events based on permissions
- GIVEN user `medewerker-1` has read access to schema `meldingen` but not `vertrouwelijk`
- AND user `medewerker-1` is connected to the SSE endpoint for register `zaken`
- WHEN an object is created in schema `vertrouwelijk`
- THEN `medewerker-1` MUST NOT receive the creation event

#### Scenario: Events for authorized schemas only
- GIVEN user `behandelaar-1` has access to schemas `meldingen` and `vergunningen`
- WHEN objects are created in both schemas simultaneously
- THEN `behandelaar-1` MUST receive events for both schemas

### Requirement: The UI MUST auto-refresh when SSE events arrive
List views and detail views MUST automatically update when relevant SSE events are received.

#### Scenario: Auto-refresh list view on create
- GIVEN the user is viewing the meldingen list showing 10 objects
- WHEN another user creates a new melding
- THEN the list MUST add the new melding without manual refresh
- AND a subtle animation SHOULD indicate the new entry

#### Scenario: Auto-refresh detail view on update
- GIVEN the user is viewing the detail of `melding-1`
- WHEN another user updates `melding-1`'s status
- THEN the detail view MUST update the status field in place
- AND a banner SHOULD briefly indicate the update source

#### Scenario: Handle deleted object in view
- GIVEN the user is viewing the detail of `melding-5`
- WHEN `melding-5` is deleted by another user
- THEN the UI MUST display a notice: `Dit object is verwijderd`
- AND editing controls MUST be disabled

### Requirement: SSE connections MUST support reconnection
The SSE client MUST automatically reconnect after connection drops and resume from the last received event.

#### Scenario: Reconnect after network interruption
- GIVEN a client connected to SSE with last event ID `42`
- WHEN the connection drops and is re-established
- THEN the client MUST send `Last-Event-ID: 42` header
- AND the server MUST replay any events after ID 42 that the client missed

#### Scenario: Event buffer retention
- GIVEN the server buffers events for reconnection
- THEN the buffer MUST retain events for at least 5 minutes
- AND events older than the buffer window MUST trigger a full data refresh on reconnection

### Requirement: SSE MUST support topic-based subscriptions
Clients MUST be able to subscribe to specific schemas, registers, or individual objects.

#### Scenario: Subscribe to a single schema
- GIVEN the client connects to /api/sse/{register}/{schema}
- THEN it MUST only receive events for that specific schema

#### Scenario: Subscribe to a specific object
- GIVEN the client connects to /api/sse/{register}/{schema}/{objectId}
- THEN it MUST only receive events for that specific object
- AND this MUST be used for detail view real-time updates

### Current Implementation Status

**Partially implemented via GraphQL Subscriptions (not SSE):**
- `lib/Controller/GraphQLSubscriptionController.php` -- SSE-based subscription controller using APCu-buffered events
- `lib/Service/GraphQL/SubscriptionService.php` -- Manages event buffer in APCu with key prefix, supports buffering object change events
- `lib/Listener/GraphQLSubscriptionListener.php` -- Listens to object events and pushes them to the subscription buffer

**What IS implemented:**
- SSE streaming endpoint exists (via GraphQL subscription controller)
- Event buffering in APCu for reconnection support
- Listener that captures object CRUD events and pushes to buffer

**What is NOT implemented:**
- Dedicated `/api/sse/{register}/{schema}` and `/api/sse/{register}/{schema}/{objectId}` endpoints (current endpoint is GraphQL-specific)
- Authorization-aware event filtering (users receiving only events for objects they can access)
- Topic-based subscriptions per register/schema/object
- Frontend auto-refresh of list and detail views on SSE events
- Monotonically increasing event IDs for reconnection
- Event buffer retention time configuration (5-minute minimum)

### Standards & References
- W3C Server-Sent Events specification (https://html.spec.whatwg.org/multipage/server-sent-events.html)
- `EventSource` Web API (https://developer.mozilla.org/en-US/docs/Web/API/EventSource)
- `Last-Event-ID` reconnection header (part of SSE spec)
- GraphQL Subscriptions over SSE (current partial implementation pattern)

### Specificity Assessment
- **Specific enough to implement?** Mostly yes -- scenarios are well-defined with clear event types, payload structures, and subscription patterns.
- **Missing/ambiguous:**
  - No specification for maximum concurrent SSE connections per server or rate limiting
  - No guidance on how SSE interacts with Nextcloud's PHP request model (long-polling in PHP is resource-heavy; APCu buffer is a workaround)
  - No specification for authentication mechanism on SSE endpoint (cookies, bearer tokens?)
  - No specification for event payload size limits
  - Scalability concerns: APCu is per-process -- multi-worker setups may miss events
- **Open questions:**
  - Should the existing GraphQL subscription infrastructure be extended or replaced with a dedicated SSE system?
  - How should SSE work in ExApp sidecar deployment (Python proxy)?
  - Should WebSocket be considered as an alternative to SSE for bidirectional communication?

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: GraphQLSubscriptionController provides an SSE-based streaming endpoint using APCu-buffered events. SubscriptionService manages the event buffer in APCu with key prefixes, supporting buffering of object change events. GraphQLSubscriptionListener captures object CRUD events and pushes them to the subscription buffer. The SSE streaming mechanism is functional and delivers real-time updates to connected clients.

**Nextcloud Core Integration**: The current implementation uses Server-Sent Events (SSE) which works within Nextcloud's PHP request model, though long-running PHP processes are resource-intensive. The APCu buffer is per-process, which is a pragmatic workaround for PHP's shared-nothing architecture. An additional integration point would be Nextcloud's notification push channel (OCP\Notification\IManager with the Nextcloud Push app), which provides a native WebSocket-like push mechanism to Nextcloud clients. This could complement SSE for users already connected through the Nextcloud web interface, delivering real-time updates via the notification bell.

**Recommendation**: The SSE implementation via GraphQL subscriptions is functional for real-time updates. To improve Nextcloud integration, consider registering a push notification provider that fires alongside the SSE buffer, giving Nextcloud desktop and mobile clients native real-time awareness of register changes. The APCu buffer approach has scalability limitations in multi-worker setups; for production deployments, consider using Nextcloud's ICache (OCP\ICache) with a Redis backend for cross-process event sharing. Dedicated /api/sse/{register}/{schema} endpoints should be added as aliases to the GraphQL subscription endpoint for REST API consistency.
