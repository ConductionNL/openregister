## Purpose

Lets the independent branches of one flow run advance at the same time, so a
branch waiting on a person, a timer or a remote call never holds up its
siblings — while keeping the run's marking consistent under concurrent writers
and its log ordered by branch rather than by which branch finished first.

## ADDED Requirements

### Requirement: Independent branches of one run MUST advance independently

A marking holding several tokens describes several things happening at once.
The engine MUST treat each such token as its own STREAM and advance streams
independently, so that a stream blocked on input/output, on a timer, or on a
human answer does not stop a sibling that has work it can do.

A run MUST park only when EVERY one of its streams is parked, and MUST reach a
terminal state only when every stream has reached one. A single-stream run
behaves exactly as it does today: the stream IS the run.

Parking one stream MUST NOT discard what a sibling stream is carrying. Each
stream's items MUST survive suspension separately, so a stream resumed hours
later resumes with the items ITS branch produced and no others.

#### Scenario: A human task on one branch does not stop its siblings
- **GIVEN** a run whose marking holds three tokens — an advice request, a
  hearing and a document check
- **WHEN** the advice branch suspends waiting for a person to answer
- **THEN** the hearing and document-check branches MUST continue to advance
- **AND** the run MUST NOT be reported as parked while either of them can still
  fire
- @e2e exclude engine-internal walk semantics — covered by the parallel-stream
  engine tests

#### Scenario: A run parks only when every stream has parked
- **GIVEN** a run with two streams, one suspended on a signal and one suspended
  on a timer
- **WHEN** the second one parks
- **THEN** the run MUST be parked
- **AND** its wake time MUST be the EARLIEST wake time among its streams, so a
  stream waiting on a signal that never arrives cannot delay a stream whose
  timer is due
- @e2e exclude engine-internal walk semantics — covered by the parallel-stream
  engine tests

#### Scenario: Each stream resumes with its own items
- **GIVEN** a run that suspends holding two tokens, whose branches produced
  different item lists
- **WHEN** the run resumes
- **THEN** each branch MUST resume with the items its own branch produced
- **AND** neither branch's items MUST be replaced by the other's
- @e2e exclude engine-internal item placement — covered by the placement tests

### Requirement: A firing MUST exclusively claim every place it touches

Concurrent writers to one run's marking are where token loss would live, so the
engine MUST make the loss unrepresentable rather than unlikely.

Before dispatching a transition, a worker MUST acquire an exclusive claim on
EVERY place that firing touches — the places it consumes from and the places it
produces onto. Two firings whose place sets intersect MUST NOT both hold claims
at the same time. Two firings whose place sets are disjoint MUST be able to
proceed simultaneously.

Claim acquisition MUST NOT block. A worker that cannot take every place it
needs MUST abandon that firing, leave the marking untouched, and move on; the
firing stays enabled and a later attempt takes it. Waiting for a sibling's
claim would reintroduce exactly the head-of-line blocking this capability
removes.

Claims MUST be acquired in a fixed, total order that does not depend on which
worker is asking, so two workers reaching for overlapping place sets can never
deadlock against each other.

#### Scenario: Two workers reaching for the same place — exactly one fires
- **GIVEN** two workers that both find the same transition enabled on one run
- **WHEN** both attempt to claim it
- **THEN** exactly one MUST acquire the claim and dispatch the step
- **AND** the other MUST NOT dispatch it, MUST NOT write the marking, and MUST
  NOT record a step
- @e2e exclude a concurrency property — covered by the claim tests driving two
  connections

#### Scenario: Two disjoint branches fire at the same time
- **GIVEN** a run whose marking holds two tokens on branches sharing no place
- **WHEN** two workers each claim one branch's firing
- **THEN** both MUST proceed
- @e2e exclude a concurrency property — covered by the claim tests

#### Scenario: A contended claim is skipped, never waited on
- **GIVEN** a worker whose next candidate firing needs a place another worker
  holds
- **WHEN** the claim attempt fails
- **THEN** the worker MUST move to its next candidate without waiting
- **AND** the skipped firing MUST still be enabled afterwards
- @e2e exclude a concurrency property — covered by the claim tests

### Requirement: A marking MUST be written as a delta, never as a whole overwrite

