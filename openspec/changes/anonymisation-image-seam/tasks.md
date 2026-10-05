# Tasks: anonymisation-image-seam

## 1. Positions in (REQ-AIS-001)

- [ ] 1.1 Extend `TextExtractionService::extractFromProvidedText()` with an optional `words` list and store word boxes per page beside the chunk. Verify: `tests/Unit/Service/TextExtractionProvidedWordsTest.php::testWordBoxesAreStoredWithTheText` (fails today: the parameter does not exist).
- [ ] 1.2 Migration: nullable `page` and `box` (JSON) on `openregister_entity_relations`; extend `EntityRelation` (real entity class in tests, not a double). Verify: `tests/Unit/Db/EntityRelationBoxTest.php`.
- [ ] 1.3 `ImageRedactionService::detectImage()` maps detected text entities to boxes and stores relations; refuses an image without OCR input. Verify: `tests/Unit/Service/File/ImageRedactionServiceTest.php::testAnOcrLocatedNameBecomesARegion`, `testAnImageWithoutOcrIsNotDetectable`.

## 2. Detector seam (REQ-AIS-002)

- [ ] 2.1 `IImageRegionDetector` interface and registry; `AnonymiqRegionDetector` adapter calling `POST /api/v1/image/regions` through `AnonymisationBackendService::requestOpenAnonymiser()`-style AppAPI plumbing. Verify: `tests/Unit/Service/File/AnonymiqRegionDetectorTest.php::testRegionsFromTheExAppBecomeEntityRelations` with a recorded response; `testAMalformedResponseIsRecordedAsFailed`.
- [ ] 2.2 Cross-app contract: request `{image, page}`, response `{regions: [{page, box: {x, y, w, h}, entityType, confidence}], detector: {name, version}}`. Verify: `tests/Contract/AnonymiqImageRegionsContractTest.php` pins the shape; `anonymiq/object-detection-in-page-images` carries the matching test on its side.
- [ ] 2.3 Record per detector `ran`, `not-installed` or `failed`, and `objectDetection: not-run` when none ran, on the `AnonymisationLog` run. Verify: `testAnAbsentDetectorIsRecordedAsNotRun`.

## 3. Burn (REQ-AIS-003)

- [ ] 3.1 `redactImage()` for PNG, JPEG, TIFF and WebP with GD: opaque fill into pixels, re-encode, strip EXIF, XMP, IPTC and thumbnails. Verify: `ImageRedactionServiceTest::testARegionIsUniformFillAfterBurning` reads the pixels back; `testExifAndXmpAreGone` reads the output bytes.
- [ ] 3.2 PDF image XObjects and image-only pages: replace the XObject stream, add no annotation. Verify: `testAnImageOnlyPdfPageIsBurnedNotOverlaid` asserts the original stream bytes are absent from the output.
- [ ] 3.3 DOCX and ODT media: replace the affected media part. Verify: `testAnImageInsideADocxIsBurned`.
- [ ] 3.4 Refuse to write an output when a decided region could not be burned (unsupported encoding, corrupt image): the call fails naming the file and region, and no `_anonymized` sibling is left behind. Verify: `testAnUnburnableRegionLeavesNoOutput`.

## 4. Wiring (REQ-AIS-004)

- [ ] 4.1 Route images and image content through the seam inside `FileService::anonymizeDocument()` / `DocumentProcessingHandler::anonymizeDocument()`. Verify: `tests/Unit/Service/FileServiceImageRouteTest.php::testADirectServiceCallBurnsImageRegions` (the opencatalogi call shape) and `tests/Unit/Controller/FileTextControllerImageTest.php` (the endpoint).
- [ ] 4.2 Report the image steps in the pipeline report when `anonymisation-discloses-itself` has landed (REQ-ADI-006); until then, record them on the run. Verify: `PipelineStepReportTest` gains the image steps if that change is merged first.
- [ ] 4.3 End to end: `tests/e2e/ci/image-redaction.spec.ts` uploads a JPEG fixture with provided OCR words, decides the PERSON region and downloads an `_anonymized` file whose region is filled.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
