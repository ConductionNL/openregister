# Tasks: or-flow-active-runs

- [x] `FlowRun::ACTIVE` — the complement of `TERMINAL`, so "still going" is
      defined once on the entity.
- [x] `FlowRunMapper::findActive()` — non-terminal statuses, newest first,
      strictly scoped to one organisation.
- [x] `FlowRunMapper::countActive()` — the honest total behind a bounded page.
- [x] `FlowRunService::queue()` stamps `organisation` from the active
      organisation, resolved lazily through the container; null (not guessed)
      when there is no session.
- [x] `FlowRunController::active()` + route `GET /api/flow-runs/active`,
      registered ABOVE `/api/flow-runs/{uuid}` so the literal `active` is not
      answered by `show('active')`.
- [x] Row summarisation: uuid, flowId, flow NAME (memoised resolve, id
      fallback), status, trigger, startedBy, subject, current step, timestamps —
      and NOT the marking / items / log.
- [x] Tests: no organisation reads nothing and never queries; scoping passes the
      caller's organisation through; rows carry the resolved name and step; an
      unresolvable flow falls back to its id; the row limit is capped.
- [ ] Backfill `organisation` on existing rows — deliberately NOT done: there is
      no sound source for a historical run's tenant, and the active-runs read
      only ever shows live runs, which are stamped from this change onward.