A whole-marking write computed from a marking read at the start of a pass is a
read-modify-write, and two of them lose a token with no error raised anywhere.

The engine MUST persist a firing's effect on the marking as a DELTA — the
tokens it consumed and the tokens it produced — applied to the marking as it is
at the moment of the write, inside a critical section that serialises writers of
that run. The delta and the items moved by the same firing MUST be committed
together, so a marking can never name a place whose items were not written.

After a lost-update-shaped interleaving — two firings on disjoint branches,
each reading the marking before either writes — the committed marking MUST
contain the effects of BOTH.

#### Scenario: Two interleaved commits keep both effects
- **GIVEN** two workers that both read the marking `{a: 1, b: 1}` before either
  writes
- **WHEN** one fires the transition consuming `a` and the other the transition
  consuming `b`
- **THEN** the committed marking MUST show both consumed and both successors
  marked
- **AND** no token MUST be present that neither firing produced
- @e2e exclude a concurrency property — covered by the marking-store tests

#### Scenario: Marking and items commit together
- **GIVEN** a firing that produces items onto its output place
- **WHEN** its marking delta is committed
- **THEN** that place's items MUST be readable in the same committed state
- @e2e exclude persistence contract — covered by the marking-store tests

### Requirement: A synchronising join MUST fire exactly once, however its branches arrive

A join declared with `join: true` has one input place per incoming edge, and
fires only when every one of them is marked. Concurrency MUST NOT be able to
make it fire twice, and MUST NOT be able to make it never fire.

Two branches arriving at genuinely the same moment deposit on DIFFERENT input
places of the join, so their firings are disjoint and both MUST be allowed to
proceed. The join's own firing consumes all of its input places at once, so
claiming it requires claiming all of them — which is what makes double-firing
impossible.

Whether the join is noticed as enabled MUST NOT depend on the arrival order. A
worker that commits an arrival MUST re-evaluate what is enabled after that
commit. If no worker takes the join in that pass, the run MUST NOT be treated
as finished or parked while the join is enabled: the next pass MUST fire it. A
missed pickup is bounded latency; it MUST NEVER be a lost wake-up.

#### Scenario: Simultaneous arrivals fire the join once
- **GIVEN** a join with two incoming edges, and two workers each committing one
  branch's arrival at the same time
- **WHEN** both re-evaluate the marking
- **THEN** the join MUST be fired exactly once
- **AND** its step MUST read the items of BOTH branches
- @e2e exclude a concurrency property — covered by the join tests

#### Scenario: A join nobody picked up in one pass is fired by the next
- **GIVEN** a join that becomes enabled by the last committed arrival of a pass
  whose workers have all finished
- **WHEN** the next worker pass runs
- **THEN** the join MUST be fired
- **AND** the run MUST NOT have been reported as completed or parked in the
  meantime
- @e2e exclude engine-internal walk semantics — covered by the join tests

### Requirement: The run log MUST be ordered by branch, never by completion

A log whose order depends on which branch returned first is not comparable
between two runs of the same flow — the property already asserted for per-item
concurrency, applied to branches.

Every step record MUST name the stream that produced it and that stream's
ordinal, taken from the AUTHOR's declaration order at the split that created
it. A run's canonical ordering MUST be by stream ordinal, then by position
within the stream. Two runs of the same flow that take the same path MUST
produce the same canonical ordering, whatever the branches' relative timing.

Positions MUST be assigned per stream, not per run: a single run-wide counter
handed out as rows are written IS completion order wearing a sequence number.

Wall-clock timestamps MUST remain on each record, so an operator who wants to
see the real interleaving can ask for it. That MUST NOT be the default order,
and MUST NOT be what any comparison between runs is built on.

#### Scenario: Two runs, different timing, identical ordering
- **GIVEN** two runs of one flow whose branches finish in opposite orders
- **WHEN** each run's log is read in canonical order
- **THEN** the two sequences of records MUST be identical in branch, node and
  position
- @e2e exclude a determinism property — covered by the run-log ordering tests

#### Scenario: The real interleaving is still recoverable
- **GIVEN** a run whose branches ran concurrently
- **WHEN** its records are read by timestamp instead
- **THEN** the actual interleaving MUST be visible
- **AND** that MUST NOT be the order used by default
- @e2e exclude covered by the run-log ordering tests

### Requirement: Intra-run fan-out MUST be bounded

