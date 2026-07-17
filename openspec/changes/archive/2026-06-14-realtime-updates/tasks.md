# Tasks: Realtime Updates

- [x] Implement: The system MUST provide a dedicated SSE endpoint for object change events
- [x] Implement: The SSE endpoint MUST support topic-based channel subscriptions
- [x] Implement: SSE events MUST be authorization-aware via RBAC filtering
- [x] Implement: The SSE endpoint MUST support authentication
- [x] Implement: SSE connections MUST support automatic reconnection with event replay
- [x] Implement: The system MUST support connection health via heartbeat
- [x] Implement: The system MUST debounce and batch rapid changes
- [x] Implement: The event payload format MUST follow CloudEvents conventions
- [x] Implement: The system SHOULD integrate with Nextcloud notify_push for native push delivery
- [x] Implement: The system MUST support fallback to polling when SSE is unavailable
- [x] Implement: The frontend MUST auto-refresh views when realtime events arrive
- [x] Implement: The frontend MUST use a reactive store pattern for realtime state management
- [x] Implement: The system MUST perform acceptably under concurrent connection load
- [x] Implement: The SSE event payload MUST support subscription filtering via query parameters
