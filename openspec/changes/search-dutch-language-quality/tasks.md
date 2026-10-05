# Tasks: search-dutch-language-quality

## 1. Stemming on PostgreSQL (REQ-SDL-001, row 6.32, D6)

- [ ] 1.1 Setting `search.textSearchConfig` (default `dutch`, validated against `pg_ts_config`) and `GET /api/settings/search` plus the capabilities entry `openregister.search.stemming`. Verify: `tests/Unit/Service/Settings/SearchConfigSettingTest.php::testAnUnknownConfigurationIsRefused`, `tests/Unit/Capabilities/SearchCapabilityTest.php::testPostgresReportsDutch`, `testMysqlReportsNoStemming` (fail today: no setting).
- [ ] 1.2 Add the `to_tsvector` arm to `MagicSearchHandler`'s `_search` (both the single-schema and the UNION paths) on PostgreSQL only, ORed with the existing `ILIKE`. Verify: `tests/Unit/Db/MagicSearchDutchTest.php::testAnInflectedFormFindsItsBase` and `testTheBaseFindsTheInflectedForm` against the PostgreSQL test database (fail today), `testMysqlSqlIsUnchanged` asserting the generated SQL on MySQL is byte-identical to `development`.
- [ ] 1.3 Functional GIN indexes: in `MagicMapper::createTableIndexes()` for new tables and a migration plus repair step for existing magic tables; a migration for `to_tsvector('dutch', text_content)` on the chunk table. Verify: `tests/Unit/Db/MagicMapperDutchIndexTest.php` asserts the definitions per platform; the migration test asserts PostgreSQL only.
- [ ] 1.4 Add the arm to `ChunkMapper::searchByKeyword()`. Verify: `tests/Unit/Db/ChunkDutchSearchTest.php::testAnInflectedFormFindsChunkText`.
- [ ] 1.5 Through the caller: a Newman request in `tests/newman/` creates an object titled `Omgevingsvergunning bouwen`, searches `GET /api/objects/{register}/{schema}?_search=omgevingsvergunningen` and asserts the hit (run against the PostgreSQL CI service; the MySQL job asserts no hit and `stemming: false`).

## 2. Matched passage (REQ-SDL-002, row 6.33, D5)

- [ ] 2.1 `lib/Service/Search/SnippetBuilder.php` for property and content hits, with the safety conditions (anonymised copy, verdict `clean`, published, readable; no write-only or encrypted property). Verify: `tests/Unit/Service/Search/SnippetSafetyTest.php::testAContentHitOnAnOriginalHasNoSnippet`, `testAnUnpublishedCopyHasNoSnippet`, `testALeakingVerdictHasNoSnippet`, `testAWriteOnlyPropertyIsNeverQuoted`, `testMarksAreTheOnlyHtml`, `testAPropertyHitIsQuoted`.
- [ ] 2.2 Wire `_snippet=true` into `searchObjectsPaginated` (reserved parameter, never a filter) and the unified search provider `lib/Search/ObjectsProvider.php` subline. Verify: `tests/Unit/Service/Object/SearchSnippetWiringTest.php::testSnippetIsOnlyAddedWhenAsked` through `ObjectService::searchObjectsPaginated()`, and the existing ZKN-CONTENT-003 tests still pass unchanged without `_snippet`.
- [ ] 2.3 Through the caller: `tests/e2e/ci/search-snippet.spec.ts` publishes an object with an anonymised attachment, searches with `_snippet=true` through the API and sees the marked passage from the anonymised copy, and sees none for a search that matches only the original.
- [ ] 2.4 Until `redaction-release-safeguards` has landed, no file carries a verdict, so content snippets are never produced. Verify: `SnippetSafetyTest::testWithoutAVerdictNoContentSnippet`; the PR body says so if this change lands first.

## 3. Cross-app

- [ ] 3.1 Contract for opencatalogi and portaliq: request flag `_snippet=true`, row key `@self.snippet` `{source, property?, fileId?, text}`. Verify: `tests/Contract/SearchSnippetContractTest.php`. The consumers' changes carry their own tests; they all require OpenRegister.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
