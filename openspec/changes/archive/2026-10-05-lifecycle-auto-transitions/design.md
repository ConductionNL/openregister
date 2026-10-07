## Context

See proposal.md, Why. The engine facts below shape the approach. Each was established by reading the
code on this branch, not assumed.

- **The write path has no wrapping transaction.** `ObjectService::saveObject()` delegates to
  `SaveObject::saveObject()`, which calls `MagicMapper::insert()` or `update()`. Those dispatch
  `ObjectCreatedEvent` / `ObjectUpdatedEvent` synchronously, straight after the row is written. The
  header of `ListenerDeferralService` records the same finding. A post-save listener therefore always
  sees a committed row.
- **But the post-save event fires in the MIDDLE of the save, not at its end.** After
  `MagicMapper::update()` returns (and has dispatched `ObjectUpdatedEvent`), `SaveObject::updateObject()`
  still writes the save's audit row, and for a save carrying a file property it calls
  `MagicMapper::update()` a SECOND time with its in-memory entity, which dispatches `ObjectUpdatedEvent`
  again. A move applied inside the first event would be written before the triggering save's audit row,
  and on a file-bearing save it would be overwritten by the second update, which carries the old
  lifecycle value. That is a lost update behind a transition event that has already fired. This single
  fact decides where a sync move runs; see "A sync move runs at the outermost write boundary".
- **`TransitionEngine::transition()` saves first and announces second.** It checks `from`, merges
  `inputs`, flips the field, calls `ObjectService::saveObject()`, then dispatches
  `ObjectTransitionedEvent`. Every gate runs inside that save, in `LifecycleValidationListener`
  (`authorization`, `condition`, `requires`), `ApprovalChainGateListener` and `LifecycleActionListener`
  (`actions[]`).
- **The save path identifies a transition by its values, not its name.** Both
  `LifecycleValidationListener::findTransitionByTarget()` and `LifecycleActionListener::matchTransition()`
  return the FIRST declared transition whose `to` matches and whose `from` contains the old value. When
  two transitions share a from and to pair, a named call to the second one is gated by the first one's
  rules and runs the first one's actions. The mock register has such pairs today (`startVerifying` and
  `assign` both move `received` to `verifying`). This is pre-existing and it matters here, because
  "an automatic move obeys what a manual one obeys" is only true if the save path evaluates the
  automatic transition's own rules.
- **Post-save events do not always fire in the request.** `defer_object_events=1` hands
  `ObjectCreatedEvent` to `DeferredObjectEventJob`. `SystemOperationContext` suppresses object events
  altogether during imports, repair steps and seeding. Bulk saves dispatch from `SaveObjects`, outside
  `ObjectService::saveObject()`. `RevertHandler` writes through `MagicMapper::update()` directly.
- **The flow engine's two execution-mode values live in `Flow::MODE_SYNC` and `Flow::MODE_ASYNC`.**
  `FlowTriggerService` does not use them: it declares its own private `MODE_SYNC = 'sync'` and
  lowercases the stored value before comparing. `ListenerDeferralService` answers the same question with
  `MODE_INLINE` / `MODE_BACKGROUND` and the `listenerDeferral` kill switch, and `MagicMapper` answers it
  for created events with `defer_object_events`. The user was told this change adds a third place that
  decides sync versus async. By this count it is the fourth decision point, and the flow values already
  have two spellings.
- **Re-entrancy.** The flow TRIGGER path has no self-trigger or re-entrancy guard: nothing in
  `FlowTriggerListener`, `FlowTriggerService::fire()`, `runInline()` or `FlowRunService::queue()` notices
  that a run's own write fired the event that queued it. Two nearby mechanisms are not that guard:
  `SubFlowNode` refuses a sub-flow already on the run's call stack and stops at `MAX_DEPTH = 16`, which
  covers invocation, not triggering, and is the precedent the loop cap copies. `SaveObject`'s save call
  stack detects circular REFERENCES during validation; a second push of the same object is quietly
  ignored. `FlowRunContext` is attribution, not a guard.
- **Identity.** ADR-099: a user action acts as the session user; a callee never widens the identity it
  was called with; `runAsSystem()` must not be reachable from user-authored input.
  `ObjectService::runAs()` wraps `setVolatileActiveUser()`. `ActorForwardedJob`, the base of every
  deferred listener job, restores its captured user with `setUser()` and does not refuse a disabled
  account.
