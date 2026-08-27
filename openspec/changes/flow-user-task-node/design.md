# Design: flow-user-task-node

## Context

See proposal.md — Why for the motivation. What matters for the approach is
what the engine already does, measured:

- **Suspension is an exception, not a return value.** `FlowSuspension`
  (`lib/Service/Flow/FlowSuspension.php:42-58`) is thrown, deliberately, so a
  node that forgets cannot silently continue. There is no return path on
  which to flush buffered state — which is why `FlowNodeResumeState` writes
  straight through to its parent (`FlowNodeResumeState.php:12-15`).
- **Resume state is already per node.** The dispatcher hands each node a
  handle scoped to that node (`FlowNodeResumeState.php:39-65`), so two nodes
  of the same type in one flow cannot read or overwrite each other's
  progress. `AwaitSignalNode` uses exactly this for `askedAt`
  (`AwaitSignalNode.php:356-374`).
- **The signal slot is NOT per node.** `$context['signal']` is one key
  (`FlowRunService.php:74`), set by `signal()` (`:530`) and unset by the walk
  it wakes (`:807`). One question, one answer, one slot.
- **Only two nodes suspend at all.** `WaitNode.php:192` and
  `AwaitSignalNode.php:266` are the only `FlowSuspension` throws in `lib/`,
  and both pass a non-null time. `SubFlowNode` runs its child inline
  (`SubFlowNode.php:327`) rather than suspending the parent.
- **So the abandoned-signal reaper currently reaps nothing.**
  `FlowRunMapper::findAbandonedSignals()` requires
  `resume_at IS NULL` (`lib/Db/FlowRunMapper.php:589-605`) and no shipped
  node produces that. The 14-day failure at `FlowRunWorker.php:311-349` is a
  loaded gun aimed at a case that does not yet exist.
- **The engine already has both advance paths.** `FlowRunAdvancer::advance()`
  takes `rethrow`, and `FlowService::run()` calls it with `rethrow: true`
  when a caller asked to wait (`FlowService.php:511`). The worker calls it
  with `rethrow: false` (`FlowRunWorker.php:439`). What is missing is a way
  for a NODE to choose which one runs after it.
- **`configForm()` already reaches the builder.** `FlowNodeRegistry::palette()`
  publishes `configForm` for any node declaring `IFlowNodeConfigForm`
  (`FlowNodeRegistry.php:243-250`), served at
  `GET /api/flow/node-catalog` (`FlowController.php:213`).
- **The task itself is somebody else's design.** `flow-task-entity` owns the
  table, the six CMMN states, the ten lifecycle verbs, the fail-closed
  authorization, the performer model, the five routing strategies and the
  append-only audit. This change consumes all of it.

## Goals / Non-Goals

**Goals:**

- One node type that is a thin, honest bridge between the graph and
  `TaskService` — validation, templating, suspend/resume, item placement,
  and nothing else.
- Resume driven by the TASK's terminality, so the semantics survive two
  user-task nodes in one flow without a per-run answer slot.
- A completion-latency control (`advance`) that reuses the engine's existing
  synchronous path instead of adding a second execution route.
- Cancellation propagation supplied at the point that knows the mapping —
  the node — so `flow-task-entity`'s propagation rules have something to
  fire on.

**Non-Goals:**

- Re-modelling anything `flow-tasks` specifies. If a rule is about who may
  claim, what a state means, or how a routing strategy resolves, it does not
  belong in this node.
- In-request advancing as a general engine feature. The budget is a ceiling
  on a walk THIS node's completion initiates; no other caller gains it here.
- Replacing `AwaitSignalNode`, and no change to its file at all.
- Any notification, calendar entry, form definition, SLA arithmetic or BPMN
  mapping — four other changes own those (proposal.md — What does NOT
  change).

## Decisions

### D-1 — Declarative-vs-imperative decision (ADR-031)

**The node is imperative because a node IS the imperative half of the
platform. What it configures stays declarative, and it is fenced from
carrying any business rule.**

ADR-031's test is: when an `x-openregister-*` schema extension expresses the
requirement, declare it rather than write a service. Applied here:

**Imperative — the node.** ADR-031's declarative surface is anchored to OR
OBJECTS: `x-openregister-lifecycle` evaluates transitions over object state,
`x-openregister-notifications` fires on object events. A flow node is
addressed by a Petri-net transition and receives ITEMS, not an object; its
whole contract is `execute(items, config, context)`
(`lib/Service/Flow/IFlowNode.php:159`). There is no schema annotation that
can throw `FlowSuspension`, and there should not be — suspension is engine
mechanics, the category ADR-031 explicitly preserves for PHP. `WaitNode`,
`SwitchNode` and `AwaitSignalNode` are all in this category already; this
node joins them rather than inventing a precedent.

