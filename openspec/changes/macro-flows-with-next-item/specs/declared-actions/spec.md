# declared-actions

## ADDED Requirements

### Requirement: A declared action may run a manual flow as a macro

A declared action MAY carry `flow` naming a published flow with a manual
trigger and `macro: true`; schema save SHALL refuse a flow that is missing,
unpublished or has no manual trigger. Invoking the action on one object
SHALL queue the flow with the object as subject, attributed to the caller,
`sync` unless the flow declares otherwise, and SHALL answer with the run id,
outcome and `next`. Invoking it on a selection SHALL queue one run per
object through the bulk write path and answer with a summary and `next`.
The action's own authorisation SHALL govern; the flow SHALL NOT widen it.

#### Scenario: a macro closes and notifies in one click

- **GIVEN** an action `close-and-notify` bound to a manual flow that sets `status` and sends a notification
- **WHEN** a handler with the action's right invokes it on a case
- **THEN** the response carries the run outcome, the case reads `closed` and the audit trail names the action and the run
- @e2e exclude {proposal only; task 4.1 adds tests/e2e/ci/macro-action.spec.ts when the host honours next}

#### Scenario: a user without the action's right cannot run the flow through it

- **GIVEN** the same action and a user without its right
- **WHEN** the user invokes it
- **THEN** the response is 403 and no run is queued
- @e2e exclude {authorisation, covered by controller unit tests}

#### Scenario: a bulk macro reports per object

- **GIVEN** a selection of three cases, one of which the flow refuses
- **WHEN** the action is invoked on the selection
- **THEN** the summary reads two succeeded, one failed with its reason, and `next` is `list`
- @e2e exclude {bulk path, covered by unit tests}
