# Realtime Updates

## Problem
Provide live data synchronization to connected clients so that register object mutations (create, update, delete) are pushed immediately without manual page refresh. The system MUST offer Server-Sent Events (SSE) as the primary transport, with Nextcloud's notify_push integration as a complementary channel, and graceful fallback to polling.

## Proposed Solution
Provide live data synchronization to connected clients so that register object mutations (create, update, delete) are pushed immediately without manual page refresh. The system MUST offer Server-Sent Events (SSE) as the primary transport, with Nextcloud's notify_push integration as a complementary channel, and graceful fallback to polling. All realtime channels MUST be authorization-aware, meaning users only receive events for objects their RBAC permissions allow them to see, and MUST support to
