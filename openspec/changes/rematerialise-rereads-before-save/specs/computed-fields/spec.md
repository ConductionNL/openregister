## ADDED Requirements

### Requirement: Rematerialising saves the row as it is stored at the moment of the save

The rematerialise command SHALL read a changed row again just before it saves it, SHALL NOT save data from its first read of the table over a row that changed since, and SHALL count a row whose expected values an earlier save in the same run already materialised as touched, without saving it and without a failure. A run that leaves every value correct SHALL exit zero.

#### Scenario: a sibling's save already materialised the row

- **GIVEN** two learniq enrolments of one course, both with an empty `completedLessonCount` declared readOnly, and saving one re-saves the other
- **WHEN** `occ openregister:rematerialise-calculations learniq enrolment` runs
- **THEN** the second row is not saved again, no "Cannot modify readOnly properties" is reported, the report says failed 0 and the command exits 0
- @e2e exclude {occ command; covered by RematerialiseStaleSnapshotTest}
