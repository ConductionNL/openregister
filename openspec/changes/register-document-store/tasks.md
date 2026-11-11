# Tasks: register-document-store

Wave 1. Row 11.11. Kind: code. Build rules: `openspec/woo-build-rules.md`.

A test marked **fails today** must be run on `origin/development` first and seen red. Doubles of Nextcloud and
OpenRegister classes use `environmentAwareDouble` against the real signatures (`OCP\Files\Node`,
`OCP\Share\IManager`, `OCP\Share\IShare`, `OCP\SystemTag\ISystemTagObjectMapper`); read them before mocking.

## 1. One root and a named store (REQ-RDS-001, REQ-RDS-002, REQ-RDS-003)

- [ ] 1.1 `lib/Service/File/DocumentStore.php`: `root(): string` (app config `document_store_root`, default
  `Open Registers`), `rootFolder(): Folder` (created on first use in the `openregister` account's home through
  `FileService::getUser()`), `describe(): array{root: string, storageId: string, backend: string, mountPoint: string, separate: bool, freeBytes: ?int}`
  (backend from `getStorage()->instanceOfStorage()` and `getMountPoint()->getMountType()`; `separate` compares the
  root's storage id with the account home's storage id). Replace the `ROOT_FOLDER` constants in `FileService`,
  `FolderManagementHandler` (including the two path patterns) and `ObjectFileMigration` with it.
  - **fails today**: `tests/Unit/Service/File/DocumentStoreTest.php::testTheDefaultRootIsOpenRegisters`,
    `testAConfiguredRootIsUsed`, `testAForeignManagedRootIsRefused`, `testDescribeNamesAnExternalMount`,
    `testDescribeOnPrimaryStorageIsNotSeparate`.
  - `tests/Unit/Service/File/DocumentStoreLiteralTest.php::testNoOtherOpenRegistersLiteralInLib` scans `lib/`.
  - Through the caller: `FolderManagementHandlerTest::testRegisterFolderIsCreatedUnderTheConfiguredRoot`.
- [ ] 1.2 Report the store: `documentStore` in `SettingsService::getFileSettingsOnly()` (so
  `FileSettingsController::getFileSettings()` returns it), a read-only block at the top of
  `src/views/settings/sections/FileConfiguration.vue`, and `occ openregister:document-store:status`
  (`lib/Command/DocumentStoreStatusCommand.php`, registered in `appinfo/info.xml`). Load the hydra `writing` skill
  for the labels.
  - **fails today**: `tests/Unit/Controller/Settings/FileSettingsControllerTest.php::testDocumentStoreIsReturned`,
    `tests/Unit/Command/DocumentStoreStatusCommandTest.php::testStatusPrintsTheStore`.
  - Playwright `tests/e2e/document-store-status.spec.ts` (`@e2e file-actions::the-administrator-reads-where-documents-live`):
    the settings page shows `Open Registers`, the primary backend and "not separate".
- [ ] 1.3 Guard the byte paths: `tests/Unit/Service/File/DocumentBytePathTest.php::testNoManagedFileIsReadByLocalPath`
  scans `lib/` for `getLocalFile(`, `getLocalFolder(`, `'datadirectory'` and fails naming file and line, with
  `Application::registerConfigurationServices()` and `Application::buildImportHandler()` as the named exception
  (configuration cache, no documents). It passes today; prove it can fail by adding a `getLocalFile()` call in a
  scratch commit and quoting the red line in the PR body.

## 2. The move (REQ-RDS-004)

- [ ] 2.1 `lib/Service/File/DocumentReferenceMap.php`: every OpenRegister place that holds a Nextcloud file or folder
  id (register `folder`, object `folder`, file-typed object properties, `openregister_file_texts.file_id`,
  `openregister_chunks`, `openregister_anonymisation_log.file_id`, `openregister_entity_relations`, export runs,
  scheduled reports; confirm each against `lib/Migration/` and `lib/Db/`).
  - **fails today**: `tests/Unit/Service/File/DocumentReferenceMapTest.php::testEveryFileIdColumnIsMapped` reads the
    migrations for `file_id`, `fileid`, `folder` and `node_id` columns and fails on one that is neither mapped nor
    listed as not a file id with a reason.
- [ ] 2.2 Migration and entity for `openregister_document_moves` (`register_id`, `old_id`, `new_id`, `kind` file or
  folder or version, `sha256`, `state` copied, verified, switched or refused, `reason`, `moved_at`), with its mapper.
  - unit `DocumentMoveMapperTest::testAMappingRoundTrips`; the migration runs in the CI PHPUnit job.
