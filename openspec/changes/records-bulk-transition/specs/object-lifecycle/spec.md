# object-lifecycle

## ADDED Requirements

### Requirement: A bulk job can move selected records through one lifecycle action

The bulk job framework SHALL offer `openregister:transition` with an `action`
and its `data`. Each selected object SHALL be moved through the same engine
call a single move uses, as the job's actor, and its outcome SHALL be
`applied`, `skipped` when the object is already in the target state, or
`failed` with the message a single move would answer.

#### Scenario: a case handler starts forty requests at once

- **GIVEN** a case handler with `update` rights on schema `aanvraag`, and forty `aanvraag` records in state `ontvangen`, two of them already `in behandeling`
- **WHEN** the handler creates a bulk job through `POST /api/bulk-jobs` with action `openregister:transition`, `action: "start"`, over all forty
- **THEN** thirty-eight records are `in behandeling` with one audit entry each naming the handler
- **AND** the job lists thirty-eight `applied` and two `skipped`
- @e2e exclude {specified only; task 2.2 adds tests/e2e/ci/bulk-transition.spec.ts}

#### Scenario: a move the lifecycle refuses is reported, not forced

- **GIVEN** the same job where one record's lifecycle requires an input `reden` that the job's `data` lacks
- **WHEN** the job runs
- **THEN** that record stays where it was and its outcome is `failed` with the missing-input message
- @e2e exclude {specified only; covered by TransitionActionTest in task 1.2 and Newman in task 2.1}

#### Scenario: the preview shows what would move

- **GIVEN** the same selection
- **WHEN** the handler previews the job before committing
- **THEN** each record is listed as available or not for `start`, and nothing changes
- @e2e exclude {specified only; covered by TransitionActionTest in task 1.2}
