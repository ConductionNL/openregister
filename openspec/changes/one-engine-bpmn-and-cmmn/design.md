# Design: one-engine-bpmn-and-cmmn

## 1. Inventory (development @5d733a7185, 10 Oct 2026)

| Concern | BPMN (flow engine) | CMMN (case layer) | Today |
|---|---|---|---|
| Model | flow graph, `FlowService`, versions, BPMN import/export (`Service/Flow/Bpmn/`) | plan definition, `CasePlanDefinition` (validated at create) | separate by nature |
| Walker / evaluator | `FlowEngine`, `FlowRunAdvancer`, `FlowTokenRouter` | `CasePlanCascade`, `CasePlanTree` | separate by nature |
| Runtime state | `openregister_flow_runs` + marking (`FlowRunMarkingStore`) | `openregister_case_items` rows | separate by nature |
| Lifecycle table | task states (`Task::STATES`, `TaskState`) | `CasePlanTransitions` over `CaseItem::STATES` | same six states, declared twice (gap 4) |
| Human work | `UserTaskNode` creates a task via `TaskService` | `CaseRealisationService::realise()` creates a task via `TaskService::import()` | **shared** |
| Conditions | `FlowExpression` (JSONLogic) | `CaseSentryEvaluator` calls `FlowExpression::isTrue()` | **shared** |
| Events | `EventCatalogService` triggers | on-parts from the same catalog, plus `case.item.*` | **shared** |
| Subject | run subject `{uuid, register, schema}` | plan anchor triple, same shape | **shared** |
| Timers | `FlowTimerService` (subjects `task`, `object`, `run`), `WorkingCalendar` | `dueAt` / `expiresAt` stored, never armed | gap 3 |
| CMMN starts BPMN | n/a | stage realised by a run (`CaseRunTerminalListener`) | stage only (gap 1) |
| BPMN touches CMMN | no node | n/a | missing (gap 2) |
| Authorization | `FlowRunAuthorization`, `TaskAuthorizationService` | `CasePlanAuthorizationService` (user/role/group rules, fail-closed) | one model per subject kind, all fail-closed |
| Audit | run steps / history (`FlowStepHistory`), task events | `openregister_case_item_audit` (append-only) | one table per state shape |
| System callers | runs execute as their attributed identity (`FlowRunAsScope`) | every verb refuses a null identity | gap 5 |
| Bring-over | `FlowRunMigrationService` (run to new version) | none | gap 6 |

dossiq's needs (dossiq/STATE.md "Lane L7", decision 146, and dossiq
`retire-cmmn-caseplanstate` design sections 1-3): start a plan from a
definition for an object, read it, transition, enable discretionary, list
enableable, attach ad-hoc, find objects by item type and state (all exist);
human items as engine tasks (exists); sentries in the one dialect (exists);
timers from the shared core (gap 3); an idempotent "ensure these items with
these states" for the drain, with an `imported` flag on history (gap 6); and
calls from listeners and `occ` without a session (gap 5). dossiq already
calls `createPlan(uid: null)` and `getPlan(uid: null)`; both are refused
today, so its projection and rollback are silent no-ops until it moves to the
system methods.

## 2. The boundary

```
            BPMN process walker          CMMN case-plan evaluator
            (FlowEngine, marking)        (CasePlanCascade, plan rows)
                     \                          /
                      \   owns: its state shape /
   ---------------------------------------------------------------
   shared core: TaskService | FlowTimerService + WorkingCalendar |
   EventCatalogService + FlowExpression | subject anchor |
   authorization primitives (fail-closed) | append-only audit
```

Rule: a model evaluator owns the shape of its own runtime state and the
algorithm that advances it. It SHALL NOT own a task store, a clock, a
condition dialect, an event vocabulary or a second authorization model. A
new need in either evaluator that fits one of those goes into the core.

Cross-links are explicit and one-directional per call:

- CMMN to BPMN: a `stage` or `processTask` realised by a run
  (`REALISATION_RUN`). The run's terminal state drives the item
  (`CaseRunTerminalListener`, existing).
- BPMN to CMMN: the `case-open` and `case-advance` nodes call
  `CasePlanService`; the plan's cascade then runs as for any other
  transition.

Loops are bounded where they already are: the cascade's fixpoint bound and
the run advance budget. A run started by a `processTask` that advances its
own item is an ordinary transition of an active item.

## 3. The gaps, as built

### 3.1 `processTask` (gap 1)

`CaseItem::TYPE_PROCESS_TASK = 'processTask'`, added to `TYPES`.
`CasePlanTransitions` maps it to the work-item edges. `CasePlanDefinition`
accepts it; a process task MUST name its flow in `planSettings.flows[key]`
(validated at create: a process task without a flow is refused, because it
would be a human task with no human). `CaseRealisationService::realise()`
queues the run for stage and processTask alike. A process task has no
children.

### 3.2 Flow nodes (gap 2)

- `openregister.case-open`: config `{definition}` (the same shape
  `createPlan` takes). Creates the plan on the run's subject through
  `createPlan`, acting as the run's attributed identity. An object that
  already has a plan is not an error for the node: it reports
  `{opened: false, reason: "exists"}` on its output, so a re-run is safe.
