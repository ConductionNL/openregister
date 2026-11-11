# Tasks: anonymisation-discloses-itself-pipeline

## 1. Disclosure per document (REQ-ADI-005)

- [ ] 1.1 Add `TextDisclosure` entity, mapper and migration (`file_id`, `service`, `host`, `at`, `bytes`) and `TextDisclosureRecorder`, called from every outbound site in `EntityRecognitionHandler` (Presidio, OpenAnonymiser external, LLM) and the Dolphin extraction client before the request is sent. Verify: `tests/Unit/Service/Anonymisation/TextDisclosureRecorderTest.php::testEveryOutboundSiteRecords` enumerates the outbound clients by reflection so a new client without a record fails; `testAFailingRecordStopsTheExternalCall`.
- [ ] 1.2 Route `GET /api/files/{fileId}/anonymisation/disclosures` (`#[NoAdminRequired]`, file read access checked in the method). Verify: `tests/Unit/Controller/FileDisclosuresControllerTest.php` with 200 for a reader and 403 for a non-reader; Newman request in `tests/newman/`; hydra gates route-auth and no-admin-idor pass.

## 2. Pipeline report (REQ-ADI-006)

- [ ] 2.1 Add `ContentPipelineReport` listing every step with its state and the setting that controls it; route `GET /api/admin/anonymisation/pipeline` (admin only). Verify: `tests/Unit/Service/Anonymisation/PipelineStepReportTest.php::testEveryRegisteredStepHasAReportEntry` and `testAnOffStepNamesItsSetting`.
- [ ] 2.2 Record each step's outcome on the `AnonymisationLog` run from the real anonymise path. Verify: `testARunRecordsEveryStepOutcome` through `DocumentProcessingHandler::anonymizeDocument()` with a DOCX fixture.
- [ ] 2.3 Show the list on the file configuration page. Verify: `tests/e2e/ci/anonymisation-disclosure.spec.ts` opens the page, switches the PDF metadata sanitiser off and sees it reported off; the same spec covers REQ-ADI-005's details view. The spec file exists from `anonymisation-discloses-itself`; extend it.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
