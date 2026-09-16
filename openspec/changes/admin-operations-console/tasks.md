# Tasks: admin-operations-console

## Where the first branch stopped

`feat/admin-operations-console` shipped the console as a read over records
that already exist, plus the one verb that was missing.

Shipped: the three panes with their counts and what needs a look, the job
rows carrying the verbs each state allows, the background job inventory with
the unobserved ones named, the cross-rule run listing, and pause and resume
on a bulk job with the runner honouring the pause.

Not shipped, and the next branch picks it up here: the wrapper and the run
history table (1.1), so every job gets a row instead of only the bulk runner;
run now and the schedule (2.1, 2.3); alerting (3); the maintenance actions,
the consistency check and the repair (4); maintenance mode, the support
bundle and the instance facts page (5). Tasks 6.1 and 6.2 are part done: the
e2e covers the failed run, the refusal of a double pause and the permission
arm, and the unit tests cover the unobserved listing and the pause the runner
honours; the clock fixture and the bundle redaction wait on 3 and 5.

## 1. The run history

- [ ] 1.1 A wrapper around job execution writing job, start, end, duration, outcome and failure (D-1).
- [x] 1.2 A run list with filters on job, outcome and period, index-backed (D-1).
- [x] 1.3 Jobs outside the recorded path are listed as unobserved, never omitted (D-1).

## 2. Run now and the schedule

- [ ] 2.1 An authorised run-now that records its cause (D-2).
- [x] 2.2 A job already running is refused, naming the run that holds it (D-2).
- [ ] 2.3 Interval, window and enabled per recurring job, with last run and next due on the row.

## 3. Alerting

- [ ] 3.1 An administered failure threshold over an administered period (D-3).
- [ ] 3.2 One alert per breach, naming the job and the first failure in the period.

## 4. Maintenance, the check and the repair

- [ ] 4.1 Search index rebuild, cache clear and warm, and the consistency check, each as a recorded job (D-4).
- [ ] 4.2 A read-only check that writes nothing and names the objects concerned (D-5).
- [ ] 4.3 A repair as a separate authorised act, naming what it will change, on the audit trail (D-5).

## 5. Maintenance mode, bundle and facts

- [ ] 5.1 Maintenance mode with an administered message, refusing reads and writes (D-6).
- [ ] 5.2 The administration surface stays reachable while the mode holds (D-6).
- [ ] 5.3 A support bundle redacted where it is built, sharing the logger's rules (D-7).
- [ ] 5.4 An instance facts page: version, build, dependencies and licence.

## 6. Tests

- [ ] 6.1 `tests/e2e/ci/operations-console.spec.ts`: a failed run with its reason, run now, the refusal of a double start, maintenance mode and leaving it.
- [ ] 6.2 Unit tests: the threshold over a clock fixture, the unobserved-job listing, the check writing nothing, the redaction of the bundle.
- [ ] 6.3 `openspec validate admin-operations-console --strict`.

## 7. Hand over

- [ ] 7.1 Hand the console to the dossiq lane for its job monitor page over fifteen background jobs, with the nineteen candidate ids.
- [ ] 7.2 Hand the rebuild action to `search-quality-operators-and-facets`, which specifies what a rebuild does.
