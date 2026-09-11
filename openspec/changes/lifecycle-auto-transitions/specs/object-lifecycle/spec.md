## ADDED Requirements

### Requirement: A lifecycle transition MAY fire automatically when its `autoWhen` rule holds after a write

A transition declared in `x-openregister-lifecycle.transitions` MAY carry an optional `autoWhen`, whose
value is a JSONLogic rule object. After an object is created or updated through the object save path,
OpenRegister SHALL look for a transition whose `from` contains the object's current lifecycle value and
whose `autoWhen` holds for the object as written. When exactly one such transition exists, OpenRegister
SHALL fire it as a named transition, exactly as `POST /api/objects/{id}/transition` would with that
transition's name and no payload.

A transition whose `to` equals the object's current lifecycle value SHALL NOT fire automatically, because
the move would change nothing and would repeat on every write.

A transition WITHOUT an `autoWhen` key SHALL behave exactly as before and SHALL never fire on its own.
The key is additive and never required.

A write made inside a system operation (a configuration import, a repair step, seeding) dispatches no
object events, and SHALL therefore fire no automatic transition. Seeding is not a user action, and an
automatic move on seed data has no one to act as.

#### Scenario: An automatic transition fires after an update and the response carries the new state
- **GIVEN** a transition `beslissen` with `from: ["in-behandeling"]`, `to: "besloten"` and `autoWhen: { "!!": { "var": "object.motivering" } }`
- **AND** an object in state `in-behandeling` without a `motivering`
- **WHEN** the object is updated through an ordinary object save that sets a non-empty `motivering`
- **THEN** the response to that save MUST carry the lifecycle value `besloten`
- **AND** a fresh read of the object MUST return `besloten`

#### Scenario: An automatic transition fires after a create
- **GIVEN** the same transition and a schema whose declared `initial` state is `in-behandeling`
- **WHEN** an object is created carrying a non-empty `motivering`
- **THEN** the response to the create MUST carry the lifecycle value `besloten`

#### Scenario: An automatic transition does not fire while its rule does not hold
- **GIVEN** the same transition
- **WHEN** an object in state `in-behandeling` is updated without a `motivering`
- **THEN** the response MUST carry the lifecycle value `in-behandeling`
- **AND** no transition MUST be applied

#### Scenario: A transition without autoWhen never fires automatically
@e2e exclude regression guard on an unchanged path, covered by the existing lifecycle PHPUnit suite
- **GIVEN** a transition declaring no `autoWhen`
- **WHEN** an object in its `from` state is saved
- **THEN** no transition MUST be applied and the lifecycle value MUST be unchanged

#### Scenario: A move to the current value is never fired
@e2e exclude candidate selection is internal, covered by PHPUnit
- **GIVEN** a transition whose `from` contains its own `to`, such as `from: ["ontvangen", "in-behandeling"]`, `to: "in-behandeling"`, with an `autoWhen` that holds
- **WHEN** an object in state `in-behandeling` is saved
- **THEN** that transition MUST NOT fire

#### Scenario: A write inside a system operation fires no automatic transition
@e2e exclude system operations have no HTTP surface, covered by PHPUnit
- **GIVEN** a transition whose `autoWhen` holds
- **WHEN** the object is written inside a system operation
- **THEN** no automatic transition MUST be applied

### Requirement: An automatic transition MUST obey every gate a manual transition obeys

An automatic transition SHALL be applied through the same path as a named transition, so every rule
that can refuse a manual transition SHALL be able to refuse the automatic one, in the same order: the
object `update` permission, the `from` check, the `authorization` list, the `condition`, the `requires`
guard, and any approval-chain gate. When the transition is applied, its declared `actions[]` SHALL run
exactly as they do for a manual transition.

When any of those gates refuses the automatic transition, OpenRegister SHALL NOT apply it, SHALL leave
the object in the state the triggering write left it in, and SHALL log the refusal at warning level
naming the schema, the object, the transition and the refusal code. The refusal SHALL NOT be retried,
SHALL NOT be surfaced to the caller as an error, and SHALL NOT affect the triggering write. A later
write that finds the rule still holding SHALL try again.

