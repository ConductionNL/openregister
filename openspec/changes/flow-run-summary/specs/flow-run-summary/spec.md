## Purpose

Per-flow run figures for a flow list, read in the datastore under the same
visibility rule as the run history.

## ADDED Requirements

### Requirement: The summary returns the newest run and a count per requested flow

`GET /api/flow-runs/summary` SHALL accept `flowIds` (comma separated or an
array, at most 200 honoured) and an optional `since` (ISO 8601, default seven
days ago). It SHALL return `{results: {flowId: {lastRun, runsSince}}, since}`
with an entry for EVERY requested flow, `lastRun: null` and `runsSince: 0`
for a flow without visible runs. `lastRun` SHALL carry only `uuid`, `status`,
`error`, `created` and `updated`, never the log or marking.

#### Scenario: a flow that never ran is still answered
- GIVEN flows A and B, where only A has runs
- WHEN the summary is read for A and B
- THEN B is present with `lastRun: null` and `runsSince: 0`

### Requirement: The summary is scoped like the run history

For a non-admin caller the summary SHALL count and return only runs the caller
triggered or runs of flows the caller owns, the same predicate the run history
applies. A caller without a session SHALL receive an empty result and no query
SHALL be issued.

#### Scenario: no session reads nothing
- GIVEN a request without a user session
- WHEN the summary is read
- THEN the results are empty and the mapper is not called

### Requirement: The run history narrows to several flows and a start moment

`GET /api/flow-runs` SHALL accept `flowIds` (any of these flows) and `since`
(created at or after). An unreadable `since` SHALL be refused with 400 on both
reads rather than ignored.

#### Scenario: an unreadable since is refused
- GIVEN `since=not-a-date`
- WHEN the summary or the history is read
- THEN the response is 400 naming the parameter
