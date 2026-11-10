# Design: flow-approval-consolidation

## Context

See proposal.md — Why. The design-relevant state of the code today:

- `openregister_approval_chains` and `openregister_approval_steps` are created
  by `lib/Migration/Version1Date20260325000003.php:53-220`; `requester_id` was
  added later by `lib/Migration/Version1Date20260714010000.php:70-89`. The
  steps table indexes `(chain_id, object_uuid)`, `status` and `role`
  (`:218-220`) — the exact three access paths the task inbox already serves.
- `ApprovalService::initializeChain()` (`lib/Service/ApprovalService.php:103-138`)
  creates one row per step definition, `pending` for index 0 and `waiting` for
  the rest, and dispatches the initiated event only for index 0 (`:129-136`).
- `approveStep()` (`:158-238`) checks separation of duties (`:169`), then
  `isInGroup()` (`:171` → `:412`), then writes the decision onto the step row
  itself (`:174-178`), then promotes the lowest-ordered `waiting` step to
  `pending` **in the same request** (`:193-204`), then dispatches Approved
  followed by either Initiated or Completed (`:209-236`).
- `rejectStep()` (`:261-320`) terminates the chain — there is no next-step
  event and no propagation onto the remaining `waiting` rows, which simply
  stay `waiting` forever until the gate deletes them.
- `ApprovalChainGateListener::evaluateGate()`
  (`lib/Listener/ApprovalChainGateListener.php:175-262`) is the enforcement
  point. It calls `installSchema()` first for idempotency (`:186`), fails
  closed when the chain cannot be resolved (`:200-206`), releases when every
  step is approved (`:225`), **deletes** the step rows of a rejected cycle
  (`:232`), and otherwise provisions and rejects (`:249-259`).
- `ApprovalChainAnnotationInstaller::upsertChain()` (`:134-175`) compiles
  `approvers[].role` into a JSON `steps` array of `{order, role}`.
- `ApprovalChainAdvanceListener` (`lib/Listener/ApprovalChainAdvanceListener.php:69-124`)
  subscribes to the completed event and calls
  `TransitionEngine::transition()` fail-soft (`:110-121`).
- The only fleet subscriber to any of the four events is filinq/docudesk,
  registering all four onto one listener
  (`../docudesk/lib/AppInfo/SigningEventRegistrar.php:64-67`).
- `AwaitSignalNode::configKeys()` (`lib/Service/Flow/Nodes/AwaitSignalNode.php:180`)
  is `['question','assignee','signalKey','heartbeatMinutes','failOnReject']` —
  no correlation field. `FlowRunController::resume()`
  (`lib/Controller/FlowRunController.php:425-450`) resolves the run by uuid
  (`:427`) and authorizes only "may run this flow" (`:432`).
- openconnector's sweep (`../openconnector/lib/Service/ApprovalService.php:638-681`)
  pages 500 `pending` rows and filters `expiresAt` in PHP (`:655-658`), and
  collapses `onTimeout: error` and `skip` onto the same write (`:662`).
  `flow-business-timers` already specified the corrected timer half.

## Goals / Non-Goals

**Goals:**

- One decision surface for approval work, with the retired one incapable of
  being reached rather than merely deprecated.
- Ordering as an explicit, authorized, audited construct — the twenty lines at
  `ApprovalService.php:193-204` made into a contract instead of a side effect
  of a loop.
- A migration whose failure mode is a loud stop, not a half-migrated database
  reporting success.
- Zero edits to any schema declaring `x-openregister-approval-chains`, in any
  app, on upgrade.
- A retirement contract precise enough that a leaf app's migration is a
  mechanical exercise and a gate can check it.

**Non-Goals:**

- **Extending the declarative dialect.** Per-step deadlines, parallel
  positions, quorum, escalation policy and delegation policy on
  `x-openregister-approval-chains` are all desirable and all deliberately
  absent. A retirement that also grows the contract cannot be verified as
  behaviour-preserving.
- **Migrating any leaf app.** Not one line changes in procest, decidiq,
  pipelinq, planix, buildiq, filinq or openconnector.
