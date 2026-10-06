---
status: proposed
---

# anonymisation-disclosure

## ADDED Requirements

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