An unbounded intra-run fan-out is the same hazard as an unbounded per-item
fan-out: one run over a wide split becomes a burst against machines and
upstreams that did nothing to deserve it. The bound MUST NOT be optional.

The number of streams of ONE run advanced simultaneously MUST be capped. The
default and the hard ceiling MUST be the SAME numbers per-item concurrency
already uses — a second and different pair would mean the load an upstream sees
depends on which layer happened to fan out. A configured value above the
ceiling MUST be clamped, not honoured; a value below one MUST become one.

A worker pass MUST also bound its total work across runs, so raising the
per-run cap cannot turn one pass into an unbounded burst.

#### Scenario: A wide split runs within the cap
- **GIVEN** a run whose marking holds twelve independent tokens and a cap of
  five
- **WHEN** a worker pass advances it
- **THEN** at most five of its streams MUST be in flight at once
- @e2e exclude a concurrency property — covered by the stream-scheduler tests

#### Scenario: A misconfigured cap is clamped
- **GIVEN** a flow configured with a stream cap far above the ceiling
- **WHEN** the run is advanced
- **THEN** the effective cap MUST be the ceiling
- @e2e exclude covered by the stream-scheduler tests

### Requirement: A branch abandoned by a crashed worker MUST be recovered, and MUST NOT be silently re-run

A worker killed inside a firing never releases its claim. Left alone, that
claim blocks its branch forever while every sibling keeps running, so the run
looks alive and is permanently incomplete — the worst failure shape, because
nothing reports it.

A claim held longer than any real firing can take MUST be released by the same
recovery pass that already recovers abandoned runs, and MUST use that pass's
existing cutoff so the two can never contradict each other.

A recovered branch MUST be FAILED, not silently retried. It may already have
written an object, sent a message or called a remote system, and re-running it
would repeat those without saying so. The failure MUST name the branch, and
MUST apply the run's error policy — so a run whose policy is to continue keeps
its siblings, and one whose policy is to stop stops.

Recovery MUST be visible: an abandoned claim MUST be reported, because "a
worker took a branch and died" is worth seeing even when it is recovered from.

#### Scenario: A stale claim is released and its branch failed
- **GIVEN** a claim whose holder died mid-firing
- **WHEN** the recovery pass runs after the cutoff
- **THEN** the claim MUST be released
- **AND** the branch MUST be recorded as failed, naming the branch
- **AND** the branch MUST NOT be re-dispatched automatically
- @e2e exclude a recovery property — covered by the claim-reaper tests

#### Scenario: A live long-running firing is not reaped
- **GIVEN** a branch inside one long step that is still within the runtime it
  was granted
- **WHEN** the recovery pass runs
- **THEN** the claim MUST NOT be released
- @e2e exclude covered by the claim-reaper tests

### Requirement: The transition ceiling MUST count a run, not a pass

The ceiling exists because a Petri net can express a loop that never settles.
Counting only the firings of the current pass makes it defeatable by any cycle
that parks once per lap, and branch concurrency multiplies both the count and
the ways to park.

A run's committed firings MUST be counted across ALL of its streams and across
every pass, and that count MUST be what the ceiling is checked against.
Reaching it MUST fail the run and say so, and MUST NOT silently truncate.

#### Scenario: A cycle that parks each lap still hits the ceiling
- **GIVEN** a flow with a cycle that suspends once per lap
- **WHEN** its committed firings across passes reach the ceiling
- **THEN** the run MUST fail with a message naming the ceiling
- @e2e exclude engine-internal backstop — covered by the ceiling tests

#### Scenario: Concurrent branches share one ceiling
- **GIVEN** a run with three streams
- **WHEN** their firings are counted
- **THEN** the ceiling MUST apply to the run's total, not to each stream
- @e2e exclude covered by the ceiling tests

### Requirement: Oversight MUST be consulted before every firing, and a refusal MUST stop the RUN

Oversight is consulted before every hop precisely so a long or repeatedly
resumed run cannot sail past a switch thrown mid-run. Concurrency MUST NOT
weaken that: the check MUST be made per FIRING, inside the claim and before the
step is dispatched. It MUST NOT be hoisted to once per worker pass, and MUST
NOT be cached across firings.

