---
status: proposed
---

# file-actions

## ADDED Requirements

### Requirement: A file's digest is recorded and sealed when it becomes public (REQ-PFD-001)

When a file becomes public, through `FilePublishingHandler::publishFile()` or the opening of its publication window, OpenRegister SHALL compute its SHA-256 by streaming, SHALL store `file_id`, `sha256`, `size`, `etag` and `published_at` in `openregister_file_digests` and `published.sha256` in the file's metadata, and SHALL write an audit row with action `file.published` carrying the file id, the object uuid, the digest and the size through the sealing insert path. If the digest or the audit row cannot be written, the file SHALL NOT be made public. Unpublishing SHALL close the record; publishing again SHALL create a new one.

#### Scenario: publishing seals the file's digest
- **GIVEN** a decision PDF attached to a publication
- **WHEN** an officer publishes it
- **THEN** the file API returns `published.sha256` equal to the SHA-256 of the bytes, and the audit trail holds a `file.published` row with that digest
- **AND** `GET /api/audit-trails/verify` reports the chain valid

#### Scenario: no seal, no publication
<!-- @e2e exclude Failure path; covered by PHPUnit PublishedDigestTest::testAFailedAuditWriteKeepsTheFilePrivate. -->

- **GIVEN** an audit insert that fails
- **WHEN** the file is published
- **THEN** no public share exists for it and the publish call fails naming the reason

### Requirement: A published file is checked against its digest when it is read (REQ-PFD-002)

A public download SHALL send `Repr-Digest` with the recorded digest. When the file's etag or size differs from the record the digest SHALL be recomputed; a mismatch SHALL refuse the public download with 409, SHALL write an audit row `file.digest-mismatch`, and SHALL raise an operations alert. A nightly job SHALL recompute every public file's digest and report mismatches on the operations console. `GET /api/files/{fileId}/digest/verify`, for any caller who may read the file, SHALL return `{recorded, current, verdict: "identical"|"changed", auditRow: {uuid, hash, previousHash}}`.

#### Scenario: a citizen proves the file is unchanged
- **GIVEN** a file published a year ago
- **WHEN** someone who downloaded it calls its verify endpoint
- **THEN** the answer is `identical` with the recorded digest and the sealed audit row

#### Scenario: a file changed behind the publication is not served
<!-- @e2e exclude Covered by PHPUnit PublishedDigestReadTest::testAChangedFileIsRefusedAndAlerted, writing new bytes through the Node API behind the share. -->

- **GIVEN** a published file whose bytes were replaced in Nextcloud Files
- **WHEN** a visitor downloads it through the public share
- **THEN** the download is refused, a `file.digest-mismatch` audit row is written and the operations console shows the alert

#### Scenario: the public listing shows the digest
<!-- @e2e exclude Covered by PHPUnit PublishedDigestApiTest::testListingsCarryTheDigest. -->

- **GIVEN** a published file
- **WHEN** a client lists the object's files or reads a public file listing
- **THEN** each published file carries `published.sha256` and `published.sealedBy`
