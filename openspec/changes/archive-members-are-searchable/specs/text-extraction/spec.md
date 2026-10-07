---
status: proposed
---

# text-extraction

## ADDED Requirements

### Requirement: Archive members are enumerated under stated bounds (REQ-AMS-001)

`ArchiveMemberEnumerator::enumerate(File $file): ArchiveListing` SHALL list the members of a zip archive and of zip archives nested one level inside it, each with `path`, `size`, `compressedSize` and detected MIME type. It SHALL stop and report when a bound is exceeded: `archive.maxMembers` (default 2,000), `archive.maxTotalBytes` (default 1 GiB uncompressed), `archive.maxRatio` (default 100 to 1 per member) and a nesting depth of 2. A member path with `..` or an absolute path SHALL be listed as refused and never resolved against the file system. An encrypted member SHALL be listed as encrypted. The bounds SHALL be returned by `GET /api/settings/files`.

#### Scenario: a zip bomb is refused, not exploded
<!-- @e2e exclude Hostile fixture; covered by PHPUnit ArchiveMemberEnumeratorTest::testARatioOverTheBoundStopsEnumeration. -->

- **GIVEN** a zip whose single member expands at more than 100 to 1
- **WHEN** it is enumerated
- **THEN** enumeration stops at that member and the listing reports the ratio bound

#### Scenario: a traversal path is never followed
<!-- @e2e exclude Hostile fixture; covered by PHPUnit ArchiveMemberEnumeratorTest::testATraversalPathIsRefused. -->

- **GIVEN** a zip with a member named `../../config/config.php`
- **WHEN** it is enumerated
- **THEN** the member is listed as refused and nothing is read or written outside the archive

### Requirement: Each member is extracted and searchable on its own (REQ-AMS-002)

For a zip file, text extraction SHALL extract every listed member whose type has an extractor (PDF, office, e-mail, plain text), store its chunks under the archive file with `positionReference.member` set to the member path, and run entity detection on each member when detection is on. The file search API and the unified search provider SHALL return a member hit with the archive file id, the member path and the owning object, applying the archive file's read rights.

#### Scenario: an officer finds a letter inside a zip
- **GIVEN** an object with a zip attachment holding `brieven/bezwaar-2024.pdf` containing "kapvergunning Lindelaan"
- **WHEN** the officer searches for "kapvergunning Lindelaan"
- **THEN** the hit names the zip, the member path `brieven/bezwaar-2024.pdf` and the owning object

#### Scenario: a reader without rights does not see the member
<!-- @e2e exclude RBAC on the search query; covered by PHPUnit ArchiveMemberSearchTest::testAMemberHitObeysTheArchiveFileRights. -->

- **GIVEN** the same zip on an object the caller may not read
- **WHEN** the caller searches the same words
- **THEN** no hit is returned

### Requirement: What could not be opened is reported (REQ-AMS-003)

The file's extraction status SHALL be `partial` when any member was refused, encrypted, over a bound or of an unsupported type, and `GET /api/files/{fileId}/extraction-status` (`FileSidebarController::getExtractionStatus`) SHALL list those members with the reason. An unsupported archive type (tar, 7z, rar) SHALL be reported as such and remain findable by its metadata.

#### Scenario: an encrypted member is named
<!-- @e2e exclude Status payload; covered by PHPUnit ArchiveMemberExtractionTest::testAnEncryptedMemberMakesThePartialStatus. -->

- **GIVEN** a zip with one readable and one encrypted member
- **WHEN** it is extracted
- **THEN** the status is `partial` and the encrypted member is listed with the reason `encrypted`
