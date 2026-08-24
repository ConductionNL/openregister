# Design: flow-parallel-streams

## Context

See proposal.md — Why. The design-relevant state of the code today:

- **The walk is one loop.** `FlowEngine::run()` is a single `while (true)`
  (`lib/Service/Flow/FlowEngine.php:310-546`). It asks
  `getEnabledTransitions()` for the whole set (`:311`), narrows it to ONE with
  `selectTransition()` (`:344`), dispatches (`:427`), then
  `$workflow->apply()` (`:535`). Several enabled transitions are fired in
  succession within one pass; none of them is ever in flight at the same time
  as another.
- **Ending is decided by whoever notices first.** An empty enabled set returns
  `STATUS_COMPLETED` (`FlowEngine.php:312-322`); a `FlowSuspension` returns
  `STATUS_SUSPENDED` for the whole run (`:474-493`).
- **The marking is a whole-value write.** `FlowRunMarkingStore::setMarking()`
  does `$this->run->setMarking($marking->getPlaces())`
  (`lib/Service/Flow/FlowRunMarkingStore.php:102-105`) from a `Marking` object
  built by `getMarking()` (`:67-86`) out of the run row read at the top of the
  pass. Read-modify-write on one JSON column, with no version and no lock.
- **Places already encode the join.** `FlowGraph::inPlace()` returns the bare
  node id (`lib/Service/Flow/FlowGraph.php:67-69`), and a declared join gets
  one input place per incoming edge, `"{nodeId}#{edgeId}"`
  (`FlowGraph.php:103-105`, reached from `targetPlace()` at `:85-91`). The
  conflict structure this design needs is therefore already in the graph — no
  new naming is required.
- **Items are per-place in memory and flat on disk.** `advanceItems()` moves
  items onto `$transition->getTos()` and clears `getFroms()`
  (`lib/Service/Flow/FlowItemPlacement.php:133-187`); `seedPlaceItems()`
  rebuilds those buffers on resume by assigning the SAME list to every marked
  place (`:90-103`), because the run persists one flat `items` array
  (`lib/Service/Flow/FlowRunService.php:810`). The comment at
  `FlowItemPlacement.php:134-161` records openregister#2488 — a shared output
  place losing items with no trace in any log.
- **The step sequence is a read-then-insert.** `recordSteps()` takes
  `highestSequence() + 1` (`FlowRunService.php:677`, mapper at
  `lib/Db/FlowRunStepMapper.php:81-97`) and increments a local
  (`FlowRunService.php:729`); `findByRun()` sorts `ORDER BY sequence ASC`
  (`FlowRunStepMapper.php:62`). Table `openregister_flow_steps`
  (`FlowRunStepMapper.php:44`).
