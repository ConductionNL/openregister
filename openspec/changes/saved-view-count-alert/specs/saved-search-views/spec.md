# saved-search-views

## ADDED Requirements

### Requirement: A view may declare a count alert

A View SHALL accept an optional `alert` block with `operator` (`gte` or
`lte`), `threshold`, `recipients`, `channels` and `every`, validated with the
notification engine's recipient and channel grammar. Only the view's owner,
or a user with write on a shared view, SHALL set or clear it.

#### Scenario: a malformed alert is refused

- **GIVEN** a view update with `alert: {operator: "above", threshold: 10}`
- **WHEN** it is saved
- **THEN** the response is 422 naming `operator`
- @e2e exclude {validator, covered by unit tests}

### Requirement: A view alert fires once per crossing and re-arms

A timed sweep SHALL count each due view's query under the owner's RBAC,
SHALL dispatch the alert through the notification engine when the count
crosses the threshold in the declared direction, SHALL then hold state
`fired` until a later count is back across the line, and SHALL expose
`alertState` (state, last count, last evaluated) on the view.

#### Scenario: a standing backlog pages once

- **GIVEN** a view with `gte 20` evaluated every 15 minutes and a count of 23 for two hours
- **WHEN** eight sweeps run
- **THEN** the recipients receive one notification and the view reads `fired` with last count 23
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/view-alert.spec.ts when the field ships in nextcloud-vue}

#### Scenario: the alert re-arms when the backlog clears

- **GIVEN** the same view in state `fired`
- **WHEN** a sweep counts 12
- **THEN** the view reads `armed` and the next count of 21 fires again
- @e2e exclude {state machine, covered by sweep unit tests}

### Requirement: The alert sweep is bounded

The sweep SHALL evaluate only views whose `every` has elapsed, SHALL cap the
number evaluated per pass, SHALL keep a watermark so no view starves, and
SHALL count rather than list.

#### Scenario: a thousand views do not stall the pass

- **GIVEN** 1,000 views with alerts due at once and a cap of 200
- **WHEN** five passes run
- **THEN** every view is evaluated exactly once and no pass exceeds the cap
- @e2e exclude {bounded sweep, covered by unit tests}