- **Dropping the legacy tables.** Left for a follow-up migration for the
  reason argued in Decision 6.
- **A general workflow-instance store.** A sequence is ordinal. Stages,
  sentries and milestones are `flow-cmmn-case-semantics`.
- **Fixing openconnector's unbounded sweep or its collapsed `onTimeout`.**
  Both are recorded; the corrected semantics live in `flow-business-timers`
  and the defects are issues, not edits here.

## Decisions

### Decision 1: an approval is a task SEQUENCE, not a generated flow

The tempting move, given ADR-098's "one engine", is to compile a declared
chain into a flow definition of N `openregister.user-task` nodes in series and
queue a run per gated object write. It was considered and rejected on three
measured grounds.

**The gate is synchronous and the engine is not.** `ApprovalChainGateListener`
runs inside `ObjectUpdatingEvent` and must decide *now* whether the write is
refused. A flow run is queued and advanced by a worker; the stock cadence is
five minutes (`AwaitSignalNode.php:82-83`). The gate would have to provision a
run and then immediately ask it a question the run has not reached yet.

**It would make step-to-step advance asynchronous, which is a regression.**
Today the next approver is pending before `approveStep()` returns
(`ApprovalService.php:193-204`). Through the engine that promotion is a
transition, so it costs either an `advance` budget on every generated node or
a worker pass. ADR-098 D9 gives us the budget, but spending it to reproduce
behaviour that is currently free is a worse trade than not needing it.

**It would put machine-authored definitions into the version lineage.**
`flow-definition-versioning` guarantees one published version per flow and a
pin per run. A definition generated from a schema annotation would need
publishing on every schema save, would deprecate its predecessor each time,
and would accumulate a version row per schema edit for a graph nobody
authored. The change that made publication a human-rate event would be undone
by a listener.

So the sequence is its own small construct: `openregister_task_sequences`
holding template, anchor, requester, resolved tier, position cursor, outcome
and timestamps, with the positions being ordinary tasks carrying a
`sequence_uuid` and an ordinal.

The escape hatch is the point of the whole chain: an author who wants a graph
— branches, timers, parallel approvers, sub-flows — uses `user-task` nodes in
a real flow. The sequence exists for the case that has no graph, which is
every schema-declared approval in the fleet today.

### Decision 2: the annotation is the contract; only what executes it moves

`x-openregister-approval-chains` is not an implementation detail of the
retired engine. It is the surface fleet schemas are authored against, and it
is load-bearing for SAFETY: the gate refuses a transition until the chain
completes, and refuses fail-closed when it cannot provision
(`ApprovalChainGateListener.php:200-206`). Retiring the annotation with the
tables would turn every declared gate into an open door on upgrade — a
fail-open regression delivered by a refactor, which is the worst shape a
security change can take.

So the annotation keeps its exact declared shape and its place in the schema
annotation vocabulary. `ApprovalChainAnnotationInstaller` becomes a compiler
to a task template; `ApprovalChainGateListener` keeps its two error codes
(`approval-chain-pending`, `approval-chain-misconfigured`) because leaf UIs
match on the strings; `ApprovalChainAdvanceListener` swaps its subscription.
The three classes keep their names: renaming them would produce a diff in
which nothing is recognisable and the behaviour-preservation argument becomes
unreviewable.

### Decision 3: the chain is a TEMPLATE, the object's cycle is a SEQUENCE

`ApprovalChain` conflates two things: the configuration (name, schema,
statusField, `steps` JSON) and the identity a step points at via `chainId`.
`flow-task-entity` already has the configuration half — `template_id`,
`template_version` and a frozen `template_snapshot`, with the freeze specified
so a running instance cannot be re-shaped by an edit.

So a chain becomes a task template and an object's attempt becomes a sequence
instantiated from it, with the template snapshot frozen at provisioning. This
also fixes a live hazard for free: today `upsertChain()` rewrites the chain's
`steps` JSON on every schema save (`ApprovalChainAnnotationInstaller.php:164`)
while in-flight steps are still pointing at that chain by id, so an
administrator editing a schema silently changes the definition of an approval
that is already half-decided. The frozen snapshot makes that impossible
without anybody having to notice it was possible.

