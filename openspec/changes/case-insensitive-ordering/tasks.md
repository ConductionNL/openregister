# Tasks: case-insensitive-ordering

## 1. Ordering (REQ-CIO-001)

- [ ] 1.1 In `lib/Db/MagicMapper/MagicSearchHandler.php`, where `_order` is applied, emit `LOWER(col)`, `col`, `_id` for string-typed properties and the string metadata fields, using the query builder's function API so both platforms get valid SQL. Verify: `tests/Unit/Db/MagicMapper/CaseInsensitiveOrderingTest.php::testTheSqlIsLowerThenRawThenIdOnPostgres` and `testTheSqlIsLowerThenRawThenIdOnMySql` (assert the SQL string per platform; both fail today), `testANonStringPropertyKeepsNativeOrdering`, `testTiesAreBrokenByRawValueThenId`.
- [ ] 1.2 Apply the same rule on the UNION path that orders across schemas, so a multi-schema list sorts the same way. Verify: `CaseInsensitiveOrderingTest::testTheUnionPathOrdersTheSameWay`.
- [ ] 1.3 Add `lower()` expression indexes beside string indexes in `MagicMapper::createTableIndexes()` on PostgreSQL only. Verify: `tests/Unit/Db/MagicMapperLowerIndexTest.php` asserts the index definitions per platform.
- [ ] 1.4 Through the caller: a Newman request in `tests/newman/` lists a seeded mixed-case fixture via `GET /api/objects/{register}/{schema}?_order[title]=asc` and asserts the order; `tests/e2e/ci/case-insensitive-ordering.spec.ts` sorts the object list by title in the UI and reads the order.
- [ ] 1.5 MariaDB: if CI has no MariaDB job, run the Newman request once against a MariaDB instance and record the result in the PR body; say plainly if that run did not happen.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
