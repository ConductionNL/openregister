# survey-object Specification

**Status**: proposed
**Scope**: openregister
**OpenSpec changes**:
- survey-object

## Purpose

A satisfaction survey as an object: its questions, who may answer it, when
it is sent, what came back, who may read it and how it exports. Sits on the
object model, RBAC, the rules engine, `access-by-link-not-by-account` and
`export-as-its-own-right`. Requested by the dossiq competitor programme,
discovery cluster 67, decision D5.

## ADDED Requirements

### Requirement: REQ-SURV-001 A survey is its own object with its own questions

The system SHALL define a `Survey` schema with a title, an introduction,
the object type it asks about, an anonymity setting and a version, and a
`SurveyQuestion` schema with a question text, a kind (`scale`, `choice`,
`text`), an order and, for `choice`, its options. A survey SHALL be
editable while it has no answers. Editing a survey that has answers SHALL
raise its version, and an existing answer set SHALL keep naming the version
it answered.

Candidate C-reporting-8, `should`, one documented passer
(jira-service-management). Documented, never counted in a driven tally
(D21).

#### Scenario: A survey is composed before it is sent

- **GIVEN** an administrator creating a survey about closed cases
- **WHEN** they add a scale question, a choice question and a free text question
- **THEN** the survey holds three questions in that order

#### Scenario: Editing a survey with answers raises its version

- **GIVEN** a survey at version 1 with twelve answer sets
- **WHEN** a question's text is changed
- **THEN** the survey becomes version 2
- **AND** the twelve answer sets still name version 1

### Requirement: REQ-SURV-002 An invitation is answerable without an account

The system SHALL define a `SurveyInvitation` binding a survey, a subject
object and a respondent address, carrying a signed token, an expiry and a
state (`sent`, `answered`, `expired`, `blocked`). Following the token SHALL
render the survey and accept answers without a login. A token SHALL be
single use unless the survey allows reopening, and SHALL be refused after
its expiry.

Candidate C-reporting-31, `should`, three driven passers (glpi, huly,
odoo). Decision D5.

#### Scenario: A requester with no account answers

- **GIVEN** an invitation sent to a citizen's e-mail address
- **WHEN** the citizen follows the link
- **THEN** the survey renders and the answers are accepted, with no account created

#### Scenario: A used token is refused

- **GIVEN** an answered invitation on a survey that does not allow reopening
- **WHEN** the link is followed again
- **THEN** the request is refused and says the survey was already answered

#### Scenario: An expired token is refused

- **GIVEN** an invitation whose expiry has passed
- **WHEN** the link is followed
- **THEN** the request is refused and says the invitation expired

### Requirement: REQ-SURV-003 The answers are an object linked to the subject

The system SHALL define a `SurveyAnswerSet` carrying the survey, the survey
version, the subject object, the answers per question and the time it was
submitted. An answer set SHALL be readable through the ordinary object
access rules. A partial submission SHALL be refused unless the survey marks
the missing questions optional.

Candidates C-reporting-8 and C-reporting-31.

#### Scenario: The answers are linked to the case they are about

- **GIVEN** an invitation about a closed case
- **WHEN** the respondent submits
- **THEN** an answer set exists, linked to that case, naming the survey version

#### Scenario: A missing required answer is refused

- **GIVEN** a survey with a required scale question
- **WHEN** a submission omits it
- **THEN** the submission is refused and names the question

### Requirement: REQ-SURV-004 An invitation is fired by a rule, with a delay and a fatigue limit

The rules engine SHALL offer an action that creates a survey invitation on
an event, with an optional delay. A fatigue rule SHALL hold a period and a
maximum, SHALL be evaluated per respondent across every survey in the
instance, and SHALL block an invitation that would exceed it. A blocked
invitation SHALL be recorded with state `blocked` and its reason, never
silently skipped.

Candidate C-reporting-8, the automation half.

#### Scenario: A survey goes out three days after a case closes

- **GIVEN** a rule firing the survey action on case closure with a three day delay
- **WHEN** a case closes
- **THEN** an invitation is created three days later

#### Scenario: An over-surveyed person is not asked again

- **GIVEN** a fatigue rule of one survey per 90 days and a respondent surveyed last month
- **WHEN** a rule would invite them again
- **THEN** an invitation is recorded as `blocked` with the fatigue reason, and nothing is sent

### Requirement: REQ-SURV-005 Who may read the answers, and whether they are named

A survey SHALL declare whether it is anonymous or attributed at creation,
and the setting SHALL NOT be changeable afterwards. An anonymous survey's
answer sets SHALL carry no respondent reference. An anonymous survey's
answers SHALL NOT be shown or exported until a configurable minimum number
of responses is reached, and below it the system SHALL show the count and
say why the answers are withheld. Read access to answer sets SHALL follow
the survey's declared reader roles.

Candidate C-reporting-8, the access half.

#### Scenario: Anonymity cannot be switched on afterwards

- **GIVEN** an attributed survey with answers
- **WHEN** an administrator tries to make it anonymous
- **THEN** the change is refused

#### Scenario: Two answers on an anonymous survey are withheld

- **GIVEN** an anonymous survey with a minimum of five and two answers
- **WHEN** a reader opens the results
- **THEN** the count is shown, the answers are not, and the reason is given

### Requirement: REQ-SURV-006 Answers export as rows

Survey answers SHALL be exportable through the existing export path, one
row per answer set and one column per question, with the survey version on
each row. An anonymous survey's export SHALL contain no respondent column
at all, and SHALL be refused below the minimum response count.

Candidate C-reporting-8, the export half.

#### Scenario: An attributed survey exports its respondents

- **GIVEN** an attributed survey with forty answer sets
- **WHEN** it is exported
- **THEN** each row carries the respondent, the version and one column per question

#### Scenario: An anonymous export has no respondent column

- **GIVEN** an anonymous survey above its minimum
- **WHEN** it is exported
- **THEN** the file has no respondent column, not an empty one
