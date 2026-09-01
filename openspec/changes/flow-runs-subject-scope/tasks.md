# Tasks: flow-runs-subject-scope

## 1. The subject filter on the live read

- [x] 1.1 `FlowRunMapper::findActive()` and `countActive()` gain an
      optional `subject` argument, added as an `AND` predicate on
      `subject_uuid` next to the unconditional organisation predicate
      (`lib/Db/FlowRunMapper.php:477`, `:565`). Rows and total share the
      predicates.
- [x] 1.2 `FlowRunController::active()` reads the `subject` request
      parameter and passes it through
      (`lib/Controller/FlowRunController.php:225`). Absent parameter is
      bit-identical to today; the no-organisation early return stays
      before any query.

## 2. The completed-runs read

- [x] 2.1 `FlowRunMapper::findCompletedForSubject()` +
      `countCompletedForSubject()`: `FlowRun::TERMINAL` statuses
      (`lib/Db/FlowRun.php:142`), required subject uuid, organisation
      predicate, newest first, capped limit.
- [x] 2.2 `FlowRunController::completedForSubject()` + route
      `GET /api/flow-runs/completed` in `appinfo/routes.php`, refusing a
      request without `subject` and reusing `summarise()` unchanged. The
      existing `flowRun#index` history endpoint is not touched.

## 3. The index

- [x] 3.1 Migration adding a composite index over
      `(organisation, subject_uuid, status)` on the runs table.

## 4. Follow-up (not in this change)

- [ ] 4.1 nc-vue: a follow-up change in nc-vue gives `CnFlowRunsWidget` a
      `subject` option (rendering the case-scoped list on detail pages)
      and a per-row deep link to the run, consuming these two reads.
      Nothing of it is specified here.

## 5. Tests

- [x] 5.1 Mapper unit tests: subject narrowing with honest totals; a
      matching subject uuid in another organisation returns and counts
      nothing; the terminal set drives the completed read (including
      `failed`); newest-first ordering.
- [x] 5.2 Controller unit tests: absent `subject` equals today's read;
      missing subject on the completed read is refused naming the
      parameter; both reads return the summarised row (uuid, flow name,
      step, status, started at, subject block) and never marking, items
      or step log.
- [ ] 5.3 Playwright coverage for the two `@e2e`-marked scenarios in
      `specs/flow-runs-subject-scope/spec.md`: the case detail widget
      lists only the case's own runs, and a finished flow appears in the
      case detail's run history.

## Acceptance criteria

- `GET /api/flow-runs/active?subject=<uuid>` returns only that subject's
  live runs inside the caller's organisation, with a total counting the
  filtered set; without `subject` the response is unchanged from today.
- A subject uuid from another organisation returns an empty result and a
  zero total, with the organisation predicate applied in the datastore.
- The completed-runs read requires a subject, serves the terminal status
  set newest first with a capped limit and honest total, and leaves
  `flowRun#index` untouched.
- Both reads share the summarise row shape; no row carries marking, items
  or a step log.
- A caller with no resolvable organisation gets an empty result from both
  reads with no query issued.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- Every touched method carries a
  `@spec openspec/changes/flow-runs-subject-scope/...` anchor; new code
  carries `@license EUPL-1.2` and `@copyright 2026 Conduction B.V.`.
- No dependency beyond shipped code: the subject columns, the active
  endpoint and `summarise()` all exist on development.
- References ADR-098 D4 (the case anchor), ADR-022 (widget consumes the
  OR read), ADR-005 (fail-closed scoping); honours `or-flow-active-runs`'
  "run history surface is unchanged" requirement.
