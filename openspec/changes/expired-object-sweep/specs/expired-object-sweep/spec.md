# expired-object-sweep Specification (delta)

## ADDED Requirements

### Requirement: A schema opts in to expiry and says what it means (REQ-EXP-001)

A schema MAY declare `x-openregister.expiry` with an `action` of `delete` or `anonymise`, the properties an anonymisation keeps, and optionally a property whose value overrides the action per object. OpenRegister MUST refuse a schema save with an unknown action or a kept property the schema does not have. For an opted-in schema, `@self.expires` on create or update MUST be stored as the object's expiry, and only a caller who may update the object MUST be able to set it.

#### Scenario: portaliq sets an expiry on a submission
- **WHEN** portaliq saves a completed submission with `@self.expires` 30 days ahead on the opted-in `portalIntakeSubmission` schema
- **THEN** the object's expiry holds that date
- @e2e exclude backend save path; covered by PHPUnit

#### Scenario: a schema with a typo in its expiry
- **WHEN** a schema is saved with `expiry.action` `remove`
- **THEN** the save is refused naming the allowed actions
- @e2e exclude backend validation; covered by PHPUnit

### Requirement: A daily sweep deletes or anonymises expired objects (REQ-EXP-002)

OpenRegister MUST run a daily job that, for every opted-in schema, deletes or anonymises each object whose expiry has passed, according to the object's action. An anonymisation MUST empty every property outside the kept list, MUST remove the object's files and MUST clear the expiry. The job MUST skip an object under an active legal hold. Each action MUST write one audit entry, and each run MUST log per schema how many objects were deleted, anonymised, held and left for the next run.

#### Scenario: a failed submission is anonymised after 90 days
- **WHEN** the sweep runs and a failed submission with action `anonymise` expired yesterday
- **THEN** its answers are empty, its files are gone, its reference, binding, state and dates remain, and an audit entry `expiry.anonymised` is written
- @e2e exclude background job; covered by a PHPUnit DB test

#### Scenario: an unfinished draft is deleted after 30 days
- **WHEN** the sweep runs and a draft with action `delete` expired
- **THEN** the object is deleted and an audit entry `expiry.deleted` is written
- @e2e exclude background job; covered by a PHPUnit DB test

#### Scenario: an object under a legal hold is kept
- **WHEN** the sweep meets an expired object under an active legal hold
- **THEN** the object is untouched and counted as held
- @e2e exclude background job; covered by PHPUnit

#### Scenario: a schema that did not opt in
- **WHEN** the sweep runs and an object of a schema without `expiry` has a past `expires`
- **THEN** the sweep does not touch it
- @e2e exclude background job; covered by PHPUnit

### Requirement: An administrator can see what the next sweep would do (REQ-EXP-003)

OpenRegister MUST offer a dry run that reports, per opted-in schema, how many objects the next sweep would delete, anonymise and skip for a hold, without changing anything.

#### Scenario: a dry run before switching the sweep on
- **WHEN** an administrator runs `occ openregister:expiry:sweep --dry-run`
- **THEN** the counts per schema are printed and no object changes
- @e2e exclude admin command; covered by PHPUnit