A transition declaring a required input cannot be fired automatically, because an automatic move
carries no payload. That declaration is refused at schema-save time; see the validation requirement.

#### Scenario: An automatic transition refused by its condition leaves the triggering write intact
- **GIVEN** a transition with an `autoWhen` that holds and a `condition` that does not hold
- **WHEN** an object in its `from` state is saved with other field changes
- **THEN** the save MUST succeed and its field changes MUST be stored
- **AND** the response MUST carry the unchanged lifecycle value
- **AND** no transition MUST be applied

#### Scenario: An automatic transition refused by its authorization list is not applied
@e2e exclude needs a second non-admin principal per run, covered by PHPUnit
- **GIVEN** a transition with an `autoWhen` that holds and an `authorization` list naming a group the saving user is not in
- **WHEN** that user saves the object
- **THEN** the save MUST succeed and the transition MUST NOT be applied
- **AND** a warning MUST be logged carrying the code `lifecycle-transition-unauthorized`

#### Scenario: A denying guard stops the automatic transition
@e2e exclude guard resolution is backend wiring, covered by PHPUnit
- **GIVEN** a transition with an `autoWhen` that holds and a `requires` guard that denies
- **WHEN** the object is saved
- **THEN** the transition MUST NOT be applied and a warning MUST be logged carrying the code `lifecycle-guard-denied`

#### Scenario: An automatic transition runs its declared actions
@e2e exclude action handlers are backend wiring, covered by PHPUnit
- **GIVEN** a transition with an `autoWhen` that holds and a declared `actions[]` entry
- **WHEN** the automatic transition is applied
- **THEN** that action MUST run exactly as it runs for the same transition fired by name

### Requirement: A sync automatic transition MUST be applied after the triggering write completes and MUST NOT unwind it

A `sync` automatic transition SHALL be applied in the same request as the write that triggered it, and
SHALL be applied only after that write is complete: after its object row, its audit row, and any
follow-up write the same save makes to the same object. It SHALL be applied before the response is
produced, so the response of the triggering request SHALL carry the lifecycle value the automatic
transition reached. This holds for both routes that write an object: an ordinary object save, and a
named transition whose save makes a further automatic transition hold.

The triggering write SHALL NOT be unwound, failed or delayed in its commit by anything the automatic
transition does. An exception raised while applying it SHALL be caught and logged at warning level; the
triggering request SHALL answer as it would have without the automatic transition.

For a named transition that triggers an automatic one, the `ObjectTransitionedEvent` of the named
transition SHALL be dispatched before that of the automatic transition, so a listener sees the moves in
the order they happened.

#### Scenario: The named-transition route returns the state an automatic transition reached
- **GIVEN** a manual transition `indienen` from `concept` to `ingediend`, and a transition `beoordelen` from `ingediend` to `in-beoordeling` whose `autoWhen` holds
- **WHEN** `POST /api/objects/{id}/transition` is called with action `indienen`
- **THEN** the response MUST carry the lifecycle value `in-beoordeling`

#### Scenario: The triggering write's audit row precedes the automatic one
@e2e exclude audit ordering is a backend invariant, covered by PHPUnit
- **GIVEN** a save that makes an automatic transition hold
- **WHEN** both writes have been applied
- **THEN** the audit row of the triggering save MUST precede the audit row of the automatic transition

#### Scenario: A save that also writes file properties keeps the automatic move
@e2e exclude the stale second write is internal to the save pipeline, covered by PHPUnit
- **GIVEN** a save carrying a file property that makes an automatic transition hold
- **WHEN** the save and the automatic transition have been applied
- **THEN** the stored lifecycle value MUST be the one the automatic transition reached

#### Scenario: The named transition's event precedes the automatic transition's event
@e2e exclude event order is a backend invariant, covered by PHPUnit
- **GIVEN** a named transition whose save makes an automatic transition hold
- **WHEN** both have been applied
- **THEN** `ObjectTransitionedEvent` for the named transition MUST be dispatched before `ObjectTransitionedEvent` for the automatic one

