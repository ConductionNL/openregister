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
