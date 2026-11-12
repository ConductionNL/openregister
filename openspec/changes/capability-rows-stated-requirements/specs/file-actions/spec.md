# file-actions

## ADDED Requirements

### Requirement: A stored file keeps a checksum from the moment it is uploaded

The system SHALL compute a SHA-256 checksum of every file when it is uploaded or
replaced, and store it with the file metadata. A later read of the file metadata MUST
return that checksum, and a fixity check MUST report a mismatch when the content no
longer matches it.

#### Scenario: an uploaded file can be proven unchanged

- **WHEN** a user uploads a file to a record and later asks for a fixity check on it
- **THEN** the file metadata carries the SHA-256 computed at upload, and the check reports the file as unchanged
- @e2e exclude {backend file pipeline, covered by unit tests in tasks 3.1 and 3.2}
