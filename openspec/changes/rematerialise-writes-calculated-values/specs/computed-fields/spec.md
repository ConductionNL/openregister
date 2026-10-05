## ADDED Requirements

### Requirement: Rematerialising writes changed calculated values through the save path

The rematerialise command SHALL write a row whose materialised calculation changed by re-saving the row's stored data through the normal save path, which materialises the calculations, and SHALL NOT write calculated values into the payload itself (they are declared readOnly). After the save it SHALL check that each changed value is on the saved row and SHALL count the row as failed when it is not. A row with an aggregate-reference that could not be resolved SHALL be counted as failed, not as unchanged, and the command SHALL then exit non-zero.

#### Scenario: a session whose isPast changed is written

- **GIVEN** a learniq session whose stored `isPast` is empty and whose calculation now gives `true`, with `isPast` declared readOnly
- **WHEN** `occ openregister:rematerialise-calculations learniq session` runs
- **THEN** the row is saved without "Cannot modify readOnly properties" and the report says touched 1, failed 0
- @e2e exclude {occ command; covered by RematerialiseWritesCalculatedValuesTest}

#### Scenario: an aggregate that cannot be resolved is a failure

- **GIVEN** an enrolment with an aggregate-reference over `lesson-completion` that cannot be resolved
- **WHEN** the command runs
- **THEN** the row is reported failed with the reason and the command exits non-zero
- @e2e exclude {occ command; covered by RematerialiseWritesCalculatedValuesTest}

### Requirement: Aggregate-references resolve regardless of who saves

A materialised aggregate-reference SHALL be resolved as the system, whoever saves the object and also with no session (occ, cron), the same rule cross-object references follow.

#### Scenario: no session

- **GIVEN** an enrolment whose `completed` counts `lesson-completion` rows
- **WHEN** it is recomputed under occ, where there is no session user
- **THEN** the count is resolved instead of refused with "You do not have permission to aggregate"
- @e2e exclude {resolution path; covered by RematerialiseWritesCalculatedValuesTest}