The resolved amount tier is frozen the same way and for the same reason: today
`resolveStepsOverride()` (`ApprovalChainGateListener.php:277-300`) re-resolves
the tier from `$newData` on every attempt, so raising the amount mid-cycle
re-routes an approval that is already running.

### Decision 4: full cutover, because the one consumer is bidirectional

A shim was costed. It buys nothing.

The four events have exactly one registered fleet subscriber
(`SigningEventRegistrar.php:64-67`), and that subscriber's contract is a loop,
not an observation: the docblock at
`../docudesk/lib/EventListener/ApprovalStepListener.php:20-23` states that the
signing provider *"is then responsible for calling
`ApprovalService::approveStep` / `rejectStep` back, closing the loop."*
Re-emitting the four events from the task service while deleting the service
they reply to would leave filinq receiving prompts it cannot answer — a green
integration that silently never completes a signature. That is a worse failure
than a load-time break, because nobody looks at it.

So: events removed, service removed, routes removed, components removed. An
app registering a listener for a deleted class fails at load, visibly, on the
day of deploy. filinq migrates in `filinq: migrate-signing-to-or-tasks`.

The one thing that is NOT removed on the same principle is the pair of error
code strings. They are matched by leaf UIs and cost nothing to keep; breaking
them would be gratuitous.

### Decision 5: the rejected cycle is closed, not deleted

`ApprovalChainGateListener.php:232` calls
`deleteByChainAndObject()` when a rejected cycle is found, so a resubmission
destroys the record of who refused and why. The comment calls it "clear it so
a fresh attempt opens a new cycle" — the intent is right and the mechanism is
a data loss. A sequence is terminal-and-kept; a new attempt opens a new
sequence with a later open time. Resubmission behaviour is byte-identical from
the author's side; the audit stops disappearing.

This is the one place where the migrated behaviour differs observably from the
retired behaviour on a non-error path, and it is called out in the spec
because a reviewer should not have to discover it.

### Decision 6: the migration is one-way, and rollback is bounded and explicit

The legacy tables are **not dropped**. They are left populated, and each
migrated step records the task it became while each migrated task records the
step it came from, so the two sets reconcile by identity rather than by
counting.

That gives a clean rollback for the only window where rollback is actually
safe: **before the first post-cutover decision**. Redeploying the previous app
version restores the retired engine over rows it never stopped owning, and
nothing was lost, because nothing had been decided on the new surface yet.

After the first post-cutover decision, rollback is NOT free, and the design
refuses to pretend otherwise. A decision recorded on a task has no place in
the old schema that can hold its performer type, its on-behalf-of identity or
its append-only audit. The supported path is a reverse repair step shipped
with this change that writes migrated tasks' decisions back onto their
originating step rows — outcome, decider, comment, decision time — for the
subset the old schema can express, and REPORTS what it could not carry rather
than dropping it. Beyond that, the answer is roll forward.

Dropping the tables is a separate, later migration, deliberately not part of
the rollback path: dropping a table that a re-deploy would need back is how a
rollback becomes an outage.

### Decision 7: correlation resolution is an indexed column, never a JSON scan

The correlation key is stored in its own indexed column on the run, populated
when the await-signal step suspends. The alternative — resolving by scanning
`context` JSON for suspended runs — is what the existing signal path already
effectively costs, and it would put a table scan on every inbound webhook.

Resolution is fail-closed in both directions and this is the whole design:
zero matches is a 404 and is NOT buffered (a buffered signal is a signal
delivered to a run that suspends later and was never the addressee); more than
one match is a 409 and wakes nothing (picking one is picking wrong half the
time, silently). Uniqueness is not enforced by a database constraint, because
two runs legitimately holding the same key is a modelling mistake by the
author, not a corruption — it must be reported at delivery, where the author
sees it, rather than at suspension, where it would fail a run that is behaving
as written.

Authorization is unchanged from `resume()`: same authority, different address.
The known hole at `FlowRunController.php:423-436` — "may run the flow" is not
"may decide this" — is not widened, because a correlated signal explicitly
cannot complete a task. That is the boundary `flow-user-task-node` drew and
this change restates rather than moves.