- [ ] 2.3 `lib/Service/File/DocumentStoreMove.php::copyRegister()`: copy folders and files into the target root,
  replay earlier versions oldest first (read each version through the versions API Nextcloud exposes for the node;
  record the original version times in the mapping), compute SHA-256 of source and copy through `fopen()` streams,
  and record `verified` or `refused`. Refuse a target on the same storage id.
  - **fails today**: `tests/Unit/Service/File/DocumentStoreMoveTest.php::testAFailedCopySwitchesNothing`,
    `testVersionsAreCarriedOldestFirst`, `testATargetWithoutVersionsRefusesAVersionedRegister`,
    `testWithoutEarlierVersionsIsRecordedPerFile`, `testTheSameStorageIsRefused`.
- [ ] 2.4 `DocumentStoreMove::rewriteReferences()`: for a register whose every copy verified, rewrite each place in
  `DocumentReferenceMap` from old to new id in one transaction per register, object properties through the object
  save path in system context with one audit trail entry per object (`action: document-store-move`).
  - **fails today**: `DocumentStoreMoveTest::testEveryReferenceFollowsTheFile`,
    `testAnUnverifiedRegisterKeepsItsReferences`, `testEachChangedObjectGetsOneAuditEntry`.
- [ ] 2.5 `DocumentStoreMove::recreateShares()` and tags: user, group and link shares through `OCP\Share\IManager`
  with the same token (`IShare::setToken()`), permissions, expiry and label; system tags through
  `ISystemTagObjectMapper`. A password-protected link share, or a share the manager refuses, marks the register
  `refused` before any reference is rewritten.
  - **fails today**: `DocumentStoreMoveTest::testALinkShareKeepsItsToken`, `testAPasswordProtectedShareStopsTheRegister`,
    `testTagsAreCopied`.
- [ ] 2.6 Refuse uploads into a register while it moves: a flag per register in app config set by the move and
  checked by `FilesController::create()`, `save()`, `createMultipart()`, `update()` and `FilePropertyHandler`'s file
  write, answering 503 `{error: "document-store-move"}` and writing nothing; reads are not checked.
  - **fails today**: `tests/Unit/Controller/FilesControllerMoveGuardTest.php::testAnUploadDuringAMoveIs503`,
    `testAReadDuringAMoveStillWorks`, and `testEveryUploadPathChecksTheFlag` enumerating the write paths.
- [ ] 2.7 Switch and purge: when every register is switched, set `document_store_root` to the target and rename the
  old root to `<root> (moved <YYYY-MM-DD>)`; `--purge-source` deletes the renamed tree only when every file in it has
  a `switched` mapping, else deletes nothing and names the files.
  - **fails today**: `DocumentStoreMoveTest::testTheRootSwitchesOnlyWhenEveryRegisterDid`,
    `testPurgeDeletesNothingWithAnUnmappedFile`, `testPurgeDeletesAFullyMappedTree`.
- [ ] 2.8 `occ openregister:document-store:move --to <path> [--register <id>] [--dry-run] [--without-earlier-versions] [--purge-source]`
  (`lib/Command/DocumentStoreMoveCommand.php`, registered in `appinfo/info.xml`), printing per register the counts and
  refusals; exit 1 when any register was refused.
  - **fails today**: `tests/Unit/Command/DocumentStoreMoveCommandTest.php::testDryRunChangesNothing`,
    `testARerunContinuesWhereItStopped`, `testARefusedRegisterExitsOne`.

## 3. The swap, proven (REQ-RDS-005)

- [ ] 3.1 `tests/Integration/DocumentStoreSwapTest.php`, run by the CI PHPUnit job against the real Nextcloud: enable
  `files_external`, allow the `local` backend, create a `local` mount for the `openregister` account through
  `occ files_external:create` and `files_external:applicable`, then run the scenario of REQ-RDS-005 (object with a
  file, one earlier version, a tag and a public link; move; read through `FilesController` and through the public
  share URL; upload again). It must not call `markTestSkipped()` (unlike `RuntimeSchemaReloadTest`): without Nextcloud it fails. Quote its line from the
  CI log in the PR body to show it ran.
- [ ] 3.2 Newman: add `GET /api/settings/files` asserting the `documentStore` keys and types to the file settings
  collection in `tests/newman/`.
- [ ] 3.3 Administrator documentation `docs/Installation/separate-document-store.md`: mount a store with the External
  storage app for the `openregister` account, set the root on a fresh installation, move an existing corpus
  (dry run, move, check status, purge), and what stops a register. Load the hydra `writing` skill first.

## 4. Live

- [ ] 4.1 Live check after merge on the dev instance: mount a `local` External storage for the `openregister`
  account, run the move with `--dry-run`, then for real on one test register, and open a moved file in the UI and
  through its public link. Record the status output and the report in the PR.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with
  `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same
  command as every `git add`.
- [ ] V.2 Every test marked **fails today** fails on `origin/development` and passes on the branch. Run each new test
  file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line
  (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base`
  the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push:
  `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the
  coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR
  body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No
  `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; 11.11 counts as
  `production` only once it ships in a store release.
