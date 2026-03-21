# Realtime Updates

## Problem
Provide live data synchronization to connected clients so that register object mutations (create, update, delete) are pushed immediately without manual page refresh. The system MUST offer Server-Sent Events (SSE) as the primary transport, with Nextcloud's notify_push integration as a complementary channel, and graceful fallback to polling. All realtime channels MUST be authorization-aware, meaning users only receive events for objects their RBAC permissions allow them to see, and MUST support topic-based subscriptions at the register, schema, and individual object level.
**Source**: Gap identified in cross-platform analysis; PocketBase provides SSE-based realtime subscriptions per collection/record with auth-aware filtering, Directus offers WebSocket connectivity with UID-based subscription management and permission-filtered broadcasts, and five platforms total offer real-time capabilities. See also: `event-driven-architecture` (CloudEvents format, event bus transports), `webhook-payload-mapping` (payload transformation via Twig mappings), `notificatie-engine` (notification channels and batching).

## Proposed Solution
Implement Realtime Updates following the detailed specification. Key requirements include:
- Requirement: The system MUST provide a dedicated SSE endpoint for object change events
- Requirement: The SSE endpoint MUST support topic-based channel subscriptions
- Requirement: SSE events MUST be authorization-aware via RBAC filtering
- Requirement: The SSE endpoint MUST support authentication
- Requirement: SSE connections MUST support automatic reconnection with event replay

## Scope
This change covers all requirements defined in the realtime-updates specification.

## Success Criteria
- Client connects to SSE endpoint and receives create event
- Client receives update event with changed fields
- Client receives delete event
- SSE response headers are correctly set
- Subscribe to all changes in a register
