---
status: proposed
---

# anonymisation-disclosure

## ADDED Requirements

### Requirement: Every detection backend reports what it is built from (REQ-ADI-001)

Each detection backend (pattern, Presidio, OpenAnonymiser internal and external, LLM) SHALL report a version record with `engine`, `engineVersion`, `model`, `modelVersion`, `patternSetVersion`, `datasets` (a list of named datasets with versions) and `changedAt`. A value the backend does not expose SHALL be reported as the string `unknown`, never omitted. `GET /api/admin/anonymisation/backend-state` SHALL return the record for every configured backend, and every `AnonymisationLog` run SHALL store the record of the backend that ran.

#### Scenario: the pattern set names its version
<!-- @e2e exclude Admin API payload; covered by PHPUnit AnonymisationBackendServiceVersionTest::testThePatternSetReportsItsVersionAndChangeDate. -->

- **GIVEN** the regex backend with `NlPatternSet`
- **WHEN** an administrator reads backend-state
- **THEN** the regex backend carries `patternSetVersion` equal to `NlPatternSet::VERSION` and `changedAt` equal to `NlPatternSet::CHANGED_AT`

#### Scenario: an external backend that hides its model says unknown
<!-- @e2e exclude Needs an external backend double; covered by PHPUnit AnonymisationBackendServiceVersionTest::testAnUnexposedModelIsReportedAsUnknown. -->

- **GIVEN** an external OpenAnonymiser endpoint whose probe returns no model version
- **WHEN** an administrator reads backend-state
- **THEN** `modelVersion` is `unknown` and the key is present

#### Scenario: a run records the detector it used
<!-- @e2e exclude Stored log field; covered by PHPUnit AnonymisationLogVersionTest::testARunStoresTheBackendVersionRecord. -->

- **GIVEN** a file anonymised through the regex backend
- **WHEN** its `AnonymisationLog` row is read
- **THEN** the row carries the version record the backend reported at that moment

### Requirement: Precision and recall are measured over a named corpus and published with the detector version (REQ-ADI-002)

OpenRegister SHALL ship a synthetic Dutch evaluation corpus with gold annotations, containing no real personal data, under a versioned name. `occ openregister:anonymisation:evaluate` SHALL run the active backend over it and store precision, recall and support per entity type with the corpus name, the corpus version, the detector version record and the moment. Backend-state and the file configuration page SHALL show the latest result for the current detector version. When the stored result was measured on another detector version, the page SHALL say the current version is not measured and SHALL NOT show the old figures as current.

#### Scenario: an administrator reads the measured figures
- **GIVEN** an evaluation run over corpus `nl-woo-synthetic` version 1 with the current pattern set
- **WHEN** the administrator opens the file configuration page
- **THEN** precision and recall per entity type are shown with the corpus name, its version, the detector version and the date

#### Scenario: a changed detector is not described by old figures
<!-- @e2e exclude Version comparison; covered by PHPUnit DetectorEvaluationServiceTest::testFiguresForAnotherDetectorVersionAreNotCurrent. -->

- **GIVEN** a stored evaluation for pattern-set version 1
- **WHEN** the pattern set is version 2 and backend-state is read
- **THEN** the evaluation is reported with `current: false` and the page says version 2 is not measured

### Requirement: Training use and retention are stated per backend (REQ-ADI-003)

An administrator SHALL set, per configured backend, `trainingUse` (`excluded`, `permitted` or `unknown`) and `retention` (`none`, an ISO 8601 duration, or `unknown`). The internal OpenAnonymiser ExApp and the regex backend SHALL be declared `excluded` and `none` by the product and SHALL NOT be editable. An external backend SHALL default to `unknown` for both. Backend-state SHALL return both statements per backend, and a change to either SHALL be written to the audit trail.

#### Scenario: the internal ExApp states the exclusion
<!-- @e2e exclude Admin API payload; covered by PHPUnit BackendStatementTest::testTheInternalExAppIsDeclaredExcluded. -->

- **GIVEN** the internal OpenAnonymiser ExApp is the active backend
- **WHEN** backend-state is read
- **THEN** it reports `trainingUse: excluded` and `retention: none` and marks both as product-declared

#### Scenario: an external endpoint is unknown until someone states it
- **GIVEN** a Presidio endpoint configured with no statement
- **WHEN** the administrator opens the file configuration page
- **THEN** the page shows training use and retention as unknown, and the administrator can set both

