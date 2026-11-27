---
kind: code
depends_on: []
---

# Proposal: a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did

Asked by buildiq `logic-automation-run-log` task T11 (design D4 there).

## Why

Buildiq compiles a maker's automation into OpenRegister's own machinery:
notifications into `x-openregister-notifications`, typed lifecycle actions into
`x-openregister-lifecycle`. OpenRegister then runs them without calling buildiq,
so buildiq's run log can only say "Runs in OpenRegister. No per-run record yet."
for those steps. A maker who asks "did my reminder go out on Tuesday?" gets no
answer.

What OpenRegister keeps today does not answer it either:
`NotificationDispatchLog` is an idempotency guard (one row per
`notification_slug` + `idempotency_key`, pruned after its 24-hour window), not a
history, and a typed lifecycle action records no outcome at all.

## What changes

- OpenRegister keeps one dispatch record per notification sent: the
  notification key, when, for which object, how many recipients, and
  `sent` / `failed` with a short reason. Kept 90 days, like buildiq's own runs.
- OpenRegister keeps one outcome record per typed lifecycle action run: the
  action key, when, for which object, and `done` / `failed` with a short reason.
- A read, for a caller who may read the subject object:
  `GET /api/automation-outcomes?keyPrefix=aut-&since=…&limit=…`, newest first,
  answering both kinds in one shape. No message body, no recipient address.

## Impact

- Affected specs: `notificatie-engine` (one added requirement).
- Affected code: the notification dispatcher and the lifecycle action runner
  (write one record each), one controller and route for the read.
- Buildiq replaces its `in-openregister` step with the real outcome.
