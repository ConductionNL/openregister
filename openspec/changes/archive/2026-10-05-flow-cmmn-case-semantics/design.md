# Design: flow-cmmn-case-semantics

## Context

See proposal.md — Why. The design-relevant state of the code today:

**In OpenRegister:**

- A flow is a Petri net. `FlowGraph::inPlace()`
  (`lib/Service/Flow/FlowGraph.php:67`) makes a node's input place its own
  node id and `joinPlace()` (`:103`) is `"{nodeId}#{edgeId}"`, so a run's
  persisted `marking` is a set of identifiers drawn from the graph. The
  engine hops with a hard ceiling, `MAX_TRANSITIONS = 1000`
  (`lib/Service/Flow/FlowEngine.php:103`, enforced at `:325`).
- `FlowRunService::queue()` (`lib/Service/Flow/FlowRunService.php:321`) is
  the single funnel every dispatch path passes through, and — after
  `flow-definition-versioning` — the place a run's definition version is
  pinned.
- A run already knows its subject: `subject_uuid`, `subject_register`,
  `subject_schema` (`lib/Db/FlowRun.php:194-208`).
- Conditions are JSONLogic. `FlowExpression::isTrue()`
  (`lib/Service/Flow/FlowExpression.php:140-160`) evaluates against the
  document built by `dataFor()` (`:82-97`: `json`, `binary`, `itemIndex`,
  `itemCount`, `context`, `subject`), and returns **false** when the
  expression could not be evaluated — already the fail-closed behaviour a
  sentry needs.
- Trigger events are a closed catalog: `EventCatalogService::CATALOG`
  (`lib/Service/Flow/EventCatalogService.php:52-69`), 17 entries including
  `object.transitioned` (`:59`). `knownTriggerIds()` (`:85`) is what a flow
  may legally store.
- **`FlowState` is per-FLOW, not per-subject.** Its table has a UNIQUE index
  on `flow_id` alone (`lib/Migration/Version1Date20260731080000.php:104`) and
  its mapper is `findByFlow(string $flowId)`
  (`lib/Db/FlowStateMapper.php:65`). It is the wrong place to hang per-case
  state, and this design does not use it.
- The declarative lifecycle dialect operates on register objects:
  `x-openregister-lifecycle` executed by
  `lib/Service/Lifecycle/TransitionEngine.php`, with the transition `inputs`
  allowlist at `:674-704`.

**In procest — the reference implementation (2,004 lines, nine classes):**

- `PlanItemTransitions.php` — six states (`:41-46`), three types (`:48-50`),
  an exhaustive per-type edge table (`:59-84`) and an explicit terminal set
  (`:93-97`). `assertLegal()` (`:153-162`) throws
  `IllegalPlanItemTransitionException` naming item, type, from and to.
- `SentryEvaluator.php` — AND within a sentry, OR across the array
  (`:65-104`); on-part against current plan-item state because
  complete/terminate/disable are monotonic (`:107-144`, and the docblock at
  `:18-23` argues the point); if-part over its OWN
  `{field, operator, value}` dialect (`:154-196`).
- `PlanItemTree::stageMandatoryChildrenAllTerminal()` (`:98-117`) — returns
  `$mandatoryFound`, so a stage with only discretionary children returns
  false. The docblock at `:88-90` says why: "all terminal" would trivially
  auto-complete such a stage on activation.
- `PlanItemCascade` — `MAX_CASCADE_DEPTH = 50` (`:64`), fixpoint passes
  (`:94-123`).
- `CaseModelEngine` — the public surface: `getCasePlan()` (`:92`),
  `enableDiscretionaryItem()` (`:118`), `completeTask()` (`:168`),
  `terminateTask()` (`:185`), `signalCaseFileEvent()` (`:203`),
  `getPlanItemAuthorization()` (`:244`), `getEnableableDiscretionaryItems()`
  (`:269`).
