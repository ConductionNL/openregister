# Tasks: redaction-release-safeguards

## 1. The verifier (REQ-RRS-001, row 4.5, filinq REQ-RWB-01)

- [ ] 1.1 Port `RedactionIrreversibilityVerifier` and `RedactionLeakRoute` from filinq `development` (`lib/Service/Redaction/`, f0fa284) into `lib/Service/Anonymisation/`, keeping the seven routes, the three verdicts and `mayBePublished()`; add route readers for DOCX, ODT, XLSX and PPTX packages beside PDF. Verify: `tests/Unit/Service/Anonymisation/RedactionIrreversibilityVerifierTest.php` with one leaking fixture per route (`testTextUnderTheMarkIsLeaking`, `testAnEmbeddedPreviewIsLeaking`, `testXmpIsLeaking`, `testExifIsLeaking`, `testAnIncrementalUpdateKeepingTheValueIsLeaking`, `testAnAnnotationIsLeaking`, `testAnEmbeddedAttachmentIsLeaking`), `testUnparsableBytesAreUnverifiable`, `testNoValuesIsUnverifiable`, `testACleanFixtureIsClean` (fails today: no class).
- [ ] 1.2 Run it last in `DocumentProcessingHandler::anonymizeDocument()` on the written bytes; store the verdict on `AnonymisationLog` (migration: `verification` JSON) and in the output file's metadata; return `verification` from `FileTextController::anonymizeFile()` with route and count only. Verify: `tests/Unit/Service/File/AnonymiseVerificationTest.php::testTheVerdictIsTakenOnTheWrittenBytes` through `FileService::anonymizeDocument()` with a DOCX and a PDF fixture; `testNoLogLineCarriesAValue`.
- [ ] 1.3 A suite guard: `tests/Unit/Service/Anonymisation/OutputModeCoverageTest.php::testEveryOutputModeHasAVerifierEntry` enumerates the output modes `DocumentProcessingHandler` dispatches on and fails for one without a verifier entry.
- [ ] 1.4 `FilePublishingHandler::publishFile()` refuses an anonymised output whose stored verdict is not `clean`. Verify: `tests/Unit/Service/File/PublishVerdictGuardTest.php::testALeakingOutputIsNotPublished`, `testAnUnverifiedOutputIsNotPublished`, `testACleanOutputIsPublished`.

## 2. The review gate (REQ-RRS-002, row 4.27, filinq REQ-RWB-02)

- [ ] 2.1 Migration: `decision`, `decided_by`, `decided_at` on `openregister_entity_relations`, backfilled from `skip_anonymization`; accept `decision` on `PATCH /api/entity-relations/{id}` through `updateDecisionMetadata`, setting `decidedBy` and `decidedAt` from the session. Verify: `tests/Unit/Db/EntityRelationDecisionTest.php::testTheBackfillMapsSkipToRelease` and `testAPatchRecordsWhoDecided`, with the real `EntityRelation` class.
- [ ] 2.2 `lib/Db/RedactionReviewMark.php`, mapper and migration (`file_id`, `run_fingerprint`, `checked_by`, `checked_at`); `POST /api/files/{fileId}/anonymisation/review-mark` (`#[NoAdminRequired]`, file read access and a user session required in the method). Verify: `tests/Unit/Controller/ReviewMarkControllerTest.php::testAPersonSetsAMark`, `testASystemOrTokenCallerCannotSetAMark`; hydra gates route-auth and no-admin-idor pass.
- [ ] 2.3 `lib/Service/Anonymisation/RedactionReviewGate.php`, ported from filinq's gate with its messages, called at the top of `FileService::anonymizeDocument()` when `anonymisation.requireReview` is on. Verify: `tests/Unit/Service/Anonymisation/ReviewGateTest.php::testADirectServiceCallIsRefusedWithoutWriting` (asserts the handler is never reached), `testAnUndecidedEntityRefuses`, `testADifferentRunMakesTheMarkStale`, `testTheSameFindingsInAnotherOrderKeepTheMark`, `testWithTheSettingOffNothingChanges`.
- [ ] 2.4 The setting on the file configuration page, admin only, with the note that opencatalogi's unattended Woo pipeline needs officers to decide once it is on. Verify: `FileConfiguration.spec.js` case; `tests/e2e/ci/redaction-review-gate.spec.ts` turns the setting on, asks for output on an unreviewed fixture and reads the refusal, decides every entity, sets the mark and gets the output.

