# Scenario-level exclusion spec

The CONTROL arm for `.github#356`. Identical shape to req-inherit/ except the
exclusion sits on ONE SCENARIO instead of the requirement body.

This currently behaves CORRECTLY — the uncovered sibling is reported. It is
fixtured so that a fix for #356 which over-corrects (killing requirement-level
exclusion outright, or leaking scenario-level exclusion) is caught in the other
direction. A repair that makes every exclusion inert would pass a one-armed
suite.

### Requirement: Sibling handling

#### Scenario: excluded sibling

@e2e exclude write-only field, invisible to a response-shape assertion

- WHEN a is written THEN nothing is echoed in the response

#### Scenario: uncovered sibling

- WHEN b happens THEN c is shown