- **Status is what every queue query filters on.** `findQueued()`
  (`lib/Db/FlowRunMapper.php:643`), `findDue()` (`:503-507`, `suspended`),
  `findStale()` (`:543-547`, `running`), `flowsWithQueuedRuns()` (`:689-694`)
  and `touch()` (`:240-255`, whose `UPDATE ... WHERE status = running` guard is
  the existing precedent for "only write this row in the state that makes the
  write mean something").
- **Recovery already has a cutoff and a posture.** `FlowRunWorker::reapStale()`
  (`lib/Cron/FlowRunWorker.php:226-275`) computes
  `max(flow_run_stale_minutes, flow_max_runtime_minutes + REAP_GRACE_MINUTES)`
  (`:251-261`, constants at `:81`, `:105`, `:117`) and FAILS what it reaps
  rather than requeueing it (`:263-272`, reasoning at `:207-218`).
- **Bounding already has numbers and an argument.** `FlowConcurrency`'s header
  docblock (`lib/Service/Flow/FlowConcurrency.php:20-31`) names the three
  properties a naive fan-out loses — BOUND, ORDER, ISOLATION — and its
  constants are `DEFAULT_LIMIT = 5` (`:72`) and `MAX_LIMIT = 20` (`:83`),
  clamped by `boundedLimit()` (`:188-194`).
- **Oversight is per-hop and fail-closed.** `assertOversightAllows()` runs
  immediately before dispatch (`FlowEngine.php:425`) and
  `FlowOversightRegistry::firstRefusal()` treats a check that throws as a
  refusal (`lib/Service/Flow/FlowOversightRegistry.php:102-128`, the fail-closed
  branch at `:106-120`).
- **An atomic-reservation pattern already exists in this app.**
  `SequenceService::reserveNext()` (`lib/Service/SequenceService.php:76-115`)
  wraps a conditional `UPDATE` (`lib/Db/SequenceMapper.php:84-93`) and a
  unique-index-guarded seed in one `IDBConnection` transaction. That is the
  shape both the claim and the marking delta reuse, so this change introduces
  no concurrency primitive the codebase has not already shipped.

## Goals / Non-Goals

**Goals:**

- A branch that waits never holds a sibling that can work. Head-of-line
  blocking is removed inside one run, as it already is between runs.
- Token loss under concurrent writers is IMPOSSIBLE by construction, not
  improbable. Every claim about safety below is a structural argument, not a
  probability.
- A run's log is a function of the path taken, not of the timing. Two runs of
  one flow that take the same path read identically.
- The blast radius of intra-run fan-out is the same as per-item fan-out's, with
  the same two numbers.
- Nothing that queries runs today changes meaning: no new status value, no new
  index on a hot path, no rename of a place.

**Non-Goals:**

- **Threads.** PHP-FPM and cron are processes; this design does not pretend
  otherwise. "Simultaneously" has two precise meanings here and neither is
  intra-process branch threading — see Decision 8.
- **Reordering work for throughput.** The scheduler is round-robin over
  streams. Priority branches, weights and deadline scheduling are not modelled;
  a stream's ordinal is for READING the log, never for deciding who runs next.
- **Distributed coordination beyond the database.** No Redis, no advisory lock
  server, no `ILockingProvider`. The claim lives in a table so it is visible,
  reapable and survives a process that vanishes.
- **Cross-run parallelism changes.** Runs already advance independently; the
  worker's `BATCH = 25` (`FlowRunWorker.php:62`) is untouched.
- **Retry of a branch.** A recovered branch fails; retry still queues a NEW run.
- **Author-visible parallelism syntax.** A split is already an edge shape and
  `join: true` already a node flag — see proposal.md, What does NOT change.

## Decisions

### Decision 1: a firing claims the PLACES it touches, not the run and not the stream

Three granularities were considered.

**A — lock the run row for the whole pass.** Correct and trivial, and it is
today's behaviour with extra machinery: two branches sharing nothing would
still be mutually exclusive, which is the thing this change exists to remove.
Rejected.

**B — claim the STREAM.** Rejected for two structural reasons, not for
performance. A join consumes one token from EACH of its input places, so its
firing spans several streams at once and a stream-granular claim cannot express
"I need all of these". And a split CREATES streams: the claimer would have to
mint stream identities before it knows which transition it is going to fire.

**C — claim the place set of a candidate firing (chosen).** In a Petri net two
transitions conflict exactly when their place sets intersect, and Symfony hands
that set over already: `$transition->getFroms()` and `$transition->getTos()`,
the same two calls `advanceItems()` makes at `FlowItemPlacement.php:163`,
`:170` and `:182`. Claiming `froms ∪ tos` therefore claims precisely the
conflict relation the graph already has — no approximation, nothing invented.

Outputs are claimed as well as inputs, not only inputs. Two firings that
consume from different places but PRODUCE onto the same one are in conflict:
openregister#2488 is exactly that shape, and it is recorded at
`FlowItemPlacement.php:143-155` as having been invisible in the run log.
Claiming inputs alone would leave that case unguarded.

Storage — `openregister_flow_claims`:

| column | why |
|---|---|
| `run_uuid`, `place` | the claim, UNIQUE together — the mutual-exclusion primitive |
| `owner` | the holder's pass token, so a reaped claim names who abandoned it |
| `stream_id`, `transition` | what the claim was taken FOR, so recovery can fail the right branch with a message a person can act on |
| `claimed_at` | the reaper's input, compared against the existing cutoff |

The unique index IS the lock. Acquisition is an `INSERT`; a unique-violation is
a refusal, returned immediately, and the caller abandons that candidate and
tries the next one (`flow-parallel-streams` — "A contended claim is skipped,
never waited on"). No `SELECT ... FOR UPDATE` is taken on the claim table, and
nothing waits on a claim, ever.

**Each claim insert commits on its own, before dispatch.** This is not an
implementation detail, it is the property that makes acquisition non-blocking:
an `INSERT` that collides with another transaction's UNCOMMITTED insert BLOCKS
on the row lock until that transaction ends. If the claim were taken inside the
firing's transaction, a second worker's attempt would wait for the first
worker's whole step — head-of-line blocking re-entering through the back door,
and worse than today because it would hold a database connection while it
waited.

### Decision 2: claims are taken in one fixed total order, which is what makes deadlock and livelock impossible

The place set is sorted with a plain byte comparison and claimed low-to-high.
On any refusal the worker DELETEs the claims it already holds for that attempt
and moves on.

Deadlock is impossible because nothing waits: a refusal is immediate and
releasing is unconditional. The ordering buys the second property, which
non-blocking alone does not give — **freedom from livelock**. Let P be the
lowest place any two contending workers both want. Whichever wins P has, by the
ordering, already taken every place it needs BELOW P; and the loser cannot hold
any of those, because it too would have had to take them below P and would then
have won P first. So the winner of the lowest contended place always completes
its acquisition. Progress is guaranteed at every contention point, not merely
likely.

Without the ordering, A wanting `{a,c}` and B wanting `{c,a}` can take `a` and
`c` respectively, both refuse, both release, both retry — forever, at full
speed. That is not a rare interleaving; it is the stable state under load.

### Decision 3: the marking is a delta committed under a run-row lock, and the claim is what makes the delta CORRECT

Two mechanisms, doing two different jobs. Conflating them is the trap.

**The run-row lock gives ATOMICITY.** The commit path is:

```
BEGIN
  SELECT ... FROM openregister_flow_runs WHERE uuid = ? FOR UPDATE
  marking := the value just read, inside the lock
  marking := marking minus one token per `from`, plus one per taken `to`
  place_items := drop the froms, set the taken tos
  INSERT the step row (stream_id, ordinal_path, sequence from the stream row)
  UPDATE the stream row (status, resume_at, next_sequence + 1)
  UPDATE the run (marking, place_items, firings + 1, derived status, updated)
COMMIT
```

Every value written is computed from the row read INSIDE the lock. The delta
itself — "remove `a`, add `c`" — does not mention any other place, so
committing it cannot disturb one. This is the whole difference from
`setMarking()` at `FlowRunMarkingStore.php:102-105`, which writes an entire
places map computed from a read taken before the step ran.

**The claim gives EXCLUSION for the side effect.** The lock cannot do this job:
`$dispatcher->dispatch()` (`FlowEngine.php:427`) writes objects, sends
messages and calls remote systems, and a transaction cannot roll those back.
Two workers that both selected the same transition would both dispatch and only
then discover the conflict. So the claim is taken and committed BEFORE
dispatch, and the expensive work happens entirely outside the lock. The lock's
critical section contains no I/O and no user code.

**Worked interleaving.** Marking `{a: 1, b: 1}`. Transition T1 consumes `a`
produces `c`; T2 consumes `b` produces `d`. Place sets `{a,c}` and `{b,d}` —
disjoint, so both must be allowed (`flow-parallel-streams` — "Two disjoint
branches fire at the same time"). Workers A and B are separate OS processes.

```
t    Worker A (T1)                          Worker B (T2)
--   ------------------------------------   ------------------------------------
 1   read run row: marking {a:1, b:1}
 2                                          read run row: marking {a:1, b:1}
 3   candidate T1; places sorted [a, c]
 4                                          candidate T2; places sorted [b, d]
 5   INSERT claim(a) — committed
 6                                          INSERT claim(b) — committed
 7   INSERT claim(c) — committed
 8                                          INSERT claim(d) — committed
 9   firstRefusal() -> null (consents)
10                                          firstRefusal() -> null (consents)
11   dispatch T1  ... 4.0 s of remote I/O
12                                          dispatch T2  ... 0.2 s
13                                          BEGIN; SELECT run FOR UPDATE  [holds]
14                                          marking read IN LOCK: {a:1, b:1}
15                                          delta -b +d  ->  {a:1, d:1}
16                                          write marking, place_items[d], step
17                                          firings := firings + 1; COMMIT
18                                          DELETE claim(b), claim(d)
19   BEGIN; SELECT run FOR UPDATE  [holds]
20   marking read IN LOCK: {a:1, d:1}   <-- NOT the value read at t=1
21   delta -a +c  ->  {d:1, c:1}
22   write marking, place_items[c], step
23   firings := firings + 1; COMMIT
24   DELETE claim(a), claim(c)
```

The stale read at t=1 is used for ONE purpose: choosing a candidate. It never
reaches a write. A re-reads at t=20 and sees B's `d`, so `d` survives; B never
saw `c` because `c` did not exist when B held the lock, and B's delta never
mentions `c`. Both effects are present. Order of the two commits is irrelevant:
swap t=13 and t=19 and the same argument runs with the letters exchanged.

Today, the same interleaving corrupts twice over. B would write
`$marking->getPlaces()` for the `Marking` it built from the t=2 read, i.e.
`{a: 1, d: 1}` — and if A commits first, that write both DROPS `c` (a token
lost) and RESURRECTS `a` (a token A already consumed, so T1 becomes enabled
again and its side effect runs a second time). Not "unlikely": guaranteed,
whenever two branches of one run are advanced by overlapping passes.

**The same-transition case.** Two workers that both select T1 both want `a`.
The unique index on `(run_uuid, place)` admits exactly one INSERT; the loser
never reaches t=9, so it does not consult oversight, does not dispatch, does not
write the marking and does not record a step — which is the four-part assertion
the spec makes.

### Decision 4: a join is correct because its claim is its input set, and its wake-up is guaranteed by the last committer

A join `j` with incoming edges `e1, e2` has input places `j#e1` and `j#e2`
(`FlowGraph.php:103-105`). Three failure modes, each closed by a different part
of the protocol:

- **Two branches arriving at genuinely the same instant.** Branch 1's arriving
  transition touches `{p1, j#e1}`, branch 2's touches `{p2, j#e2}`. Disjoint —
  both claim, both dispatch, both commit under the lock in some order. The
  second commit's in-lock read already contains the first's token, so the
  marking that results has BOTH inputs marked. This is Decision 3's argument
  with no addition.
- **Firing twice.** The join's own place set is `{j#e1, j#e2} ∪ tos`. Claiming
  it requires BOTH input places, and the unique index admits one holder per
  place, so two workers cannot both be inside the join's firing. Exactly once,
  by the same primitive that gives exactly-once anywhere else.
- **Firing early.** Impossible without new code: while branch 2's arrival is
  still in flight, `j#e2` is not marked, so Symfony does not report the join
  enabled. The claim adds nothing here and does not need to.

The real hazard is the **lost wake-up**: branch 2 commits the last arrival at
the very end of a pass whose workers have already finished their own loops.
Nobody re-evaluates, the join sits enabled, and — under today's rule at
`FlowEngine.php:312-322`, where an empty enabled set means COMPLETED — a worker
finishing its loop could declare the run complete with tokens stranded on the
join's inputs.

So terminality stops being a conclusion a worker draws from its own loop:

> **Only a writer holding the run-row lock may declare a run parked or
> terminal, and only from the marking it has just written.**

Concretely, the commit at Decision 3 ends by asking `getEnabledTransitions()`
against the marking it wrote. If anything is enabled and unclaimed, the run's
derived status is `queued`, which `findQueued()` (`FlowRunMapper.php:643`)
drains on the next pass with no new query, no new index and no new scan. The
commit that MAKES the join enabled is therefore the same commit that re-arms
the run. A missed pickup costs one cron period of latency; it can never be a
lost wake-up, because the arming is inside the transaction that created the
condition.

### Decision 5: `sequence` becomes position WITHIN a stream, and order comes from a declaration-derived ordinal path

`FlowConcurrency`'s ORDER property (`FlowConcurrency.php:25-28`) says a run log
whose order depends on which call returned first is not comparable between two
runs. Two things break that for branches:

1. `highestSequence() + 1` (`FlowRunService.php:677`) is a read-then-insert
   outside any lock — two branches read the same maximum and write the same
   number.
2. Even made atomic, a single run-wide counter handed out as rows are written
   IS completion order wearing a sequence number. The spec names this trap
   directly.

So `openregister_flow_steps` gains `stream_id` and `ordinal_path`, and
`sequence` is reinterpreted as position within the stream, allocated from
`next_sequence` on the stream row by the same locked transaction that commits
the delta (the `incrementScope()` shape at `lib/Db/SequenceMapper.php:84-93`).
Canonical order becomes `ORDER BY ordinal_path ASC, sequence ASC, id ASC`,
replacing the bare `ORDER BY sequence ASC` at `FlowRunStepMapper.php:62`.

**`ordinal_path` is a zero-padded dotted path.** The root stream is `0001`. A
firing that produces tokens on K taken output places gives its children
`parent.0001 … parent.000K`, where the index is the position in
`$transition->getTos()` — which is the order the AUTHOR wrote the edges in the
flow document. Padding to four digits per segment makes lexicographic ordering
equal tree ordering without any parsing, and `'' < '.'` puts a parent's own
steps before its children's.

**A join folds the path back.** The merged stream takes the longest common
prefix of its inputs' paths — `0002.0001` and `0002.0002` join to `0002` — and
resumes THAT stream's `next_sequence`. A split-and-join therefore reads as one
history with a fan-out in the middle rather than as an orphan branch, and the
rule is total: for branches that came from different splits the common prefix is
shorter, possibly the root, and it is still deterministic.

Determinism follows because every segment is a function of (parent path, index
in declaration order of the taken exit) and nothing else. Two runs that take the
same path produce identical paths and identical per-stream sequences whatever
the timing — which is exactly the spec's "Two runs, different timing, identical
ordering". `created` stays on every row (`FlowRunService.php:699-700`), so the
real interleaving remains readable on request and is never the default.

### Decision 6: intra-run fan-out reuses layer 1's numbers, and the pass gets its own ceiling

The cap on streams of ONE run holding claims simultaneously is
`FlowConcurrency::DEFAULT_LIMIT` (5, `FlowConcurrency.php:72`), clamped by
`MAX_LIMIT` (20, `:83`) through the same `max(1, min(...))` as
`boundedLimit()` (`:188-194`). Not a similar pair of numbers — the same two
constants, referenced.

The argument is `FlowConcurrency`'s own, transposed: a node fanning out over
1,000 items and a run fanning out over 1,000 tokens hit the same upstream, and
a second and different pair of numbers would mean the load an API sees depends
on which layer happened to fan out. The constants stay where they are and this
layer reads them; duplicating the values into a second class is how the two
drift apart in a later edit.

A second bound is required because the first composes multiplicatively:
`BATCH = 25` runs (`FlowRunWorker.php:62`) times five streams is 500 firings a
pass could hold at once. So a pass also carries a total ceiling on claims held
across all runs, defaulting to `BATCH × DEFAULT_LIMIT`, which makes the
per-run cap safe to raise without any single change turning a pass into a
burst.

### Decision 7: status stays derived, with no eighth value

Adding a status for "partly running, partly waiting" would remove such runs from
`findQueued()` (`FlowRunMapper.php:643`), `findDue()` (`:503-507`) and
`findStale()` (`:543-547`) at once — with no error, because a `WHERE status =`
that matches nothing looks exactly like a table with nothing to do. The run
would be invisible to the queue and to recovery simultaneously.

So the run's `status` becomes a projection of its streams, written by whichever
commit last held the lock:

| condition on the run's streams | run status |
|---|---|
| any stream holds a live claim | `running` |
| else any stream has an enabled transition | `queued` |
| else every stream parked | `suspended` |
| else every stream terminal | most severe: `failed` > `dead_letter` > `stopped` > `completed` |

`running` therefore means "a branch is actually being worked on right now",
which is precisely how `findStale()` and `touch()`'s `WHERE status = running`
guard (`FlowRunMapper.php:247-252`) already read it. The spec's "a long-waiting
branch does not make the run look abandoned" then falls out with no special
case: a run whose only remaining stream waits on a signal holds no claim, so it
is `suspended`, so `findStale()` never sees it.

`resume_at` is `MIN` over the streams' non-null wake times, and is null only
when EVERY stream is waiting on a signal. Computing it as a plain `MIN` over
all streams is the trap: one signal-waiting stream would make the whole run's
wake time null and a sibling's due timer would never be picked up by
`findDue()`.

Per-branch detail — which branch waits, on what, since when — is answered from
`openregister_flow_streams` (`run_uuid`, `stream_id`, `ordinal_path`,
`parent_stream_id`, `status`, `resume_at`, `next_sequence`, `error`), never by
overloading the run's status.

Stream status reuses `FlowRun`'s seven constants (`lib/Db/FlowRun.php:98-110`)
rather than defining a parallel vocabulary: a stream is a run-shaped thing, and
one set of strings means `TERMINAL` (`:117-121`) and `ACTIVE` (`:134-137`)
apply to both.

### Decision 8: "simultaneously" means two precise things, and neither is PHP threads

Stated plainly because the specs use the word and a reader could take it for
something PHP cannot do.

1. **Across processes — genuinely parallel, and real today.** Overlapping cron
   passes, and an HTTP request completing a task while a cron pass advances the
   same run, are separate OS processes hitting one database. This is where the
   claim protocol earns its keep, and it is also where today's lost update
   already lives.
2. **Within one pass — interleaved, not threaded.** The pass walks a run's
   streams round-robin instead of draining one to exhaustion, so a stream that
   suspends yields to its siblings rather than returning the whole run
   (`FlowEngine.php:474-493` today returns). That alone removes head-of-line
   blocking, which is the user-visible complaint, and it needs no threads.

Real in-flight overlap inside one PHP process exists only where
`FlowConcurrency::map()` already provides it — per item, inside one node
(`FlowConcurrency.php:117-172`). Branch-level overlap within a single process is
NOT promised. The cap of Decision 6 is enforced as "claims held for one run",
which is meaningful and checkable across processes; that is the reading of the
spec's "at most five in flight".

### Decision 9: an advance budget follows the token, and contention ends it rather than blocking it

ADR-098 D9's `advance: 0 | N | "all"` on task completion becomes a loop over
the COMPLETING stream that takes claims exactly as a worker does, decrementing
the budget per committed firing. Three consequences, each of which is a
scenario in the spec:

- **A sibling's claim ends the advance.** The completion request returns the
  run's state as it stands and leaves the rest to the queue. It never waits —
  Decision 1 forbids waiting anywhere, and a person pressing Approve must not
  pay for a sibling's remote call.
- **A join reached within the budget fires within it.** The join consumes the
  completing branch's `j#eK`, so it is in that stream's own conflict set; the
  budget follows the token, not the label. If the sibling has not arrived, the
  join simply is not enabled and the advance ends there.
- **`"all"` is bounded by the same three things a worker is.** The run-wide
  firing ceiling (Decision 10), the per-hop oversight check, and the request's
  runtime budget (`FlowRunService::DEFAULT_MAX_RUNTIME_MINUTES`,
  `lib/Service/Flow/FlowRunService.php:63`, `:246-251`). `"all"` means "keep
  going while this branch can", not "ignore the bounds".

### Decision 10: the firing ceiling becomes a persisted per-run counter

`$fired` is a local initialised at `FlowEngine.php:299` and compared to
`MAX_TRANSITIONS = 1000` (`:103`, checked at `:325`). It resets on every
`execute()`, so a cycle that suspends once per lap never trips it — a hole that
exists today, before any of this. Branch concurrency would multiply both the
count and the number of ways to park.

So the count moves onto the run as a column, incremented by the same locked
transaction that commits each delta (Decision 3), and checked against the same
constant. Reaching it fails the run with the existing message
(`FlowEngine.php:335`) rather than truncating silently — the posture at
`:96-102` is unchanged, only its scope.

Incrementing inside the transaction is what makes the count exact under
concurrency: an increment outside it would be a read-modify-write of the same
shape this design just removed from the marking.

### Decision 11: oversight is checked inside the claim, and a refusal ends the run

`assertOversightAllows()` stays immediately before dispatch
(`FlowEngine.php:425`), now inside the claim and per firing. It is not hoisted
to once per pass and not cached across firings: the check exists so a switch
thrown mid-run is honoured on the next hop, and a per-pass check would let a
run with ten enabled firings sail past a refusal thrown after the first.

A refusal ends the RUN. Streams that have not started do not start; a stream
already inside `dispatch()` finishes that firing — its side effect is in flight
and cannot be unmade — commits its result so the log is not missing a step that
really happened, and then stops without beginning another. The refusing check's
id is recorded, as it already is via `FlowStop::checkId()`
(`FlowEngine.php:454-456`). A check that throws is still a refusal
(`FlowOversightRegistry.php:106-120`) — unchanged, and worth noting because a
concurrency change is exactly where a fail-open shortcut would be tempting.

### Decision 12: per-place items are persisted, seeded from today's flat array

`place_items` (JSON) joins `marking` on `openregister_flow_runs`, written by
the same locked transaction so a marking can never name a place whose items
were not written. On first read of an in-flight run it is seeded exactly as
`seedPlaceItems()` seeds today — the same list to every marked place
(`FlowItemPlacement.php:90-103`) — so a run that spans the upgrade behaves
precisely as it does now and nothing has to be reconstructed.

`items` on the run stays as the last firing's output
(`FlowRunService.php:810`), because surfaces read it, but it stops being the
resume source. That is the whole of openregister#2488's remedy at the
persistence layer.

## Declarative-vs-imperative decision (ADR-031)

**Imperative, engine core — and there is no declarative alternative to weigh.**
ADR-031's dialect operates on register objects: `x-openregister-lifecycle` hangs
on a schema and `lib/Service/Lifecycle/TransitionEngine.php` transitions an
object. Nothing here is an object. `lib/Db/Flow.php:6-11` states the position —
a flow definition is deliberately NOT an OpenRegister object — and a marking, a
place claim, a stream ordinal and a firing counter are engine state on native
tables reached by mapper, with no schema to hang a lifecycle on and no object
write for a transition's `inputs` contract to validate.

Nothing is taken away from the dialect, because nothing about token-level
concurrency was ever expressible in it. This change adds no notification, no
aggregation, no calculation and no relation; if "a branch was abandoned" later
needs to notify, it notifies through the ADR-031 subsystem from the same place
every other flow-side send does, not through a second mechanism.

**No seed data.** No register or schema is introduced or modified, so ADR-001
seed data does not apply. The equivalent obligation is the back-fill in the
migration plan below, which is a data repair with an idempotency requirement.

## Risks / Trade-offs

- **The run-row lock serialises one run's WRITES**, so twelve branches still
  commit their deltas one at a time. → Accepted, and it is the point: the
  critical section holds no I/O and no user code — a handful of statements
  against rows already in cache — while `dispatch()`, measured in seconds of
  remote work, is entirely outside it. Serialising microseconds to parallelise
  seconds is the trade this design is making on purpose.
- **Write amplification: two to four claim rows inserted and deleted per
  firing.** → Accepted. The table is narrow, hot and short-lived, and every row
  is deleted by the firing that took it or by the reaper. The alternative —
  Decision 1's option A — costs the feature.
- **SQLite has no row-level locking**, so `FOR UPDATE` is inert and the whole
  file serialises writers. → Safe, not unsafe: the guarantee is stricter than
  the design needs. It is called out so nobody reads a green SQLite test suite
  as evidence that the locking works.
- **Livelock under heavy contention.** → Closed by construction in Decision 2;
  additionally, a candidate skipped repeatedly is logged with its place set, so
  a pathological graph is visible rather than merely slow.
- **A split inside a loop mints a stream per lap.** → Bounded by the now-durable
  run-wide ceiling (Decision 10), and stream rows are pruned with their run by
  the retention pass already at `FlowRunWorker.php:203`.
- **`ordinal_path` grows with nesting depth.** → Five bytes per level; a
  `varchar(255)` holds roughly fifty levels of nested splits, far past any
  graph a person draws. A path that would exceed the column truncates the run
  with a named error rather than writing a path that sorts wrongly.
- **BREAKING at the spec level for `flow-engine`'s run-level suspension
  requirement.** → Behaviour for a single-stream flow is identical, because a
  flow with one stream IS the run. That equality is asserted by a test rather
  than argued in prose.
- **Reading a run's log now needs the ordinal.** A consumer that sorts by
  `sequence` alone sees per-stream positions interleaved. → Mitigated by
  `findByRun()` returning canonical order by default (`FlowRunStepMapper.php:62`
  is the only ordering in the mapper), so a consumer using the mapper is
  correct without changing anything.
- **Two failure vocabularies could drift** — a stream's status and the run's
  derived one. → Mitigated by reusing `FlowRun`'s seven constants for both, so
  `TERMINAL` and `ACTIVE` (`lib/Db/FlowRun.php:117-137`) are the single source.

## Migration Plan

1. **Schema.** Create `openregister_flow_claims` with UNIQUE
   `(run_uuid, place)` and an index on `(claimed_at)` for the reaper. Create
   `openregister_flow_streams` with UNIQUE `(run_uuid, stream_id)` and an index
   on `(run_uuid, status)`. Add `place_items` (JSON, nullable) and `firings`
   (integer, notnull, default 0) to `openregister_flow_runs`. Add `stream_id`
   (string, nullable) and `ordinal_path` (string 255, nullable) to
   `openregister_flow_steps`.
2. **Back-fill streams.** For every non-terminal run, create one stream per
   MARKED place, ordinals assigned in sorted place-name order, status copied
   from the run, `resume_at` copied from the run, `next_sequence` set from
   `highestSequence() + 1` so the resumed history continues rather than
   restarting. A run with no marked places (queued, never started) gets no
   stream row; its first firing mints the root.
3. **Honest caveat on step 2.** A pre-upgrade run's ordinals are place-name
   order, not the author's declaration order, because declaration order was
   never recorded for a run already in flight. Such a run is therefore not
   ordinal-comparable with one started after the upgrade. This is stated in the
   migration's own log line rather than left for someone to discover from a
   diff that will not explain itself.
4. **Back-fill steps.** Stamp existing step rows with the root path `0001` and
   their run's root stream id, so `ORDER BY ordinal_path, sequence` reproduces
   today's order for every historical run exactly.
5. **Back-fill items.** Leave `place_items` null; it is seeded on first read
   from the flat `items` by the same rule `seedPlaceItems()` uses today
   (`FlowItemPlacement.php:90-103`), so an in-flight run's behaviour across the
   upgrade is unchanged rather than reconstructed.
6. **Idempotency.** Every step is guarded on existence, so a second run creates
   no duplicate stream and re-stamps no step.
7. **Rollback.** All additions are additive and the old code reads none of
   them: a null `stream_id` is the single implicit stream, and `sequence`
   retains its old meaning for every back-filled row. Reverting the app code
   restores today's behaviour with the new columns inert. Dropping them is a
   separate, optional migration and MUST NOT be part of the rollback path.
8. **Verification.** After deploy: no claim row is older than the reaper's
   cutoff; every non-terminal run has at least one stream unless its marking is
   empty; no step row has a null `ordinal_path`; every run's `status` equals the
   projection of its streams under Decision 7's table; and no run's `firings`
   exceeds `MAX_TRANSITIONS`.

## Open Questions

- The retention horizon for `openregister_flow_claims` rows the reaper releases
  — deleted immediately, or kept briefly as evidence of an abandonment.
  Deferrable: it changes no spec, no interface and no task, and it cannot be
  chosen sensibly before there is a fleet's worth of abandonment data.
- Whether the per-run stream cap should be author-configurable per flow (as a
  node's concurrency limit already is) or remain instance-wide. Deferrable: the
  clamp of Decision 6 makes both safe, and adding a per-flow source later
  changes no stored shape.
