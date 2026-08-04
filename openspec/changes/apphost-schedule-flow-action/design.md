# Design — `openregister:flow-run` schedule action

## Context

`apphost-manifest-schedules` shipped the AppHost scheduling engine: a manifest
declares `schedules[]`, `ScheduleReconciler` idempotently upserts one
OpenConnector `job` per `applicationId + scheduleId`, and OpenConnector's
`JobService` executes it. That change's D-4 deliberately made the action space a
CLOSED, server-owned map so an app can never name an arbitrary FQCN — and seeded
it with exactly one entry.

Verified against the code as it stands today:

- `ScheduleActionAllowList::MAP` has one key,
  `openconnector:synchronization` → `OCA\OpenConnector\Action\SynchronizationAction`
  (`lib/AppHost/Scheduling/ScheduleActionAllowList.php:48-50`). `resolve()`
  returns null for anything else and the reconciler skips + logs
  (`ScheduleReconciler::reconcile()`, the `$jobClass === null` branch).
- `ScheduleDescriptor` already carries `id`, exactly one of
  `intervalSeconds`/`cron`, `action`, `arguments` and `enabled`, and carries **no**
  execution identity by construction.
- Owner resolution is already fail-closed: `resolveOwner()` returns null unless
  `IUserManager::get()` finds a live user, and a null owner skips the schedule
  with a warning rather than writing an ownerless job.
- The reconciled `job` carries `userId`; OpenConnector's
  `JobService::executeJob()` reads it, sets the session user, skips the job with
  a WARNING when the user no longer exists, and restores the prior session user
  in a `finally` (`openconnector/lib/Service/JobService.php:363-421`). The job
  class itself is instantiated via `$this->containerInterface->get($jobData['jobClass'])`
  and invoked as `$action->run($arguments)`.
- On the flow side, `FlowRunService::queue()` takes an explicit
  `?string $user` and stores it as the run's `triggeredBy`;
  `FlowRunService::execute()` then carries it into
  `context['triggeredBy']` (the or#2158 fix,
  `lib/Service/Flow/FlowRunService.php:186-194`). `ObjectWriteNode` refuses to
  write when that key is null or does not resolve to a user account
  (`lib/Service/Flow/Nodes/ObjectWriteNode.php:698-712`).
- The flow engine can now do real recurring work: `openregister.object-write`
  exists in-tree, and `openconnector.source-call` /
  `openconnector:synchronization-run` landed in ConductionNL/openconnector#1067.

The gap is therefore narrow and specific: the allow-list has no flow entry, so a
virtual app's only schedulable action is an OpenConnector synchronisation.

**Adjacent defect found while verifying.** `FlowScheduleService::fire()`
(`lib/Service/Flow/FlowScheduleService.php`) — the native `trigger: schedule`
path, driven by `FlowScheduleWorker` — calls `queue()` with `flowId`, `subject`,
`trigger` and `context` but **no `user:` argument**. Every natively-scheduled
flow run therefore has a null `triggeredBy` and every object-write inside it
refuses. This is the same defect class as or#2158 / `FlowRunController::test`,
sitting in the code most adjacent to this change, so it is fixed here rather
than left to be rediscovered.

## Goals / Non-Goals

**Goals:**

- One new server-owned allow-list entry, `openregister:flow-run`, so a manifest
  schedule can name a flow.
- A vetted action class that resolves the flow and queues exactly one run per
  fire, with `trigger: 'schedule'`.
- Attribution that is mandatory and fail-closed: no owner → no run queued, and
  never a run with a null `triggeredBy`.
- The same defect closed on the native scheduled-flow path.

**Non-Goals:**

- No arbitrary FQCN execution. The allow-list stays closed; nothing in
  `manifest.schedules[]` becomes a class name.
- No new scheduling engine, no second clock. Cadence, enable/disable, per-app
  scoping, upsert, reference keying and GC are consumed from
  `ScheduleReconciler` unchanged.
- No manifest-schema change. `schedules[].action` is already a free string
  validated against the allow-list; `arguments` is already an open object.
- No inline execution. The action queues; `FlowRunWorker` executes.
- No webhook/push action in this pass (see D-5).

## Decisions

### D-1 — One new allow-list entry, closed map preserved

`ScheduleActionAllowList::MAP` gains `'openregister:flow-run' => 'OCA\\OpenRegister\\AppHost\\Scheduling\\Action\\FlowRunAction'`.
Everything that made D-4 safe is untouched: the value is a constant in
OpenRegister's own source, not manifest data; `resolve()` still returns null for
anything unknown; the reconciler still skips-and-logs a non-allow-listed action.

*Alternative considered — a `flow:<uuid>` action-type pattern* (parse the flow
id out of the action string). Rejected: it turns the closed enum into a parsed
grammar, and the flow id belongs in `arguments` where every other action's
parameters already live (`arguments.synchronizationId` is the precedent).