**Declarative — what the node points AT.** The node holds no rule about who
may perform the task; it names candidates and a strategy and lets
`TaskAuthorizationService` decide, which is `flow-task-entity`'s D-1
argument and not re-made here. It writes no notification: telling the
performer is `x-openregister-notifications` on the task projection
(`flow-task-inbox-projections`), addressing the NAMED TRANSITION ACTIONS
`flow-tasks` records. It computes no deadline: escalation rules are
declarative and belong to `flow-business-timers`.

**Derived, never stored on the node.** The node does not cache the task's
state in its resume slot. It stores the task UUID and the creation time, and
asks the task service for terminality on every pass. A cached state is a
clock-adjacent fact maintained by hand, and the fleet has already paid for
that once — three schemas store `overdue`, and decidesk's `actionOverdue`
notification fires only when something remembered to write it.

**The fence.** `UserTaskNode` may not contain a branch about what a specific
app's task MEANS. Every branch in it must be about the graph (do I have
items?), the task's terminality (may I continue?), or placement (where does
the outcome go?). A branch on an approval's business meaning belongs on an
edge condition, where the author can see it.

### D-2 — Resume on task terminality, not on the run's signal slot

The obvious implementation is to reuse `signal()`: complete the task, POST
the payload to the run, let the node read `context.signal` the way
`AwaitSignalNode` does. Rejected.

`$context['signal']` is ONE slot per run (`FlowRunService.php:74`), and the
walk that wakes consumes it (`:807`). The flow-engine spec already names the
resulting hazard — *"a flow with two awaiting steps MUST NOT have the second
read the answer given to the first"* — and `AwaitSignalNode` only escapes it
because the slot is cleared on consumption, which is a race that holds when
answers are minutes apart and stops holding when two people answer two tasks
in the same worker window.

The task removes the problem instead of guarding it. Every node's task is a
row addressed by uuid, held in that node's OWN resume slot, and terminality
is a property of that row. Two nodes, two rows, two independent answers, no
shared slot to race over. It also means the node needs no delivery
guarantee: the answer is not in transit, it is in the database.

`signal()` is still used — with an empty payload — as the WAKE, because it
is the one supported way to park a suspended run as due. It carries no
answer; the answer is read from the task.

### D-3 — The heartbeat stays, and null is not on the table

The brief for this change described suspending with `resumeAt: null`. That
is refused for two measured reasons.

First, `flow-engine`'s spec requires the opposite: *"A node that suspends
waiting on a signal MUST ALSO carry a heartbeat `resumeAt`."* The reason
given there applies verbatim — a completion can land while the run is still
mid-walk and has not suspended yet, and that loses the only wake the run was
going to get.

Second, and worse: `findAbandonedSignals()` matches
`status = suspended AND resume_at IS NULL AND updated < now - 14 days`
(`FlowRunMapper.php:589-605`; the window is
`FlowRunWorker.php:94`). No shipped node produces a null `resume_at`, so
that reaper has never fired on anything. A user-task node parking on null
would make human approvals its **first** input, and its action is
`STATUS_FAILED` with "Abandoned" — it would fail exactly the long-running
approvals a municipal case is made of, at the two-week mark, silently, with
the task still sitting in someone's inbox.

So: heartbeat, clamped the same way `AwaitSignalNode` clamps
(`AwaitSignalNode.php:87`, `:98` — 15 minutes default, 5-minute floor
because the stock cron period is five minutes). The cost is a no-op wake per
interval per open task, which is the same cost the platform already pays for
`await-signal` and which the node's own docblock already justifies.

The consequence is worth stating: a run holding an open user task will never
be reaped by the abandoned-signal path. That is correct — a task that is
open is not abandoned, it is unanswered, and the thing that should act on an
unanswered task is `expires_at` enforcement in `flow-business-timers`, which
knows about business days and escalation. Reaping a run for the sin of
waiting is the wrong instrument.

### D-4 — `advance` is `0 | N | "all"`, and `null` is REJECTED

ADR-098 D9 gives the node a budget. The design question is how "unlimited"
is spelled, and the answer is a string.

The tempting spelling is `null` — "no limit". In PHP and in JSON it is a
catastrophe of coercion, and every step of it is silent:

- `(int)null === 0`, so a config read as `(int)($config['advance'] ?? 0)`
  turns "unlimited" into "park for the worker" — the opposite behaviour, and
  a plausible-looking one, so nobody files it.
- `$config['advance'] ?? 'all'` cannot distinguish `null` from ABSENT,
  because `??` fires on both. The default and the explicit unlimited become
  the same value, so whichever the author picked, they get the other one
  half the time.
- `empty(null)` and `empty(0)` are both true, so any guard written as
  `if (empty($advance))` collapses the default and unlimited into one branch.
- A JSON round-trip through a definition editor may drop a null key
  entirely, so "unlimited" survives a save as "absent".

