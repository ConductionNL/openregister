# gdpr-data-subject-rights

## ADDED Requirements

### Requirement: Discovery also finds records through schema-declared subject id fields

`DataSubjectRequestService` discovery (subject data, the access export and erasure) SHALL, besides the PII entity index, find every object whose value in a field its schema declares under `x-openregister-processing.subjectIdFields` equals the subject id, matched exactly. When a type is given, it SHALL search only the fields declared under that type. A list-valued field SHALL match when it contains the id.

#### Scenario: a learner's enrolments are in the access export

- **GIVEN** a schema `inschrijving` with `x-openregister-processing.subjectIdFields: {"learnerId": "learnerId"}` and three enrolments whose `learnerId` is `5b1c…`, none of them with a detected entity
- **WHEN** `assembleAccessExport("5b1c…", "learnerId")` is called by a handler who may read them
- **THEN** the bundle contains the three enrolments
- **AND** each records `subjectFields` with type `learnerId` and field `learnerId` as the reason for inclusion
- @e2e exclude {service consumed by apps; task 2.1 adds the unit test, task 2.3 the Newman case}

#### Scenario: no fuzzy match on an id

- **GIVEN** an enrolment whose `learnerId` is `5b1c-aaaa`
- **WHEN** discovery runs for `5b1c` in `ilike` mode
- **THEN** that enrolment is not returned through its declared field
- @e2e exclude {matching rule; covered by a unit test}

### Requirement: Each discovered object records what included it

Results from the entity index and from the declared subject id fields SHALL be merged per object, so an object found by both is listed once, and each object SHALL record what included it: its detected entities, its declared subject fields, or both.

#### Scenario: an object found by both sources is listed once with both reasons

- **GIVEN** an enrolment whose `learnerId` is the subject id and whose text holds a detected entity of the same subject
- **WHEN** the subject's data is discovered
- **THEN** the enrolment is listed once
- **AND** it carries both `gdprEntities` and `subjectFields`
- @e2e exclude {merge rule; covered by a unit test}

### Requirement: Declared-field results are scoped and held like entity-index results

RBAC, tenant scoping and legal holds SHALL apply to objects found through a declared subject id field exactly as to objects from the entity index.

#### Scenario: an erasure reaches records keyed on the id

- **GIVEN** the same enrolments, one of them under a legal hold
- **WHEN** `erase()` runs for the subject in pseudonymise mode
- **THEN** the two enrolments without a hold are pseudonymised through the audited write path
- **AND** the held one is reported as held and left unchanged
- @e2e exclude {service consumed by apps; covered by a unit test}

#### Scenario: a record the handler may not read stays out

- **GIVEN** an enrolment keyed on the subject in an organisation the handler does not belong to
- **WHEN** the handler requests the subject's data
- **THEN** that enrolment is not returned
- @e2e exclude {authorisation; covered by a unit test with a real tenant fixture}
