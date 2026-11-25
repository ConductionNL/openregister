# Tasks: survey-object

## 1. The schemas

- [x] 1.1 Add `Survey`, `SurveyQuestion`, `SurveyInvitation` and `SurveyAnswerSet` to the register fragments.
- [x] 1.2 Version a survey on edit once it has answers, and keep the version on each answer set.
  - already on main: SurveyWriteListener raises the version and keeps it on an updated answer set (SurveyWriteListenerTest)
- [x] 1.3 PHPUnit on the version bump and on editing a survey with no answers.

## 2. Answering without an account

- [x] 2.1 Mint the invitation token over `access-by-link-not-by-account`, with an expiry.
  - `SurveyInvitations::invite()` mints a read link over the invitation object (`ObjectAccessLinksInterface`, a Kernel contract over the sharing service) and writes the invitation once, with `token` = the link's uuid (never its anchor) and `expiresAt`. The link outlives the invitation by `LINK_GRACE_DAYS` so a late visitor reads "expired" rather than a bare 404.
- [x] 2.2 Render the survey and accept answers with no login.
  - `GET`/`POST /api/public/surveys/{anchor}` (`SurveyAnswerController`, public, rate limited, brute-force counted): the form (survey and questions in order, public fields only) and the submission, written through the ordinary save path so `SurveyWriteListener` refuses a missing required answer and scrubs an anonymous respondent. The rendered page is the portaliq half (7.3).
- [x] 2.3 Refuse a used token unless the survey allows reopening, and refuse an expired one.
  - 410 with `SurveyRules::refuseInvitation()`'s reason (expired, already answered, never sent); 404 for a link that opens no invitation.
- [x] 2.4 PHPUnit on all three refusals, with a frozen clock for the expiry.
  - `SurveyAnsweringTest` (frozen `ITimeFactory`: expired a second ago, already answered, never sent; reopening), `SurveyAnswerControllerTest`, `ObjectAccessLinksTest`.

## 3. The answer set

- [x] 3.1 Link the answer set to its subject object and to the survey version.
- [x] 3.2 Refuse a submission missing a required answer, naming the question.
- [x] 3.3 PHPUnit on the link and on the refusal.

## 4. Automation

- [x] 4.1 Add the invitation action to the rules engine, with an optional delay.
  - Flow step `openregister.survey-invite` (`SurveyInviteNode`, messaging palette): `register`, `survey`, `respondent` (template), `subject` (defaults to the item's object), `expiresIn` (default 30 days), `delay`. A delay suspends the run like the Wait step and the engine resumes it; no scheduler of its own. The outcome (id, state, blocked, reason, answering `url`) goes on the item under `surveyInvitation`.
- [x] 4.2 Add the fatigue rule: period, maximum, evaluated per respondent across the instance.
  - App config `survey_fatigue_maximum` (0 = no limit) and `survey_fatigue_period_days` (default 90); counted over every invitation to the respondent in the survey register, whichever survey sent it, blocked ones excluded (`SurveyInvitations::recentInvitations()`).
- [x] 4.3 Record a blocked invitation with its reason rather than skipping it.
  - Written with state `blocked` and `blockedReason`, no link minted.
- [x] 4.4 PHPUnit on the delay and on the fatigue block.
  - `SurveyInviteNodeTest` (the delay suspends, the resume invites), `SurveyInvitationsTest` (the fatigue block with a frozen clock).

## 5. Access and anonymity

- [x] 5.1 Declare anonymity at creation and refuse a later change.
- [x] 5.2 Hold no respondent reference on an anonymous survey's answer sets.
- [x] 5.3 Withhold anonymous results below the configurable minimum, showing the count and the reason.
  - `GET /api/surveys/{register}/{survey}/results` (SurveyResultsController over SurveyResults and SurveyRules::disclosure)
- [ ] 5.4 Apply the survey's reader roles to answer sets.
  - Open: the results and export routes already enforce `readerRoles`. Doing the same for the plain object API means an object-level authorization block on every answer set, and object-level blocks are not honoured by the list paths today (see the slot notes in `grants-that-follow-a-slot-a-relation-or-a-reason`). Stamping one would make a read and a list disagree, so this needs a decision on how a schema delegates reads to a parent record.
- [x] 5.5 PHPUnit on the refusal, the withholding and the role check, probing with a reader who must be refused.
  - SurveyResultsTest, SurveyResultsControllerTest; the anonymity-change refusal in SurveyWriteListenerTest

## 6. The export

- [x] 6.1 Export answer sets as rows with questions as columns, through the existing export path.
  - `GET /api/surveys/{register}/{survey}/export` (CSV): the existing SurveyExportShaper headers and rows, one column per question
- [x] 6.2 Omit the respondent column entirely on an anonymous survey, and refuse below the minimum.
  - refused with 403, the count and the reason
- [x] 6.3 PHPUnit on both export shapes.
  - SurveyResultsTest (attributed and anonymous tables, the refusal), SurveyResultsControllerTest (the CSV)

## 7. Handover

- [ ] 7.1 Give the pipelinq lane the object shape `customer-satisfaction-closed-loop` runs its campaign on, and agree what pipelinq owns of the reminder schedule.
- [ ] 7.2 Give the dossiq lane the one declaration it needs: which case types fire a survey on closure. Row 6.16 records the rest as a deliberate no.
- [ ] 7.3 Give the portaliq lane the rendering half, under decision D5, and check it against cluster 51.
- [ ] 7.4 Add this change to the openregister umbrella index and tick it there when it archives.
