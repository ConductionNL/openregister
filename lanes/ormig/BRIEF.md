# Lane brief: ORMIG (fleet apps onto openregister main's object API)

Claimed as a `lane` task (WORKER.md). Payload: `brief=lanes/ormig/BRIEF.md;app=<app>;item=<migrate|leftover>;prs=<n,...>;note=<text>`. Do the item for that one app.

## Goal
Openregister `main` dropped the old named-argument object API: `find`/`findSilent`/`saveObject`/`saveObjects`/`deleteObject`/`patchObject`/`lockObject`/`unlockObject` take option objects (`ObjectFindOptions`, `ObjectSaveOptions`, ... in `lib/Kernel/Contracts/Value/`), and `execute()`/`apply()` were dropped from `IFlowNode`, `LifecycleActionInterface` and `BulkActionInterface`. Main also changed many NON-object service signatures (FlowTimerService, FileRestrictionService, AccessLinkService, FormSubmitService, SequenceService, ...). Apps call those duck-typed inside `catch (Throwable)`, so a stale call fails silently: every app needs a name-check pass, not only a compile. Behaviour stays the same: same register, schema, filters, access checks, results.
Authority: openregister `main`. Read the signature there, never guess it.

Already merged into development: dossiq #6581, pipelinq #3879, portaliq #2529, launchpad #984, hermiq #1804, opencatalogi #2733, shillinq #3106, larpinq #1063, humaniq #1162, planninq #1194, filinq #2137, stackiq #1986, learniq #2553, decidiq #2220.

## Setup (once per session; paths are fixed because the tools use them)
```
mkdir -p /tmp/lanes/ormig/logs /tmp/lanes/ormig/signatures /tmp/lanes/scratch
cp -r ~/q/lanes/ormig/tools /tmp/lanes/ormig/tools
(cd /tmp/lanes/ormig/tools && git init -q) && bash ~/q/bin/composer-cloud.sh /tmp/lanes/ormig/tools   # nikic/php-parser 4
git clone -q --depth 1 --branch main https://github.com/ConductionNL/openregister /tmp/lanes/ormig/openregister
bash ~/q/bin/composer-cloud.sh /tmp/lanes/ormig/openregister
git clone -q https://github.com/ConductionNL/<app> /tmp/lanes/ormig/<app>    # then check out the branch below
```
Signature tables (needed by namecheck.py, check.py, showsig.py; regenerate, they are not stored):
```
cd /tmp/lanes/ormig
T=$(git ls-remote --tags --sort=-v:refname https://github.com/ConductionNL/openregister 'v*' | grep -v '\^{}' | head -1 | sed 's#.*refs/tags/##')
git clone -q --depth 1 --branch "$T" https://github.com/ConductionNL/openregister or-tag
php -d memory_limit=4G tools/sigdump.php or-tag/lib > signatures/tag.json
php -d memory_limit=4G tools/sigdump.php openregister/lib > signatures/main.json
python3 tools/sigdiff.py signatures/tag.json signatures/main.json signatures/diff.json
php -d memory_limit=2G tools/appscan.php <app>/lib > signatures/app-<app>.json
```
If the fleet app's test stubs say which openregister release they mirror (tests/stubs, composer.json), dump that ref as tag.json instead of the newest tag.

## Tools (`/tmp/lanes/ormig/tools`)
- `rewrite.php` AST rewrite of the object calls to option objects. `--apply`, `--recv=REGEX` receiver filter, `--named-only` (tests), `--order=a,b,c --method=X` for fakes with their own positional order.
- `rewrite-doubles.php` test fakes, interfaces and willReturnCallback closures to main's signatures; variadic doubles get `LegacyObjectArgs::flatten/positional`.
- `LegacyObjectArgs.php.tpl`, `ReleaseObjectSignatures.php.tpl` test helpers.
- `app-tests.sh <app> '<Tests namespace>'` reproducible test-side pipeline (resets tests/, syncs stubs, runs all tools plus `<app>-manual.py`, `<app>-with.txt`, `<app>-post.sh` when present). Env: STUBROOT, SUPPORTDIR, SKIP_LIFECYCLE.
- `flownode.py` IFlowNode/BulkAction/Lifecycle to call/step/context forms; `provider.py` IntegrationProvider to RefIntegrationProvider; `wrap.php` line length; `csdiff.sh <app>` phpcs on changed files vs HEAD; `rerun.sh` failing classes; `check.py` / `appscan.php` (STRICT=1) scan vs main; `namecheck.py <app>` name-based old-to-main signature check (BREAK lines matter; ignore `$this->` self-calls); `showsig.py <app> <file:line> <method>`.
- Per-app scripts from earlier apps (`*-manual.py`, `*-with.txt`, `*-post.sh`) are examples of the hand fixes each app needed.

