# flow-engine

## ADDED Requirements

### Requirement: A manual trigger declares where the person goes next

A node of type `openregister.trigger-manual` MAY declare `next` as `stay`,
`next` or `list`, defaulting to `stay`; an end node MAY override it for the
run. The run result SHALL carry the effective `next`. The engine SHALL NOT
navigate.

#### Scenario: the hint reaches the caller

- **GIVEN** a manual trigger with `next: "next"`
- **WHEN** the flow runs to an end node without an override
- **THEN** the run result carries `next: "next"`
- @e2e exclude {run result, covered by engine unit tests}