- `CasePlanRepository::persist()` (`:151-152`) is one line:
  `$ctx['case']['casePlanState'] = json_encode($ctx['state']);`. The field it
  writes is declared `"type": "string"`, `"visible": false`
  (`procest/lib/Settings/procest_register.json:1188-1192`).
- The definition shape is already close to what we want:
  `procest/lib/Settings/register.d/70-cmmn-case-model.json:50` declares the
  type enum, and `:56-84` the `entryCriteria` sentry shape.
- The ZTC surface exists: `procest/lib/Controller/ZtcController.php:982-989`
  resolves `eigenschappen`, `statustypen`, `resultaattypen` and `roltypen`
  per zaaktype.

## Goals / Non-Goals

**Goals:**

- Express a case whose next step is a judgement, not an arrow, without
  changing anything about how the Petri net executes.
- Make plan state queryable: "which cases are stuck where" is an indexed
  query, not a decode-every-row scan.
- Reuse the engine's existing condition language and event catalog for
  sentries, so a case author and a flow author learn one dialect.
- Let a caseworker attach work at runtime without touching a pinned,
  immutable published flow version.
- Give the zaaktype — the artifact a Dutch buyer actually has — a first-class
  import path.

**Non-Goals:**

- **Compiling the case plan into the Petri net.** Considered and rejected in
  D-5; the reason is structural, not preferential.
- A case UI. The API and the model land here; the canvas and the case view
  are nc-vue work behind `CnGraphCanvas` (ADR-098 D8).
- Per-item concurrency beyond row-level. Two transitions on the SAME plan
  item serialise; two on different items do not.
- Case migration between definition versions, for the same reason
  `flow-definition-versioning` refused in-flight instance migration.
- Any interpretation of `doorlooptijd`/`servicenorm` beyond storing them.

## Decisions

### D-1 — Declarative-vs-imperative decision (ADR-031)

ADR-031's default is declarative: business logic belongs in
`x-openregister-*` on a schema, executed by the shared engines, not in a new
Service class. This change lands on BOTH sides of that line, deliberately.

**Imperative: the plan-item machine.** `x-openregister-lifecycle` transitions
a register OBJECT. A plan item is not a register object — it is a row in a
native table, for the reasons in D-2 — so `TransitionEngine` has nothing to
transition, `x-openregister-lifecycle` has no schema to hang on, and the
`inputs` allowlist (`lib/Service/Lifecycle/TransitionEngine.php:674-704`) has
no object write to validate against. More importantly the dialect cannot
express what a sentry needs: a sentry is a condition over ANOTHER item's
state plus the anchoring object's state, and the declarative lifecycle has no
cross-record referential form. This is the same argument
`flow-definition-versioning`'s design made for its lifecycle guard, and the
same one procest reached for `workflowTemplate` even though that IS an
object.

**Declarative: the write-through.** The business status the plan advances —
the zaak's `status`, its `resultaat` — is a property of a register object and
is written through the ordinary object-write path, so
`x-openregister-lifecycle` on the case schema governs it, `object.transitioned`
is emitted by the existing machinery, readOnly enforcement applies, and every
existing notification rule keyed on `transition(action)` keeps working
untouched. The case layer supplies the transition; it does not reimplement
one.

**Declarative: the sentry if-part.** JSONLogic via `FlowExpression`, not a new
evaluator — see D-4.

**Notifications, aggregations, derived fields, relations, widgets:** none are
introduced by this change. A plan item's realisation notifies through the
task capability's existing path; nothing here dispatches directly.

### D-2 — Rows, not a JSON blob

