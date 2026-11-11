---
status: proposed
---

# duplicate-detection

## ADDED Requirements

### Requirement: Every extracted file carries a text signature (REQ-NDT-001)

When `TextExtractionService` stores text chunks for a file it SHALL compute and store a MinHash signature of 128 values over word 5-shingles of the normalised text (lower-cased, whitespace folded, punctuation stripped), the shingle count and the SHA-256 of the normalised text. A file with fewer than 50 shingles SHALL get no signature and SHALL be reported as `tooShort`. A re-extraction SHALL replace the signature; deleting a file's chunks SHALL delete its signature. The signature computation SHALL be deterministic: the same text gives the same signature on every instance.

#### Scenario: a re-extracted file gets a fresh signature
<!-- @e2e exclude Covered by PHPUnit TextSignatureTest::testReExtractionReplacesTheSignature through TextExtractionService::extractFile. -->

- **GIVEN** a file with a stored signature
- **WHEN** its text changes and it is re-extracted
- **THEN** exactly one signature is stored for it and it matches the new text

#### Scenario: a cover note is not signed
<!-- @e2e exclude Covered by PHPUnit TextSignatureTest::testAShortTextIsNotSigned. -->

- **GIVEN** a file whose text has 20 words
- **WHEN** it is extracted
- **THEN** no signature is stored and the file is reported as `tooShort` in any grouping

### Requirement: Near-identical documents are grouped by their text (REQ-NDT-002)

`GET /api/files/near-duplicates` SHALL accept exactly one scope of `objectIds`, `folderId` or `fileIds`, SHALL drop every file the caller may not read before comparing, and SHALL return `groups: [{representative, members: [{fileId, similarity, identicalText}]}]` and `notCompared: [{fileId, reason}]` with reason `noText`, `tooShort` or `notExtracted`. Candidate pairs SHALL be found by banding (32 bands of 4 rows), never by comparing all pairs, and SHALL be grouped when their estimated Jaccard similarity is at or above the threshold (`duplicates.textThreshold`, default 0.9, refused outside 0.5 to 1). The representative SHALL be the earliest created file of the group. A scope of more than 5000 files SHALL be refused with 400.

#### Scenario: a forwarded mail, its reply and its print are grouped
- **GIVEN** three files on one object: `mail.eml`, `fw-mail.eml` that adds a forward header, and `mail.pdf` printed from the first, plus an unrelated `besluit.pdf`
- **WHEN** a reviewer asks for near duplicates of that object
- **THEN** one group holds the three mail files with `mail.eml` as representative and a similarity of at least 0.9 for each
- **AND** `besluit.pdf` is in no group

#### Scenario: a file the caller may not read is not revealed
<!-- @e2e exclude Covered by PHPUnit NearDuplicateEndpointTest::testAnUnreadableFileIsLeftOutBeforeComparing. -->

- **GIVEN** two near-identical files, one in an object the caller may not read
- **WHEN** the caller asks with both file ids
- **THEN** no group is returned and the unreadable file appears nowhere in the response

#### Scenario: a scan without text is said not compared
<!-- @e2e exclude Covered by PHPUnit NearDuplicateEndpointTest::testAFileWithoutTextIsNamedNotCompared. -->

- **GIVEN** a scanned PDF with no extracted text
- **WHEN** it is in the scope
- **THEN** it is listed under `notCompared` with reason `noText`, not silently absent

### Requirement: A schema can declare text similarity as a match rule (REQ-NDT-003)

`x-openregister-dedup` SHALL accept a match rule with `method: text` and no `field`, which compares two objects by the highest signature similarity between their attached files. `findDuplicates()` SHALL score such a rule like any other rule, and the existing duplicates review SHALL list the resulting pairs with `matchedOn` containing `text`.

#### Scenario: the duplicates review shows a text duplicate
<!-- @e2e exclude Covered by PHPUnit DuplicateTextRuleTest::testTwoObjectsWithNearIdenticalFilesArePaired through DuplicateController::index. -->

- **GIVEN** a schema whose dedup annotation holds `{method: text}` and two objects whose attached PDFs are near-identical
- **WHEN** an officer opens the duplicates review for that schema
- **THEN** the pair is listed with `matchedOn` `["text"]`
