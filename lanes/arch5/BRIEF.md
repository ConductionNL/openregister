# Lane brief: ARCH5 (maintainability of dossiq and pipelinq to an estimate of 4.5)

Claimed as a `lane` task (WORKER.md). Payload: `brief=lanes/arch5/BRIEF.md;app=<dossiq|pipelinq|stackiq>;item=<name>;branch=<branch>;note=<text>`.

## Goal and measure
Raise the maintainability estimate of the app to a midpoint of at least 4.5 (5 stars: `ratingToStars(mid)`, mid = (p1 + p-1) / 2 of the estimator) by behaviour-preserving refactors of PHP in lib/ (and Vue/JS duplication in src/). No gaming of the metric: no reformatting or re-wrapping, no 1:1 body wraps (a public method forwarding all its parameters to a private copy of its body), no renaming variables or reordering use statements to break duplicate windows, no comments inserted into code. Rejected before: a second pass of 1:1 body wraps (quality/arch5-g-f2 after 9e7a3e2a45).
Where things stand (16 Nov 08:00 UTC, development): pipelinq 4.38-4.40, dossiq 4.34-4.37. Merged: pipelinq #3867 #3869 #3874 #3881, dossiq #6576 #6579 #6586.

STOPPED APPS (coordinator, 16 Nov 23:00): dossiq, shillinq, integriq. Rounds there gain under +0.01 each within this recipe. A task for one of them: `task_done <id> "skipped: stopped app"` at once, build nothing, and never queue a next round for it.

## Items
- `pipelinq-k`: branch `quality/arch5-k` (72197b6ef) = helpers I (contracts, +0.07) + J (5+ parameters, +0.04) + K (last mile), development merged in; phpstan 0, suite 9000 tests no failures, cscmp/pmdcmp SAME, no-admin-idor 67 = 67 when it was built; K measured 4.51-4.52 before the merge. Left: merge origin/development in again, re-verify (below), measure, open the PR (body: what moved, estimate before and after, verification), land it.
- `dossiq-round5`: branch `quality/arch5-dq-round5` (4193cd7603) = helpers L (component independence) + M (5+ and 7+ parameter methods) + N (PHP duplication) + n-f3 + n-f4. Fixes already in: ContactMomentController reverted (lookup sweep), CopyMessageAttachments repair (identity sweep), ComplaintHearing and SubsidieBeschikking contracts dropped (idor), QueueItem import, catch allowlist ceiling 231 followed. no-admin-idor equals development (86). Left: merge origin/development in, verify, measure, PR, land.
- `next-<app>`: after the item above landed and the estimate is still under 4.5: pick the largest remaining lever (exposed lines via Kernel contracts, 5+ parameter methods, duplication), do one round on a new branch `quality/arch5-<app>-r<k>`, verify, measure, PR, land, and queue another `next-<app>` when still under 4.5.

## Recipes
- Interfacing: private methods drop parameters they can read from `$this`, or take one small final readonly value class (only for 5+ parameters); public/protected methods keep their signature and become a short orchestration of private steps that take 2 parameters or fewer, split by real phases (validate, load, build, write).
- Component independence: a big class used from another top-level component gets a `<Name>Interface` in lib/Kernel/Contracts with only the methods outside callers use, the class implements it, it is bound like the existing contract bindings, and outside callers' constructor types move to the interface. Never move or rename Event, BackgroundJob or Repair classes (stored by class name); never change constructor defaults.
- Duplication: a private method copied into many classes becomes one small internal final class or trait in the `Support` namespace of the one component that uses it; repeated blocks in one file become one private method.
- Hard boundary: never change a public or protected method signature, a constructor signature, anything under lib/Contract or lib/Kernel/Contracts that already exists, an interface another app uses, a class name or namespace, a route, an event, a container id or a constant value. Never change an openregister call (find/saveObject/... with option objects): not its text, not its arguments. Mind phpmd CouplingBetweenObjects (prefer documented array shapes near the limit).
- Gates that must not move: controllers' guards stay visible to the no-admin-idor gate (never move a refusal or guard out of a controller method or its own private methods); compare every controller branch vs development with `python3 /tmp/lanes/dotgithub/hydra-gates/scripts/lib/check_no_admin_idor.py <files>` (clone: `git clone -q --depth 1 https://github.com/ConductionNL/.github /tmp/lanes/dotgithub`) (method names, line numbers dropped): no new line. Source-reading tests (tests/Unit/Architecture, CitizenLookupRateLimitTest, SeedWriteIdentityTest and similar) must keep finding what they look for in the class itself; update allowlists only to follow moved code, never to admit new sites.
- New PHP files copy a neighbour's header (PHPDoc header, SPDX lines, `declare(strict_types=1);`), class docblock and `@spec` tag; every new class gets a unit test with an SPDX header. Name classes after what they are, never Params/Context/Dto/Helper. Comments describe code, never the process.