The reference implementation keeps the whole runtime plan in one string field
(`CasePlanRepository.php:151-152` writing
`procest_register.json:1188`'s `casePlanState`). Three costs, each of which
this change is partly for:

1. **Nothing is queryable.** "Which bezwaren are waiting on external advice?"
   requires loading and decoding every case object. There is no index on a
   field inside a JSON string.
2. **Every transition is a whole-blob read-modify-write.** Two caseworkers
   completing two different items in the same case is a lost-update race by
   construction. Row-level storage makes them independent.
3. **`"visible": false`** means the state driving the case is invisible in
   every generic OR surface — the object detail page, exports, the audit
   trail's diff. A field nobody can see is a field nobody can verify.

Alternative considered: keep a blob but add a projection table for querying.
Rejected — two representations of one truth, and the projection is exactly
the kind of derived-and-stored field the fleet has already been burned by
(`overdue`, three schemas, decidiq#846).

### D-3 — The case anchor is the OpenRegister object; no case entity

A plan item carries `object_uuid` + `register_id` + `schema_id` — the same
triple `FlowRun` already carries as `subject_*` (`lib/Db/FlowRun.php:194-208`)
and the same triple `flow-task-entity` anchors a task on.

Alternatives:

- **A `Case` entity with its own id and lifecycle.** Rejected: it duplicates
  what the object already is, and it creates a second identity for one thing
  — the classic consequence being two statuses that disagree. Camunda needs
  this (a process instance has no domain object, so Camunda 7 bolts on a
  "document"/business-key concept and Valtimo built a whole document module
  around it); we do not, because in OR the zaak, the bezwaar and the
  vergunning already ARE objects with uuids, schemas, audit and
  authorization.
- **`FlowState`.** Rejected on fact: it is keyed by flow uuid, UNIQUE on
  `flow_id` (`lib/Migration/Version1Date20260731080000.php:104`,
  `lib/Db/FlowStateMapper.php:65`), and holds state that outlives runs of ONE
  flow. It is per-flow bookkeeping, not per-subject state, and using it for a
  case would collide every case of the same type into one row.

Consequence worth stating: a case plan is resolvable for an object that never
had a run. That is not an edge case, it is the ad-hoc path (D-6).

### D-4 — Sentries reuse `FlowExpression` and the event catalog

The reference `SentryEvaluator` carries its own operator vocabulary —
`eq|neq|gt|gte|lt|lte|in|notIn|truthy|falsy` at
`procest/lib/Service/Cmmn/SentryEvaluator.php:178-196`, with deliberately
loose `==` comparison at `:188-189`. Adopting it would make **four**
condition dialects in the fleet: JSONLogic in the flow engine,
`ScheduledFilterEvaluator`'s `equals|notEquals|withinNext|olderThan`, the
three invented notification dialects that are all silently dead
(openregister#2787, 24 rules), and this. The cost of a fourth is not
theoretical — the 24 dead rules are what a second dialect looks like a year
later.

So:

- **if-part** = a JSONLogic expression through
  `FlowExpression::isTrue()`. The evaluation document extends `dataFor()`'s
  shape with a `case` key carrying `{items: {itemId: state}, object: {...}}`
  — additive, so an author who knows flow expressions already knows sentry
  expressions. `isTrue()` returning false for an unevaluable expression
  (`FlowExpression.php:145-149`) is precisely the fail-closed semantics
  `SentryEvaluator::ifPartSatisfied()` implements by hand at `:157-160`; we
  get it from the shared primitive instead.
- **on-part** = an event id from `EventCatalogService::CATALOG`, extended
  with `case.item.completed`, `case.item.terminated`, `case.item.disabled`.
  Validation at save time is `knownTriggerIds()`
  (`EventCatalogService.php:85`), so an author's typo fails in the editor
  rather than producing a sentry that never fires.

**Monotonicity is kept, and it is why no event log is needed for plan-item
on-parts.** `completed`/`terminated`/`disabled` are terminal
(`PlanItemTransitions.php:93-97`), so "the event has occurred" and "the item
is currently in that state" are the same question — the argument at
`SentryEvaluator.php:18-23`, and it survives the port unchanged. Object-state
on-parts are NOT monotonic, which is why they go through the event catalog
and are evaluated against the event being handled, not against a stored
history.

### D-5 — The case layer schedules; the Petri net executes

The tempting design is to compile the case plan into a flow document —
plan items become nodes, sentries become edge conditions — so that everything
is one Petri net. It does not survive contact with two facts:

1. **A run's marking names graph identifiers**
   (`FlowGraph::inPlace()`, `:67`) and `flow-definition-versioning` pins a run
   to an immutable published version. A discretionary item enabled on day
   three, or an ad-hoc item that appears in no definition at all, would have
   to ADD a node to a graph a live run is pinned to. That is precisely the
   dangling-marking failure versioning exists to prevent.
2. **Sentries are not edges.** An entry criterion is a condition over the
   whole case's state that may become true at any time from any cause. As a
   Petri net that is an n-to-n cross product of transitions, and its size is
   quadratic in the plan.

So a plan item entering `active` does exactly one of three things:

| Type | Realisation |
| --- | --- |
| `humanTask` | create a task via the task capability, anchored to the same object, carrying candidates and deadline values |
| `stage` with a `flow` binding | `FlowRunService::queue()` against that flow's pinned published version |
| `milestone` | complete immediately — a milestone performs no work by definition (`PlanItemTransitions.php:80-83` gives it exactly two edges) |

The case layer never writes a marking, never queues a transition, never
alters a run status. Dependency direction enforces it: `Service\Case\*`
depends on `Service\Flow\FlowRunService` and on the task service; nothing in
`Service\Flow\` depends on `Service\Case\`. The engine cannot reach the case
layer, so no run-path change can be introduced by accident.

Coupling is one-directional: the realisation's terminal outcome drives the
plan item's terminal state; the plan item writes to its realisation only to
terminate it on exit or cascade. Nothing else. Two state machines that write
to each other is how they drift.

### D-6 — A plan item is not a task; a task realises it

Reusing `openregister_tasks` for every plan item is superficially attractive
— it already has the six states, `is_terminal`, `parent_task_id` and an
audit. Two facts defeat it:

- **Repetition.** A repeating plan item produces N realisations while
  remaining ONE plan item. One row cannot be both.
- **A milestone has no performer.** `flow-task-entity` makes `performer_type`
  NOT NULL over `user|group|agent|worker`; a milestone would need a fifth,
  non-performing value, which weakens a column whose whole point is that
  every task has someone accountable for it.

The clean reading is the one CMMN itself uses: the plan item is the
occurrence in the plan; the task is the work. That maps exactly onto
`flow-task-entity`'s template/instance split (`template_id`,
`template_version`, frozen `template_snapshot`) — the plan item is the
case-scoped template, the realisation is the instance.

Drift between the two lifecycles is prevented by the one-directional rule in
D-5 plus one invariant asserted in tests: a plan item is terminal if and only
if all of its realisations are terminal.

### D-7 — Discretionary and ad-hoc items are rows, and authorization is a decision

`flow-task-entity` already made `run_uuid`/`node_id` optional provenance, and
that is exactly the property an ad-hoc item needs: its task points at no
node, because there is no node. Nothing has to be added to a pinned graph to
support one — which is D-5's argument reaching its conclusion.

Two shapes, both first-class and distinguishable in the record:

- **discretionary** — in the definition, not auto-entered, requires an act.
  "Which can I enable right now?" is the reference's
  `getEnableableDiscretionaryItems()`
  (`procest/lib/Service/Cmmn/CaseModelEngine.php:269-276`): discretionary,
  entry-satisfied, parent `active`.
- **ad-hoc** — in no definition. Authorization derives from the parent stage,
  or from the plan root when parentless; an ad-hoc item cannot declare itself
  unguarded, because "add an item nobody may block" is a privilege-escalation
  primitive.

The reference returns the authorization list for a caller to check —
`getPlanItemAuthorization()` returns `array<int,string>`
(`CaseModelEngine.php:244-268`) and the REST layer does the comparison. We
invert that: `CasePlanAuthorizationService` DECIDES, fail-closed, before any
write. An unresolvable role or an unavailable group backend DENIES; there is
no nullable "could not determine" return a caller can read as "check
skipped". That pattern has its own gate in this fleet
(`hydra-gate-unsafe-auth-resolver`), and the reason this programme exists at
all is an endpoint that authorized the wrong question
(`lib/Controller/FlowRunController.php:423-436`).

### D-8 — Stage completion, repetition, and a bounded cascade

- **Auto-complete**: every REQUIRED child terminal AND no child `active`. The
  "required" qualifier and the `$mandatoryFound` guard are both taken from
  `PlanItemTree::stageMandatoryChildrenAllTerminal()` (`:98-117`) — a stage
  with only optional children must NOT auto-complete on activation, and the
  only thing distinguishing "all required children are terminal" from
  "there are no required children" is that flag.
- **Exit cascade**: non-terminal children → `terminated`, unentered children
  → `disabled`, each individually audited with the parent exit as cause.
  Matches `PlanItemStateMachine::forceTerminateChildren()` (`:145`) and
  `disableUnplannedDiscretionaryChildren()` (`:121`).
- **Bound**: evaluation is a fixpoint loop and needs a ceiling, as the
  reference has at `PlanItemCascade.php:64` (`MAX_CASCADE_DEPTH = 50`). Ours
  fails loudly at the bound with the bound named, and rolls back rather than
  leaving a half-cascaded plan — the same posture as `MAX_TRANSITIONS`
  (`FlowEngine.php:103`).
- **Repetition** produces a new realisation per repetition against one plan
  item. The plan item is terminal only when its repetition rule is exhausted
  AND every realisation is terminal.

### D-9 — Zaaktype import is a mapping, not a client

The mapper takes a `zaaktype` document that is ALREADY in a register and
returns a draft skeleton. It performs no HTTP. The reason is boundaries:
fetching from a remote ZTC is an integration concern with credentials,
retries and rate limits, and procest already owns that surface
(`ZtcController.php:982-989`). Making the mapper pure keeps it unit-testable
against fixtures and reusable by whoever did the fetching.

| Zaaktype element | Becomes | Owned by |
| --- | --- | --- |
| `statustypen`, ordered by sequence number | milestones, in order | this change |
| `roltypen` (initiator, behandelaar, adviseur, beslisser) | candidate roles on human items, generic designation preserved | this change (the roles), `flow-task-entity` (the performer model) |
| `resultaattypen` | the constrained end-state set; completing outside it is refused | this change |
| `doorlooptijd`, `servicenorm` | deadline values carried onto items | STORED here, ACTED ON by `flow-business-timers` |
| archiving terms on `resultaattypen` | carried as metadata | out of scope — archival capabilities own it |
| everything else | a report entry | this change |

The output is a **draft**. Importing never makes a definition live —
`flow-definition-versioning` already requires an explicit publish, and an
imported skeleton must go through it like anything else.

**Reference process templates (bezwaar, Woo, vergunning) are NOT in this
change.** GEMMA publishes no importable BPMN process library — a documented
deliberate choice — so those templates are ours to author, and authoring
three correct Dutch statutory processes is domain work with its own review
cycle, not a side effect of building the mapper. Proposed follow-up:
`flow-case-reference-templates`. See Open Questions.

## Data model

`openregister_case_items` — one row per plan-item instance:

| Group | Columns |
| --- | --- |
| Identity | `id` (PK), `uuid` (NOT NULL, unique), `item_key` (stable id within the plan, referenced by sentries), `name`, `description` |
| Anchor | `object_uuid` (NOT NULL), `register_id`, `schema_id` |
| Definition provenance | `flow_uuid`, `flow_version`, `definition_item_key`, `origin` (NOT NULL: `defined` \| `discretionary` \| `adhoc`) |
| Structure | `parent_item_id`, `plan_item_type` (NOT NULL: `stage` \| `humanTask` \| `milestone`), `position` |
| Lifecycle | `state` (NOT NULL, the six), `is_terminal` (NOT NULL bool), `entered_at`, `terminated_reason` |
| Criteria | `entry_criteria` (JSON), `exit_criteria` (JSON), `required` (NOT NULL bool, default true), `discretionary` (NOT NULL bool), `repetition` (JSON) |
| Realisation | `realisation_kind` (`task` \| `run` \| `none`), `realisation_uuid`, `realisation_count` |
| Authorization | `authorization` (JSON: the roles/groups that may enable or attach here) |
| Performer hints | `candidate_users` (JSON), `candidate_groups` (JSON), `candidate_role` — passed to the task on realisation, never evaluated here |
| Deadlines | `due_at`, `expires_at`, `doorlooptijd`, `servicenorm` — carried, not computed |
| Stamps | `created` (NOT NULL), `updated`, `created_by` |

Indexes: `(object_uuid, is_terminal)` for "what is open on this case";
`(object_uuid, parent_item_id)` for the tree read; `(plan_item_type, state)`
for "which cases are stuck where"; `(realisation_uuid)` for the reverse
lookup from a task; unique on `uuid`; unique on
`(object_uuid, item_key, realisation_count)` so a repetition cannot collide
with itself.

No `overdue`, no `days_until_due`, no `days_overdue` — same rule as
`flow-task-entity`, same reason.

`openregister_case_item_audit`: `id`, `case_item_id`, `from_state`,
`to_state`, `cause` (`sentry` \| `user` \| `realisation` \| `cascade` \|
`import`), `cause_ref` (the sentry id, the task uuid, the parent item uuid),
`actor`, `reason`, `authorized` (bool — denials are recorded too), `created`.
Append-only: no UPDATE and no DELETE path exists, and deleting a plan item
does not cascade to it.

`openregister_tasks` gains NO column. The link runs plan item → task, via
`realisation_uuid`.

## Seed Data (ADR-001)

Fixtures for PHPUnit and for a demo instance. No new register or schema is
introduced by this change, so these are plan-item rows against existing
demo objects plus one zaaktype fixture for the mapper. All UUIDs are nil
placeholders (`00000000-0000-0000-0000-000000000000` with a varying final
group); all uids are obviously fake.

1. **A two-stage municipal permit case** anchored to one demo object:
   stage `intake` (active) containing a required humanTask
   `completeness-check` (candidate group `demo-behandelaars`) and a
   milestone `application-complete`; stage `assessment` (available) whose
   single entry sentry has an on-part on `application-complete` completing.
   Exercises nesting, milestone-satisfies-sentry, and required-child
   completion.
2. **A discretionary advice item** under `assessment`: `external-advice`,
   `discretionary: true`, `authorization: ["demo-beslissers"]`, still
   `available`. Exercises the enableable-items query and the authorization
   denial path for a non-member.
3. **An ad-hoc item on a live case**: `origin: adhoc`, no
   `definition_item_key`, no `flow_uuid`, parented to `intake`, realised by a
   task whose `run_uuid` is null. This fixture is the proof that the ad-hoc
   path needs no flow definition at all.
4. **A terminated stage with its cascade**: a stage in `terminated` with one
   child `terminated` and one `disabled`, each with an audit row whose
   `cause` is `cascade` and whose `cause_ref` is the parent uuid.
5. **A repeating item**: one plan item with `realisation_count` 2, two task
   realisations, one `completed` and one `active` — so "the plan item is
   terminal iff all realisations are" has a live counter-example fixture.
6. **A zaaktype fixture** for the mapper: four `statustypen` with sequence
   numbers deliberately out of document order, three `roltypen`, two
   `resultaattypen`, a `doorlooptijd` and a `servicenorm`, plus two elements
   the mapping does not cover so the report has content to assert on.

All seeds are idempotent on uuid and install through the existing seeding
path.

## Migration Plan

Additive only. Two new tables; no existing table altered; no data
backfilled; nothing in `openregister_tasks`, `openregister_flows`,
`openregister_flow_runs` or `openregister_flow_state` is touched. The
`EventCatalogService` change appends three catalog entries, which widens
`knownTriggerIds()` and cannot invalidate a stored trigger.

Rollback is dropping the two tables and reverting the catalog entries: no
existing behaviour depends on either, because there is no consumer in this
change. procest keeps running its own CMMN engine on `casePlanState`
untouched until its own migration change runs, so the two coexist by
construction rather than by coordination.

The procest-side migration (`retire-cmmn-caseplanstate`, procest repo) is
where the interesting risk lives — decoding every `casePlanState` string into
rows, with a dry-run that reports items it cannot map and a period where the
blob is retained read-only for reconciliation. It is deliberately not in this
change: the target must exist and be tested before anything is drained into
it.

## Risks / Trade-offs

- **Two state machines (plan item and task) can drift** → one-directional
  coupling (D-5) plus an invariant test: a plan item is terminal iff all its
  realisations are terminal. Any code path that sets a task's non-terminal
  state from the case layer is a review failure, and dependency direction
  makes the reverse impossible.
- **Sentry evaluation is a fixpoint loop and could be expensive on a large
  plan** → evaluation is scoped to one case (indexed by `object_uuid`),
  bounded (D-8), and triggered by events rather than polled. A plan large
  enough to hurt is a plan that should have been decomposed; the bound turns
  that into a visible error instead of a slow request.
- **Extending the event catalog widens a hot path** → it does not: the
  catalog is a constant read at validation and dispatch time, and
  `openregister_flow_triggers`' match index is untouched. Case events do not
  enter the trigger index.
- **The JSONLogic if-part is more expressive than the reference's
  `{field, operator, value}`, so a badly written sentry can be subtler** →
  `FlowExpression::isValid()` (`FlowExpression.php:166-183`) already runs at
  save time, and an unevaluable expression is false at run time
  (`:145-149`). The failure mode is "the item never enters", which is visible
  in the case view, not "the item enters wrongly".
- **`realisation_count` in a unique key means a repetition rule change on a
  live case can collide** → repetition is evaluated only forward, and a
  definition change does not reach a live case (D-3's non-goal, inherited
  from `flow-definition-versioning`).
- **We adopt a standard whose notation is receding** (Camunda dropped CMMN
  in v8; ADR-065 D6) → that is the trade being made knowingly. The concepts
  — plan item lifecycle, sentries, stages, milestones, discretionary work —
  describe Dutch casework accurately and have no better-supported rival. The
  notation, which is the part that is dying, is exactly the part we do not
  adopt.
- **`FlowState` looked like the natural home for case state and is not** →
  named here because the assumption is easy to make and cheap to act on
  wrongly: it is UNIQUE on `flow_id`
  (`lib/Migration/Version1Date20260731080000.php:104`), so every case of one
  type would share one row.

## Open Questions

- **Do the reference process templates (bezwaar, Woo-verzoek, vergunning)
  ship as a separate change or as seed data here?** Provisionally: a separate
  change, `flow-case-reference-templates`, depending on this one. They are
  domain artifacts needing legal review, and putting them here would put this
  change's tasks over the 20-task ceiling for content that is not
  engineering. Answering this later changes no spec and no task in this
  change.
- **Should a case plan be exportable as BPMN?** Provisionally no — a case
  plan is not a process graph, and exporting one as BPMN would produce a
  diagram that misrepresents it. `flow-bpmn-interchange` continues to export
  FLOWS. Revisit only on a named procurement requirement.
- **Where does a case-level SLA (as opposed to a per-item deadline) live?**
  Provisionally `flow-business-timers`, since it already owns computation
  over `doorlooptijd`/`servicenorm`; this change carries the values on the
  item and on the anchor object either way.
