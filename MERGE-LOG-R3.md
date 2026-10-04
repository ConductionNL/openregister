# MERGE-LOG-R3 (openregister landing lane, round 3, 2026-09-28)

Order: #4111 (feat/docx-structured-reader), #4116 (feat/register-folder-on-first-upload).
TMPDIR=/tmp/claude-1000/or-land3-tmp. PHPUnit: `./vendor/bin/phpunit --no-coverage` (default suite), junit logs in TMPDIR.

## Setup
- No live process had its cwd in the clone (checked /proc/*/cwd; only this lane's own shell). Local branch heads = origin heads for both PRs (0 unpushed).
- origin/development tip 5cda2f0434 (#4112). Neither branch contains it yet. No composer.json/lock change since c53dd0685c, so vendor still matches the lock.
- Neither PR touches `lib/Settings/*_register.json`, l10n, manifest, composer or package files.
- Baseline on detached origin/development 5cda2f0434: 24169 tests, 0 failures, 0 errors, 25 skipped, 1 warning, exit 0 (baseline.fails empty; log and junit in TMPDIR).

## #4111 feat/docx-structured-reader
- CI per brief: 36 pass, 0 fail. Branch head = origin 2ef8feaf99 (0 unpushed). Merge development 5cda2f0434: conflicts no (development brought lib/Db/Schema.php, ImportHandler.php, 2 tests, openspec docs; disjoint from the PR). `git grep -l '^<<<<<<<'` empty. No register/l10n/manifest file in the PR diff; check:register exit 0 (18 files PASS).
- Full PHPUnit --no-coverage: 24218 tests, 0 failures/errors, 25 skipped, exit 0; new failures vs baseline: none.
- Pushed 95f767c814 (ls-remote matches). `gh pr merge 4111 --squash --admin` exit 0: MERGED 2026-09-28T06:00:13Z as 9ce171e921.

## #4116 feat/register-folder-on-first-upload
- CI read once before merging (head 0bbd9ff011): 15 pass, 2 skipping, 18 pending, 0 fail. The pending checks are the Code Quality run 36383808926, still `queued` at read time (CI bottleneck), not red. Nothing red, so nothing to fix; landed on local verification (the lane's own check:strict for this PR already ran once: lint/phpcs/phpmd/psalm pass, phpstan pass on a fresh cache, full phpunit pass; npm lint/format/test:l10n 0).
- Branch head = origin 0bbd9ff011 (0 unpushed). Merge development 9ce171e921 (incl. #4111): conflicts no. `git grep -l '^<<<<<<<'` empty. PR diff vs development now = its own 10 files (docs/api/objects.md, Application.php, RegisterFolderRecorder.php, FolderManagementHandler.php, 6 tests). No register/l10n/manifest file; check:register exit 0.
- Full PHPUnit --no-coverage: 24228 tests, 0 failures/errors, 25 skipped, exit 0; new failures vs baseline: none.
- Pushed 57d8c128c7 (ls-remote matches). `gh pr merge 4116 --squash --admin` exit 0: MERGED 2026-09-28T07:00:59Z as ecaba04a96.

## End
- development tip after lane: ecaba04a96. No register descriptor changed by either PR, so no version bump was needed.
- Clone left on feat/register-folder-on-first-upload, tree clean apart from untracked logs and .tmp/.