#### Scenario: An exception while applying an automatic transition does not fail the request
@e2e exclude provoking an engine exception needs a broken fixture, covered by PHPUnit
- **GIVEN** a transition with an `autoWhen` that holds, whose application raises an exception
- **WHEN** the object is saved
- **THEN** the save MUST answer with the status it would have had without the automatic transition
- **AND** the exception MUST be logged at warning level naming the transition

### Requirement: `executionMode` selects sync or async, using the flow engine's two values

@e2e exclude background job execution is not browser-observable, covered by PHPUnit

A transition declaring `autoWhen` MAY also declare `executionMode`. Its value SHALL be exactly one of the
two values the flow engine already defines for the same question, `sync` and `async`, and OpenRegister
SHALL compare against those definitions rather than spelling the strings anew. When `executionMode` is
absent, the transition SHALL run `sync`.

An `async` automatic transition SHALL be decided at the moment its `sync` counterpart would be applied,
using the same document, and SHALL then be queued and applied off-request. The queued move SHALL be
applied only when the object has not been written since the decision. When it has, the queued move
SHALL be dropped silently, because the newer write made its own decision.

A write made outside any request-scoped save, such as a bulk save, a deferred create event or a revert,
has no point at which a `sync` move can be applied after the write completes and before a response. Its
automatic transitions SHALL be queued as `async`, whatever their declared mode.

When the instance's listener-deferral kill switch is set to run deferred work inline, an `async`
automatic transition decided inside a request-scoped save SHALL be applied where a `sync` one would be.
A move decided outside a request-scoped save SHALL still be queued, because there is no safe inline
point for it.

#### Scenario: An absent executionMode runs sync
- **GIVEN** a transition declaring `autoWhen` and no `executionMode`
- **WHEN** its rule holds after a save
- **THEN** it MUST be applied before the response is produced

#### Scenario: An async automatic transition is applied off-request
- **GIVEN** a transition declaring `autoWhen` and `executionMode: "async"` whose rule holds after a save
- **WHEN** the save's response is produced
- **THEN** the response MUST carry the unchanged lifecycle value
- **AND** once the queued move is processed, the object MUST carry the transition's `to` value

#### Scenario: A queued move is dropped when the object changed in between
- **GIVEN** a queued `async` automatic transition
- **AND** a later write to the same object before the queue is processed
- **WHEN** the queued move is processed
- **THEN** it MUST NOT be applied

#### Scenario: A write outside a request-scoped save queues its automatic transitions
- **GIVEN** a `sync` transition whose rule holds after a bulk save
- **WHEN** the bulk save completes
- **THEN** the automatic transition MUST be queued and applied off-request

#### Scenario: An unrecognised stored executionMode does not fire
- **GIVEN** a stored transition whose `executionMode` is neither `sync` nor `async`
- **WHEN** its rule holds after a save
- **THEN** it MUST NOT fire and a warning MUST be logged naming the transition

### Requirement: Automatic transitions MUST be bounded by a loop cap that logs its breach

An automatic transition's own write is a write, so it can make another automatic transition hold. A
pass is the work started by one write and everything it triggers automatically. Within a pass,
OpenRegister SHALL keep applying automatic transitions one at a time, re-deciding after each, until no
rule holds or the pass is cut.

A pass SHALL be cut, for the object concerned, when either limit is reached:

- **Revisit.** A move whose `to` is a state the object already occupied in this pass, including the
  state it started in, SHALL NOT be made.
- **Ceiling.** At most 10 automatic transitions SHALL be applied to one object in one pass.

A cut SHALL be logged at error level, naming the schema, the object, the limit reached and the sequence
of transitions applied so far. The cut SHALL NOT raise an exception and SHALL NOT recurse. The ceiling
is a fixed constant of the engine and SHALL NOT be configurable per schema or per instance.

A pass SHALL survive an `async` hop: a queued move SHALL carry the pass's count and visited states, and
the job applying it SHALL continue the same pass rather than start a new one. A loop SHALL NOT escape
the cap by crossing into a background job.

#### Scenario: A chain of automatic transitions continues in the same pass
- **GIVEN** transitions `a` from `stap-1` to `stap-2` and `b` from `stap-2` to `stap-3`, both `sync` with an `autoWhen` that holds
- **WHEN** an object in `stap-1` is saved
- **THEN** the response MUST carry the lifecycle value `stap-3`