### Decision 8: separation of duties moves up, and gets stricter exactly once

`verifySeparationOfDuties()` (`ApprovalService.php:334-352`) resolves the
schema lazily and defaults to ON when a declarative entry exists
(`resolveSeparationOfDuties()`, `:371-397`, `($entry['separationOfDuties'] ??
true) !== false`). That default is kept — an unstated policy on an approval is
the safe one.

The one deliberate tightening: the check runs against the acting identity AND
the on-behalf-of identity. Delegation does not exist in the retired engine, so
there is no behaviour to preserve here; there is only a hole to not dig. A
delegated self-approval is a self-approval.

The refusal stays distinguishable from an authorization failure, which the
current implementation already gets right and documents at
`ApprovalService.php:165-168`: separation of duties is evaluated BEFORE the
group check so a self-decision gets an honest error instead of being masked.

### Decision 9: the anti-pattern gate lives in hydra-gates, not in OpenRegister

The rules are fleet policy (ADR-022), the checked artefacts are other repos,
and the runner is already the single source of truth for mechanical checks.
OpenRegister ships the CONTRACT — the spec requirements and the retirement
inventory the gate reads — and the gate itself is a hydra-side deliverable in
`ConductionNL/.github`, `hydra-gates/`.

The gate must check three different kinds of thing and the third is the one
that makes it useful now rather than in six months: shapes (a home-grown step
engine, a stored `overdue`, a definition mirror), declarations (a schema whose
properties mirror `nodes`/`edges`/`limits`/`trigger`), and **broken
integrations** (a call to a removed route, a listener registration for a
removed event class). The third category is not a style finding; it is a
runtime failure detected statically, and it is what turns
`consume-or-approval-workflow-fleet-wide`'s proposed 90-day WARN period into a
sensible policy rather than a delay.

### Decision 10: hermiq's mirror is contract-retired here and deleted there

The rule — contribute node types, resolve definitions from the flow entity —
is fleet-wide and belongs in this spec. Removing `agentflow` and
`agentflowrun` from `../hermiq/lib/Settings/hermiq_register.json:3589,3678` is
a hermiq migration with hermiq's own data in it, and it is named as
`hermiq: retire-agentflow-object-store`.

Worth recording why the mirror is still dangerous although the UI already
moved: `../hermiq/src/manifest.json:1248` re-pointed the index at
`/api/flows?app=hermiq`, and `SeedHydraTriageFlow.php:331` writes through
`FlowMapper` — but `SeedHydraTriageFlow.php:114` still declares
`FLOW_SCHEMA = 'agentflow'` and the two schemas are still installed. A
declared mirror with a live constant pointing at it is one convenient patch
away from being written to again, which is exactly how it drifted the first
time.

## Declarative-vs-imperative decision (ADR-031)

ADR-031's default is declarative: behaviour belongs in `x-openregister-*` on a
schema, executed by the platform, not in a new Service class. This change is
unusual in the chain because it is the one that most obviously keeps the
declarative path — and it keeps it deliberately, not by accident.

**What stays declarative, and gets stronger.**
`x-openregister-approval-chains` remains the authoring surface for every
schema-gated approval in the fleet, unchanged in shape. Nothing is moved from
the annotation into code; the annotation gains reach, because what it
provisions now has deadlines, escalation, delegation, an inbox and an audit it
never had. A schema author writes the same eight lines and gets strictly more.

**What is imperative, and why the dialect cannot hold it.** The sequence
service — provision, enable-next, terminate-remainder, freeze the snapshot and
the tier — is imperative for the same structural reason
`flow-definition-versioning` gave for its own guard: the declarative lifecycle
dialect operates on register OBJECTS, executed by
`lib/Service/Lifecycle/TransitionEngine.php`. A task is not a register object
— `flow-task-entity` chose a native table over OR objects and recorded why —
so `x-openregister-lifecycle` has no schema to hang on and the transition
`inputs` contract (`TransitionEngine.php:675-704`) has no register write to
validate against. Expressing sequence advance declaratively would mean putting
tasks back into a register, undoing the decision the entity change made.

