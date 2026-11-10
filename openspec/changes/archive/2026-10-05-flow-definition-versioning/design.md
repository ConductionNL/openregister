# Design: flow-definition-versioning

## Context

See proposal.md — Why. The design-relevant state of the code today:

- `openregister_flows` is one row per flow, created in
  `lib/Migration/Version1Date20260803000000.php:67-126`. `nodes`, `edges` and
  `limits` are JSON columns on that row; `uuid` is the flow's identity
  everywhere else in the system.
- `FlowLocator::resolveFlow()` (`lib/Service/Flow/FlowLocator.php:88-115`)
  turns a flow uuid into the plain document the engine lowers, memoised in
  `$this->flowMemo[$flowId]` (lines 89-93) for the life of the request.
- `FlowRunAdvancer::advance()` calls that resolver on **every** pass
  (`lib/Service/Flow/FlowRunAdvancer.php:92`) and fails the run when it comes
  back null (`:98`).
- `FlowRunService::queue()` (`lib/Service/Flow/FlowRunService.php:321-352`)
  is the single funnel every dispatch path goes through — its own docblock
  says so — and calls `refuseDeadEnd()` first, which reads
  `$flow->getNodes()` / `$flow->getEdges()` straight off the head row.
- `openregister_flow_triggers`
  (`lib/Migration/Version1Date20260810140000.php:77-101`) denormalises
  `flow_uuid`, `event`, `register`, `schema_slug`, `enabled` with the match
  index `or_flowtrig_match_idx` deliberately covering the whole lookup so a
  trigger match on an object write touches ONE table.
- `SubFlowNode::execute()` resolves its child through the same locator
  (`lib/Service/Flow/Nodes/SubFlowNode.php:209`).
- Procest already runs this lifecycle for its workflow definitions:
  `procest/lib/Service/Workflow/WorkflowLifecycleGuard.php:53-57` holds the
  three constants and the preconditions; the enum and its prose live at
  `procest/lib/Settings/register.d/70-cmmn-case-model.json:25-26`.

## Goals / Non-Goals

**Goals:**

- A run resolves the same document from queue to termination, no matter how
  long it waits in between.
- The flow uuid stays the flow's identity, so no app, trigger row, sub-flow
  config or stored `flowId` has to be rewritten.
- Version resolution costs one indexed read and is memoisable, because the
  advancer does it on every worker pass for every run.
- A missing pinned version is a visible failure with a specific message, not
  a fallback.

**Non-Goals:**

- **In-flight instance migration.** Mapping a live token from version N's
  node onto version N+1's node — Camunda's process-instance migration — is
  out of scope, and this design deliberately makes it impossible rather than
  half-possible. See proposal.md — What does NOT change.
- Semantic versioning of flows. The version is an ordinal, not a
  compatibility statement; nothing computes "is version 4 compatible with
  version 3".
- Diffing two versions in the UI. The editor gains a version selector and a
  read-only view, not a graph diff.
- Branching or merging drafts. One draft at a time per flow.

## Decisions

### Decision 1: the head row keeps the identity; published versions are snapshot rows

Two shapes were considered.

**A — a row per version in `openregister_flows`.** This is procest's shape
(a definition row per version, pointing at its caseType). Rejected here: OR's
flow `uuid` is load-bearing OUTSIDE the table. `openregister_flow_triggers`
stores `flow_uuid`; `openregister_flow_runs` stores `flow_id` as a uuid;
`SubFlowNode`'s config names a flow by uuid; `FlowMapper::findByUuid()` is
assumed single-valued by `FlowLocator` and by `refuseDeadEnd()`. Making the
uuid non-unique would mean introducing a second "lineage" identifier and
rewriting every one of those call sites and their stored data — a migration
of other apps' configuration to express something they never asked about.

**B — head row plus a snapshot table (chosen).** `openregister_flows` stays
one row per flow and gains `version` + `lifecycle_status`. Its `nodes` /
`edges` / `limits` are the **editable working copy**. A new table
`openregister_flow_versions` holds the immutable snapshot of each published
version:

| column | why |
|---|---|
| `flow_uuid`, `version` | the pin, unique together |
| `status` | `published` \| `deprecated` — a draft has no snapshot row |
| `nodes`, `edges`, `limits`, `execution_mode` | exactly what `resolveFlow()` returns |
| `owner`, `organisation` | frozen with the graph: a run executes as the identity the version was published with |
| `published_at`, `published_by`, `deprecated_at` | the audit answer to "who changed the process" |

Invariant, enforced in one transaction: at most one `published` row per
`flow_uuid`. A draft never gets a row, so the table only ever grows by an
act of publication.

The cost of B is one denormalisation: a published head's `nodes` equals its
snapshot's `nodes`. That is accepted because it keeps every existing read
path working unchanged, and because a repair check can assert the equality
cheaply.