## Setup and tools
```
mkdir -p /tmp/lanes/arch5 && cp -r ~/q/lanes/arch5/* /tmp/lanes/arch5/
git clone -q https://github.com/rubenvdlinde/CommonGroundChecker /tmp/lanes/scan/checker
git -C /tmp/lanes/scan/checker checkout -q aa578398248c36aeb9f3d4620772c78993f41c52   # the estimator the floors use
git clone -q https://github.com/ConductionNL/<app> /tmp/lanes/arch5/<app> && cd /tmp/lanes/arch5/<app> && git checkout <branch>
bash ~/q/bin/composer-cloud.sh .
```
dossiq's tests read openregister main beside the app: `git clone -q --depth 1 --branch main https://github.com/ConductionNL/openregister /tmp/lanes/arch5/openregister` and `bash ~/q/bin/composer-cloud.sh /tmp/lanes/arch5/openregister`.
- `tools/cscmp.sh <clone> <outprefix>` must print SAME-PHPCS (new files: 0 findings); `tools/pmdcmp.sh <clone> <outprefix>` SAME-PHPMD (a rule count may go down, never up per file).
- `tools/verify.sh <clone> <outprefix> <fail-base>`: phpstan errors no more than development (pipelinq 0, dossiq 2: FilinqRedactionClient:214, BespokeServiceRegistrar:182) and SAME-FAILS against `<app>-fail-base.txt` (pipelinq empty, dossiq 19). Re-baseline on current development when development moved: run the suite there once and use its failure set.
- `dups.py <clone> php 60`, `exposed.py <clone> 40`: duplication and exposure reports. `summ.py <m.json>`: summary of an estimate.
- Measure on an exported tree, never with vendor moved while a check runs: `git archive HEAD | tar -x -C /tmp/lanes/arch5/m-<k>` then `python3 /tmp/lanes/scan/checker/scripts/maintainability/check_maintainability.py report /tmp/lanes/arch5/m-<k> --json /tmp/lanes/arch5/m-<k>.json > /dev/null` and `python3 /tmp/lanes/arch5/summ.py /tmp/lanes/arch5/m-<k>.json | head -12`. Measure development the same way for the before figure.
- Unit suite in the foreground (Bash timeout 600000; split by directory if one run would exceed it). The merge gate's maintainability floor (no rating drops more than 0.01) runs at landing anyway.

## PR and landing
Commits `refactor(<area>): <what> [skip ci]`; `git add lib tests src` then `git status --short` (new files listed; never `git add -u`). PR to development, title `refactor(<app>): <what>`, body with the estimate before and after and the verification lines. Never review or land your own PR (a self-review is refused by the permission check and wastes the task): verify the tree (one-PR tree via cm_tree.sh), record a GREEN verdict row with `st_append queue/fleet-verdicts.tsv` as the fix type does, and end the task with `PR #n, GREEN`; the feeder queues review and merge for another worker. (16 Nov 22:10, coordinator)

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
