# Tasks: files-create-from-url

## 1. Fetch (REQ-FCU-001, REQ-FCU-002)

- [ ] 1.1 Extract the fetch in `FilePropertyHandler::fetchFileFromUrl()` into a shared `lib/Service/File/UrlFileFetcher.php` (keep the property path calling it), adding streamed size enforcement and the reason codes. Verify: `tests/Unit/Service/File/UrlFileFetcherTest.php::testAPrivateAddressIsRefused`, `testARedirectIsNotFollowed`, `testTheCeilingStopsTheReadNotTheWrite`, `testANon2xxIsReported`, `testAnEmptyBodyIsReported`; `FilePropertyHandlerTest` keeps passing (no behaviour change on that path).
- [ ] 1.2 `FilesController::create()` accepts `source.url`, refuses both or neither, runs the upload checks on fetched bytes, writes `sourceUrl`, `fetchedAt`, `sha256` and the audit entry. Verify: `tests/Unit/Controller/FilesCreateFromUrlTest.php::testAFileIsCreatedFromAUrl` (fails today: `content` is required), `testContentAndSourceTogetherAreRefused`, `testAFailedFetchCreatesNoFile`, `testObjectAccessIsCheckedFirst`.
- [ ] 1.3 Through the route: a Newman request in `tests/newman/` posts `source.url` pointing at a file the test instance serves, and asserts 200 and the metadata; a second posts a `127.0.0.1` URL and asserts 422.
- [ ] 1.4 Document `source` in the OpenAPI spec and `docs/features/files.md`. Verify: the API surface contract test (`tests/Contract/`) lists the new body field.

## 2. Cross-app

- [ ] 2.1 Contract for opencatalogi: request body `{name, source: {url}, share?, tags?}`, success returns the file JSON with `metadata.sourceUrl`, `metadata.fetchedAt`, `metadata.sha256`; failure returns 422 `{error, reason}`. Verify: `tests/Contract/FilesCreateFromUrlContractTest.php` pins both shapes; opencatalogi's copy path carries the consumer test. Without OpenRegister nothing calls it; opencatalogi always requires OpenRegister.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
