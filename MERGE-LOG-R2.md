# MERGE-LOG-R2 (openregister landing lane, 2026-09-27)

Order: #4077 (feat/pptx-structured-reader), #4079 (feat/store-plane-publish), #4080 (feat/demo-data-purge-by-batch).
TMPDIR=/tmp/claude-1000/or-land-tmp. PHPUnit: `./vendor/bin/phpunit --no-coverage` (default suite "Unit Tests"), junit logs in TMPDIR.

## Setup
- No live process had its cwd in the clone (checked /proc/*/cwd). Local branch heads = origin heads for all three PRs.
- origin/development tip d611a366a7 (#4055) is already an ancestor of all three branches.
- vendor was stale (zbateson/mail-mime-parser 3.0.5 vs lock 3.0.8): `composer install --no-scripts` on detached origin/development, vendor now matches the lock.
- None of the three PRs touches `lib/Settings/*_register.json`, composer or package files.
- #4079 CI read once (cb198dae91): 22 pass, 4 skipping, 13 pending (Code Quality still running). Previous push c94b5c12e5 failed the coverage guard: surviving code 89.23% -> 83.19%, 40 uncovered of 238. Head clover from that run: 25 of the 40 are `StoreActionAuthorizer::canPublish` and 1 is `StorePublishRules::encodedBody`, exactly the code the five risky tests exercise (risky only because `StoreDescriptor` was not declared, so PHPUnit discarded their coverage). cb198dae91 adds `@uses StoreDescriptor` to both test classes, the GenericStoreControllerTest pattern. Un-risking them gives 224/238 = 94.1%, above the 89.23% floor.
- Baseline on origin/development d611a366a7: 24081 tests, 0 failures, 0 errors, 25 skipped, exit 0 (baseline.fails empty).

## #4077 feat/pptx-structured-reader
- Branch head = origin (0 unpushed). origin/development had moved to 0ca409ee04 (#4081, npm deps only, so the PHP baseline stays valid). Merge: conflicts no (package.json/package-lock.json from #4081). Merge commit message amended to plain `merge development into feat/pptx-structured-reader` before push. No register file in the PR diff; check:register exit 0. Full PHPUnit --no-coverage: 24109 tests, 0 failures/errors, exit 0; new failures vs baseline: none. Pushed c6ac2aaa27 (ls-remote matches). `gh pr merge --squash --admin` OK: MERGED as c0d9ebbff1.

## #4079 feat/store-plane-publish
- CI read once more at landing time (cb198dae91): 35 pass, 8 skipping, 1 pending (Quality Report); `Coverage Baseline Protection` pass and `PHPUnit (PHP 8.3, NC stable35, pgsql)` pass, so the coverage guard is green and no further fix was needed.
- Branch head = origin (0 unpushed). Merge development (c0d9ebbff1, incl. #4077 and #4081): conflicts no. `<<<<<<<` hits are the same 3 files as on development itself (merge-hygiene.yml pattern, tokenizer.json, a png), no line-start markers. No register file in the PR diff; check:register exit 0. Full PHPUnit --no-coverage: 24141 tests, 0 failures/errors, exit 0; new failures vs baseline: none. Pushed 7d39013ffa (ls-remote matches). `gh pr merge --squash --admin` OK: MERGED as 84352bae41.

## #4080 feat/demo-data-purge-by-batch
- Branch head = origin (0 unpushed). Merge development (84352bae41, incl. #4077 and #4079): conflicts no. `<<<<<<<` hits = development's own 3 files. No register, migration, l10n or manifest file in the PR diff; check:register exit 0. Full PHPUnit --no-coverage: 24165 tests, 0 failures/errors, exit 0; new failures vs baseline: none. Pushed 054d5bd744 (ls-remote matches). `gh pr merge --squash --admin` OK: MERGED as c53dd0685c.

## End
- development tip after lane: c53dd0685c. No register descriptor changed by any of the three PRs, so no version bump was needed.
- Clone left on feat/demo-data-purge-by-batch; vendor synced to development's composer.lock (mail-mime-parser 3.0.8).
