---
kind: code
depends_on: [notification-scheduled-filter-grammar]
---

# Proposal: saved-view-count-alert

## Summary

Let a saved view raise an alert when its count crosses a threshold. A View
persists a query (`saved-search-views` REQ-001) and the notification engine
already runs bounded, watermarked scheduled sweeps. This change adds an
`alert` block on a View: a threshold, a direction, recipients and a channel.
A sweep counts the view's query, fires once per crossing, and re-arms when
the count returns below the line.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 9.13 | Alert when a saved search crosses a count threshold | no | S |

## Why

The register's note: "Nothing equivalent." The best competitor, verbatim
from the `best` column: "GLPI 11: src/SavedSearch_Alert.php
(`_round3/compare/proposed-rows.md`)".

The register's `why`: "an alert when a view's count crosses a threshold is
a scheduled notification over a saved view", and its `covered` note names
`notification-scheduled-filter-grammar` as "the filter grammar it would
use".

## What changes

- A View gains an optional `alert`: `{ "operator": "gte" | "lte", "threshold": n,
  "recipients": [recipient blocks], "channels": [channel blocks],
  "every": "15m" | "1h" | "1d" }`, validated with the notification
  engine's recipient and channel grammar. Only the view's owner, or a user
  with write on a shared view, may set it.
- A `ViewAlertSweepJob` (TimedJob) counts the query of every view with an
  alert due for evaluation, using the view owner's RBAC, and dispatches
  through the notification engine when the count crosses the line.
- Crossing state is stored per view (`armed` | `fired`, last count, last
  evaluated) so a threshold fires once, not every sweep, and re-arms when
  the count is back on the other side.
- The alert's last count and state are read on the view, so a lens can show
  "23 overdue, alert fired 08:15".

## Consumers

- dossiq: a threshold on the Overdue lens for the team lead. Specified in
  dossiq by the dossiq lane (register row 9.13).
- pipelinq (leads without follow-up), humaniq (open leave requests),
  keepiq (expiring secrets): the same block on any view.
- nextcloud-vue's `CnSavedViewsControl` gains an alert field in a later
  change; this change is the backend.

## ADRs

- ADR-031: recipients and channels reuse the declarative notification
  grammar.
- ADR-069: a `TimedJob` in `lib/BackgroundJob/`.
- openregister ADR-058 (bounded object queries): the sweep counts, it does
  not list.
- ADR-022.

## Impact

- Extends: `saved-search-views` REQ-001 and the `notificatie-engine`
  requirement "Periodic sweeps are bounded and watermarked".
- Affected code: `lib/Db/View.php` (`alert`, `alertState`), the views
  controller and validator, `lib/BackgroundJob/ViewAlertSweepJob.php`, the
  dispatcher (a `view-alert` trigger source).
- Size: S.
