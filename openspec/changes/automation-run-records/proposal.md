---
kind: code
depends_on: []
---
# Proposal: automation-run-records

## Summary

An app that compiles automations into OpenRegister declarations (notifications into `x-openregister-notifications`, lifecycle actions into `x-openregister-lifecycle`) cannot tell its user whether an automation ran, for which record, and whether it worked. OpenRegister executes those steps and keeps no readable record of them: dispatches are logged for deduplication only, with no read route, and a lifecycle action leaves no outcome at all. This change gives both a per-run record and a read route filtered by the key the app gave the declaration.

## Halves this closes

buildiq's `logic-automation-run-log` (merged in buildiq #1046), design D4: "OpenRegister needs a read endpoint over its notification dispatch log filtered by notification key (the `aut-` prefix), and an outcome record for typed lifecycle actions." Until then buildiq shows those steps as "Runs in OpenRegister. No per-run record yet." No row in OpenRegister's matrix: this is the platform half of a buildiq capability.

## What is there

- `lib/Db/NotificationDispatchLog.php`: `notificationSlug`, `idempotencyKey`, `dispatchedAt`; written by `NotificationDispatchLogMapper::record()` (`:150`) only for a notification with an idempotency key, and pruned after the dedup window (`pruneExpired()`, `:198`). No controller reads it.
- `lib/Db/NotificationHistory.php`: one row per recipient and channel, with `ruleId`, `schemaId`, `objectUuid`, `channel`, `status`, `errorMessage`, `dispatchedAt`, `eventId`. `GET /api/notification-history` (`appinfo/routes.php:1388`) is a user's own inbox.
- `lib/Service/Lifecycle/LifecycleActionExecutor.php:75` `run()`: runs a transition's `actions[]` in order, fail loud on a missing handler or a bad condition, skips an action whose condition is false. Nothing is recorded.

## What changes

- A notification declaration MAY carry `key`. Every dispatch of a declared notification writes one dispatch record: key, notification slug, schema, object uuid, trigger event, time, outcome (`sent`, `partial`, `failed`, `skipped`) with a reason for skipped (duplicate, rate limit, quiet hours, no recipients) and counts per channel. Recipients are counted, not named.
- A lifecycle action declaration MAY carry `key`. The executor writes one action record per declared action it reaches: key, action name, transition, schema, object uuid, time, outcome (`succeeded`, `failed`, `skipped`) and a message.
- `GET /api/automation-records?kind=notification|lifecycle-action&key=<prefix>&schema=&object=&since=&until=&limit=&offset=` reads both, filtered by key prefix. The same read is a public service method for an app in process.
- Records are kept 90 days by default, configurable; the dedup log keeps its own window.

## ADRs

- ADR-022: OpenRegister runs the step, so OpenRegister records it; the app reads.
- ADR-031: notification declarations stay declarative; `key` is metadata on them.
- ADR-005: the read shows a record only to a caller who may read its schema's objects; recipients are counts.

## Impact

- Extends `notificatie-engine` and `object-lifecycle`.
- Affected code: a migration for `openregister_automation_records`, an entity and mapper, `AnnotationNotificationDispatcher`, `LifecycleActionExecutor`, a controller and route, the annotation vocabulary (`key` on both declarations), a retention job.
- Backwards compatible: declarations without `key` are recorded with an empty key and change nothing else.
- Size: M.