*Alternative considered — let the manifest name the node/step directly.*
Rejected: that is a flow author's job, expressed in the flow document. A
schedule says *when*, the flow says *what*.

### D-2 — The action queues, it never executes

`FlowRunAction::run(array $argument): array` resolves
`argument['flowId']` through `FlowResolverRegistry::resolveFlow()`, then calls
`FlowRunService::queue(flowId, subject: [], trigger: 'schedule', context: [...], user: <owner>)`
and returns a stack-trace-shaped array like `SynchronizationAction` does.
`FlowRunWorker` picks the queued run up on its own cadence and executes it.

Rationale: a cron pass must not sit on an arbitrary graph — that is exactly the
argument in `FlowRunService::queue()`'s own docblock, and it applies at least as
strongly to a background sweep that also has to run every other job in the pass.
Queuing also means a scheduled run gets the same retry, suspension, logging and
retention behaviour as every other run, for free.

*Alternative considered — call `execute()` inline* so the OC job log records the
run's outcome directly. Rejected: an unbounded graph inside `JobService`'s
try/finally would let one slow flow starve the whole cron pass, and would create
a second execution path with its own failure semantics.

### D-3 — Attribution: mandatory, derived from the schedule's owner, fail-closed

The chain, end to end, has exactly one identity source and no fallback to the
session:

1. `ScheduleReconciler` resolves the owner from the owning application
   (`@self.owner` for a virtual app, the matching `openbuild` application object
   for an on-disk app), verifies it against `IUserManager`, and refuses to write
   a job at all when it is unresolvable.
2. That owner is persisted as the job's `userId`.
3. `JobService::executeJob()` skips the job with a WARNING when that user no
   longer exists, and otherwise sets it as the session user for the duration of
   `run()`.
4. `FlowRunAction` reads the acting user from `IUserSession` and re-verifies it
   against `IUserManager`. **If it is null or does not resolve, the action
   queues nothing and returns an ERROR result.** It never passes `user: null`
   to `queue()`.

Step 4 is deliberately redundant with steps 1-3. Three dispatch paths have
already been caught dropping the actor between queue and node (or#2158,
`FlowRunController::test`, and the `execute()` context-carry itself); the
cheapest defence is that the last hop before `queue()` refuses rather than
degrades. A scheduled run has no session of its own, so "whoever is logged in"
is not an available fallback and must not be invented as one.

`arguments.runAs` / `arguments.owner` are **ignored** if present, matching the
reconciler's existing treatment of author-supplied identity.

*Alternative considered — carry the owner in `arguments` at reconcile time.*
Rejected: it duplicates identity into manifest-derived data, which is precisely
where an author could later try to influence it. `userId` on the job is the one
server-owned identity field, and `JobService` already enforces it.

### D-4 — Flow resolution by uuid or slug, refuse on miss

`argument['flowId']` is resolved through `FlowResolverRegistry::resolveFlow()`,
which asks every contributed resolver in turn; OpenRegister's own resolver looks
the flow up in the configured flow register/schema
(`flow_register`/`flow_schema`, defaulting to `flows`/`flow`) and returns null
for an object that is not shaped like a flow.

Two failure modes are explicitly non-silent: a missing/blank `flowId` and a
`flowId` no resolver owns both produce an ERROR result and **no** queued run.
Queuing a run for a flow that cannot be resolved would only create a run the
worker is guaranteed to fail.