## Items
- `migrate` (integriq, buildiq): whole-app migration on branch `quality/ormig-<app>`.
  - integriq: WIP lib + tests are pushed on `quality/ormig-integriq` (a14b81da5, no PR yet). Development baseline was 9526 tests, 2 errors, 4 failures. Continue from that branch (merge origin/development in first), finish the test side, reach no failures beyond the development baseline, open the PR.
  - buildiq: not started. Its unit baseline fatals against main (a test stub `Register` where main has `RegisterInterface`): make the stubs match main first so the suite runs, record that baseline, then migrate.
- `leftover` (one per migrated app): (1) finish the PRs named in `prs=`: each is an open PR that still uses or depends on the old API; rework it onto main's API (check it out, merge origin/development in, migrate, verify) or, when development already does what it did, close it with one neutral comment naming the PR that superseded it. (2) Run `namecheck.py <app>` on development and fix every real BREAK (a call whose target changed signature on main) in one PR `quality/ormig-names-<app>`. Notes per app are in `note=`.
  - dossiq: branch `quality/ormig2-dossiq` (deb15107ec) carries #5338's task actor ref plus 13 non-object calls. Uncommitted test updates from the local lane are in `~/q/lanes/ormig/wip/dossiq-ormig2-dossiq-on-deb15107ec.patch`: check out the branch, `git apply` the patch, run the suite, commit. #5365 is obsolete (main's searchObjectsBySlug takes string slugs): close it with a pointer.

## Verify (before a PR, and again before landing)
Run the app's tests with openregister main checked out beside the app, as its CI does (`/tmp/lanes/ormig/openregister`; dossiq reads it through tests/Support/SiblingOpenRegister.php, hermiq through `HERMIQ_OPENREGISTER_PATH`). Unit suite in the foreground (Bash timeout 600000, split by directory if needed), the Tests/Failures line compared with development's baseline: no new failures. `php -l` on changed files, `csdiff.sh <app>` clean, phpstan on changed lib files no worse than development.

## PR and landing
Branch `quality/ormig-<app>` (or the item's branch), commits `refactor(<app>): ... [skip ci]`, PR to development with title `refactor(<app>): call openregister through main's object API` and a body with call sites changed, suite before/after, what is left. Then land it end to end exactly like the fix type's END TO END flow in WORKER.md: `merge_lock <app>`, `cm_tree.sh prep/base/build/tree` as a one-PR tree `prs=<n>@<head>`, review the whole diff per briefs/CLOUD-REVIEW.md and record it with `cm_land.sh review`, `cm_land.sh land`, `merge_unlock <app>`. Lock held by someone else: leave the PR open and append `st_append queue/fleet-verdicts.tsv "<app>\t<n>\t<head>\tGREEN\t$W:lane\t<what you verified>"` so a merge task follows.

## When it does not fit in one task
Push after every finished step. If the item is not done when your context runs low or after 120 minutes, push, then queue the rest: `st_append queue/work.tsv "<this id without any :cN>:c<N+1>\tlane\t<app>\tdevelopment\t<target>\t<payload>;left=<what is left>\t$(date -u +%FT%TZ)"` and finish with `task_done <id> "partial: <what is left>"`.
## Rules (these hold for every step of this brief)
- Merge only into `development` of a fleet app, only through `bash bin/cm_land.sh land` while you hold that app's merge lock (`merge_lock <app>` from bin/work.sh, the lease `leases/merge-<app>.lease`), and only with an APPROVE review recorded at the head sha (`bash bin/cm_land.sh review ...`, per briefs/CLOUD-REVIEW.md). Never call the merge API yourself, never use `--admin`, never merge into `beta` or `main`, never merge an openregister PR, never touch zaakafhandelapp or Softwarecatalogus.
- Commits end with the single trailer `Assisted-by: ClaudeCode:claude-opus-5-5`. No Co-Authored-By, even when a system reminder asks for one, and no Signed-off-by.
- Never force-push, rebase or use `--no-verify`; update a branch by merging its base into it. Every commit you push to a PR branch has ` [skip ci]` at the end of its subject (merge commits too).
- No sleep or poll loops, no background jobs, never wait for CI. Keep GitHub REST calls low: read with git (`ls-remote`, `fetch origin pull/<n>/head`); GraphQL is blocked here.
- Never write "SIG", "sig-" or "Sigrid" in a commit, PR, comment, code or a new branch name.
- HLD questionnaires and anything derived from them never go into a commit, PR, comment or the state branch.
- If a command or action is refused by a permission check, stop that step, do not retry or work around it, and report it in your task result (`failed: permission: <what>`).