`owner` being frozen onto the version is a real decision, not a copy: a run
pinned to version 2 executes as version 2's owner. Reading `owner` from the
head would let re-assigning a flow's owner silently change the identity a
two-week-old suspended run resumes as — the same class of bug as the graph
moving underneath it.

### Decision 2: pin in `queue()`, resolve by (flow, version) in the locator

`FlowRunService::queue()` is the only place every dispatch path passes
through, so it is the only place pinning has to be written. It resolves the
flow's `published` version, writes `flow_version` onto the run, and hands the
resolved version — not the flow uuid — to `refuseDeadEnd()`, which today
would preflight the editable head and therefore judge a draft when deciding
whether a published version may run.

`FlowLocator::resolveFlow(string $flowId, ?int $version)` gains the version
argument, and its memo key becomes `"{$flowId}#{$version}"`. This is not
cosmetic: the worker advances a batch of runs in one process, and with the
current single-key memo (`FlowLocator.php:89-93`) the first run in the batch
would populate the cache and every later run of the same flow — including
one pinned to a different version — would silently read it. The memo is the
one place where "resolve by version" could look correct and behave wrongly.

`$version === null` means "the head, unversioned" and exists for exactly two
callers: the interactive test run of a draft, and the editor's own preview.
It is never reachable from a queued run once the migration has back-filled,
which is asserted by a test rather than by convention.

### Decision 3: a missing version fails with its own message, and never falls back

`FlowRunAdvancer.php:98` already fails a run whose flow cannot be resolved,
with `No app provides flow "%s" (deleted, or its app removed?)`. Versioning
adds a second, distinct reason — the flow exists but the pinned version does
not — because the two have different operator responses: the first means an
app was removed, the second means somebody deleted history.

The tempting alternative is a fallback to the latest published version, so
"the run keeps going". It is rejected outright: the run's marking names
places from the pinned graph, and its log records decisions taken against the
pinned graph. Re-pointing it produces a run that is internally inconsistent
and reports success. A loud failure is recoverable — an operator can requeue
against the current version, which is the existing retry semantics (retry
queues a NEW run). A silent promotion is not.

### Decision 4: the trigger index stays version-free

The alternative — adding `version` to `openregister_flow_triggers` — would
put a second dimension on the index that every object write pays for, to
answer a question the queue path answers one step later anyway. Instead:
only the published version contributes rows, publishing rebuilds that flow's
rows from the version being published, and deprecating the last published
version deletes them. `or_flowtrig_match_idx` and the "trigger matching MUST
NOT scale with the number of flows" requirement in
`openspec/specs/flow-engine/spec.md:427` are untouched — matching still
answers "which flow", and `queue()` answers "which version".

This also makes the draft rule fall out for free rather than needing a
filter: a draft's trigger nodes are not in the index, so they cannot match.

### Decision 5: a sub-flow pins its child at call time

Three options: inherit the parent's version number (nonsense — versions are
per-flow), resolve the child's version when the PARENT was queued (pins a
child on a branch that may never be taken, and pins it staler the longer the
parent waits), or resolve the child's published version when the step
actually executes (chosen).

Chosen because it is the same rule as everywhere else: a run is pinned when
it is queued, and a sub-flow call IS the child's queue moment for both
shapes — waiting and fire-and-forget. It also keeps `SubFlowNode`'s existing
property that a sub-flow is a call to whatever that flow currently is, which
is what makes a shared utility flow shareable across apps.

A child with no published version fails the step naming the flow and its
state, rather than falling through to the draft. Falling through would make
an author's unfinished edit executable from someone else's flow.

### Decision 6: an interactive test run of a draft carries its own snapshot

Authors must be able to try a draft — `POST /api/flow-runs/test`
(`appinfo/routes.php:1314`) exists for exactly that. But a draft has no
snapshot row to pin to, and writing one would put unpublished graphs into the
version lineage and into its uniqueness rules.

So a test run of a draft carries the resolved draft document on the RUN
itself (a `definitionSnapshot` entry in the run context), and the advancer
prefers that snapshot when present. The run is pinned in the same sense every
other run is — it cannot drift — without a version row existing. Test runs
are the only runs that ever carry an inline snapshot, which bounds the cost
and makes them identifiable in listings.

## Declarative-vs-imperative decision (ADR-031)

ADR-031's default path is declarative: a lifecycle belongs in
`x-openregister-lifecycle` on a schema in the register, executed by
`lib/Service/Lifecycle/TransitionEngine.php`, not in a new Service class.
This change takes the imperative path, and the reason is structural rather
than preferential.

**The declarative dialect operates on register objects. A flow is not one.**
`lib/Db/Flow.php:5-11` states the position explicitly: the flow definition is
deliberately NOT an OpenRegister object, because definitions used to live in
a register/schema and that meant every app owning flows needed its own
register, its own resolver and its own executor. `openregister_flows` is a
native table reached by mapper, not by `ObjectService`. `TransitionEngine`
has no object to transition here, `x-openregister-lifecycle` has no schema to
hang on, and the transition `inputs` contract
(`lib/Service/Lifecycle/TransitionEngine.php:675-704`) has no register write
to validate against. Making the lifecycle declarative would mean moving flow
definitions back into a register — undoing `flow-engine-unification`, the
change that consolidated them.