#### Scenario: A ping-pong between two states stops at the first revisit
- **GIVEN** a transition from `heen` to `terug` and a transition from `terug` to `heen`, both with an `autoWhen` that always holds
- **WHEN** an object in `heen` is saved
- **THEN** exactly one automatic transition MUST be applied
- **AND** the response MUST carry the lifecycle value `terug`

#### Scenario: A chain longer than the ceiling stops at ten moves
- **GIVEN** twelve states `s0` to `s11` and eleven transitions each moving one state forward, all with an `autoWhen` that always holds
- **WHEN** an object in `s0` is saved
- **THEN** exactly ten automatic transitions MUST be applied
- **AND** the response MUST carry the lifecycle value `s10`

#### Scenario: A cut is logged at error level with the chain
@e2e exclude log output is not browser-observable, covered by PHPUnit
- **GIVEN** a pass cut by either limit
- **WHEN** the cut happens
- **THEN** one error-level log line MUST name the schema, the object, the limit and the transitions applied

#### Scenario: The count travels with an async hop
@e2e exclude background job execution, covered by PHPUnit
- **GIVEN** an `async` automatic transition queued after nine automatic transitions in one pass
- **WHEN** the job applies it and a further rule holds
- **THEN** the job MUST apply at most one more move and then cut the pass

### Requirement: The `autoWhen` document is the condition document, read when the move is decided

@e2e exclude document construction is internal, covered by PHPUnit

An `autoWhen` SHALL be evaluated against the same four top-level keys a transition `condition` reads,
and against no others:

- `object`: the object as the triggering write stored it, so its lifecycle field holds the transition's
  `from` value;
- `previous`: the object as it was before the triggering write, or an empty object when that write
  created it;
- `user`: the identity the automatic transition acts as, as `uid` and `groups`, with an empty string and
  an empty list when there is none;
- `transition`: the candidate transition, as `action`, `from` (the current value) and `to`.

The keys are shared with `condition` and their meaning differs in one place an author must know: in an
`autoWhen`, `object` holds the state being LEFT, whereas in a `condition` it holds the state being
ENTERED. An `autoWhen` is decided once per candidate per step of the pass, after the triggering write
is complete, so it reads computed fields and defaults as stored.

A reference to a key the document does not carry SHALL resolve to null. An expression that cannot be
evaluated SHALL count as not holding, so an unevaluable rule never moves an object.

#### Scenario: The rule reads the object as stored
- **GIVEN** an `autoWhen` referencing `object.motivering`
- **WHEN** it is evaluated after a save that stored a `motivering`
- **THEN** the reference MUST resolve to the stored value

#### Scenario: The previous state is empty after a create
- **GIVEN** an `autoWhen` referencing `previous.status`
- **WHEN** it is evaluated after the object was created
- **THEN** the reference MUST resolve to null

#### Scenario: The transition key names the candidate
- **GIVEN** a candidate transition `beslissen` from `in-behandeling` to `besloten`
- **WHEN** its `autoWhen` is evaluated
- **THEN** `transition.action`, `transition.from` and `transition.to` MUST resolve to `beslissen`, `in-behandeling` and `besloten`

#### Scenario: An unevaluable rule does not move the object
- **GIVEN** a stored `autoWhen` that cannot be evaluated for the object at hand
- **WHEN** the object is saved
- **THEN** the transition MUST NOT fire

### Requirement: An ambiguous or shadowed automatic transition MUST NOT fire

@e2e exclude candidate selection is internal, covered by PHPUnit

When more than one transition whose `from` contains the current value has an `autoWhen` that holds,
OpenRegister SHALL fire none of them and SHALL log a warning naming every candidate. Declaration order
SHALL NOT be used to choose, because it is not preserved by every database Nextcloud supports.

A transition SHALL NOT fire automatically when an earlier-declared transition has the same `to` and a
`from` that also contains the current value. The save path identifies a transition by its from and to
values and takes the first match, so it would enforce the earlier transition's gates and run its
actions, not the automatic transition's. OpenRegister SHALL log a warning naming both transitions.