## 3. Spreadsheets and slides (REQ-RRS-003, row 4.21)

- [ ] 3.1 `lib/Service/File/Sanitizer/XlsxSanitizer.php` and `PptxSanitizer.php`, registered in `OfficeDocumentSanitizer`. Verify: `tests/Unit/Service/File/Sanitizer/XlsxSanitizerTest.php::testAHiddenSheetIsRemoved`, `testAHiddenRowIsCleared`, `testCommentsAndRevisionsAreRemoved`; `PptxSanitizerTest::testSpeakerNotesAreRemovedAndReported`, `testAHiddenSlideIsRemoved` (fail today: no class).
- [ ] 3.2 Through the caller: `tests/Unit/Service/File/AnonymiseSpreadsheetTest.php` anonymises an XLSX fixture through `FileService::anonymizeDocument()` and reads the persisted report from the `AnonymisationLog` run.

## 4. Metadata masked in place (REQ-RRS-004, row 4.22)

- [ ] 4.1 Mask title, subject, keywords, description and text custom properties in `PdfMetadataSanitizer`, `DocxSanitizer`, `OdtSanitizer` and the two new sanitisers with the substitution map; strip identity fields as today; name unmaskable fields. Verify: `tests/Unit/Service/File/MetadataMaskTest.php::testATitleIsMaskedNotBlanked` per format, `testIdentityFieldsAreStillStripped`, `testANonTextCustomPropertyIsRemovedAndNamed`.

## 5. Custody of the original (REQ-RRS-005, row 4.26)

- [ ] 5.1 Setting `anonymisation.originalRetention` (`keep` or an ISO 8601 duration); on `publishFile()` of an anonymised output store the original's deletion date. Verify: `tests/Unit/Service/Anonymisation/OriginalCustodyTest.php::testPublishingSchedulesTheOriginal`, `testKeepSchedulesNothing`.
- [ ] 5.2 `GET /api/files/{fileId}/anonymisation/custody` (`#[NoAdminRequired]`, file read check in the method). Verify: `tests/Unit/Controller/CustodyControllerTest.php` with 200 for a reader and 403 for a non-reader; a Newman request in `tests/newman/`.
- [ ] 5.3 `lib/BackgroundJob/OriginalRetentionJob.php` (daily, registered in `appinfo/info.xml`), deleting through `DeleteFileHandler` with an audit row, skipping legal holds and keep nominations. Verify: `tests/Unit/BackgroundJob/OriginalRetentionJobTest.php::testAnOriginalPastItsDateIsDeletedWithAnAuditRow`, `testALegalHoldStopsTheDeletion`, `testABewarenNominationStopsTheDeletion`, all through the job's `run()`.

## 6. Cross-app

- [ ] 6.1 Contract for opencatalogi and filinq: the anonymise response keys `verification.verdict` (`clean`, `leaking`, `unverifiable`), `verification.mayBePublished`, `verification.outputMode`, `verification.routesChecked[]`, `verification.findings[].route|count`; the gate refusal 409 `{error: "review-required", reason: "no-mark"|"undecided-entities"|"stale-mark", undecided, message}` and the PHP exception `ReviewRequiredException` with the same fields for direct callers. Verify: `tests/Contract/RedactionGuaranteeContractTest.php` pins both; `opencatalogi/woo-redaction-scans-and-text-layer` and `filinq/redaction-guarantees-from-the-engine` carry their consumer tests. Both require OpenRegister, so there is no absent-engine path.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
