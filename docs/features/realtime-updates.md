# Real-Time Updates

## Overview

OpenRegister provides live data synchronization to connected clients via Server-Sent Events (SSE) as the primary transport, with Nextcloud notify_push as a complementary channel and graceful fallback to polling. All real-time channels are authorization-aware — users only receive events for objects their RBAC permissions allow them to see. Subscriptions are topic-based at the register, schema, or individual object level.

## Server-Sent Events (SSE)

SSE is the primary real-time transport. Clients open a persistent HTTP connection and receive a stream of events:

```
GET /api/realtime/subscribe?topic=register/meldingen-register/schema/meldingen
```

### Topics

| Topic Pattern | Description |
|--------------|-------------|
| `register/{slug}` | All events for all schemas in a register |
| `register/{slug}/schema/{slug}` | All events for a specific schema |
| `object/{uuid}` | Events for a single object |
| `organisation/{uuid}` | All events for an organisation (multi-tenant) |

Multiple topics can be subscribed in one connection:

```
GET /api/realtime/subscribe?topic[]=register/meldingen&topic[]=register/besluiten
```

### Event Format

Events are delivered in CloudEvents v1.0 format as SSE `data:` lines:

```
event: object.created
data: {"specversion":"1.0","type":"nl.conduction.openregister.object.created","source":"...","id":"evt-123","time":"2026-03-21T12:00:00Z","data":{"uuid":"...","schema":"meldingen","register":"meldingen-register","object":{...}}}

event: object.updated
data: {"specversion":"1.0","type":"nl.conduction.openregister.object.updated",...,"data":{"uuid":"...","changed":{"status":{"old":"nieuw","new":"in_behandeling"}}}}
```

### RBAC Filtering

Before each event is sent to a subscriber, the SSE service checks:

1. Schema-level RBAC: does the subscriber have `read` permission for the event's schema?
2. Row-level RBAC: do the object's field values satisfy the subscriber's conditional rules?
3. Property-level RBAC: unauthorized fields are stripped from the event payload

This ensures users only receive events for data they are authorized to see, even in multi-tenant deployments.

### Reconnection with Event Replay

Clients that disconnect and reconnect can request missed events:

```
GET /api/realtime/subscribe?topic=...&lastEventId=evt-099
```

The SSE service replays events since `lastEventId` from an in-memory ring buffer (configurable size, default 1000 events). Events older than the buffer are not replayed — clients should poll for a full resync.

### Authentication

SSE connections require authentication:

- Session auth: cookie included in the EventSource request
- Bearer token: `?token=<api-token>` URL parameter (for clients that cannot set headers on EventSource)
- OAuth2: standard Bearer token in `Authorization` header

## GraphQL Subscriptions

Real-time events are also available via GraphQL subscriptions over SSE:

```
GET /api/graphql/subscriptions?query=subscription { meldingenEvents(filter:{status:"nieuw"}) { _uuid titel status } }
```

GraphQL subscriptions support inline filtering (only events matching the filter are delivered) and field selection (only requested fields are in the payload).

## Nextcloud notify_push Integration

For Nextcloud desktop and mobile clients, OpenRegister publishes notifications via `notify_push`:

- Object changes trigger a push notification to connected Nextcloud clients
- The client then polls the REST API for the actual updated data
- Enables notification on mobile without a persistent SSE connection

## Polling Fallback

When SSE or notify_push is unavailable, clients can poll:

```
GET /api/objects/{register}/{schema}?_updatedSince=2026-03-21T12:00:00Z&_limit=50
```

The `_updatedSince` parameter returns only objects updated after the given timestamp. Combined with the `_updated` field on each object, clients can implement efficient incremental polling.

## API

```
GET /api/realtime/subscribe              SSE subscription endpoint
GET /api/realtime/topics                 List available topics
GET /api/realtime/status                 Connection statistics
```

## Standards

| Standard | Role |
|----------|------|
| Server-Sent Events (W3C) | Primary real-time transport |
| CloudEvents v1.0 | Event payload format |
| GraphQL Subscriptions | Real-time GraphQL queries |
| Nextcloud notify_push | Mobile/desktop push notification channel |

## Related Features

- [Event-Driven Architecture](event-driven-architecture.md) — all object mutations dispatch events consumed by the SSE service
- [OpenAPI & GraphQL APIs](api-generation.md) — GraphQL subscriptions auto-generated from schemas
- [Access Control (RBAC)](access-control.md) — RBAC filtering applied to every SSE event
- [Webhooks & Notifications](webhooks-and-notifications.md) — outbound webhooks for server-to-server real-time
