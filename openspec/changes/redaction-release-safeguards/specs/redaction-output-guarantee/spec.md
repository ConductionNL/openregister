---
status: proposed
---

# redaction-output-guarantee

## ADDED Requirements

### Requirement: The written copy cannot be read back (REQ-RRS-001, carried from filinq REQ-RWB-01)

Every redacted copy `FileService::anonymizeDocument()` writes SHALL be verified on the bytes actually written, after every later step (sanitisers, metadata, structure preservation, any appended page), for each redacted value along seven routes: text beneath the mark, embedded thumbnail or preview streams, XMP, EXIF and document properties, incremental updates or earlier revisions, annotation and form field values, and files attached inside the document. The verdict SHALL be `clean` only when every route was examined and nothing was found, `leaking` when a value was found, and `unverifiable` when the bytes could not be examined, the file is empty, or no redacted values were supplied. The verification SHALL run on every output mode, and an output mode with no verification entry SHALL fail the suite. The verdict, the output mode and the routes checked SHALL be stored on the `AnonymisationLog` run and on the output file's metadata, and SHALL be returned by `POST /api/files/{fileId}/anonymize` as `verification`. Findings SHALL carry the route and a count, never the value, in logs and responses. `FilePublishingHandler::publishFile()` SHALL refuse to publish an anonymised output whose verdict is not `clean`.

#### Scenario: no live text under the mark
- **GIVEN** a PDF with a name redacted by the anonymise endpoint
- **WHEN** an officer downloads the written copy and its text is extracted
- **THEN** the name does not appear, and the anonymise response carried `verification.verdict` `clean` with seven routes checked

#### Scenario: an incremental update that kept the original is caught
<!-- @e2e exclude The check reads produced bytes; covered by PHPUnit RedactionIrreversibilityVerifierTest::testAnIncrementalUpdateKeepingTheValueIsLeaking with a fixture per route. -->

- **GIVEN** an output PDF whose earlier revision still holds the name
- **WHEN** it is verified
- **THEN** the verdict is `leaking`, naming the route `incremental_update` and a count of one

#### Scenario: an unreadable copy is not clean
<!-- @e2e exclude Covered by PHPUnit RedactionIrreversibilityVerifierTest::testUnparsableBytesAreUnverifiable and testNoValuesIsUnverifiable. -->

- **GIVEN** written bytes that cannot be parsed, or an empty list of redacted values
- **WHEN** they are verified
- **THEN** the verdict is `unverifiable` and `mayBePublished` is false

#### Scenario: a leaking copy cannot be published by any path
- **GIVEN** an anonymised output whose verdict is `leaking`
- **WHEN** an officer or an app publishes that file
- **THEN** publication is refused with a message naming the verdict and the routes, and the file has no public share

#### Scenario: a new output mode cannot pass by omission
<!-- @e2e exclude Suite wiring; covered by PHPUnit OutputModeCoverageTest::testEveryOutputModeHasAVerifierEntry. -->

- **GIVEN** an output mode added without a verifier entry
- **WHEN** the suite runs
- **THEN** it fails, naming the mode

### Requirement: Nothing is written until a person has checked it (REQ-RRS-002, carried from filinq REQ-RWB-02)

`EntityRelation` SHALL carry `decision` (`undecided`, `redact`, `release`; existing rows migrate to `redact` when `skipAnonymization` is false and to `release` when true, with `decidedBy` null), `decidedBy` and `decidedAt`, written through `PATCH /api/entity-relations/{id}`. A review mark SHALL be recorded by `POST /api/files/{fileId}/anonymisation/review-mark` only by a signed-in person, naming them and the moment, and SHALL be bound to the file's current detection run by a fingerprint of what the run found (the sorted set of entity type, value hash and position). When the administrator setting `anonymisation.requireReview` is on, `FileService::anonymizeDocument()` SHALL refuse with 409 and write nothing unless every detection of the current run has a decision other than `undecided` and a review mark matches the current fingerprint. The refusal SHALL be enforced in the service, so the endpoint, opencatalogi's direct call, filinq's batch path and every leaf reach the same refusal, with one message telling the operator what to do and, for a stale mark, who checked earlier and when. A re-detection that finds the same entities in another order SHALL keep the mark; one that finds something else SHALL not.

#### Scenario: the screen path is gated
- **GIVEN** `anonymisation.requireReview` on and a document whose entities were detected and not reviewed
- **WHEN** an officer asks for the anonymised output
- **THEN** the request is refused, no `_anonymized` file is written, and the message says the document must be checked first

#### Scenario: a direct service call is gated too
<!-- @e2e exclude opencatalogi's call shape; covered by PHPUnit ReviewGateTest::testADirectServiceCallIsRefusedWithoutWriting, asserting the document processing handler is never reached. -->

- **GIVEN** the setting on and an unreviewed file
- **WHEN** another app calls `FileService::anonymizeDocument()` directly, as opencatalogi's `DocumentRedactor` does
- **THEN** it is refused by the same rule and nothing is written