- **Job arguments are capped.** Nextcloud's `JobList::add()` refuses a JSON argument longer than 4000
  characters, so a queued move cannot carry the object's previous state.

## Goals / non-goals

**Goals:**

- A schema author can make a status follow the object's data, with no PHP in the consuming app.
- An automatic move is a named transition in every observable way: same gates, same actions, same
  events, same audit trail, marked as automatic.
- A sync move lands in the response of the request that caused it, and nothing it does can fail or
  unwind that request.
- No declaration can make an object loop, in one request or across background jobs.
- Zero behaviour change for a schema without `autoWhen`.

**Non-goals:**

- Time-based triggers ("move to `verlopen` when the deadline passes"). `autoWhen` is evaluated on a
  write. A rule reading the clock is only re-read when something writes the object. Scheduled
  re-evaluation is a separate change.
- Graph-mode automatic moves. Refused, like graph-mode conditions; `lifecycle-graph-enforcement` owns
  the gap.
- Fixing the value-based transition matching in the two listeners. This change refuses to fire an
  automatic transition that the matching would misidentify; making the save path honour the named
  action is a separate change with its own consumers.
- A re-entrancy guard for the flow trigger path. Recorded under Risks.
- Unifying the sync/async decision points listed in Context.

## Decisions

### Declarative-vs-imperative decision

Per ADR-031, stated plainly because the surface reading is backwards: this change is PHP. A candidate
selector, one new method on the existing evaluator, a listener, a request-scoped pass, a job and
validator branches. No app declares anything in its
register file here.

ADR-031 distinguishes an APP writing a service from the ENGINE providing the declarative extension an
app uses instead. This change is the second kind. Today "the case is decided once the motivation is
filled in" costs a consuming app a post-save listener or a flow with an object-write node. That is the
"custom state-machine service" anti-pattern ADR-031 lists, and it exists because the lifecycle
annotation can refuse a move but cannot make one. ADR-031 exception (1) names the remedy: when OR's
extension is missing, build the extension.

The imperative code here removes imperative code from consumers. `requires` guards and flows remain
the seam for work that is not a rule over the object's own data: IO, cross-schema lookups, anything
with a side effect beyond the move itself.

No register patch ships with this change. The declarative side belongs to the consuming apps, and
adopting it is ADR-031's opportunistic migration. The e2e spec seeds its own schemas, so the mock
register stays free of an automatic move that would fire on every developer's seeded objects.

### A sync move runs at the outermost write boundary, not in the listener

The obvious design fires the transition from inside an `ObjectUpdatedEvent` listener. Context shows why
that is wrong here: the event fires before the triggering save has written its audit row, and on a
file-bearing save before a second update that would overwrite the move. The listener position also
inverts event order for a named transition: the automatic move's `ObjectTransitionedEvent` would be
dispatched before the named one's, because `TransitionEngine` announces after its save returns.

So the listener only RECORDS. On `ObjectCreatedEvent` and `ObjectUpdatedEvent` it checks whether the
schema declares any `autoWhen` (a cheap read of the annotation) and, if so, records the object in a
request-scoped pass: uuid, register, schema, and the event's old object data as `previous`, first
capture winning. It never evaluates and never writes. That keeps it honestly clear of the
listener-placement gate (ADR-078): there is no write inside the user's save to annotate or defer.

The pass has a boundary counter. `ObjectService::saveObject()` and `TransitionEngine::transition()`
each enter it on the way in and leave it in a `finally`. When the OUTERMOST boundary is left, the
triggering write is finished in every sense that matters: row, audit row, follow-up writes, and for a
named transition its `ObjectTransitionedEvent`. Only then does the pass drain.

The drain is a loop, not recursion. For each recorded object it re-reads the stored object, decides the
candidate, and fires it through `TransitionEngine::transition()`. That call enters the boundary again,
and its own `ObjectUpdatedEvent` records the object again. A draining flag stops the nested boundary
exit from starting a nested drain, so the outer loop picks the re-recorded object up and decides the
next step. Each step is counted against the cap.

The outermost boundary returns the object as it stands after the drain: the entity returned by the last
move applied to that object, or the original entity when none was. That is how the response of both
routes carries the new state without a controller change.

Alternatives considered:

- Fire inside the listener. Rejected: the lost update and the audit and event order above.
- A shutdown hook. Rejected: the response has already been produced, so a sync move would not be in it.
- Have the controllers re-read after saving. Rejected: it covers only the routes someone remembers to
  change, and it leaves the lost update in place.

### The move is decided when the pass drains, against the stored object

`autoWhen` is evaluated at drain time, against the object as stored, not against the event payload.
Computed fields, defaults and file ids are all present by then, and the two `ObjectUpdatedEvent`s of a
file-bearing save collapse into one decision because the pass records per object. `previous` is the
old object data the FIRST event of the pass carried, which is the state before the write that started
the pass, or an empty object on create.

### `autoWhen` is evaluated by `LifecycleConditionEvaluator`, not a second way

The first link put every piece of condition evaluation in `LifecycleConditionEvaluator::refusal()`: the
refusal of a present-but-not-rule-object value before evaluation, the four-key document built from the
session user, and the `FlowExpression::isTrue()` call. `autoWhen` reuses that class rather than
evaluating JSONLogic along a second path. The evaluator gains one public method, `holds()`, taking the
rule and the same inputs `refusal()` takes, and returning true only for a non-empty rule object that
evaluates true. `refusal()` is rewritten to call `holds()` and then build its message, so there is
exactly one place that decides whether a lifecycle rule holds, and one place that builds its document.

This matters beyond tidiness. The two failure-direction guards the first link paid for (a scalar is
refused before it reaches `FlowExpression`, an unevaluable expression counts as false) are the same two
an `autoWhen` needs, and a second evaluator would have to rediscover both. `holds()` logs the
not-a-rule-object case at warning, as `refusal()` does today, and logs nothing when a well-formed rule
simply does not hold, since for `autoWhen` that is the common case on every write.

The candidate selector (which transitions are eligible, ambiguity, shadowing, mode) is a separate class
in `lib/Service/Lifecycle/` that calls `holds()`. It owns selection; the evaluator owns evaluation, the
split the first link drew for the same complexity reason.

The one semantic difference between the two rules is recorded in the spec: in an `autoWhen`, `object` is
the state being left, not the state being entered. The document's shape is identical because it is
built by the same method.

Alternative considered: lift the document builder into a third collaborator and let each rule evaluate
on its own. Rejected: it shares the document but duplicates the scalar guard and the `isTrue()` call,
which are exactly the parts where a drift would fail open.

### `executionMode` binds to the flow engine's constants, and defaults to sync

The validator and the pass compare against `Flow::MODE_SYNC` and `Flow::MODE_ASYNC`, exactly, with no
lowercasing. A value that is neither is refused at save time and does not fire at runtime. This does
not remove the extra decision point the user accepted; it guarantees the lifecycle and the flow engine
cannot drift into different spellings of the same two words. The stray private constant in
`FlowTriggerService` is a one-line follow-up outside this change's blast radius.

The default is `sync`, the opposite of a flow's `async` default, deliberately. A flow queues by default
because an arbitrary graph must not sit on the save's critical path. An automatic transition is one
bounded write through a path the caller already waits on, and an author writing "decided once the
motivation is filled in" expects the response to say `besloten`. The user chose sync as the default.

### Async is decide now, apply later if nothing changed

An `async` candidate is decided at drain time with the full document, exactly like a sync one. It is
then handed to `ListenerDeferralService` as an `ActorForwardedJob` entry carrying the uuid, register,
schema, the transition name, the object's version and `updated` timestamp at decision time, and the
pass lineage (hop count and visited states). The job applies the move only when the stored version and
timestamp still match. If they do not, a newer write has made its own decision and the job is a no-op.
That is the at-least-once, reconcile-against-current-state contract `ActorForwardedJob` already
documents.

The job does NOT re-evaluate `autoWhen`, because it cannot: `previous` does not fit in a job argument
capped at 4000 characters, and re-evaluating with a different `previous` would make an async rule mean
something different from the same rule in sync. The chunk size is chosen so a full chunk fits that cap.
The dedupe key is uuid plus version, because `ListenerDeferralService` keeps the FIRST entry per key:
deduping on uuid alone would keep a stale decision and drop the current one.

The job enters a boundary seeded with the carried lineage, so a chained sync move after an async hop
drains inside the job and counts against the same pass.

Under the `listenerDeferral=inline` kill switch, an async move decided inside a boundary is applied at
the pass drain, as the existing deferred listeners do. A move decided outside a boundary is still
queued; see the next section.