### Requirement: The detector says when it last changed (REQ-ADI-004)

OpenRegister SHALL keep a history of detector version records per backend. When a probe or a run reports a version record that differs from the last stored one, a new history entry SHALL be stored with the moment it was first seen. Backend-state SHALL return `changedAt` for the current record and the history on request.

#### Scenario: a model upgrade is noticed
<!-- @e2e exclude Probe history over time; covered by PHPUnit DetectorVersionHistoryTest::testANewModelVersionStartsAHistoryEntry. -->

- **GIVEN** a stored record with `modelVersion` `1.4`
- **WHEN** the next probe reports `1.5`
- **THEN** a history entry for `1.5` is stored with the probe moment and backend-state reports that moment as `changedAt`

### Requirement: Every external call that carries a document's text is recorded per document (REQ-ADI-005)

Every call that sends a document's text, or a part of it, to a service outside the Nextcloud instance (Presidio, an external OpenAnonymiser, Dolphin, an LLM) SHALL write a disclosure record with the file id, the service, the host, the moment and the byte count, before the response is used. `GET /api/files/{fileId}/anonymisation/disclosures` SHALL list them for a caller who may read the file. A call whose disclosure record cannot be written SHALL NOT be made.

#### Scenario: an officer sees where the text went
- **GIVEN** a file detected through an external Presidio endpoint
- **WHEN** the officer opens the file's anonymisation details
- **THEN** one disclosure names Presidio, its host, the moment and the byte count

#### Scenario: an unrecordable disclosure stops the call
<!-- @e2e exclude Failure injection on the record store; covered by PHPUnit TextDisclosureRecorderTest::testAFailingRecordStopsTheExternalCall. -->

- **GIVEN** a disclosure store that refuses the write
- **WHEN** detection would call an external service
- **THEN** the call is not made and the detection fails with a message naming the disclosure record

### Requirement: Every content-altering step reports its state (REQ-ADI-006)

`GET /api/admin/anonymisation/pipeline` SHALL list every content-altering step: the anonymiser, the PDF text replacer, the PDF metadata sanitiser, the DOCX sanitiser, the ODT sanitiser, and every sanitiser added later. Each step SHALL report `on`, `off` or `always-on`, and a step that is off SHALL say which setting turned it off. Every `AnonymisationLog` run SHALL record for each step `ran`, `skipped` with the reason, or `not-applicable` for the file type. The file configuration page SHALL show the pipeline list. A step registered without a report entry SHALL fail the unit suite.

#### Scenario: an operator sees a sanitiser that is off
- **GIVEN** the PDF metadata sanitiser switched off
- **WHEN** the operator opens the file configuration page
- **THEN** the pipeline list shows the PDF metadata sanitiser as off, naming the setting

#### Scenario: a run says which steps ran
<!-- @e2e exclude Stored run field; covered by PHPUnit PipelineStepReportTest::testARunRecordsEveryStepOutcome. -->

- **GIVEN** a DOCX file anonymised with every step on
- **WHEN** its `AnonymisationLog` row is read
- **THEN** the anonymiser and the DOCX sanitiser are `ran`, and the PDF steps are `not-applicable`

#### Scenario: a new step cannot hide
<!-- @e2e exclude Suite wiring; covered by PHPUnit PipelineStepReportTest::testEveryRegisteredStepHasAReportEntry. -->

- **GIVEN** a sanitiser registered with no report entry
- **WHEN** the unit suite runs
- **THEN** it fails, naming the step

### Requirement: The confidence threshold is an organisation setting (REQ-ADI-007)

The minimum confidence for a detection SHALL be the file setting `anonymisation.confidenceThreshold`, a number between 0 and 1, default 0.5. Every detection path SHALL read it at request time instead of a literal. `GET /api/settings/files` SHALL return it and backend-state SHALL report it. A value outside 0 to 1 SHALL be refused with 400.

#### Scenario: a raised threshold drops weak findings
<!-- @e2e exclude Detection over a fixture; covered by PHPUnit EntityRecognitionThresholdTest::testTheSettingReplacesTheLiteral. -->

- **GIVEN** `anonymisation.confidenceThreshold` set to 0.8
- **WHEN** a text with a 0.6 and a 0.9 finding is detected
- **THEN** only the 0.9 finding is stored