`"all"` has none of these properties: it is truthy, it is distinguishable
from absent, it survives a JSON round-trip, and it reads correctly in a
stored definition a human is looking at. The same reasoning is why the
registry publishes `configForm` as ABSENT rather than empty when a node
declares none (`FlowNodeRegistry.php:245-250`) — "did not say" and "said
nothing" must not be the same value.

`null` is therefore not merely undocumented, it is REFUSED in
`validateConfig()` with a message naming the value and stating the spelling.
Silently accepting it would leave a flow whose author asked for unlimited
running with a budget of zero, which is exactly the class of failure this
decision exists to prevent.

Alternatives considered: `-1` for unlimited (arithmetic-safe, but `-1 < 1`
is true, so every naive bound check treats it as "already exhausted");
`PHP_INT_MAX` (works, but a stored definition then contains
`9223372036854775807`, which no author can read as "all").

### D-5 — The budget bounds a walk, it does not become a second engine

`advance: N` does not re-implement stepping. The completion path calls
`FlowRunAdvancer::advance(run: $run, rethrow: true)` — the same call
`FlowService::run()` already makes for a synchronous run
(`FlowService.php:511`) — with a per-walk transition ceiling carried on the
run context and read by the engine's existing loop counter, alongside
`MAX_TRANSITIONS` (`FlowEngine.php:103`, `:325`). Whichever ceiling is lower
wins. There is one walk implementation, one oversight call site, one log
format.

Three properties fall out, and all three are the point:

1. **Oversight is not bypassed.** `assertOversightAllows()` runs before every
   hop (`FlowEngine.php:425`) and fails closed when a check throws
   (`FlowOversightRegistry.php:104-120`). An in-request continuation gets
   the identical treatment; there is no "fast path" that skips the gate.
2. **The run row exists before the walk.** `FlowService::run()`'s comment
   applies here too: queue first, then advance the queued row, so a
   synchronous continuation is still visible in the run log and still
   retryable. A continuation that executed invisibly would leave the person
   who completed the task with nothing to look at.
3. **Failure degrades to the default.** If the in-request walk throws, the
   task is already completed (a separate, committed transaction) and the run
   is already due. The worker picks it up on its next pass. The budget is an
   optimisation, so its failure mode must be the unoptimised behaviour —
   never a lost answer.

`"all"` stops at the next suspension, the next user task, or an end, because
those are the engine's natural stopping points already; it needs no extra
rule, only the honest documentation that "all" is not "forever".

### D-6 — The completion outcome goes onto the items

`AwaitSignalNode.php:294-296` states the rule and the reason: *"Onto every
item rather than into the token, because the steps that follow route per
item; a Switch cannot branch on something only the run holds."* This node
carries it unchanged, under `outcomeKey` (default `task`) rather than
`signalKey`, so a flow containing both nodes does not have them writing over
each other's key by default.

What goes in the bag is fixed rather than free-form: outcome, comment,
completing identity, performer type, `on_behalf_of`. A delegated completion
must be routable — "approved by the deputy under mandate X" is a different
fact from "approved by the manager", and a flow that cannot see the
difference cannot enforce a four-eyes rule.

A task that reached a terminal state WITHOUT a completion (terminated,
expired) is marked as such in the same bag. Both are terminal; only one is a
decision, and collapsing them would let an expired approval look like an
approval.

Non-array items are skipped rather than failing the run — the same
defensive shape as `AwaitSignalNode.php:298-300`.

### D-7 — Cancellation propagation is wired here because only here knows the mapping

`flow-task-entity` specifies WHAT must happen (terminate, with a reason,
audited, idempotent, never touching a run-less task). It cannot specify WHEN,
because it has no knowledge of nodes or branches.

Two sources drive it:

- **Run terminality** — a listener on the run reaching `completed`,
  `stopped`, `failed` or `dead_letter`. Idempotent by necessity: terminality
  can be observed by the completing request and again by the worker's
  reaper (`FlowRunWorker.php:226-287`), and a second observation must be a
  no-op, not a second audit entry.
- **Branch mootness** — an explicit call when the router's taken-exits
  decision (`FlowEngine.php:402`, `keepOnlyTakenExits()` at `:410`) leaves a
  user-task node's place unreachable (`FlowEngine.php:521`, `:540`). This is
  the harder half and the one
  worth being explicit about: the Petri net does not raise an event saying
  "this place will never be marked", so the node's tasks are resolved by
  asking which user-task nodes had live tasks in places the pruning just
  cleared.

Rejected alternative: a periodic sweep that terminates tasks whose run is
terminal. It works, and it is what a retrofit would do, but it leaves a
window — up to one cron period — in which a person can open and act on a
task belonging to a dead run. For an approval that window is a wrong
decision recorded as a right one.

### D-8 — Idempotent creation lives in the node's resume slot

