# Tasks: Realtime Updates

- [ ] Implement: The system MUST provide a dedicated SSE endpoint for object change events
- [ ] Implement: The SSE endpoint MUST support topic-based channel subscriptions
- [ ] Implement: SSE events MUST be authorization-aware via RBAC filtering
- [ ] Implement: The SSE endpoint MUST support authentication
- [ ] Implement: SSE connections MUST support automatic reconnection with event replay
- [ ] Implement: The system MUST support connection health via heartbeat
- [ ] Implement: The system MUST debounce and batch rapid changes
- [ ] Implement: The event payload format MUST follow CloudEvents conventions
- [ ] Implement: The system SHOULD integrate with Nextcloud notify_push for native push delivery
- [ ] Implement: The system MUST support fallback to polling when SSE is unavailable
- [ ] Implement: The frontend MUST auto-refresh views when realtime events arrive
- [ ] Implement: The frontend MUST use a reactive store pattern for realtime state management
- [ ] Implement: The system MUST perform acceptably under concurrent connection load
- [ ] Implement: The SSE event payload MUST support subscription filtering via query parameters
