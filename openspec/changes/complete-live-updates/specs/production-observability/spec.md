# Production Observability — delta

## REMOVED Requirements

### Requirement: Realtime change records MUST be emitted as CloudEvent-shaped envelopes

**Reason**: `RealtimeService` and its `openregister_realtime_events` store are
removed in this change — the CloudEvent rows were written on every object save
but never read (the cursor-polling endpoints had zero consumers across the
entire apps-extra workspace). Realtime delivery is covered by the notify_push
transport (`NotifyPushListener`) and the GraphQL SSE subscription path; audit
history remains in the audit trail.

**Migration**: None — no consumers. The `openregister_realtime_events` table is
left in place on installed instances (drop is a follow-up).