### Writes outside a boundary queue their moves

A bulk save, a deferred created event, a revert and a direct mapper write all dispatch post-save events
with no `ObjectService::saveObject()` around them. There is no point at which a sync move can run after
the write completes and before a response, because either there is no response or the write is not
the caller's last. Their candidates are queued as async, whatever the declared mode, and a debug line
says so. The consequences worth naming: with `defer_object_events=1`, an automatic move on create is
never in the create's response, and a revert that lands an object in a state whose rule holds will move
it again shortly after.

### The loop cap: no revisit, and a ceiling of 10

Two limits, both per object per pass, following `SubFlowNode`'s shape (refuse what is already on the
stack, and backstop with a depth ceiling):

- **No revisit.** A move into a state the object already occupied in this pass is not made. A to B to A
  is cut at the second move, deterministically, after exactly one write. A count-only cap would let the
  object flip ten times, write ten audit rows, send ten rounds of notifications, and stop on whichever
  state the parity of the ceiling picked.
- **Ceiling of 10.** It only bites on a chain through more than ten distinct states, which no lifecycle
  in the fleet has. It is a private constant, like `SubFlowNode::MAX_DEPTH`, and not configurable: a
  configurable cap invites raising it to make a loop's symptom go away.

A cut logs at error level with the schema, the object, the limit and the transitions applied, and stops.
It never throws, since the triggering write has already succeeded and nothing about the cut is the
caller's fault.

The user asked for a cap "per request per object". A pass is slightly different, on purpose. Within one
request every write reachable from the triggering one is in the same pass, so it is at least as strict
there. And the pass travels with a queued move, which a per-request counter cannot do: a request-scoped
counter resets in every background job, so an async A to B, B to A loop would run forever, one cron
tick at a time.

### Ambiguity fires nothing, and order is not a tie-breaker

When two automatic transitions hold from the same state, neither fires and a warning names both. Three
options were weighed:

- **First declared wins.** Rejected. Declaration order is the order of keys in a JSON object, and
  MySQL's native JSON type does not preserve key order, while PostgreSQL's `json` does. The same schema
  would pick different transitions on different installations.
- **Prove mutual exclusion at save time.** Rejected. Two JSONLogic rules over arbitrary fields cannot in
  general be shown disjoint, and a partial check would pass the cases it cannot see.
- **Refuse at runtime and say so.** Chosen. It fails closed: an ambiguous move is not made, the object
  stays put, and the warning tells the author which rules overlap.

### A shadowed automatic transition does not fire

Context shows the save path identifies a transition by its from and to values, first match wins. An
automatic transition that shares its pair with an earlier-declared one would be gated by the earlier
one's rules and run its actions. Firing it would silently break the promise this change makes. The
candidate selection checks for an earlier transition with the same `to` whose `from` contains the
current value, and refuses with a warning naming both. The underlying matching is not fixed here; see
Non-goals.

### The move acts as the caller, never as the system

A sync move runs under the ambient session: the identity whose write triggered it, which is what
ADR-099 prescribes for a user action. Nothing is swapped, so there is nothing to restore. An async move
carries that uid through `ListenerDeferralService`, and the job refuses a uid that no longer resolves or
belongs to a disabled account, as `FlowRunAsScope` does. The automatic move never enters
`SystemOperationContext` and never calls `runAsSystem()`: `autoWhen` is authored in a schema, which is
exactly the user-authored input ADR-099 keeps away from the userless principal.

A session-less write, such as an `occ` command without a session, gets a session-less move. Its
`authorization` list refuses it, and `TransitionEngine`'s `update` permission check refuses it unless
RBAC admits anonymous updates on that schema. That is the correct default. A CLI that wants automatic
moves must act as a user, which the annotation reference documents alongside the empty-`user` note from
the first link.

`ActorForwardedJob` restores identity with `setUser()` rather than ADR-099's `setVolatileActiveUser()`,
and does not check for a disabled account. The new job adds the disabled check locally. Moving the base
class onto the ADR-099 primitive touches five other jobs and is a follow-up.

### A refusal is logged at warning and never retried

A fired transition can be refused by any gate a manual one meets. The pass catches the refusal
(`HookStoppedException`, `NotAuthorizedException`, `RuntimeException`,
`InvalidTransitionInputException`) and any other throwable, logs a warning naming the schema, the
object, the transition and the refusal code, and moves on. It does not retry and it does not surface
the refusal to the caller, whose write succeeded.