The ordering rule is also cross-row, which the dialect does not express in any
form: "enabling position N+1 is permitted iff position N is terminal with an
approving outcome and no earlier position is rejected" is a statement about
sibling rows, and the dialect's guards are per-object.

**Notifications stay in the ADR-031 subsystem.** This change adds no channel,
no template and no dispatch of its own. A position becoming enabled is a
transition on a system entity, and
`AnnotationNotificationDispatcher::dispatchWithSchema()` is already public and
already serves six non-object system entities — the same seam
`flow-task-inbox-projections` rides. `transition(action)` trigger matching
already exists in the dispatcher. Nothing here needs a second notification
path, and building one would be the anti-pattern this change exists to
enforce against.

**No seed data (ADR-001).** No register and no schema is introduced or
modified: the sequence is a native table, the templates are created from
schemas that already exist, and the annotation vocabulary entry is already
registered. The equivalent obligation is the data migration and its
verification below.

## Risks / Trade-offs

- **filinq breaks at deploy, by design.** → Accepted and sequenced: the
  breakage is at app load (a listener registration for a missing class), not
  at signature time, so it is discovered by the deploy rather than by a
  customer waiting for a document to be signed. The migration change is named
  and must land in the same release train. Deploying this change without it
  leaves filinq's signing flow down.
- **A partially applied migration is the worst possible state**: some
  approvals decidable on tasks, some still on steps. → Mitigated by the
  verification being part of the migration and failing loudly, by the legacy
  decision paths being gone in the same release so a half-migrated step cannot
  be decided at all, and by the reconciliation being by identity rather than
  by count.
- **The rollback window is narrow and depends on nobody deciding anything.**
  → Stated rather than hidden (Decision 6), with a reverse repair for the
  expressible subset and an explicit "roll forward" beyond it. An operator who
  believes rollback is free after a week of decisions is the actual risk, so
  the migration writes the cutover timestamp where an operator will find it.
- **The sequence is a second orchestration construct beside the flow.** →
  Bounded on purpose: ordinal, no branching, no parallelism, no timers of its
  own. If it ever needs a branch, that is the signal that the case belongs in
  a flow, and the escape hatch already exists.
- **Freezing the template snapshot changes behaviour for an administrator who
  relied on editing a schema to re-shape a running approval.** → That
  behaviour is a bug being removed, not a feature; but it is observable, so
  it is specified rather than slipped in.
- **The correlation key is author-supplied, so two runs can collide.** →
  Reported at delivery as an ambiguity refusal rather than guessed. A
  uniqueness constraint was rejected because it would fail the second run at
  suspension time for a mistake that only matters if a signal ever arrives.
- **`consumedAt` has no confirmed home** (see Open Questions). → Provisionally
  a `consume` verb on the task audit; the risk if that is wrong is confined to
  openconnector's migration, which is a separate change, and the spec requires
  a home to exist before the runner retires — so getting it wrong blocks a
  retirement rather than losing a semantic.

## Migration Plan

1. **Schema.** Create `openregister_task_sequences` (`uuid`, `template_id`,
   `template_version`, `template_snapshot`, `anchor_object_uuid`,
   `register_id`, `schema_id`, `chain_key`, `requester_id`, `resolved_tier`,
   `position_cursor`, `status`, `outcome`, `run_uuid` nullable, `node_id`
   nullable, `opened_at`, `closed_at`) with indexes on
   `(anchor_object_uuid, template_id)` and `(status, template_id)`. Add
   `sequence_uuid` + `sequence_position` to `openregister_tasks` with an index
   on `(sequence_uuid, sequence_position)`. Add `legacy_step_id` to
   `openregister_tasks` and `migrated_task_uuid` to
   `openregister_approval_steps` — the reconciliation pair. Add
   `correlation_key` to `openregister_flow_runs` with an index on
   `(status, correlation_key)`.
