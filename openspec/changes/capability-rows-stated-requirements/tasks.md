# Tasks: capability-rows-stated-requirements

## 1. JSON Schema download

- [x] 1.1 Add a test that validates a record against the downloaded schema with a standard JSON Schema validator and gets the same verdict as the save path. Verify: `tests/Unit/Db/SchemaTest.php`.
      > 2026-11-13: in its own file, `tests/Unit/Db/SchemaJsonSchemaDocumentTest.php` (7 tests). The document declares draft 2020-12 and carries `required`, `maxLength`, `minimum` and `enum`. Opis with the downloaded JSON and `ValidateObject` (the save path) agree on six records: two accepted, and four refused (missing required field, too long, below minimum, outside the enum).

## 2. URN after a move

- [ ] 2.1 Keep the URN a record was given, and resolve it after the record moves to another register or schema. Verify: a `UrnService` unit test that moves a record and resolves its old URN.

## 3. File checksum

- [ ] 3.1 Compute a SHA-256 checksum when a file is uploaded or replaced and store it with the file metadata. Verify: a `FileService` unit test that uploads a file and reads the checksum back.
- [ ] 3.2 Add a fixity check that reports a mismatch when the content no longer matches the stored checksum. Verify: a unit test that changes the content and gets the mismatch.