- `openregister.case-advance`: config `{item, to, reason?}` where `item` is
  the plan-item key. Resolves the item on the run's subject and calls
  `transition`. Refusals (illegal edge, authorization) fail the step with the
  engine's message; they are never swallowed.

Both register through `FlowNodeRegistry` like every other node.

### 3.3 Plan-item timers (gap 3)

`FlowTimer::SUBJECT_TYPES` gains `case-item`. `CasePlanItemTimers` (new),
driven by `CaseItemTimerListener` on `CaseItemTransitionedEvent` (after
commit) and `FlowTimerFiredEvent`:

- an item entering `active` with `dueAt` arms a `due` timer (advisory,
  `legalEffect: none`). The core measures whole hours (calendar days past
  its 10,000-hour bound) from an anchor, so the anchor is set back from the
  deadline by the rounded budget: anchor + budget lands on `dueAt` exactly;
- with `expiresAt` it arms an `expiry` timer, legal effect `servicenorm`
  (advisory) unless the plan settings list the item key under `statutory`,
  in which case it is `wettelijk` with `onExpiry: transition:terminate`, the
  core's own rule for an enforcing outcome;
- an item reaching a terminal state: `cancelForSubject('case-item', uuid)`;
- a fired enforcing expiry on a `case-item` subject:
  `CasePlanService::onTimerExpired()` terminates the item through the state
  machine, cause `realisation`, cause ref `flow-timer:<uuid>`, then
  evaluates the plan. An item already terminal is left alone.

A deadline already past at activation arms nothing (logged). Arming and
cancelling failures are logged and never block the transition. Items
brought over by `ensureItems` emit no transition event and so get no timers;
their source engine had none.

### 3.4 One state list (gap 4)

`CaseItem::STATES = Task::STATES` and
`CaseItem::TERMINAL_STATES = Task::TERMINAL_STATES`. A unit test asserts both
the identity and the six values, so a seventh task state has to be decided
for plan items too.

### 3.5 System access (gap 5)

```php
public function createPlanAsSystem(string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, string $app): array;
public function getPlanAsSystem(string $objectUuid, string $app): array;
public function ensureItems(string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, array $history, string $app): array;
```

- `$app` must be a non-empty app id (`[a-z0-9_]+`); the audit actor is
  `system:<app>`. A Nextcloud uid cannot contain `:`, so the actor never
  collides with a user.
- No authorization check: these are reachable only from PHP running inside
  the server, which can already read and write every row. A structural test
  asserts that no file under `lib/Controller/` references `AsSystem` or
  `ensureItems`.
- The existing user verbs are unchanged: a null identity is still refused.

### 3.6 `ensureItems` (gap 6)

Input: the same definition `createPlan` takes (`settings` plus nested
`items`), where a node MAY carry `state` (one of the six; default `available`). History entries are
`{item, from, to, at, actor?, reason?}` keyed by item key.

Algorithm, in one transaction:

1. Validate the nodes with `CasePlanDefinition::validate()` (unknown type,
   bad sentry, duplicate key refused before anything is written) and every
   `state` against the lifecycle: a milestone may only carry `available`,
   `completed` or `terminated`.
2. Read the object's rows. If a row with a key exists, keep it untouched
   (its state is never overwritten). If a row exists whose register or schema
   differs from the call, refuse: the anchor is one object.
3. Insert only missing nodes, under their parent's row (existing or just
   inserted), with the recorded state, `entered_at` set for a non-available
   state, and no realisation: an imported active human item gets no new task,
   because the history it came from had none.
4. For each row inserted in this call: one audit entry (`from: null`,
   `to: state`, cause `import`, cause ref `ensure:system:<app>`,
   actor `system:<app>`), then each history entry for that key as an audit row with
   `imported: true` and `created` = its `at`. History for a key that already
   existed is skipped, so a re-run appends nothing.
5. No cascade runs. A drained case resumes evaluation on its next event or
   an explicit `evaluate`; evaluation in the middle of an import would act on
   a half-written plan.

Returns `{created: [keys], existing: [keys], items: [rows]}`.

## 4. Why the state tables stay separate

A run's marking is a token distribution over places; a plan is a tree of
items each with a lifecycle state. Forcing either into the other's table
means a column set that is half empty for every row and a migration of every
existing run and plan, for no behaviour a user sees. The overlap Ruben named
lives in the services, and those are the ones this change makes one. If a
single "what is running on this object" read is wanted later, it is a query
over runs, plan items and tasks by the shared anchor, not a merged table.

## 5. Migration

Additive. `Version1Date20261010120000` adds `imported` (boolean, nullable because Nextcloud refuses NOT NULL booleans on Oracle,
default false) to `openregister_case_item_audit`. Existing rows read as not
imported, which is true. No data is backfilled. Rollback: drop the column;
nothing else depends on it.

## 6. Risks

- **A process task's run fails.** Same as a stage today: the run's terminal
  state drives the item, a failed run terminates it.
- **Timer arming on a busy cascade.** One insert per item entering active
  with a deadline; plans are small by the cascade bound.
- **System methods misused from a controller.** Held by the structural test.
- **dossiq calls the user methods with a null uid today.** That stays
  refused; dossiq moves to the system methods (sibling ask).