A refusal MUST end the RUN, not the branch that happened to ask. A kill switch
that stops one branch while a sibling keeps writing objects is not a kill
switch. Streams that have not started MUST NOT start; a stream already inside a
firing MUST finish that firing — a side effect in progress cannot be unmade —
and MUST then stop without beginning another. The refusing check's identity
MUST be recorded.

A check that cannot form an opinion MUST still be a refusal, exactly as it is
for a single-stream run.

#### Scenario: A refusal reaches every branch
- **GIVEN** a run with three streams and a check that refuses
- **WHEN** the refusal is raised by one stream's next firing
- **THEN** no stream MUST begin a further firing
- **AND** the run MUST end as stopped, recording which check refused
- @e2e exclude covered by the oversight tests

#### Scenario: A firing in flight is bounded, not abandoned
- **GIVEN** a stream already dispatching a step when another stream is refused
- **WHEN** that step returns
- **THEN** its result MUST be committed and recorded
- **AND** that stream MUST NOT begin another firing
- @e2e exclude covered by the oversight tests

### Requirement: A run's status MUST stay derivable from its streams, with no new value

Every surface that asks about runs filters on the existing status values, and a
run that is neither running nor suspended has no place to be. Adding a value
would remove such runs from every existing filter without any error — the
queue's own pass reads only queued and due runs, so a run outside both is
unreachable.

The status set MUST NOT grow. A run's status MUST be derived from its streams:
it is running while any stream holds a live claim, parked when every stream is
parked, and terminal when every stream has reached a terminal state. "Running"
therefore MUST mean "a branch is actually being worked on" — which is exactly
what the abandonment recovery reads it as.

Per-branch detail MUST be answerable — which branches are waiting, on what, and
since when — from the streams themselves, not by overloading the run's status.

#### Scenario: One branch waiting and one working reads as running
- **GIVEN** a run with one stream suspended on a human task and one advancing
- **WHEN** the run's status is read
- **THEN** it MUST be running
- **AND** the suspended branch MUST be visible as suspended in the run's own
  per-branch detail
- @e2e exclude status derivation — covered by the run-status tests

#### Scenario: All branches parked reads as parked, and is woken normally
- **GIVEN** the same run once its advancing stream also parks
- **WHEN** the queue's due-run pass runs after the earliest wake time
- **THEN** the run MUST be picked up exactly as a single-stream parked run is
- @e2e exclude covered by the run-status tests

#### Scenario: A long-waiting branch does not make the run look abandoned
- **GIVEN** a run with a branch that has been waiting for a signal for days
- **WHEN** the abandonment recovery pass runs
- **THEN** the run MUST NOT be failed as abandoned
- @e2e exclude covered by the run-status tests

### Requirement: A completion's advance budget MUST apply to the completing branch

Completing a task may advance the run in the same request by a configured
budget. With concurrent branches that budget MUST be scoped to the branch the
completion belongs to, so a person pressing Approve never pays the wall-clock
of an unrelated branch's remote calls.

The budget MUST follow the TOKEN, not the label: if advancing the completing
branch reaches a join and enables it, firing that join is part of the budget,
because the join consumes the completing branch's place.

An in-request advance MUST take claims exactly as a worker does and MUST NOT
wait on one. Reaching a place a sibling holds MUST end the in-request advance
and leave the rest to the queue; the request MUST return the run's state as it
stands rather than blocking.

The run-wide ceiling and oversight MUST bound an in-request advance exactly as
they bound a worker's.

#### Scenario: Approving one branch does not run a sibling's work
- **GIVEN** a run with a completing branch and a sibling mid remote call
- **WHEN** the task is completed with a budget that continues the run
- **THEN** only the completing branch MUST advance in the request
- @e2e exclude covered by the advance-budget tests

#### Scenario: A budget that reaches a join fires it
- **GIVEN** a completing branch whose next place is the last unmarked input of
  a join, within the budget
- **WHEN** the completion advances
- **THEN** the join MUST fire in the same request
- @e2e exclude covered by the advance-budget tests

#### Scenario: A contended place ends the advance instead of blocking
- **GIVEN** a completing branch whose next firing needs a place a sibling holds
- **WHEN** the completion advances
- **THEN** the request MUST return without waiting
- **AND** the remaining work MUST be left to the queue
- @e2e exclude covered by the advance-budget tests
