---
status: proposed
---

# file-actions

## ADDED Requirements

### Requirement: Text extraction has a stated ceiling and a file above it stays findable (REQ-LFH-001)

The setting `textExtraction.maxBytes` (default 104857600, the 100 MB of today's unread `maxFileSizeMB`) SHALL be returned by `GET /api/settings/files` and shown on the file configuration page. `TextExtractionService::extractFile()` SHALL compare the file size to it before reading content. A file above it SHALL NOT be read; it SHALL get the metadata chunk only, an extraction status `skipped-too-large` with the ceiling that applied, and SHALL remain findable through file search by name and by its object's metadata. Every search hit and every `GET /api/files/{fileId}/extraction-status` response for such a file SHALL carry `textSearched: false`.

#### Scenario: a large scan is findable by its name, and says it was not text-searched
- **GIVEN** `textExtraction.maxBytes` of 50 MB and a 300 MB PDF `raadsdossier-2024.pdf` attached to an object
- **WHEN** extraction runs and an officer searches files for `raadsdossier`
- **THEN** the file is in the results with `textSearched: false`
- **AND** its extraction status reads `skipped-too-large` with the 50 MB ceiling

#### Scenario: the ceiling is stated
<!-- @e2e exclude Settings read; covered by PHPUnit FileSettingsCeilingTest::testTheCeilingIsReturnedAndMigrated. -->

- **GIVEN** an instance upgraded with `maxFileSizeMB` 100
- **WHEN** a caller reads `GET /api/settings/files`
- **THEN** the body holds `textExtraction.maxBytes` 104857600, and `maxFileSize` and `maxFileSizeMB` are gone

#### Scenario: content of a file over the ceiling is never read
<!-- @e2e exclude Covered by PHPUnit ExtractionCeilingTest::testAFileOverTheCeilingIsNotOpened with a File double whose getContent fails the test. -->

- **GIVEN** a file one byte over the ceiling
- **WHEN** `extractFile()` runs
- **THEN** the file's content is not opened and no text chunk is written

### Requirement: A large file uploads in parts with stated boundaries (REQ-LFH-002)

`POST /api/objects/{register}/{schema}/{id}/uploads` with `{name, size, sha256}` SHALL open an upload session after the same object access check as a file upload, and SHALL answer `{uploadId, partSize, partCount, parts: [{n, start, end}], expiresAt}`, with `partSize` from `upload.partSizeBytes` (default 10485760). `PUT .../uploads/{uploadId}/parts/{n}` SHALL store part n and SHALL refuse with 400 a part whose byte length differs from its stated range. A session SHALL be usable only by the user who opened it. Parts SHALL be stored in the app's data store, not in the object's folder.

#### Scenario: an integrator uploads a 95 MB mail export in parts
- **GIVEN** an object the integrator may update and `upload.partSizeBytes` 10 MiB
- **WHEN** it opens a session for a 95 MB file
- **THEN** the answer states ten parts with their byte ranges, the last one shorter
- **AND** ten `PUT` requests with those ranges are each accepted

#### Scenario: a wrongly sized part is refused
<!-- @e2e exclude Covered by PHPUnit ChunkedUploadServiceTest::testAPartOfTheWrongLengthIsRefused. -->

- **GIVEN** an open session whose part 3 spans 10485760 bytes
- **WHEN** a part 3 of 9000000 bytes is sent
- **THEN** the response is 400 naming the expected length and nothing is stored

#### Scenario: another user cannot write into the session
<!-- @e2e exclude Covered by PHPUnit ChunkedUploadControllerTest::testAnotherUserGets404OnTheSession. -->

- **GIVEN** a session opened by user A
- **WHEN** user B sends a part for it
- **THEN** the response is 404 and nothing is stored

### Requirement: An interrupted upload resumes and states its completeness (REQ-LFH-003)

`GET .../uploads/{uploadId}` SHALL return `received`, `missing` and `complete`. `POST .../uploads/{uploadId}/complete` SHALL refuse with 409, naming the missing parts or the mismatch, when any part is missing, the assembled size differs from the declared size or the SHA-256 differs; it SHALL then create no file. On success it SHALL run the shared upload checks on the assembled bytes, create the file through the same path as `files#create`, delete the parts and return the file. An unfinished session SHALL be removed after `upload.sessionTtl` (default `PT24H`) by a background job.

#### Scenario: a broken connection resumes from the last received part
- **GIVEN** a ten part upload whose connection broke after part 6
- **WHEN** the client reads the session
- **THEN** it reads parts 1 to 6 received, 7 to 10 missing and `complete: false`
- **AND** after sending 7 to 10 and completing, the file is on the object with the declared SHA-256

#### Scenario: a mismatched checksum creates nothing
<!-- @e2e exclude Covered by PHPUnit ChunkedUploadServiceTest::testAChecksumMismatchCreatesNoFile. -->

- **GIVEN** all parts received but one part's bytes altered in transit
- **WHEN** the client completes
- **THEN** the response is 409 naming the checksum mismatch, and the object has no new file

#### Scenario: an abandoned session is cleaned up
<!-- @e2e exclude Background job; covered by PHPUnit UploadSessionCleanupJobTest::testAnExpiredSessionIsRemoved. -->

- **GIVEN** a session last touched 25 hours ago
- **WHEN** the cleanup job runs
- **THEN** its parts and the session are gone
