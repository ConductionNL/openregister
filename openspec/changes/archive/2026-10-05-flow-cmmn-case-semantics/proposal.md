---
kind: code
depends_on: [flow-task-entity]
---

# Proposal: flow-cmmn-case-semantics

## Summary

Give OR Flow a **case layer**: a plan of stages, human tasks and milestones
whose entry and exit are governed by sentries, to which a caseworker may
attach work at runtime that no author drew. We adopt the CMMN 1.1
**concepts** and none of its notation — no CMMN XML in, no CMMN XML out.
The execution substrate does not change: the case layer decides WHEN a plan
item becomes actionable, and the Petri net on symfony/workflow still does
every piece of work. A plan item is a row anchored to an OpenRegister
object; the task entity from `flow-task-entity` is how a human plan item is
realised.

## Why

**A Petri net cannot express a case.** OR Flow is a persisted Petri net:
`FlowGraph::inPlace()` makes a node's input place its own node id, and a
run's `marking` is therefore a set of node ids over a graph that
`flow-definition-versioning` deliberately freezes at queue time. That is the
right model for a process somebody drew. It is the wrong model for a
`bezwaarschrift` where the caseworker decides on day three to order an
external advice nobody put on the diagram: there is no node to put a token
in, and adding one would edit a pinned, immutable published version.

**The fleet has already built the answer once, in the wrong place.** procest
runs a working CMMN engine — `procest/lib/Service/Cmmn/`, 2,004 lines across
nine classes: an exhaustive per-type transition table
(`PlanItemTransitions.php:59-84`), a sentry evaluator
(`SentryEvaluator.php`), a cascade with a depth bound
(`PlanItemCascade.php:64`), a stage-completion rule
(`PlanItemTree.php:98-117`). Under ADR-065 Decision 8 a leaf app that owns
an execution engine is a gate failure, and under ADR-098 Decision 1 this is
one of the seven runtimes that converge into OR. It is the reference
implementation for this change, not code to be reinvented.

**And it stores its runtime state as a string.** The entire case plan lives
in one OR object field: `case.casePlanState`, declared `"type": "string"`,
`"visible": false` at `procest/lib/Settings/procest_register.json:1188-1192`,
written as `json_encode($ctx['state'])` in
`procest/lib/Service/Cmmn/CasePlanRepository.php:151-152`. Nothing can query
it. "Which cases are stuck in the advice stage?" requires decoding every
case. Two concurrent plan-item transitions are a read-modify-write over the
whole blob. This change replaces that string with rows.

**Meanwhile the market payload has no home.** A Dutch municipality does not
arrive with a BPMN diagram; it arrives with a `zaaktype` from the VNG
Catalogi (ZTC) API — ordered `statustypen`, a `doorlooptijd` and a
`servicenorm`, `roltypen`, `resultaattypen`. procest already serves that
whole surface (`procest/lib/Controller/ZtcController.php:982-989` resolves
`eigenschappen`, `statustypen`, `resultaattypen`, `roltypen` per zaaktype)
and there is nothing to turn it into. GEMMA publishes no importable BPMN
process library, so "import your process" cannot mean BPMN for this buyer —
it has to mean the zaaktype.

## What Changes

- **A case plan is a tree of plan-item ROWS, not a JSON blob.** New table
  `openregister_case_items` plus an append-only
  `openregister_case_item_audit`. One row per plan-item instance, carrying
  its `plan_item_type` (`stage` | `humanTask` | `milestone`), its `state`
  (the same six CMMN states `flow-task-entity` already put on a task), its
  `parent_item_id`, and its criteria. Queryable, indexable, and
  transitionable one item at a time.
- **The case anchor is the OpenRegister object.** No `case` entity is
  introduced and none is needed: a plan item carries
  `object_uuid` + `register_id` + `schema_id`, the same triple a run already
  carries as `subject_uuid`/`subject_register`/`subject_schema`
  (`lib/Db/FlowRun.php:194-208`) and the same triple a task anchors on. The
  zaak, the bezwaar, the vergunning IS the case. Camunda has to bolt a
  "document" concept alongside its process instance to get this; we have it
  for free because everything in OR is already an object.