Warning rather than debug, because Nextcloud's default log level hides anything quieter, and an
automatic move that never happens is invisible by construction (the lesson `DeferredObjectEventJob`
records). The volume is bounded: a refusal needs a write while the rule holds, and a rule whose move is
refused on every write is an authoring defect worth seeing.

### Create fires; system operations do not

The listener subscribes to `ObjectCreatedEvent` as well as `ObjectUpdatedEvent`. A rule is about the
state the object is in, not about how it got there, and "created complete, so it skips straight to
review" is a real use. `previous` is an empty object on create. The first link's listener, by contrast,
ignores creates because a create has no transition to gate; there is nothing contradictory in that.

The listener does not subscribe to `ObjectTransitionedEvent`. A named transition's save already
dispatched `ObjectUpdatedEvent`, so listening to both would record every named transition twice.

### Automatic moves are marked on the event and in the audit row

`ObjectTransitionedEvent` gains an additive constructor parameter `automatic`, defaulting to false, and
a getter. `TransitionEngine::transition()` takes it through an ambient frame on the pass rather than a
new public parameter, so no caller can claim a manual move was automatic. `FlowTriggerListener` adds
`automatic` to the run context beside `action`, `from` and `to`.

The audit row is sealed into a hash chain when it is built, so the marker must be applied before
sealing. `AuditTrailMapper::buildAuditTrail()` already applies `AuditFlowAttribution` from ambient state
at exactly that point, for exactly this reason. The automatic marker follows the same pattern: the pass
exposes the transition it is applying, and the builder folds `automaticTransition: {action}` into the
row's `changed` data. The row is still attributed to the acting user.

### Validation refuses the save, not just warns

`SchemaMapper::validateLifecycleAnnotation()` refuses the save for exactly two codes,
`lifecycle-condition-malformed` and `lifecycle-condition-graph-unsupported`, and treats every other
lifecycle error as advisory: it logs a warning and stores the annotation as written. The advisory
policy exists because refusing a whole register import over a partial or different-dialect lifecycle
block broke other apps' imports.

**Decision: a malformed `autoWhen` goes in the refusing bucket**, and so do the other three new codes
(`lifecycle-execution-mode-malformed`, `lifecycle-autowhen-requires-input`,
`lifecycle-autowhen-graph-unsupported`). The filter's code list grows from two entries to six. Two
reasons.

A stored malformed `autoWhen` is worse than a stored malformed condition. A scalar evaluates as a
truthy literal, so the transition would fire on every write from its `from` state: unbidden writes,
audit rows and notifications, each one feeding the next decision. The loop cap bounds it, but "bounded
damage on every save" is not an acceptable failure mode for a typo. An unknown `executionMode`, a
required input and a graph-block `autoWhen` all describe a move that can never happen as written, which
is the silent no-op class the first link refused.

And refusing breaks no import: no register can carry `autoWhen` or `executionMode` before this key
exists. `lifecycle-message-malformed` stays advisory, unchanged.

The exception message still leads with "Invalid", because `SchemasController` maps a save exception to
400 by matching that word, and it is generalised from "condition" so it reads correctly for both rules.
The runtime does not trust save time: a stored non-rule `autoWhen` counts as not holding and logs a
warning, which covers schemas written by a path that skips the mapper.

Alternative considered: keep `autoWhen` advisory and rely on that runtime refusal alone, since a
non-rule value never fires. Rejected for three reasons. The runtime guard catches a scalar but not a
well-formed rule with an unknown operator, which would store silently and never fire. An advisory
warning goes to a log the author does not read, while the schema save reports success. And the
advisory bucket's only justification, not breaking existing imports, does not apply to a key no
register carries yet.

### Graph mode is refused

A graph block has no per-transition object, and graph-mode moves are validated only inside
`TransitionEngine`, never on the ordinary save path. An automatic graph move would fire through the
engine and so would be checked, but a later direct edit could undo it unchecked, and the author could
not declare which derived target to move to without putting sibling UUIDs in a schema. Refused with
`lifecycle-autowhen-graph-unsupported`, for the reasons the first link refused `graph.condition`.
`lifecycle-graph-enforcement` is where both are revisited.

### Tests are mutation-checked, the e2e lives in `workflows/`, and CI must be told to run it

