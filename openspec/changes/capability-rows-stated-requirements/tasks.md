# Tasks: capability-rows-stated-requirements

## 1. JSON Schema download

- [x] 1.1 Add a test that validates a record against the downloaded schema with a standard JSON Schema validator and gets the same verdict as the save path. Verify: `tests/Unit/Db/SchemaTest.php`.
      > 2026-11-13: in its own file, `tests/Unit/Db/SchemaJsonSchemaDocumentTest.php` (7 tests). The document declares draft 2020-12 and carries `required`, `maxLength`, `minimum` and `enum`. Opis with the downloaded JSON and `ValidateObject` (the save path) agree on six records: two accepted, and four refused (missing required field, too long, below minimum, outside the enum).

## 2. URN after a move

- [x] 2.1 Keep the URN a record was given, and resolve it after the record moves to another register or schema. Verify: a `UrnService` unit test that moves a record and resolves its old URN.
      > 2026-11-13: `UrnService::resolveUrl()` follows the record's `moved` audit entries (the pointer `MoveObject` writes): when one left from the address the URN names, the URN answers the target of the newest entry, so a record moved twice answers where it lives now. The URN itself and the uuid do not change. `tests/Unit/Service/UrnServiceMoveTest.php` (5 tests).

## 3. File checksum

- [x] 3.1 Compute a SHA-256 checksum when a file is uploaded or replaced and store it with the file metadata. Verify: a `FileService` unit test that uploads a file and reads the checksum back.
      > 2026-11-14: `lib/Service/File/FileChecksum.php` streams the content through SHA-256 and stores it in the file's Nextcloud metadata under `openregister-sha256`, the key a URL-fetched file already carries. `CreateFileHandler` (upload) and `UpdateFileHandler` (replace) record it after the write; best effort, a failure is logged and leaves the file without a checksum. Tests: `tests/Unit/Service/File/FileChecksumTest.php`, and the wiring in `CreateFileHandlerTest` and `UpdateFileHandlerTest`.
- [x] 3.2 Add a fixity check that reports a mismatch when the content no longer matches the stored checksum. Verify: a unit test that changes the content and gets the mismatch.
      > 2026-11-14: `FileChecksum::check()` answers `unchanged`, `changed` (with the stored and the current checksum) or `unrecorded` for a file uploaded before checksums existed. Service level only: no endpoint or command exposes it yet.
