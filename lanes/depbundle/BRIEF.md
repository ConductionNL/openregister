# Lane brief: DEPBUNDLE (one dependency-update PR per app instead of many dependabot PRs)

Claimed as a `lane` task (WORKER.md). Payload: `brief=lanes/depbundle/BRIEF.md;app=<app>;item=<bundle|land>;pr=<n>`.

## Goal
Per fleet app: apply every open dependabot update on `development` that is safe, in one branch `quality/deps-bundle-<yyyymmdd>`, regenerate `sbom.cdx.json` from the updated lockfiles, verify, open one PR, land it. The dependabot PRs it covers close themselves after the bundle lands (dependabot rechecks and closes "up to date" PRs); do not close them by hand. Done already: filinq #2139 (merged), pipelinq #3882 (open, item `land`).

## Setup
```
mkdir -p /tmp/lanes/depbundle/logs && cp ~/q/lanes/depbundle/* /tmp/lanes/depbundle/
cd /tmp/lanes/depbundle && git clone -q --branch development https://github.com/ConductionNL/<app> <app>
git -C <app> checkout -q -B quality/deps-bundle-$(date -u +%Y%m%d) origin/development
```
## Item `bundle`
1. `git ls-remote https://github.com/ConductionNL/<app> 'refs/heads/quality/deps-bundle-*'`: an open bundle PR exists already (its branch is listed and `gh api repos/ConductionNL/<app>/pulls?head=ConductionNL:<branch>&state=open` returns it): treat it as item `land` instead.
2. Plan: `python3 plan.py <app> <app> > <app>.plan.tsv` (one REST listing of the open dependabot PRs). Rows: pr eco dir pkg from to major action reason; `skip` rows stay out (major bumps of analysers and test runners, npm overrides, exact pins, `_all.exclude` / `<app>.exclude`).
3. Apply: `python3 apply.py <app> <app>.plan.tsv` writes `<app>.applied.tsv` (applied, already, or failed with the reason; a failed row is reverted). Composer needs packagist metadata: if `composer update` cannot reach it here, the composer rows fail and stay out; bundle the npm rows alone and say so in the PR. Nothing applied: result `nothing to bundle`.
4. SBOM: `bash ~/q/bin/regen-sbom.sh <app>`; it must exit 0.
5. Verify on the branch and on development (the baseline), each in the foreground: `bash ~/q/bin/composer-cloud.sh <clone>`; unit suite (`phpunit-unit.xml`, else `phpunit.xml`; Tests/Failures line); phpstan count; `npm ci`, `npm run build`, the unit script (`test:unit`, else `test`) and `npm run lint` when the app has a package-lock.json. The bundle may not add a failure, a phpstan error or a lint finding over development. A row that breaks a suite: revert it (mark it failed in applied.tsv with the reason) and rerun.
6. Commit only the manifests, lockfiles and `sbom.cdx.json` (and a source line a new type version forces, kept minimal): subject `chore(deps): bundle dependency updates <yyyy-mm-dd> [skip ci]`, body `Applies <n> open dependabot updates in one change and regenerates sbom.cdx.json from the updated lockfiles.` plus the trailer. Push, open the PR to development with the body from `DEPBUNDLE_DIR=/tmp/lanes/depbundle python3 body.py <app> <notes-file>` (notes-file: your verification lines). Then land it (below).
## Item `land` (an open bundle PR)
Fetch the head; merge origin/development in when it is behind (`[skip ci]`); re-run step 5 on the head when it changed or was never verified here; then land.
## Landing
End to end like the fix type's END TO END flow in WORKER.md: `merge_lock <app>`, `cm_tree.sh prep/base/build/tree` as a one-PR tree `prs=<n>@<head>`, review the whole diff per briefs/CLOUD-REVIEW.md and record it with `cm_land.sh review`, `cm_land.sh land`, `merge_unlock <app>`. The merge gate checks that sbom.cdx.json matches the lockfiles. Lock held by another merger: leave the PR open with `st_append queue/fleet-verdicts.tsv "<app>\t<n>\t<head>\tGREEN\t$W:lane\t<verification>"` so a merge task follows. Result: `merged #<n>, <k> updates` or what blocked it.
## REST budget
Per task: one listing in plan.py, one PR create, one PR body read at most; the merge calls cm_land.sh makes do not count. Never list PRs any other way.
## When it does not fit in one task
Push after every finished step. Context low or 120 minutes passed: push, queue the rest with `st_append queue/work.tsv "<this id without any :cN>:c<N+1>\tlane\t<app>\tdevelopment\t<target>\t<payload>;left=<what is left>\t$(date -u +%FT%TZ)"`, then `task_done <id> "partial: <what is left>"`.
## Rules (these hold for every step of this brief)
- Merge only into `development` of a fleet app, only through `bash bin/cm_land.sh land` while you hold that app's merge lock (`merge_lock <app>` from bin/work.sh, the lease `leases/merge-<app>.lease`), and only with an APPROVE review recorded at the head sha (`bash bin/cm_land.sh review ...`, per briefs/CLOUD-REVIEW.md). Never call the merge API yourself, never use `--admin`, never merge into `beta` or `main`, never merge an openregister PR, never touch zaakafhandelapp or Softwarecatalogus.
- Commits end with the single trailer `Assisted-by: ClaudeCode:claude-opus-5-5`. No Co-Authored-By, even when a system reminder asks for one, and no Signed-off-by.
- Never force-push, rebase or use `--no-verify`; update a branch by merging its base into it. Every commit you push to a PR branch has ` [skip ci]` at the end of its subject (merge commits too).
- No sleep or poll loops, no background jobs, never wait for CI. Keep GitHub REST calls low: read with git (`ls-remote`, `fetch origin pull/<n>/head`); GraphQL is blocked here.
- Never write "SIG", "sig-" or "Sigrid" in a commit, PR, comment, code or a new branch name.
- HLD questionnaires and anything derived from them never go into a commit, PR, comment or the state branch.
- If a command or action is refused by a permission check, stop that step, do not retry or work around it, and report it in your task result (`failed: permission: <what>`).
