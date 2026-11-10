# Tasks: survey-object

## 1. The schemas

- [ ] 1.1 Add `Survey`, `SurveyQuestion`, `SurveyInvitation` and `SurveyAnswerSet` to the register fragments.
- [ ] 1.2 Version a survey on edit once it has answers, and keep the version on each answer set.
- [ ] 1.3 PHPUnit on the version bump and on editing a survey with no answers.

## 2. Answering without an account

- [ ] 2.1 Mint the invitation token over `access-by-link-not-by-account`, with an expiry.
- [ ] 2.2 Render the survey and accept answers with no login.
- [ ] 2.3 Refuse a used token unless the survey allows reopening, and refuse an expired one.
- [ ] 2.4 PHPUnit on all three refusals, with a frozen clock for the expiry.

## 3. The answer set

- [ ] 3.1 Link the answer set to its subject object and to the survey version.
- [ ] 3.2 Refuse a submission missing a required answer, naming the question.
- [ ] 3.3 PHPUnit on the link and on the refusal.

## 4. Automation

- [ ] 4.1 Add the invitation action to the rules engine, with an optional delay.
- [ ] 4.2 Add the fatigue rule: period, maximum, evaluated per respondent across the instance.
- [ ] 4.3 Record a blocked invitation with its reason rather than skipping it.
- [ ] 4.4 PHPUnit on the delay and on the fatigue block.

## 5. Access and anonymity

- [ ] 5.1 Declare anonymity at creation and refuse a later change.
- [ ] 5.2 Hold no respondent reference on an anonymous survey's answer sets.
- [ ] 5.3 Withhold anonymous results below the configurable minimum, showing the count and the reason.
- [ ] 5.4 Apply the survey's reader roles to answer sets.
- [ ] 5.5 PHPUnit on the refusal, the withholding and the role check, probing with a reader who must be refused.

## 6. The export

- [ ] 6.1 Export answer sets as rows with questions as columns, through the existing export path.
- [ ] 6.2 Omit the respondent column entirely on an anonymous survey, and refuse below the minimum.
- [ ] 6.3 PHPUnit on both export shapes.

## 7. Handover

- [ ] 7.1 Give the pipelinq lane the object shape `customer-satisfaction-closed-loop` runs its campaign on, and agree what pipelinq owns of the reminder schedule.
- [ ] 7.2 Give the dossiq lane the one declaration it needs: which case types fire a survey on closure. Row 6.16 records the rest as a deliberate no.
- [ ] 7.3 Give the portaliq lane the rendering half, under decision D5, and check it against cluster 51.
- [ ] 7.4 Add this change to the openregister umbrella index and tick it there when it archives.