2. **Templates.** For every row in `openregister_approval_chains`, create one
   task template named by the chain's `name`, bound to its `schemaId`, with
   one position per entry in its `steps` JSON carrying that entry's `role` and
   `order`. Guarded on existence, so a re-run creates no second template.
3. **Sequences and tasks.** For every distinct `(chain_id, object_uuid)` in
   `openregister_approval_steps`, open one sequence from that chain's template
   and convert each step to a task at the step's `stepOrder`: `role` →
   candidate group with performer type `group`; `requesterId` → sequence
   requester; `created` preserved; `pending` → enabled; `waiting` →
   non-enabled; `approved` / `rejected` → terminal with the matching outcome.
   Write `legacy_step_id` and `migrated_task_uuid` on both sides.
4. **Decision history.** For every terminal step, append a task audit entry
   carrying `decidedBy`, `comment`, `decidedAt` and the outcome, attributed to
   the recorded decider, flagged as migrated. A decider that no longer exists
   keeps its recorded string.
5. **Sequence status.** Close each sequence: rejected if any step was
   rejected; completed if every step was approved; running otherwise, with its
   cursor at the ordinal that was pending. A `(chain, object)` set with no
   pending and no waiting step and no approval at all is closed as terminated,
   not left running.
6. **Freeze the legacy rows.** Stamp `migrated_task_uuid` on every step and
   record the cutover timestamp in app config where an operator will find it.
   The tables keep their data; the code that could write them is gone in the
   same release, so they are undecidable by construction rather than by a
   flag.
7. **Verification, in the migration, failing loudly.** Every non-terminal step
   has exactly one non-terminal task; every chain has exactly one template; no
   `(object, template)` has two running sequences; every migrated task's
   ordinal equals its step's `stepOrder`; the count of enabled tasks equals
   the count of steps that were `pending`. Any mismatch names the chain, the
   object and the step, and the migration stops.
8. **Idempotency.** Steps 2-6 are each guarded on the reconciliation columns,
   so a second run creates no template, no sequence, no task and no audit
   entry.
9. **Rollback.** Before the first post-cutover decision: redeploy the previous
   app version; the legacy tables are intact and the retired engine resumes
   over them. After the first post-cutover decision: run the reverse repair
   step shipped with this change, which writes migrated tasks' decisions back
   onto their step rows for the fields the old schema can express and REPORTS
   the fields it cannot (performer type, on-behalf-of, mandate, per-entry
   audit), then redeploy. The tables are NOT dropped by this change, and
   dropping them MUST NOT be part of any rollback path.
10. **Follow-ups, named and not done here**: `filinq:
    migrate-signing-to-or-tasks`; `hermiq: retire-agentflow-object-store`;
    `openconnector: retire-hitl-runner-to-or-tasks`; per-app leaf migrations
    for procest, decidiq, pipelinq, planix and buildiq; the hydra-gates
    anti-pattern gate; the drop-legacy-approval-tables migration; and the
    scheduled-filter dialect repair covering openconnector's two rules that
    spell `op` instead of `operator` (ConductionNL/openregister#2787).

## Open Questions

- **Where `consumedAt` lands.** openconnector's `approval_request` carries a
  consumed marker so an approved-but-unconsumed request cannot silently
  re-authorize a later run; the schema fragment's own `$comment` explains it.
  Provisionally it becomes a `consume` verb on the task audit, referenced by
  the work that relied on the approval. Deferrable: it changes nothing in this
  change's specs or tasks — the requirement here is only that a home exists
  and is named before the runner retires — and the shape is best fixed by the
  openconnector migration that has the call sites in front of it.
- **Whether the sequence gains an explicit skip verb.** Several fleet
  approvals want "this position is not required for this instance" (an absent
  approver, a delegated authority already exercised). Provisionally NO for
  this change: skipping is a change to the ordering contract, and adding it to
  a retirement makes behaviour-preservation unprovable. Deferrable because
  adding a verb later changes no stored shape.
- **Whether the retirement inventory should be a spec fixture or generated.**
  Provisionally a checked-in fixture the test asserts against, because it must
  fail when openconnector's schema changes. Deferrable: it changes no
  requirement, only where the test reads from.