The node must never create a second task, and the check must be per node:
`$resume->has('taskUuid')`, exactly the shape `AwaitSignalNode` uses for
`askedAt` (`AwaitSignalNode.php:362-364`).

The same file also documents why `askedAt` is written ONCE
(`AwaitSignalNode.php:344-348`): a heartbeat that restamps it resets the
record of how long somebody has waited, and "waiting 15 minutes" is the
reading that stops anyone chasing a two-week-old approval. The creation time
here is written once for the same reason.

Note what this does NOT protect against: two worker passes advancing the
same run concurrently. That is the run-level stale/lease concern the engine
already owns (`FlowRunWorker::reapStale()`, `FlowRunWorker.php:81`,
`:226-287`), not something a node can solve. The task's own `run_uuid` +
`node_id` uniqueness is the belt to this braces, and it belongs to
`flow-task-entity`'s mapper.

### D-9 — The palette carries the division of labour

Two nodes that both "wait for an answer" is a choice an author will get
wrong unless the palette tells them. So the descriptions are written as a
pair, and neither is generic:

- `openregister.await-signal` — *"Pause until another system reports back."*
- `openregister.user-task` — *"Ask a person or an agent to do something, and
  wait for their answer."*

Both are offered at `SCOPE_USER` and `SCOPE_ADMIN`. `AwaitSignalNode.php:168-170`
justifies it for itself — "Waiting grants no privilege" — and it holds
harder here: creating a task grants nothing, because the task service
authorizes the ANSWER independently, which is the entire point of
`flow-task-entity`.

## Risks / Trade-offs

- **Every open user task costs a heartbeat wake, forever, until something
  ends it.** At the 15-minute default a 30-day approval is ~2,880 no-op
  wakes. → Accepted: `AwaitSignalNode.php:78-87` already makes and justifies
  this trade, a no-op wake does no work, and the interval is configurable
  per node. The real fix for a task nobody answers is `expires_at`
  enforcement in `flow-business-timers`, not a shorter fuse here.
- **A run with an open user task can never be reaped as abandoned** (D-3), so
  a flow whose task is never answered and never expires holds
  `hasActiveRun()` true and its schedule shut. → Mitigated by making
  `expires_at` configurable on the node and by `flow-business-timers`
  enforcing it. Documented explicitly so nobody rediscovers it as a bug in
  the reaper.
- **`advance: "all"` makes a task completion as slow as the rest of the
  flow**, and puts a downstream node's side effects inside the completer's
  HTTP request. → The default is `0`; `"all"` is opt-in per node; the
  ceiling and the oversight gate still apply; and a failure degrades to
  worker advancement without losing the completion (D-5).
- **Branch-mootness detection is the part most likely to be incomplete.**
  Some graph shapes — a merge that never synchronises, a stage closed by a
  sub-flow — may leave a task un-terminated. → The run-terminality
  propagation is the backstop: whatever branch pruning misses, run
  termination catches. An orphan therefore survives at most as long as its
  run, never past it.
- **The node depends on a service that does not exist yet.** `flow-task-entity`
  must land first, and its `TaskService` signatures are the contract. → The
  dependency is declared, and the ONE thing this change asks of that service
  beyond its own spec is a terminality read by task uuid — cheap, and
  already implied by the inbox.
- **Two waiting nodes will confuse authors regardless of palette text.** →
  Mitigated by D-9, and bounded by the fact that using the wrong one is
  recoverable: an `await-signal` step in a human flow is unauthorized and
  uninboxed, which is visible immediately, not months later.

## Migration Plan

Nothing to migrate. The node is additive: a new registration in
`FlowNodeRegistrationListener`, a new palette entry, no schema change of its
own (`flow-task-entity` owns the tables), no change to any existing node or
endpoint.

Deploy order is the dependency chain: `flow-definition-versioning` →
`flow-task-entity` → this change. Rollback is removing the registration —
existing flows using the node then fail to resolve their type at run time
with the registry's ordinary "unknown type" error, which is the correct loud
failure and the same one any withdrawn node produces.

Existing `await-signal` flows are untouched by deployment and by rollback.

## Open Questions

- **Does a user-task node ever want more than one task?** A "three of five
  approvers" gate is a real municipal shape. Provisionally: ONE task per
  node per run, and multi-instance is a later change (or a `flow-parallel-streams`
  fan-out over a sub-flow). Deciding otherwise later adds a node config key;
  it does not invalidate anything specified here.
- **Where does an escalation land when it re-assigns?** If
  `flow-business-timers` reassigns a task on SLA breach, the node's stored
  task uuid is still valid, so nothing here changes. If it instead CANCELS
  and re-creates, the node's idempotence check would refuse to make the
  replacement. Provisionally: escalation reassigns, never recreates — to be
  confirmed when `flow-business-timers` is specified.