- **A plan item's lifecycle is a table, not prose.** A pure
  `CasePlanTransitions` service holding the per-type legal-edge table and
  the terminal set, ported from `PlanItemTransitions.php:59-97` — including
  its asymmetry: a milestone may only go `available → completed` or
  `available → terminated`, because a milestone performs no work.
- **Sentries reuse the engine's existing primitives. No new condition
  language.** A sentry's **if-part** is a JSONLogic expression evaluated by
  `FlowExpression::isTrue()` (`lib/Service/Flow/FlowExpression.php:145-160`),
  which already returns false for an expression it could not evaluate. A
  sentry's **on-part** is an entry in the flow event catalog
  (`lib/Service/Flow/EventCatalogService.php:52-69`), which this change
  extends with `case.item.completed` / `.terminated` / `.disabled`. procest's
  own `{field, operator, value}` dialect
  (`SentryEvaluator.php:178-196`: `eq|neq|gt|gte|lt|lte|in|notIn|truthy|falsy`)
  is deliberately NOT adopted — it would be a fourth condition dialect in a
  fleet that is already paying for three (openregister#2787).
- **Stages and milestones.** A stage nests plan items and has its own entry
  and exit criteria; a milestone marks that a state has been reached,
  performs no work, and can itself satisfy another item's sentry. Stage
  completion has a written rule (required children terminal, no child
  active) rather than an arrow somebody forgot to draw.
- **Discretionary / ad-hoc items.** A caseworker attaches work to a live
  case that exists in no definition. This works because `flow-task-entity`
  already made `run_uuid`/`node_id` OPTIONAL provenance — an ad-hoc task
  needs no node to point at, so nothing has to be added to the pinned graph
  to support one. Who may attach what is an authorization decision on the
  item, modelled on `CaseModelEngine::getPlanItemAuthorization()`
  (`procest/lib/Service/Cmmn/CaseModelEngine.php:244-268`) and enforced
  fail-closed rather than returned for a caller to interpret.
- **Realisation, not execution.** A plan item entering `active` does exactly
  one of three things: create a task through `TaskService` (humanTask),
  queue a flow run against a pinned published version (a stage bound to a
  flow), or complete immediately (milestone). The case layer never advances
  a marking, never queues a transition and never touches the engine's
  scheduler.
- **Zaaktype → case skeleton.** A mapper turning a VNG Catalogi `zaaktype`
  document that is already in a register into a case-plan skeleton:
  `statustypen` in `volgnummer` order become milestones, `roltypen` become
  candidate roles on human items, `resultaattypen` become the constrained
  end states, and `doorlooptijd`/`servicenorm` are CARRIED onto the item for
  `flow-business-timers` to act on. The import produces a report of what it
  could not map, in the same spirit as the BPMN importer's lossy-mapping
  report.
- **Business state is written through, never owned.** Runtime plan state
  lives in `openregister_case_items`; the business facts a citizen or an
  auditor can see (the zaak's status, its resultaat) are mirrored onto the
  register object through the ordinary object-write path. The register is
  the record; the engine is not (Common Ground vijflaagsmodel, ADR-022).

## What does NOT change

- **The task entity and `TaskService`** — `flow-task-entity`. This change
  consumes them; it adds no lifecycle verb, no performer type and no inbox
  query.
- **The `openregister.user-task` node** — `flow-user-task-node`. A stage may
  queue a flow that contains one; the case layer does not define it.
- **Timers.** `doorlooptijd` and `servicenorm` are mapped and stored here;
  business-day arithmetic, escalation matrices and breach sweeps are
  `flow-business-timers`.
- **The Petri net.** No node type is added, no marking semantics change, no
  `MAX_TRANSITIONS` or oversight behaviour is touched. ADR-098 Decision 4 is
  explicit that the execution substrate stays the Petri net, and the whole
  design of this change exists to keep that true.
- **procest's migration onto this layer.** Retiring
  `procest/lib/Service/Cmmn/` and draining `case.casePlanState` into
  `openregister_case_items` is a **procest-side change** — proposed slug
  `procest/openspec/changes/retire-cmmn-caseplanstate` — with its own
  backfill and its own rollback. Nothing in procest is touched here.
- **CMMN XML.** Not parsed, not serialised, not exported. ADR-065 Decision 6
  established the ground: no PHP CMMN implementation exists (all 15
  Packagist hits are substring false positives), no PHP FEEL parser exists
  at all, and Camunda dropped CMMN in v8 — the notation is receding while
  the concepts are not. We take the concepts. A CMMN XML export is
  reconsidered only if a buyer asks for one by name.
- **`flow-bpmn-interchange`.** It stays as written. BPMN is a FORMAT, never
  an execution semantic. One follow-up edit belongs to THAT change and not
  to this one: its import table currently maps `bpmn:userTask` →
  `await-signal` (`openspec/changes/flow-bpmn-interchange/design.md`,
  Decision 2) because no user-task node existed when it was written; once
  `flow-user-task-node` lands, that row's target becomes
  `openregister.user-task`.

## Capabilities

### New Capabilities
- `flow-cases`: the case layer — plan items anchored to an OpenRegister
  object, their CMMN lifecycle and transition table, sentries over the
  engine's existing expression and event primitives, stages, milestones,
  discretionary items and their authorization, stage/case completion rules,
  the zaaktype-to-skeleton mapping, and write-through of business state to
  the register.

### Modified Capabilities
<!-- None. flow-engine is untouched: no node type, no marking semantics, no
     run-lifecycle change. flow-tasks is CONSUMED unmodified — a human plan
     item is realised by an ordinary task, which is exactly what
     flow-task-entity already specifies. -->

## Impact

- **Affected code**: new `lib/Db/CaseItem.php` + `CaseItemMapper.php`,
  `CaseItemAudit.php` + `CaseItemAuditMapper.php`; new
  `lib/Service/Case/` — `CasePlanService.php`,
  `CasePlanTransitions.php`, `CasePlanStateMachine.php`,
  `CaseSentryEvaluator.php`, `CasePlanAuthorizationService.php`,
  `CasePlanCascade.php`, `ZaaktypeCaseSkeletonMapper.php`; new
  `lib/Controller/CaseController.php` + `appinfo/routes.php` entries; one
  migration. `lib/Service/Flow/EventCatalogService.php:52-69` gains the
  `case.item.*` entries — additive, and its `aliasesFor()` contract is
  unchanged.
- **Affected data**: two new tables. No existing table altered; no data
  backfilled. `openregister_tasks` gains no column — the link runs the other
  way, from a plan item to the task uuid it created.
- **Affected apps**: none in this change. procest is the first consumer and
  migrates in its own repo; opencatalogi and softwarecatalog are unaffected
  (additive migration only).
- **Depends on**: `flow-task-entity` — a human plan item's realisation IS a
  task row, and the optional `run_uuid`/`node_id` on that entity is what
  makes a discretionary item expressible without editing a pinned graph.
  Transitively `flow-definition-versioning`, whose immutable published
  version is the reason a runtime-added item must be a row and not a node.
- **ADRs**: ADR-098 D4 (this change is that decision), D1 (one engine —
  procest's CMMN converges here), D2 (the task entity realises a human plan
  item); ADR-065 D6 (CMMN was deferred because nothing existed to buy; we
  adopt concepts, not notation) and D8 (a leaf app owning an engine is a
  gate failure); ADR-031 (declarative-vs-imperative — argued in design.md);
  ADR-022 (apps consume OR abstractions; the register owns business state);
  ADR-011 (reuse before implement — the if-part reuses `FlowExpression`).