#### Scenario: re-detecting with a different finding needs a new check
<!-- @e2e exclude Covered by PHPUnit ReviewGateTest::testADifferentRunMakesTheMarkStale and testTheSameFindingsInAnotherOrderKeepTheMark. -->

- **GIVEN** a document checked by `j.devries` on 2026-10-01
- **WHEN** detection is re-run and finds one name more
- **THEN** output is refused again, saying it was checked by `j.devries` on 2026-10-01 before the detection changed

#### Scenario: an app token cannot check a document
<!-- @e2e exclude Covered by PHPUnit ReviewMarkControllerTest::testASystemOrTokenCallerCannotSetAMark. -->

- **GIVEN** a call without a user session, or with a machine credential
- **WHEN** it posts a review mark
- **THEN** it is refused with 403

### Requirement: Spreadsheets and slides are sanitised like documents (REQ-RRS-003)

`XlsxSanitizer` and `PptxSanitizer` SHALL implement `SanitizerInterface` and SHALL be registered in `OfficeDocumentSanitizer`. The XLSX sanitiser SHALL remove hidden and very hidden sheets, clear the cells of hidden rows and hidden columns, and remove comments, threaded comments, tracked revisions and custom XML parts. The PPTX sanitiser SHALL remove speaker notes, comments, hidden slides and custom XML parts. Each SHALL report what it found and removed in the `SanitizationReport` persisted on the `AnonymisationLog` run.

#### Scenario: a hidden sheet does not go out
- **GIVEN** an XLSX with a visible sheet and a hidden sheet `berekening-intern`, and one hidden row holding a name
- **WHEN** it is anonymised
- **THEN** the output has no `berekening-intern` sheet and no value in that row, and the run's report lists one hidden sheet and one hidden row removed

#### Scenario: speaker notes are found and reported
<!-- @e2e exclude Covered by PHPUnit PptxSanitizerTest::testSpeakerNotesAreRemovedAndReported. -->

- **GIVEN** a PPTX whose slide 3 has speaker notes
- **WHEN** it is anonymised
- **THEN** the output has no notes slide and the report counts one

### Requirement: A value inside a metadata field is masked, and unmaskable fields are named (REQ-RRS-004)

For PDF, DOCX, ODT, XLSX and PPTX, title, subject, keywords, description and text custom properties SHALL be masked with the same substitution map as the body, keeping the rest of the field. Author, last modified by, creator and comparable identity fields SHALL be stripped as today. A field that cannot be masked SHALL be removed and named in the report under `unmaskableFields` with the reason. The verifier's metadata routes SHALL run on the masked result.

#### Scenario: a title keeps its meaning without the name
- **GIVEN** a DOCX titled `Besluit op bezwaar Jan Jansen` where `Jan Jansen` is detected
- **WHEN** it is anonymised on a Dutch instance
- **THEN** the output's title reads `Besluit op bezwaar [PERSOON: 1]`

#### Scenario: a field that cannot be masked is named
<!-- @e2e exclude Covered by PHPUnit MetadataMaskTest::testANonTextCustomPropertyIsRemovedAndNamed. -->

- **GIVEN** a document with a binary custom property `scan-origineel`
- **WHEN** it is anonymised
- **THEN** the property is removed and `unmaskableFields` names `scan-origineel` with the reason

### Requirement: The unredacted original's custody is stated and its deletion scheduled (REQ-RRS-005)

`GET /api/files/{fileId}/anonymisation/custody` SHALL return the path and file id of the original and of the redacted copy, the retention applied to the original (`keep` or a duration), and the scheduled deletion date or null. The setting `anonymisation.originalRetention` SHALL default to `keep`. When it holds an ISO 8601 duration, publishing the redacted copy SHALL set the original's deletion date to the publication moment plus that duration, and a daily background job SHALL delete originals past their date, writing an audit row naming the file, the redacted copy and the rule. The job SHALL skip and report an original whose object is under a legal hold or whose archival nomination is to keep (`bewaren`). The same access check as reading the file SHALL apply to the custody endpoint.

#### Scenario: an officer reads where the original lives and when it goes
- **GIVEN** `anonymisation.originalRetention` `P90D` and a redacted copy published on 2026-10-01
- **WHEN** the officer opens the custody statement of the file
- **THEN** it names the original's path, retention `P90D` and deletion on 2026-12-30

#### Scenario: an original under a legal hold is not deleted
<!-- @e2e exclude Background job; covered by PHPUnit OriginalRetentionJobTest::testALegalHoldStopsTheDeletion through the job's run(). -->

- **GIVEN** an original past its deletion date whose object is under a legal hold
- **WHEN** the job runs
- **THEN** the original stays and the run reports it as held

#### Scenario: by default nothing is deleted
<!-- @e2e exclude Covered by PHPUnit OriginalRetentionJobTest::testKeepSchedulesNothing. -->

- **GIVEN** the default setting `keep`
- **WHEN** a redacted copy is published
- **THEN** no deletion date is set and the custody statement says the original is kept as the record