**Known hazard, called out for implementation:** `ObjectService::find(id, register:, schema:)`
does not actually scope the lookup by register/schema — a bare slug such as
`flow` resolves against the first matching object anywhere (or#2161). The action
MUST therefore treat resolution as "the resolver returned a flow-shaped
document", and the tasks include a live check that a slug belonging to a
different register is not silently accepted.

### D-5 — Webhook/push action: recommended as a follow-up, NOT in this pass

A `openregister:webhook-call` action is tempting to add in the same edit — the
allow-list is a map, and one more entry is one more line. The recommendation is
still **no, not here**, for two reasons:

- A flow can already do it. With `openconnector.source-call` merged, "on a
  schedule, POST to an endpoint" is a one-node flow scheduled through
  `openregister:flow-run`. A dedicated webhook action would be a second, weaker
  way to express something the first entry already covers — and it would arrive
  without the flow engine's run log, retry and suspension.
- Egress is a security surface with its own unresolved control. OR already has
  SSRF hardening on the object-write file path
  (`lib/Service/Object/SaveObject/FilePropertyHandler.php`), and the hydra
  console work recorded that the egress allowlist is not enforced at the network
  layer. Adding a *scheduled, unattended, owner-privileged* outbound call is a
  materially different risk from an interactive one, and deserves its own change
  with its own destination allow-list — not a line in this map.

If a direct webhook action is wanted later, it should reuse
`lib/Service/WebhookService.php` and land with a destination allow-list, not as
a bare URL argument.

### D-6 — Fix `FlowScheduleService::fire()` in the same pass

`fire()` gains an owner-bearing `queue()` call: the acting user is the flow
object's owner (`@self.owner`), verified against `IUserManager`; when it does not
resolve, the flow is **skipped** for that tick (logged) rather than fired
ownerless. The last-fire timestamp is only recorded when a run was actually
queued, so a flow blocked on attribution is not silently marked as having run.

Scoped in here rather than split out because it is the same one-line defect
class in the immediately adjacent scheduler, and shipping the manifest path
correct while the native path stays broken would be the more confusing outcome.

### Declarative-vs-imperative (ADR-031)

**Declaration side: declarative. Execution side: an existing imperative engine,
reused — nothing new is written imperatively.**

The *what to run and when* stays entirely in data: `manifest.schedules[]` (an
app declares its schedule) plus the flow document itself (a flow declares its
nodes and edges, and the flow engine walks it). Neither the app author nor this
change writes procedural per-app PHP.

The imperative surface this change adds is one thin adapter class whose whole
body is "resolve a flow id, check the owner, call `queue()`". It sits in the
same seam `apphost-manifest-schedules` D-1 already justified: a clock-driven
sweep is not derivable from object state, so it lives in a `TimedJob`-shaped
engine rather than an `x-openregister-*` extension. This change does not widen
that seam — it adds a value to a map and an adapter into an engine that already
exists. Critically, it *reduces* the imperative surface for app authors: work
that previously required either an OpenConnector synchronisation or shipped PHP
can now be expressed as a declared flow.

### Seed data (ADR-001)

**None.** This change introduces no register or schema JSON and therefore no
seed objects, and it ships no schema migration.

- The allow-list is a PHP constant in OpenRegister's own source, not seeded
  data — that is what makes it server-owned and closed (D-1).
- Flows are ordinary OR objects in the configured flow register/schema, created
  by users; this change reads them and never seeds one.
- Flow runs are rows created at execution time by `FlowRunService`, not seed
  data.
- Reconciled OpenConnector `job` objects are created at runtime by
  `ScheduleReconciler` against the pre-existing `openconnector`/`job` schema.

## Risks / Trade-offs

- **[Ownerless scheduled run writes nothing, silently]** A schedule whose owner
  was deleted would previously have produced a run that failed deep inside
  `ObjectWriteNode`. → Refuse at the action, before queuing, and log an ERROR
  result that lands in the OC job log where an admin will see it (D-3).
- **[Privilege of a scheduled flow]** A flow scheduled by an app runs as the
  app owner, unattended, on a clock. → Identity comes only from the
  server-resolved job `userId`; `arguments.runAs`/`owner` are ignored; the
  reconciler already refuses to create the job without a live owner.
- **[Flow id resolves to the wrong object]** `find()` does not scope by
  register/schema (or#2161), so a bare slug can match elsewhere. → Resolve only
  through `FlowResolverRegistry`, require a flow-shaped document, and verify with
  a live check that a foreign slug is refused (D-4).
- **[Run pile-up]** A cadence shorter than the flow's runtime queues runs faster
  than `FlowRunWorker` drains them. → The action queues exactly one run per
  fire and the existing OC cadence bounds fire rate; overlapping-run suppression
  is explicitly out of scope and noted as an open question.
- **[Cross-app instantiation]** The vetted class is an OpenRegister class
  instantiated by OpenConnector's container. → Nextcloud's autoloader resolves
  `OCA\OpenRegister\*` for any enabled app, and this is the same seam the
  existing entry uses in the opposite direction; covered by a live check rather
  than assumed.
- **[Scope creep into egress]** → Webhook/push action explicitly deferred with a
  reason (D-5).

## Migration Plan

1. Land the allow-list entry + `FlowRunAction` + the `FlowScheduleService` fix.
   All three are additive; instances with no `openregister:flow-run` schedule are
   unaffected.
2. No migration, no seed, no schema change. Rollback is reverting the commit —
   an existing schedule using the new action simply reverts to being logged as
   non-allow-listed and skipped, which is the pre-change behaviour.

## Open Questions

- Should a scheduled flow suppress a new run while a previous run for the same
  schedule is still `queued`/`running`? Provisionally **no** (out of scope, and
  the flow engine has no per-flow concurrency notion today), but it is the most
  likely first follow-up.
- Should `arguments` be passed into the run's `context` as a payload so a flow
  can be parameterised per schedule? Provisionally **yes**, under a `payload`
  key, mirroring `FlowScheduleService::fire()`'s existing
  `context: ['payload' => ...]` shape.
