# Proposal: or-flow-active-runs

## Summary

Give every app one honest read of "which flows are running right now", scoped to
the caller's organisation.

## Why

Flow runs are already durable (`or-flow-runs`) and already inspectable
(`or-flow-tooling`), but only after the fact: `GET /api/flow-runs` is history —
newest-first, one optional status, no tenant boundary, and each row carrying its
whole marking, item list and step log.

Three things stop that being the read behind a dashboard widget:

| Problem | Consequence |
|---|---|
| One `status` filter | `status=running` is nearly always empty — a run holds `running` only during a worker pass, while `queued` and `suspended` are where a live run actually waits |
| No organisation scope | The endpoint is `#[NoAdminRequired]`; putting it on every app's dashboard would show every tenant's activity to every user |
| Whole-run payloads | The marking, the items (which can hold the record data itself) and the full log, per row, for a list that renders a name and a status |

And runs could not be scoped even if the endpoint wanted to: `FlowRun` has had an
`organisation` column since the original migration, and nothing ever wrote to it.

## What Changes

- `FlowRun::ACTIVE` — the complement of `TERMINAL` (`queued`, `running`,
  `suspended`), so "still going" is defined once, on the entity.
- `FlowRunMapper::findActive()` / `countActive()` — non-terminal runs, newest
  first, STRICTLY scoped to one organisation. A bounded list plus a real total,
  because "3 of 47 running" needs the 47.
- `FlowRunService::queue()` stamps `organisation` from the caller's active
  organisation, resolved lazily through the container so the cron worker does
  not drag the RBAC graph into every pass. Null when there is no session — an
  unattributed run, recorded as such rather than guessed at.
- `GET /api/flow-runs/active` — the widget's read. Summarised rows (uuid, flow
  id AND flow NAME, status, trigger, who started it, subject, current step,
  timestamps), a total, and an empty list when no organisation resolves.

`GET /api/flow-runs` is deliberately UNCHANGED. It is the history surface, its
existing e2e coverage depends on its current shape, and the point of a separate
endpoint is that widening run visibility to every user of every app does not
also widen it across tenants.

## Design decisions

**Strict scoping, not "or unattributed".** A run with no organisation is
returned to nobody. Including NULL rows for everyone would leak pre-change and
cron-queued runs across tenants; including them for admins only would be a
carve-out nobody could infer from the endpoint's name. Legacy rows are almost
all terminal anyway, so an active-runs read barely notices.

**A new endpoint, not a widened `index`.** Adding `statuses[]` + scoping to
`index` would change what existing callers see. The new surface can be exactly
what a widget needs and nothing else.

**Flow NAME resolved server-side.** `FlowResolverRegistry::resolveFlow()`
memoises per flow id, so a list over a handful of flows costs one resolve per
DISTINCT flow. Doing it client-side would mean N requests from a widget, and a
flow uuid on a dashboard tells a person nothing.

**Summarised rows.** A list view never renders a marking or a step log, and
`items` can carry the subject's own data. The single-run endpoint stays the place
to ask for a run's contents.
