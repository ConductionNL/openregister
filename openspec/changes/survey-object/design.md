# Design: survey-object

## Context

Openregister already has schemas, objects, RBAC, an export path, the rules
engine and access by link rather than by account. A survey needs no new
machinery, only four schemas and one rule action. Every decision below
names what it reuses.

## Decisions

### A survey is four schemas, not one

`Survey` holds the definition. `SurveyQuestion` holds a question in an
order. `SurveyInvitation` binds a survey to a subject and a respondent.
`SurveyAnswerSet` holds what came back. Keeping questions separate lets a
survey be edited without rewriting every answer's shape, and keeping the
invitation separate is what makes "who was asked and never answered"
answerable.

Storing the answers inside the invitation was rejected: an invitation that
is resent would then carry two answer sets in one object.

### Answering needs no account

An invitation carries a signed token with an expiry. The respondent follows
it, answers, and is done. This is decision D5 as answered, and it reuses
`access-by-link-not-by-account` rather than inventing a second token
scheme.

A token is single use by default. Reopening an answered invitation is a
deliberate setting, because a survey that can be answered twice by the same
link cannot be reported on.

### Fatigue is a rule on the respondent, not on the survey

A fatigue rule holds a period and a maximum. It is evaluated per respondent
across every survey in the instance, not per survey, because the person who
is over-surveyed does not care which survey did it. An invitation blocked
by fatigue is recorded as blocked rather than not created, so the gap in
the response data has a reason.

### Automation is a rules-engine action

Firing an invitation is an action the existing rules engine calls on an
event, with a delay. No scheduler of its own. The case app declares the
event, this change declares the action.

### Identity is a property of the survey, decided once

A survey is anonymous or attributed, and the choice is made when it is
created and cannot be changed afterwards. Changing it later would either
reveal respondents who answered on an anonymous promise, or hide answers
somebody already acted on by name.

### The export is the existing export

Answers export as rows with questions as columns, through
`export-as-its-own-right`. An anonymous survey exports no respondent
column at all, rather than an empty one, because an empty column invites a
join that would defeat the promise.

## Risks

- **An anonymous survey with three responses identifies its respondents.**
  The spec requires a minimum response count before an anonymous survey's
  answers are shown or exported, and says what is shown below it.
- **A question is edited after answers exist.** Questions are versioned
  with the survey, and an answer set names the version it answered.
- **A token leaks and is forwarded.** Single use by default, with an
  expiry, and the invitation records when and from where it was used.

## Open questions

- Whether the fatigue period is per instance or per survey group. The spec
  requires an instance-wide evaluation and leaves grouping open.
- What the minimum response count for an anonymous survey should be. The
  spec requires a configurable threshold with a safe default.
