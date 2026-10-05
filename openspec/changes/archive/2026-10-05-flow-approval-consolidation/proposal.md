---
kind: code
depends_on: [flow-business-timers, flow-user-task-node]
---

# Proposal: flow-approval-consolidation

## Summary

Retire OpenRegister's own approval engine onto the task service. The
`ApprovalChain` / `ApprovalStep` pair, `ApprovalService`, the two listeners,
the four events, the nine REST routes and the two Vue components go away;
what a schema declares stays exactly where it is, and is executed by
`TaskService` through a new ordered **task sequence**. There is **no facade
period** — ADR-098 Decision 1 says full migration, and the measurement below
says a facade would not have saved the one app that consumes the events
anyway.

This change also writes down the contract the leaf apps migrate against, so
the anti-pattern gates that `consume-or-approval-workflow-fleet-wide` and
`consume-or-workflow-engine-fleet-wide` proposed can finally be switched on
against something that exists.

## Why

**OpenRegister ships two human-work engines, and the older one is a subset of
the newer one.** `ApprovalStep` (`lib/Db/ApprovalStep.php:62-136`) is eleven
columns: uuid, chainId, objectUuid, stepOrder, role, status, decidedBy,
comment, decidedAt, created, requesterId. Every one of them has a home in
`openregister_tasks` — `flow-task-entity`'s own proposal says so in as many
words ("Shape-wise this is `ApprovalStep` … given a nullable `run_uuid` +
`node_id`"). What `ApprovalStep` has that the task does not is **ordering**:
`stepOrder` plus the `waiting` → `pending` promotion at
`ApprovalService.php:193-204`. That is the whole of the delta, and it is
twenty lines.

**Everything else the chain does, the task does better.** The step's
authorization is one line — `isInGroup($userId, $role)`
(`ApprovalService.php:412-413`) — with no claim, no delegation, no
`on_behalf_of`, no candidate pool, no routing strategy and no audit row: the
decision is written onto the step itself, destructively
(`ApprovalService.php:174-178`). There is no inbox: "what am I being asked to
approve?" is `GET /api/approval-steps` filtered by role
(`appinfo/routes.php:1238`), which knows nothing about any other kind of work
a person owes. There is no deadline of any kind — the chain has no `dueAt`,
no `expiresAt`, no escalation and no sweep, so an approval that nobody
answers stays pending until the heat death of the register.

**The role performer is the one thing worth keeping, and it is the fleet's
only one.** `ApprovalStep.role` (`lib/Db/ApprovalStep.php:90`) stores a
Nextcloud group NAME resolved to people at decision time, not a uid.
`flow-task-entity`'s performer model was written to hold exactly this
(spec.md, "The performer model spans people, groups, agents and workers":
*"a ROLE name resolved to people at authorization time —
`lib/Db/ApprovalStep.php:81-87` stores a role, not a uid"*). Consolidation is
therefore not a capability trade; it is a strict superset, provided the
migration actually carries the role across.

**A facade would protect one app, and would not even do that.** The four
events (`ApprovalStepInitiatedEvent`, `…Approved`, `…Rejected`,
`…Completed`) have exactly ONE registered subscriber in the whole fleet:
filinq/docudesk registers all four onto a single listener
(`../docudesk/lib/AppInfo/SigningEventRegistrar.php:64-67` →
`../docudesk/lib/EventListener/ApprovalStepListener.php`). And that listener
does not merely observe — its own docblock (`:20-23`) says the signing
provider *"is then responsible for calling `ApprovalService::approveStep` /
`rejectStep` back, closing the loop."* filinq consumes the events **and**
drives the service. An event shim would re-emit four events into an app whose
reply path had been deleted underneath it: a shim that reports success while
the loop stays open. So the events are REMOVED, with a named migration, and
filinq moves in its own change.

**The declarative surface is a different thing from the runtime, and it must
not move.** `x-openregister-approval-chains` on a schema is what fleet apps
actually author against; `ApprovalChainGateListener` REFUSES the gated
transition until every provisioned step is approved
(`lib/Listener/ApprovalChainGateListener.php:186-262`), fail-closed even when
the chain cannot be provisioned (`:200-206`). Deleting the annotation along
with the tables would turn every declared gate in the fleet into an open
door, silently, on upgrade. So the annotation stays, byte-identical, and only
what executes it changes — which is also ADR-031's default path: declarative
first, imperative only where the dialect cannot reach.

**And there is a harvest deadline.** ADR-065 relocates openconnector's
runner. Its `approval_request` schema
(`../openconnector/lib/Settings/register.d/hitl-approval-rule-action.json`)
is the fleet's only source of `expiresAt` + `onTimeout: error|skip|dead_letter`
+ `onReject: error|skip|dead_letter`, swept at 300s by
`../openconnector/lib/BackgroundJob/ApprovalTimeoutSweepJob.php:53` into
`ApprovalService::sweepExpired()`
(`../openconnector/lib/Service/ApprovalService.php:638-681`).
`flow-business-timers` already harvested the timer half. What it did not take
is `onReject` (a decision outcome, not a clock) and `consumedAt` (an
approved-but-unconsumed request must not silently re-authorize a later run —
the schema fragment's own `$comment` says so). Those two have to land
somewhere before the runner moves, and this is the change that owns
"nothing was lost".

**hermiq mirrored the flow definition into an object store and it drifted.**
`agentflow` (`../hermiq/lib/Settings/hermiq_register.json:3589-3590`) and
`agentflowrun` (`:3678`) hold `nodes`, `edges`, `limits`, `trigger`, `cron`,
`enabled` — the same fields as `openregister_flows`. hermiq's own manifest
records the outcome (`../hermiq/src/manifest.json:1248`): *"a duplicate mirror
of the native flow rows — so the list showed objects while the engine ran the
native rows, free to drift."* The index has since been re-pointed at
`/api/flows?app=hermiq`, but the schemas are still declared and
`SeedHydraTriageFlow.php:114` still carries `FLOW_SCHEMA = 'agentflow'` as a
vestige while the repair itself writes through `FlowMapper` (`:331`). A
mirror that is merely unused today is a mirror that will be used again.

## What Changes

- **`ApprovalChain`, `ApprovalStep` and their mappers are RETIRED**, together
  with `lib/Service/ApprovalService.php` (464L),
  `lib/Controller/ApprovalController.php` (361L), the nine routes at
  `appinfo/routes.php:1231-1240`, `src/components/workflow/ApprovalChainPanel.vue`,
  `src/components/workflow/ApprovalStepList.vue`, and the four
  `lib/Event/ApprovalStep*Event.php` classes. **BREAKING** for any caller of
  `/api/approval-chains` or `/api/approval-steps` and for any subscriber to
  the four events.
- **An ordered task sequence** — `openregister_task_sequences` +
  `TaskSequenceService` — supplies the only thing the chain had that the task
  does not: `stepOrder`, promotion of the next task when one completes, and
  termination of the remainder when one is rejected. It is the task
  equivalent of `ApprovalService.php:193-204`, made explicit, authorized and
  audited. It is NOT flow-specific: a sequence with no `run_uuid` is
  first-class, exactly as a task with no run is.
- **The declarative surface is preserved verbatim and re-pointed.**
  `x-openregister-approval-chains` keeps its shape — `transition`,
  `approvers[].role`, `amountField` + `minAmount` tiers,
  `separationOfDuties`, `onApprove: advanceTransition`, `statusOnApprove` /
  `statusOnReject`. `ApprovalChainAnnotationInstaller` becomes a compiler
  that provisions a task TEMPLATE instead of an `ApprovalChain` row;
  `ApprovalChainGateListener` keeps refusing the transition, and asks the
  SEQUENCE whether it is complete instead of asking `ApprovalStepMapper`.
  **No schema in any app is edited by this change.**
- **The role performer survives as a first-class case**, not as a string
  copied into a comment: a migrated step becomes a task with
  `performer_type: group`, the role as its candidate group, and the
  `single-role` routing strategy — the mapping `flow-task-entity`'s performer
  requirement was written to accept.
- **Separation of duties is kept and hardened.** `verifySeparationOfDuties()`
  (`ApprovalService.php:334-352`) defaults to ON for a declared chain and
  refuses a decision by the recorded requester. It moves into the sequence's
  authorization, where it also survives delegation: an `on_behalf_of` that
  resolves to the requester is the same self-decision, and today's check
  would miss it because delegation does not exist today.
- **A full data migration with an in-flight contract.** Every chain becomes a
  template; every step becomes a task; every non-terminal step becomes a
  non-terminal task at the same position with the same role, requester and
  age. The old rows are left in place, marked migrated, and **made
  undecidable** — the code that could decide them is gone in the same
  release. Verification is specified, not assumed: counts reconcile, no
  in-flight approval is lost, and no approval can be decided twice.
- **The four events are REMOVED with a named replacement.** Consumers move to
  the task lifecycle events from `flow-task-entity` plus the sequence's own
  `TaskSequenceCompletedEvent`. The mapping is written down per event, and
  filinq's migration (`filinq: migrate-signing-to-or-tasks`) is named as the
  follow-up that consumes it.
- **`AwaitSignalNode` stays, and gains a correlation key.** The division of
  labour is the one `flow-user-task-node` already stated — a signal is for a
  system that will call back, a task is for a performer who must be found.
  What a system calling back cannot do today is say WHICH run it is calling
  about without knowing a run uuid: `POST /api/flow-runs/{uuid}/resume`
  (`appinfo/routes.php:1312`) is addressed by run uuid and by nothing else,
  and `configKeys()` (`AwaitSignalNode.php:180`) has no correlation field.
  "The vote closed", "the batch settled", "the provider finished" are events
  that carry a business key, not a run uuid. A `correlationKey` config plus a
  key-addressed signal endpoint closes that, fail-closed on ambiguity.
- **The openconnector HITL retirement contract**: an inventory of the six
  semantics its `approval_request` carries, each with the named home it lands
  in (`flow-business-timers` for the clock, here for `onReject` and
  `consumedAt`, `flow-task-entity` for the approver group and the comment),
  and a verification that the runner cannot be retired while any of them is
  homeless.
- **The migration contract + anti-pattern gate for leaves**: what a leaf app
  MUST stop shipping (its own step-routing engine, its own approver-group
  resolution, a schema mirroring flow-definition fields, a stored `overdue`)
  and what it calls instead. Enforced by a Hydra gate that fails on the
  retired OR routes and event classes as well as on the shapes.
- **hermiq's `agentflow` mirror is contract-retired here, deleted there.**
  This change states the rule — an app contributes node types through
  `RegisterFlowNodesEvent` and resolves definitions from the OR flow entity,
  never from an object schema of its own. Removing the two schemas is
  `hermiq: retire-agentflow-object-store`.

## What does NOT change

- **The task entity, the user-task node and the timers themselves.**
  `flow-task-entity`, `flow-user-task-node` and `flow-business-timers` are
  dependencies, not scope. This change adds no task column, no performer
  type, no lifecycle value, no node and no timer semantic. Where it needs one
  that does not exist, it says so in DEFERRED_QUESTIONS rather than inventing
  it here.
- **Per-app leaf migrations.** procest (`parafeeractie`, the parafering
  engine), decidesk/decidiq (`DecisionTransitionGuard`, `WorkflowService`),
  pipelinq, planix, openbuild/buildiq, filinq (signing) and openconnector's
  runner each migrate in a change in their own repo. This change ships the
  target and the contract; it edits no leaf app.
- **CMMN case semantics.** Stages, sentries, milestones and discretionary
  items are `flow-cmmn-case-semantics`. A sequence here is ordinal, not a
  case plan.
- **BPMN interchange.** Mapping an approval onto `bpmn:userTask` on import
  and back out is the existing `flow-bpmn-interchange` change.
- **`x-openregister-approval-chains` itself.** Not extended, not renamed, not
  deprecated. Extending it — per-step deadlines, parallel steps, delegation
  policy — is a later change, deliberately not bundled with a retirement.
- **openconnector's two dead scheduled notification rules.** They use `op`
  instead of `operator` and are silently inert (issue
  ConductionNL/openregister#2787, which covers 24 fleet rules across three
  invented dialects). Named here so the retirement does not carry them
  forward as if they worked; FIXED in the dialect change, not in this one.

## Capabilities

### New Capabilities
- `flow-approval-consolidation`: the ordered task sequence and its
  advance/reject/terminate semantics; the declarative gate re-pointed onto
  it; the chain-and-step retirement with its data migration and verification;
  the event replacement mapping; the `await-signal` correlation key and the
  signal-versus-task division; the leaf migration contract and its
  anti-pattern rules; the openconnector HITL and hermiq `agentflow`
  retirement contracts.

### Modified Capabilities
- `approval-workflow`: every requirement describing the chain/step RUNTIME —
  chain CRUD, step listing, step decisions, the four events and the
  workflow-execution history rows — is REMOVED, because the runtime is
  removed. The requirements describing the DECLARATIVE surface
  (`x-openregister-approval-chains`, the transition gate, threshold routing,
  separation of duties, auto-advance) are restated against the task sequence,
  because their observable behaviour is preserved and a schema author must
  not have to notice.

<!-- flow-engine is NOT listed. The correlation-key signal endpoint is new
     behaviour built ON the engine's existing signal delivery, and it lives in
     the consuming capability's spec — the same placement flow-user-task-node
     used for its own "the signal node keeps machine-to-machine work"
     requirement. No engine requirement changes. -->

## Impact

- **Affected specs**: new `flow-approval-consolidation`; `approval-workflow`
  loses its runtime requirements and keeps its declarative ones.
- **Affected code, removed**: `lib/Db/ApprovalChain.php` (185L),
  `ApprovalChainMapper.php` (189L), `ApprovalStep.php` (208L),
  `ApprovalStepMapper.php` (268L), `lib/Service/ApprovalService.php` (464L),
  `lib/Controller/ApprovalController.php` (361L), the four
  `lib/Event/ApprovalStep*Event.php`, `src/components/workflow/ApprovalChainPanel.vue`,
  `src/components/workflow/ApprovalStepList.vue`, and their unit tests.
- **Affected code, rewritten**: `lib/Listener/ApprovalChainGateListener.php`
  (380L — the refusal stays, the store it consults changes),
  `lib/Listener/ApprovalChainAdvanceListener.php` (127L — subscribes to the
  sequence's completion instead of `ApprovalStepCompletedEvent`),
  `lib/Service/ApprovalChainAnnotationInstaller.php` (188L — compiles to a
  task template), `src/views/schemas/SchemaWorkflowTab.vue:42` (mounts the
  task-sequence panel instead of `ApprovalChainPanel`).
- **Affected code, new**: `lib/Db/TaskSequence.php` +
  `TaskSequenceMapper.php`; `lib/Service/Task/TaskSequenceService.php`;
  `lib/Event/TaskSequenceCompletedEvent.php`; the correlation-key resolution
  on `lib/Service/Flow/FlowRunService.php` and one new route beside
  `appinfo/routes.php:1312`; one migration with a repair step.
- **Affected APIs**: `/api/approval-chains` (5 routes) and
  `/api/approval-steps` (3 routes) are REMOVED
  (`appinfo/routes.php:1231-1240`). Chains and steps are read and decided
  through `flow-task-entity`'s task and inbox routes. One route is added for
  correlation-addressed signal delivery.
- **Affected apps**: filinq/docudesk is the only app that breaks at deploy
  (`SigningEventRegistrar.php:64-67`) and is named as a follow-up. procest,
  decidiq, pipelinq, planix, buildiq and openconnector are migration TARGETS
  of this contract and are not touched here. opencatalogi and softwarecatalog
  declare no approval chain and are unaffected — asserted by a test, not by
  reading.
- **Depends on**: `flow-user-task-node` (the human step in a graph, and the
  cancellation propagation a rejected sequence reuses) and
  `flow-business-timers` (an approval with no deadline is what we are
  replacing; the harvested `expiresAt`/`onTimeout` land there). Both
  transitively depend on `flow-task-entity` and `flow-definition-versioning`.
- **ADRs**: ADR-098 D1 (one engine — the consolidation half), D2 (native task
  entity), D3 (performer types — a role is a group performer, an agent may
  hold an approval step), D6 (versioning first); ADR-065 (openconnector's
  runner relocates, so its semantics are harvested before it moves); ADR-022
  (apps consume OR abstractions — this change makes the rule enforceable);
  ADR-031 (declarative-vs-imperative — argued in design.md); ADR-005
  (fail-closed authorization); ADR-001 (seed data — none introduced, argued
  in design.md).
