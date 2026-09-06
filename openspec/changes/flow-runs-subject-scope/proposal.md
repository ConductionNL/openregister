---
kind: code
---

# Proposal: flow-runs-subject-scope

## Summary

Let a case page ask "what is running on THIS case, and what already ran".
The live-runs read (`GET /api/flow-runs/active`) accepts an optional
`subject` filter matching `FlowRun.subject_uuid`, applied INSIDE the
caller's existing organisation scope. A new completed-runs read answers
the history half for the same subject. Both reads return the row a
case-detail widget needs (run uuid, flow name, current step, status,
started at) and nothing heavier. The nc-vue widget consumption is a
follow-up change in nc-vue; tasks.md carries one pointer line for it.

## Why

**The anchor exists; no read uses it.** `FlowRun` carries
`subject_uuid`, `subject_register` and `subject_schema`
(`lib/Db/FlowRun.php:241-255`), and the active endpoint's own
`summarise()` already returns the subject block per row
(`lib/Controller/FlowRunController.php:288-292`). But
`FlowRunMapper::findActive()` and `countActive()` filter on organisation
and nothing else (`lib/Db/FlowRunMapper.php:477`, `:565`), so the ONLY
consumer today is the org-wide dashboard widget. A case detail page that
wants "runs on this case" has to fetch the org-wide list and filter
client-side, which silently drops any matching run outside the fetched
page. The dossiq flow proof needs exactly that view: a hersteltermijn run
suspended on a resident is invisible from the case it is about.

**The history half has the same gap with an extra constraint.** The
`or-flow-active-runs` capability requires that "the run history surface is
unchanged": widening run visibility must not be done by widening the
existing history endpoint. So a case page's "what already ran here" needs
its own bounded, subject-required read rather than a loosened
`flowRun#index`.

**The tenant boundary must be unmovable by the new filter.** The
live-runs read is strictly scoped to one organisation, an unattributed run
is returned to nobody, and a caller with no organisation reads nothing
without a query being issued (all three are existing `flow-active-runs`
requirements). A subject filter must only ever NARROW that result: a
subject uuid is guessable, and a filter that widened by subject would turn
the case anchor into a cross-tenant read primitive.

## What Changes

- **`GET /api/flow-runs/active` accepts `subject`** (a subject object
  uuid). The filter is applied in the datastore inside the organisation
  scope; the returned `total` counts the filtered set.
- **A completed-runs read for a subject**: terminal runs
  (`FlowRun::TERMINAL`: completed, stopped, dead_letter, failed) for a
  REQUIRED subject uuid, inside the caller's organisation scope, newest
  first, bounded with an honest total. It reuses the existing summarise
  row shape; it does not touch `flowRun#index`.
- **The row contract is stated once for both reads**: uuid, flow name
  (id fallback), current step (null when none), status, started at, and
  the subject block; never the marking, the items or the step log.
- **A supporting index** on the runs table so the subject reads are a
  range scan, not a table walk.
- **nc-vue follow-up (one task line)**: `CnFlowRunsWidget` gains a
  `subject` option and a run deep link, in its own nc-vue change.

## What does NOT change

- **The org-wide behaviour of `flow-runs/active`.** No `subject` means
  exactly today's read; every existing `flow-active-runs` requirement
  (strict org scope, unattributed runs returned to nobody, bounded list,
  honest total, name fallback, step derivation) holds unchanged with and
  without the filter.
- **`flowRun#index`, `flowRun#show` and every other run endpoint.** The
  history surface keeps its current filters, shape and visibility, as the
  existing capability requires.
- **RBAC and organisation resolution.** The reads use the same
  organisation resolution the active endpoint already uses; this change
  adds no new authorization model.
- **The widget itself.** nc-vue's `CnFlowRunsWidget` changes in nc-vue.

## Capabilities

### New Capabilities

- `flow-runs-subject-scope`: the subject filter on the live-runs read,
  the subject-required completed-runs read, and the shared case-widget
  row contract.

### Modified Capabilities

<!-- None. flow-active-runs' requirements all hold unchanged; the subject
     filter only narrows a result that capability already scopes, and the
     completed read is a new surface, which is exactly what its
     "run history surface is unchanged" requirement demands. -->

## Impact

- **Affected specs**: new `flow-runs-subject-scope`.
- **Affected code**: `lib/Controller/FlowRunController.php` (`active()`
  gains the parameter; one new controller method + route for the
  completed read); `lib/Db/FlowRunMapper.php` (`findActive`/`countActive`
  gain an optional subject argument; `findCompletedForSubject` +
  `countCompletedForSubject`); one migration for the supporting index.
- **Affected apps**: dossiq's case detail page and any app whose detail
  page anchors runs to an object become consumers, through the nc-vue
  widget follow-up.
- **Depends on**: nothing beyond what exists. The subject columns,
  the active endpoint and the summarise shape are all shipped.
- **ADRs**: ADR-098 D4 (the case anchor is the OR object; this read is
  that anchor's view of the engine), ADR-022 (apps consume OR
  abstractions: the widget consumes this read instead of each app
  building a run query), ADR-005 (fail-closed scoping).