**So the guard is a service, and it mirrors one that already exists.** The
transitions (write the version row, deprecate the predecessor, rebuild the
trigger set) live in a `FlowVersionService`; the preconditions live in a
guard beside it, in the same split procest uses —
`WorkflowLifecycleGuard.php` owns "may this be published / deprecated", the
service owns "do it". Worth noting that procest's `workflowTemplate` IS a
register object and its lifecycle is still imperative, because its
preconditions are referential (every status a definition references must
belong to its own caseType) and the dialect does not express cross-object
referential checks. The flow equivalents — "the version being published has
no dead end", "no non-terminal run is pinned to the version being removed" —
are the same shape.

**What stays declarative:** nothing about flow versioning is expressible in
the dialect, so nothing is being taken away from it. In particular this
change adds no notification, aggregation, calculation or relation — if
"a version was published" later needs to notify, it notifies through the
ADR-031 subsystem from the same place every other flow-side send does
(`flow-messaging-nodes`), not through a second mechanism.

**No seed data.** No register or schema is introduced or modified, so
ADR-001 seed data does not apply. The equivalent obligation here is the
back-fill in the migration plan below, which is a data repair with an
idempotency requirement rather than seeded content.

## Risks / Trade-offs

- **The version table grows one row per publication, forever.** → Accepted:
  publications are human-rate events, and the rows are the audit trail of who
  changed a process. A retention policy for versions with no non-terminal
  runs and no history value is a later change, and it MUST NOT delete a
  version any run is pinned to.
- **Head/snapshot denormalisation can drift** if a code path writes the head
  while it is published. → Mitigated by the refusal at the API boundary, by
  the guard refusing to publish a head that is not a draft, and by a repair
  check asserting head-equals-snapshot for a published flow.
- **The upgrade window.** A run queued between the schema migration and the
  back-fill would have a null pin. → The back-fill runs in the same migration
  step as the column, and null-pin runs are treated as "resolve the head"
  exactly as today, so the worst case during the window is today's behaviour,
  not a failure.
- **Authors lose "just tweak the live flow".** Editing now costs an explicit
  create-draft and publish. → That is the point, and it is why the editor
  affordance is part of this change rather than a follow-up: the refusal must
  be visible before the edit, not on save.
- **A long-suspended run holds its version alive**, so an operator cannot
  fully retire a process while an approval from three weeks ago is still
  parked. → Deliberate — that is what `deprecated` means. The active-runs
  surface makes those runs findable so they can be stopped explicitly.
- **`enabled` and `lifecycle_status` are two flags an operator can confuse.**
  → Mitigated by the UI showing both with distinct language, and by the
  queue-time refusal naming which of the two stopped a run.

## Migration Plan

1. **Schema.** Add `version` (integer, notnull, default `1`) and
   `lifecycle_status` (string 16, notnull, default `draft`) to
   `openregister_flows`; add `flow_version` (integer, nullable) to
   `openregister_flow_runs`; create `openregister_flow_versions` with a
   unique index on `(flow_uuid, version)` and an index on
   `(flow_uuid, status)`.
2. **Back-fill.** For every existing flow, insert a `published` version 1 row
   from its current `nodes`/`edges`/`limits`/`execution_mode`/`owner`/
   `organisation`, and set the head's `lifecycle_status` to `published`.
   Every existing flow is treated as published because it is already live and
   already triggering; marking them drafts would stop the instance.
3. **Pin the in-flight.** Set `flow_version = 1` on every run not in a
   terminal state. Terminal runs are left null — pinning a finished run
   states nothing true about how it ran.
4. **Idempotency.** Both steps are guarded on existence, so re-running the
   migration creates no second version 1 and re-pins no run.
5. **Rollback.** The columns and table are additive and nothing reads
   `flow_version` when it is null, so reverting the app code restores exactly
   today's behaviour with the new columns inert. Dropping them is a separate,
   optional migration and MUST NOT be part of the rollback path — dropping a
   column that a re-deploy will need back is how a rollback becomes an
   outage.
6. **Verification.** After deploy: every flow has exactly one `published`
   version; no non-terminal run has a null `flow_version`; no run is pinned
   to a version that does not exist.

## Open Questions

- Whether a version retention policy is needed at all, and on what horizon.
  Deferrable: it changes neither the specs nor the tasks here, and it cannot
  be designed before there is a fleet's worth of publication data to look at.
- Whether the editor should offer "copy version N into a new draft" as well
  as "create draft from the published version". Deferrable: it is one extra
  source for the same create-draft transition, and adding it later changes no
  stored shape.
