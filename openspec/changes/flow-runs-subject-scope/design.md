# Design: flow-runs-subject-scope

## Context

Measured, in the shipped code:

- `FlowRun` stores the subject anchor (`lib/Db/FlowRun.php:241-255`:
  `subjectUuid`, `subjectRegister`, `subjectSchema`) and defines the
  status sets (`ACTIVE` at `:159`, `TERMINAL` at `:142`).
- `FlowRunController::active()` (`lib/Controller/FlowRunController.php:225`)
  resolves the caller's organisation, returns empty without querying when
  none resolves, caps the limit at 50, and reduces each run through
  `summarise()` (`:277-299`), which already carries uuid, flowName,
  status, subject block, step and created.
- `FlowRunMapper::findActive()`/`countActive()`
  (`lib/Db/FlowRunMapper.php:477`, `:565`) filter on status set and
  organisation only.
- The `or-flow-active-runs` capability pins the boundary: strict per-org
  scoping, unattributed runs returned to nobody, honest totals, and "the
  run history surface is unchanged".

## Goals / Non-Goals

**Goals:**

- One datastore-filtered read for "live runs on this subject" and one for
  "finished runs on this subject", both inside the existing org scope.
- A row contract a case-detail widget can render without a second request
  per row.

**Non-Goals:**

- Any change to the org-wide dashboard behaviour, the history endpoint,
  or run visibility rules.
- Cross-subject or cross-org aggregation, free-text search, or filtering
  on register/schema (the uuid identifies the case; the widget already
  knows which case it is on).
- The widget itself (nc-vue follow-up).

## Decisions

### D-1: The live filter is a parameter on the existing endpoint, not a new route

`flow-runs/active` already has the organisation resolution, the limit
cap, the summarise shape and a consumer. A `subject` parameter narrows
it; a parallel `/active-for-subject` route would duplicate all four and
drift. The parameter is optional and absent means bit-identical behaviour
to today, which keeps the existing widget untouched.

### D-2: The filter narrows in the datastore, after the organisation predicate

The mapper gains an optional subject argument added as an `AND` predicate
next to the existing organisation predicate. Order of evaluation is a
correctness statement, not an optimisation: the organisation scope is
applied unconditionally first, so a guessed subject uuid from another
tenant matches zero rows rather than leaking one. Client-side filtering
is refused for the same reason the task inbox spec refuses it: a filter
over a server-paginated page silently drops rows the page did not
contain. The `total` is counted with the same predicates as the rows.

### D-3: The completed read is a new, subject-REQUIRED surface

The existing capability forbids widening the history endpoint, so case
history gets its own read with a deliberately narrow contract: the
subject uuid is REQUIRED (there is no org-wide "all finished runs" here;
that is what the history endpoint already serves to its audience),
status is the `FlowRun::TERMINAL` set, ordering is newest first, and the
result is bounded with an honest total. It reuses `summarise()`
unchanged: a finished run's row needs nothing a live run's row lacks,
and one shape means the widget renders one list.

### D-4: The row contract names the widget's five fields and freezes the exclusions

What a case-detail widget needs is run uuid, flow name, current step,
status and started at; the shipped `summarise()` already carries all five
(started at is served by `created`) plus the subject block. The spec
therefore pins the CONTRACT rather than inventing a shape: those fields
present on every row, and the marking, items and step log absent, for the
same reason `or-flow-active-runs` gives: kilobytes per row a list never
renders, and items can hold the record data itself. The single-run
endpoint stays the place to ask for a run's contents.

### D-5: One supporting index

The live read today scans on `(status, organisation)` shapes; adding the
subject predicate makes `(organisation, subject_uuid, status)` the
selective path for both new reads. One migration, one composite index; no
schema change to the entity.

## Risks / Trade-offs

- **A subject uuid is not secret.** Mitigated structurally: the
  organisation predicate is unconditional, so knowing a uuid from another
  tenant yields an empty result, indistinguishable from a case with no
  runs.
- **Two reads can drift in shape.** Mitigated by sharing `summarise()`;
  the spec makes the shared row a requirement, so a divergence is a spec
  violation rather than a taste question.
- **Unbounded history growth on busy cases.** The completed read is
  bounded with the same cap discipline as the live read and ordered
  newest first; "everything ever" stays on the history surface where its
  existing audience and filters live.

## Migration Plan

Additive: one parameter, one route, two mapper methods, one index
migration. No consumer changes behaviour until it passes `subject`.
Rollback is dropping the route and parameter; the index is harmless to
leave.

## Open Questions

- **Does the widget also want counts per status** (2 live, 14 finished)
  for a badge? The honest totals of the two reads already provide both
  numbers at one request each; a combined count endpoint is deferred
  until a consumer measures the two requests as a problem.
