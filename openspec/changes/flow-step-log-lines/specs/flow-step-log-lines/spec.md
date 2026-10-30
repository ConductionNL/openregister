## Purpose

Each step of a run says when it started and, in its own words, what it did.

## ADDED Requirements

### Requirement: Every step entry records when the step started

Every log entry the engine writes for a step SHALL carry `startedAt`, the
moment the step began, as ISO 8601 with milliseconds in UTC. This SHALL hold
for every step status, including a pinned step and a suspended one.

#### Scenario: a failed step still says when it started
- GIVEN a two-step flow whose second step throws
- WHEN the flow runs
- THEN both log entries carry `startedAt`, the first no later than the second

### Requirement: A node can write timestamped lines into its step's entry

`FlowStepReport::line(level, message)` SHALL append `{at, level, message}` to
the current step's `report.lines`. `level` SHALL be one of `info`, `success`,
`warning`, `error`; any other value SHALL be recorded as `info`. A step SHALL
keep at most 50 lines; past that the last line SHALL say how many lines were
left out. Lines SHALL clear with the rest of the report after each hop.

#### Scenario: the cap is stated, not silent
- GIVEN a node that writes 53 lines in one step
- WHEN the step's report is taken
- THEN it holds 50 lines and the last reads "4 more lines left out."
