# Retention Management — delta

## REMOVED Requirements

### Requirement: The system MUST prune the realtime events log daily

**Reason**: The `RealtimeEventRetentionJob` pruned the
`openregister_realtime_events` table, which is removed from the write path in
this change (`RealtimeService`/`RealtimeEventListener` deleted — the log had
zero consumers). With nothing writing to the table, there is nothing to prune;
the job and its `info.xml` registration are deleted. This is independent of the
AVG retention pass and the Archiefwet destruction workflow, which are unchanged.

**Migration**: The leftover table is dropped by migration
`Version1Date20260714000000` (idempotent, drops only when present).
