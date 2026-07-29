---
kind: code
---

## Why

An OpenBuild app declares its background work in `manifest.schedules[]`, and
OpenRegister's AppHost `ScheduleReconciler` turns each declaration into an
OpenConnector `job` row. Which work a declaration may name is deliberately not
the app author's choice: `ScheduleActionAllowList::MAP`
(`lib/AppHost/Scheduling/ScheduleActionAllowList.php:48-50`) is a CLOSED,
server-owned map, and a manifest-supplied FQCN is never used as a `jobClass`
(design D-4, ADR-005). That is the right shape — an app must not be able to
schedule arbitrary code as its owner.

The problem is that the map has exactly **one** entry today,
`openconnector:synchronization`. So a virtual app can schedule an OpenConnector
synchronisation and *nothing else*. In particular it cannot schedule a **flow**,
even though the OpenRegister flow engine is now the fleet's one flow engine
(ADR-065) and a flow is already able to do the two things recurring work needs:
call an external API (`openconnector.source-call`, merged with
`openconnector:synchronization-run` in ConductionNL/openconnector#1067) and write
objects (`openregister.object-write`,
`lib/Service/Flow/Nodes/ObjectWriteNode.php`). An app author who wants
"every night, fetch X and update Y" has to model it as a synchronisation or ship
PHP — which is exactly what the virtual-app programme exists to avoid.

Attribution is the sharp edge, not the plumbing. Three separate dispatch paths
were just found dropping the acting user, leaving `context['triggeredBy']` null
so `ObjectWriteNode` refused every write (or#2158 in
`FlowMcpToolProvider::runFlow()`, plus `FlowRunController::test`, plus the
`FlowRunService::execute` context-carry itself,
`lib/Service/Flow/FlowRunService.php:186-194`). A scheduled run has no session at
all, so it cannot fall back to "whoever is logged in": the owner must come from
the schedule's own declaration, and the action must refuse to run rather than run
ownerless.

## What Changes

- Add a second, server-owned entry to `ScheduleActionAllowList`:
  `openregister:flow-run` → a vetted OpenRegister action class. The allow-list
  stays CLOSED; no manifest-supplied FQCN becomes executable, and no new
  manifest key is introduced (`schedules[].action` already carries a type
  string).
- Add `OCA\OpenRegister\AppHost\Scheduling\Action\FlowRunAction` — the vetted
  class the new entry points at. Its `run(array $argument)` resolves the flow
  named by `argument.flowId` (uuid or slug) through `FlowResolverRegistry`,
  refuses when it does not resolve, and otherwise queues one run through
  `FlowRunService::queue()` with `trigger: 'schedule'`.
- **Attribution is mandatory and fail-closed.** The action derives the acting
  user from the executing job's `userId` — which the reconciler already resolves
  from the owning application's owner and refuses to write without
  (`ScheduleReconciler::reconcile()`, the `$ownerUid === null` branch, and
  `resolveOwner()`). When no live user is resolvable at execution time the
  action performs **no** queue and returns an ERROR result. It never queues a
  run with a null `triggeredBy`.
- Fix the same defect class in the adjacent native path: `FlowScheduleService::fire()`
  (`lib/Service/Flow/FlowScheduleService.php`) queues every `trigger: schedule`
  flow with **no** `user:` argument, so every natively-scheduled flow is
  ownerless today and every object-write inside it refuses. A scheduled native
  flow is attributed to its flow object's owner, fail-closed the same way.
- No new scheduling engine. Cadence (`interval` / `cron`), `enabled`,
  per-application scoping, idempotent upsert, reference keying and
  garbage-collection all stay exactly as `ScheduleReconciler` implements them;
  the flow action is only a new value in the existing allow-list.
- **Not in this change:** a webhook/push action. See Design — recommended as a
  follow-up, not folded in here.

## Capabilities

### New Capabilities
- `apphost-schedule-flow-action`: the `openregister:flow-run` schedule action —
  its allow-list entry, argument contract, flow resolution, fail-closed
  attribution, and its non-goals (no arbitrary FQCN, no second scheduler).

### Modified Capabilities
<!-- The `apphost-scheduling` capability (openspec/changes/apphost-manifest-schedules)
     is not yet archived into openspec/specs/, and none of its requirements change:
     the reconciler contract is consumed as-is, not amended. No delta spec. -->

## Impact

- `lib/AppHost/Scheduling/ScheduleActionAllowList.php` — one added map entry.
- `lib/AppHost/Scheduling/Action/FlowRunAction.php` — new.
- `lib/Service/Flow/FlowScheduleService.php` — owner-bearing `queue()` call.
- Depends on `FlowResolverRegistry::resolveFlow()`, `FlowRunService::queue()`,
  `IUserSession`/`IUserManager`, and `FlowRunWorker` (which executes the queued
  run — this change queues, it never executes inline).
- Cross-app: OpenConnector's `JobService::executeJob()` instantiates the vetted
  `jobClass` through its container and sets the session user from the job's
  `userId` before calling `run()`
  (`openconnector/lib/Service/JobService.php:363-397`). OpenRegister ships no
  change to OpenConnector.
- Tests: `tests/Unit/AppHost/Scheduling/ScheduleActionAllowListTest.php` gains
  the new entry; a new unit test covers the action's fail-closed attribution.
