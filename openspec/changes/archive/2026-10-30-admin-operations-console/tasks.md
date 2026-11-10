# Tasks: admin-operations-console

## Where this change stands

Complete. The first branch shipped the console as a read over records that
already exist, plus pause and resume. This branch adds the half that was
missing: the run log itself, the acts over it, and the two things an
administrator reaches for when an instance is in trouble.

Shipped here: a run row per execution, written by `RecordedTimedJob` /
`RecordedQueuedJob` around the work rather than by each job; run now, once,
refusing a job that is already running by naming the run that holds it; the
administered interval, window and enabled state, with the last run and next
due on the row; the failure threshold over an administered period, one alert
per breach; the search index rebuild, the cache clear and warm and the
consistency check as recorded jobs; the read-only check, which refuses any
probe query that is not a select, and the repair as a separate authorised act
naming what it will change; maintenance mode with its message, leaving the
console reachable; and the support bundle, redacted where it is built.

**The observed list is now computed, not kept.** A job is observed because it
implements `RecordsItsRuns`, and the console asks the class. The 61 jobs that
predate this base class are still unobserved, and the console names them,
which is what REQ-AOC-001 asks for. Moving them onto the recorded base is a
per-job change with a constructor edit each, and belongs to the debt sweep.

## 1. The run history

- [x] 1.1 A wrapper around job execution writing job, start, end, duration, outcome and failure (D-1).
- [x] 1.2 A run list with filters on job, outcome and period, index-backed (D-1).
- [x] 1.3 Jobs outside the recorded path are listed as unobserved, never omitted (D-1).

## 2. Run now and the schedule

- [x] 2.1 An authorised run-now that records its cause (D-2).
- [x] 2.2 A job already running is refused, naming the run that holds it (D-2).
- [x] 2.3 Interval, window and enabled per recurring job, with last run and next due on the row.

## 3. Alerting

- [x] 3.1 An administered failure threshold over an administered period (D-3).
- [x] 3.2 One alert per breach, naming the job and the first failure in the period.

## 4. Maintenance, the check and the repair

- [x] 4.1 Search index rebuild, cache clear and warm, and the consistency check, each as a recorded job (D-4).
- [x] 4.2 A read-only check that writes nothing and names the objects concerned (D-5).
- [x] 4.3 A repair as a separate authorised act, naming what it will change, on the audit trail (D-5).

## 5. Maintenance mode, bundle and facts

- [x] 5.1 Maintenance mode with an administered message, refusing reads and writes (D-6).
- [x] 5.2 The administration surface stays reachable while the mode holds (D-6).
- [x] 5.3 A support bundle redacted where it is built, sharing the logger's rules (D-7).
- [x] 5.4 An instance facts page: version, build, dependencies and licence.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/operations-console.spec.ts`: a failed run with its reason, run now, the refusal of a double start, maintenance mode and leaving it.
- [x] 6.2 Unit tests: the threshold over a clock fixture, the unobserved-job listing, the check writing nothing, the redaction of the bundle.
- [x] 6.3 `openspec validate admin-operations-console --strict`.

## 7. Hand over

- [x] 7.1 Hand the console to the dossiq lane for its job monitor page over fifteen background jobs, with the nineteen candidate ids.
- [x] 7.2 Hand the rebuild action to `search-quality-operators-and-facets`, which specifies what a rebuild does.

## 8. What this change deliberately did not do

- The 61 jobs that predate `RecordedTimedJob` keep running unwrapped. They are
  NAMED as unobserved on the console rather than omitted, which is the
  behaviour REQ-AOC-001 requires; moving them over is a per-job constructor
  change and belongs to the debt sweep, not to this branch.
- The operations acts (a repair, entering and leaving maintenance mode) are
  recorded on the run log rather than on `openregister_audit_trails`. That
  table is object-centric: every row hangs off an `ObjectEntity`, and a
  maintenance act has no object. The run log carries the actor, the moment and
  the objects concerned, and is the record the console reads.