#### Scenario: Two rules that hold at once fire nothing
- **GIVEN** two transitions from `ontvangen`, each with an `autoWhen` that holds
- **WHEN** an object in `ontvangen` is saved
- **THEN** neither transition MUST be applied
- **AND** one warning MUST name both transitions

#### Scenario: One rule that holds beside one that does not fires
- **GIVEN** two transitions from `ontvangen`, of which only one has an `autoWhen` that holds
- **WHEN** an object in `ontvangen` is saved
- **THEN** exactly that transition MUST be applied

#### Scenario: A shadowed automatic transition does not fire
- **GIVEN** a transition `toewijzen` from `ontvangen` to `in-behandeling` with an `autoWhen` that holds
- **AND** an earlier-declared transition `starten` from `ontvangen` to `in-behandeling`
- **WHEN** an object in `ontvangen` is saved
- **THEN** `toewijzen` MUST NOT fire
- **AND** a warning MUST name both transitions

### Requirement: An automatic transition acts as the caller whose write triggered it

@e2e exclude identity plumbing, covered by PHPUnit

A `sync` automatic transition SHALL act as the identity that made the triggering write. Its
`authorization` list, its object `update` permission, the `user` key of its `condition` and `autoWhen`,
its guard's user id and its audit row SHALL all see that identity.

An `async` automatic transition SHALL carry that identity into the queued move and act as it when
applied. When the identity no longer resolves to an account, or resolves to a disabled one, the queued
move SHALL NOT be applied and a warning SHALL be logged.

An automatic transition SHALL NEVER act as a system principal or run with elevated rights. When the
triggering write had no identity, such as a CLI command without a session, the automatic transition
SHALL act as no one, and any gate that requires an identity SHALL refuse it.

#### Scenario: A sync move is authorized as the saving user
- **GIVEN** a transition with an `autoWhen` that holds and an `authorization` list naming group `behandelaars`
- **WHEN** a member of `behandelaars` saves the object
- **THEN** the transition MUST be applied and its audit row MUST name that user

#### Scenario: A queued move acts as the captured user
- **GIVEN** an `async` automatic transition decided on a save by user `behandelaar-1`
- **WHEN** the queued move is applied
- **THEN** it MUST be authorized and audited as `behandelaar-1`

#### Scenario: A queued move for a disabled account is not applied
- **GIVEN** an `async` automatic transition decided on a save by a user who is disabled before the move is processed
- **WHEN** the queued move is processed
- **THEN** it MUST NOT be applied and a warning MUST be logged

#### Scenario: A session-less write gets no elevated automatic move
- **GIVEN** a transition with an `autoWhen` that holds and an `authorization` list
- **WHEN** the object is saved by a caller with no session
- **THEN** the automatic transition MUST be refused and MUST NOT be applied

### Requirement: An automatic transition MUST be recorded as automatic

@e2e exclude event and audit fields are backend contracts, covered by PHPUnit

The `ObjectTransitionedEvent` of an automatic transition SHALL say it was automatic; the event for a
manual transition SHALL say it was not. The flag SHALL be additive and default to not automatic, so
every existing listener and dispatcher is unaffected. A flow triggered by the transition SHALL see the
flag on its run context beside `action`, `from` and `to`.

The audit row of an automatic transition SHALL identify the move as automatic and name the transition,
while attributing it to the identity it acted as. An auditor SHALL be able to tell a move a user asked
for from a move a rule made on that user's write.

#### Scenario: The transition event carries the automatic flag
- **GIVEN** an automatic transition that is applied
- **WHEN** its `ObjectTransitionedEvent` is dispatched
- **THEN** the event MUST report that the transition was automatic
- **AND** the event for the same transition fired by name MUST report that it was not

#### Scenario: A flow sees the automatic flag
- **GIVEN** a flow wired to the object state-change trigger
- **WHEN** an automatic transition fires it
- **THEN** the run context MUST carry the automatic flag

#### Scenario: The audit row marks the automatic move
- **GIVEN** an automatic transition that is applied on a save by user `behandelaar-1`
- **WHEN** its audit row is read
- **THEN** the row MUST identify the move as automatic, name the transition, and name `behandelaar-1`