Every guard this change adds (the scalar refusal, the revisit rule, the ceiling, the ambiguity refusal,
the shadow refusal, the drain position) has a test that is shown to fail when the guard is removed, and
then restored. A test that only saves a valid object and sees the new state passes with the cap
deleted.

The Playwright spec goes in `tests/e2e/workflows/`, because `playwright.config.ts` excludes
`tests/e2e/api-direct/` from every project, twice, so a spec there never runs and reports nothing. It
drives the real API, like `lifecycle-conditions.spec.ts`, and proves the two things only an end-to-end
run can: a sync move is in the same response, and the cap holds against a real save loop.

Writing the file is not enough for CI. The CI job runs `tests/e2e/ci/playwright.config.ts`, whose
`testDir: '..'` is narrowed by an explicit `testMatch` allow-list: a spec not named there never runs in
CI, and gate 19 would still count its `@e2e` anchors as coverage. So the spec is written to pass all
four admission criteria recorded in that config, and then admitted with a one-line entry and a comment
stating how it meets each:

1. **Hermetic.** It seeds its own register and schemas through the REST controllers via
   `_fixtures.ts`. No `occ`, no docker, no pre-seeded data.
2. **Self-cleaning.** Everything is namespaced by `makeRunId()`, and `afterAll` deletes what
   `beforeAll` seeded, re-resolving by slug when a mid-run failure lost an id.
3. **No conditional asserts.** Every assertion is unconditional. There is no `isVisible().catch()`
   guard, and each test re-reads the object and asserts its lifecycle value outright.
4. **No seed-dependent skip.** No `test.skip()` depends on data. If a cascade guard is needed inside a
   serial describe, it can only fire after an earlier create in the same file has already failed.

The first link's `lifecycle-conditions.spec.ts` is not on the allow-list today, so it does not run in
CI either. Admitting it is outside this change, and is noted in the report rather than done here.

## Risks / trade-offs

- **A loop that crosses an async flow run is not bounded by this cap.** An automatic move fires
  `object.transitioned`; an async flow on that trigger writes the object back later, in the worker; that
  write starts a new pass. The flow trigger path has no re-entrancy guard (Context), so this loop is
  bounded only by the worker's cadence. A sync flow loop is caught, because it runs inside the pass.
  → Documented on the annotation reference. A guard for the flow trigger path is its own change.
- **Response latency.** A sync move adds a full transition to the triggering request, and a chain adds
  one per hop, up to ten. → Bounded by the ceiling. `async` exists for moves whose gates or actions are
  slow.
- **The response of a chain may differ in rendering from the triggering save's.** The returned entity
  is the one the last move produced, rendered with default `extend` rather than the triggering request's.
  → Recorded as an open question; the lifecycle value, which is what the feature promises, is correct
  either way.
- **An automatic move can surprise a user.** A save that only filled in a field comes back in a
  different state. → The response carries the new state, the event and audit row are marked automatic,
  and the rule sits in the schema where it can be read.
- **Every write to a schema with `autoWhen` costs a re-read at drain.** → Only schemas declaring
  `autoWhen` are recorded; every other write pays one array lookup.
- **Revert and deferred create move objects off-request.** → Documented; see "Writes outside a boundary
  queue their moves".
- **The pass is ambient, request-scoped state, and a leak would carry one request's lineage into the
  next job in a long-running worker.** → Boundaries are entered and left in `finally`, the drain clears
  what it drained, and a test asserts the pass is empty after a job, the leak direction, as
  `FlowRunContext`'s own tests do.
- **Value-based transition matching stays.** → Automatic transitions refuse to fire when shadowed, and
  the gap is written down, but manual named calls to a shadowed transition remain misgated as today.

## Migration plan

Purely additive. No migration, no schema rewrite, no data change. A transition without `autoWhen`
takes exactly today's path; the listener records nothing for a schema that declares none.

Rollback is reverting the change. Any schema saved meanwhile with `autoWhen` stays stored and simply
stops firing, which fails safe: no object moves on its own. Queued async jobs whose class no longer
exists are dropped by Nextcloud's job list.

## Open questions

- Should the outermost boundary return the refreshed entity rendered with the triggering request's
  `extend` parameters? The lifecycle value is right either way; this only affects extended fields in
  the response, and it can be settled during implementation without changing the spec.
- `ListenerDeferralService`'s chunk size for the new job: whatever keeps a full chunk under 4000
  characters. A number, not a design question.