### Requirement: A malformed or unsupported automatic transition MUST be refused at schema-save time

@e2e exclude schema-save validation, covered by PHPUnit

At schema-save time OpenRegister SHALL validate every transition that declares `autoWhen` or
`executionMode`, and SHALL refuse the schema save, not merely warn, when any of these holds:

- `autoWhen` is not a non-empty JSONLogic rule object, or is a rule object the expression engine cannot
  validate: code `lifecycle-autowhen-malformed`. A scalar, including `true` and a string in the
  `@self.<field> == '<value>'` form an `actions[]` entry uses, SHALL be refused with this code, because
  a scalar evaluates as a truthy literal and would fire on every write from that state.
- `executionMode` is present and is not exactly `sync` or `async`: code
  `lifecycle-execution-mode-malformed`. Case variants SHALL be refused, not normalised.
- A transition declaring `autoWhen` also declares an `inputs` entry with `required: true`: code
  `lifecycle-autowhen-requires-input`, because an automatic move carries no payload and would be refused
  on every attempt.
- A graph-mode `graph` block carries `autoWhen`: code `lifecycle-autowhen-graph-unsupported`, stating
  that graph-mode automatic transitions are not supported while graph-mode moves are unenforced on the
  ordinary save path.

Each error SHALL name the schema and the offending transition. These errors SHALL refuse the save
exactly as `lifecycle-condition-malformed` does. Every other lifecycle error SHALL keep its existing
advisory treatment.

At runtime the evaluation SHALL NOT trust save-time validation: a stored `autoWhen` that is present but
not a non-empty rule object SHALL count as not holding and SHALL be logged at warning level.

Every test covering this requirement and the loop cap SHALL be proven to fail against a deliberately
broken guard before it is accepted.

#### Scenario: A scalar autoWhen is refused
- **GIVEN** a transition declaring `autoWhen: true`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-malformed` naming the transition

#### Scenario: An action-dialect string is refused as autoWhen
- **GIVEN** a transition declaring `autoWhen: "@self.motivering != ''"`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-malformed`
- **AND** the message MUST point the author at the JSONLogic rule-object form

#### Scenario: An unknown operator is refused
- **GIVEN** a transition declaring an `autoWhen` rule object with an operator the expression engine does not know
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-malformed`

#### Scenario: An unknown executionMode is refused
- **GIVEN** a transition declaring `executionMode: "background"`, and another declaring `executionMode: "SYNC"`
- **WHEN** the schema is saved
- **THEN** each MUST be refused with code `lifecycle-execution-mode-malformed`

#### Scenario: An automatic transition with a required input is refused
- **GIVEN** a transition declaring `autoWhen` and `inputs: [{ "field": "motivering", "required": true }]`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-requires-input`

#### Scenario: autoWhen on a graph block is refused
- **GIVEN** an annotation declaring a `graph` block that carries `autoWhen`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-graph-unsupported`

#### Scenario: A well-formed declaration passes
- **GIVEN** a transition declaring `autoWhen: { "!!": { "var": "object.motivering" } }` and `executionMode: "async"`
- **WHEN** the schema is saved
- **THEN** no automatic-transition error MUST be returned and the annotation's other rules MUST be unaffected

#### Scenario: A malformed message stays advisory
- **GIVEN** a transition declaring a valid `autoWhen` and a malformed `message`
- **WHEN** the schema is saved
- **THEN** the save MUST succeed with the existing `lifecycle-message-malformed` warning

#### Scenario: A stored malformed autoWhen does not fire
- **GIVEN** a stored transition whose `autoWhen` is a scalar, written by a path that skipped validation
- **WHEN** an object in its `from` state is saved
- **THEN** the transition MUST NOT fire and a warning MUST be logged

#### Scenario: The validation and cap tests are proven to fail first
- **GIVEN** the PHPUnit tests for this requirement and for the loop cap
- **WHEN** the scalar refusal, the revisit rule and the ceiling are each deliberately removed
- **THEN** the test guarding each MUST fail for that reason before it is restored
