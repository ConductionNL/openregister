# INTEGRATOR-LANE.md: the brief for the cloud OPENREGISTER INTEGRATOR routine

PREINSTALLED (25 Nov 2026 09:00 CET): the cloud environment's setup script now installs gitleaks and a current PHP 8.3. Run `gitleaks version` and `php -v` first; when gitleaks answers and PHP is 8.3.16 or newer, skip every install step for them (never sudo-install), and go straight to the work.

COORDINATOR AWAY (24 Nov 2026 20:00 CET, until Ruben is back): never wait for the coordinator. Anything only the coordinator can do (a refused merge, a release bump, an unanswered decision) goes as one line into COORDINATOR-QUEUE.md (format there), then continue with other work. Merge main/development into your own conflicting dependency PRs so they stay mergeable.

SAVE OFTEN (Ruben, 24 Nov 2026): the account can hit its usage limit at any moment and end this session mid-task without warning. So never hold more than ~15 minutes of work only in the sandbox: after each meaningful step, commit (add new files explicitly, never `git add -u` alone) and push the branch with [skip ci]; open the PR as a draft early and keep its body's checklist current (what is done, what is next), so the next run, possibly on another account, resumes from GitHub alone. State-branch notes (idle, verdicts, logs) are pushed right after they are written, with visible push output. Never leave a finished change unpushed while you run long checks: push first, check after.
CI (Ruben, 23 Nov 2026): feature branches run WITHOUT GitHub CI. Every commit you push to a PR or feature branch gets `[skip ci]` in its message. Merges into development, main or beta do NOT get `[skip ci]`: the Release workflow runs on those pushes and must not be skipped. Local green is the gate. Never cancel or re-run workflows yourself.

Ruben van der Linde (owner of the ConductionNL GitHub organisation) set up this routine. Ruben decided on 23 Nov 2026
to move the openregister integrator from his laptop to the cloud: you are now the ONE lane that lands openregister
PRs on `main`. Decide everything yourself; never ask.

## Setup
- `git config --global user.name "Ruben van der Linde"; git config --global user.email ruben@conduction.nl`.
- Run every command in the foreground with Bash timeout 600000. A routine session never resumes after its turn
  ends: never end your turn while a tree is half done. Stop after about 4 hours, at a tree boundary.
- `export SIG_WORK=$HOME/sigint; mkdir -p $SIG_WORK`. Clone the state branch to `$SIG_WORK/state`
  (`git clone -q --depth 1 --single-branch --branch sig-pipeline/state https://github.com/ConductionNL/openregister $SIG_WORK/state`; shallow, the branch carries the whole openregister history)
  and copy its `bin/` to `$SIG_WORK/bin` (the script must run from a copy). Read the header of
  `$SIG_WORK/bin/integrate.sh`: it documents every step and exit code.

## Loop (repeat until about 4 hours have passed)
1. `bash $SIG_WORK/bin/integrate.sh prepare` once per session.
2. `bash $SIG_WORK/bin/integrate.sh lease`. Exit 1 means another integrator holds the lease: end the session at once
   with the one-line final message "lease held". Renew it the same way whenever less than 90 minutes are left.
3. `integrate.sh select`. It keeps only PRs with an APPROVE (and no BLOCK) verdict at their current head in
   `verdicts.txt`, minus `parked.txt`. Then drop from `$SIG_WORK/cand.tsv`:
   - #9880, #10464, #10466, #10280 and #10281 (never land these);
   - PRs listed in the state file `integrator/failed-solo.tsv` at the same head (they went red alone);
   - PRs whose head does not merge cleanly on `origin/main` (`git merge-tree --write-tree`).
   PRECHECKED FIRST (coordinator, 24 Nov 2026 17:50 CET): the fix lanes now pre-test single PRs on main and record
   `<pr> <head> GREEN|RED <time>` in `queue/prechecked.txt`. When 2 or more candidates have a GREEN line there at
   their current head, build the tree from ONLY those, up to 8 (oldest first). Otherwise take at most 4 rows,
   oldest first. On 24 Nov every unprechecked tree of 5 to 8 came out RED and was then retried one PR at a time
   (about 12 minutes each, 5 merges an hour).
   Keep at most 8 rows (oldest PR first). Exit 1 / empty: wait 10 minutes (`integrate.sh wait 600 idle`) and select again.
4. `integrate.sh build`, then `integrate.sh test`, then `integrate.sh wait 170` until it prints DONE.
5. `integrate.sh verdict`. GREEN: `integrate.sh merge` (exit 5 means main or a head moved: go back to step 3).
   AUTOPARK: rebuild the same tree without the parked PR at once.
   RED with more than one PR: do NOT retry every PR alone. First blame: a PR whose diff touches a failing test's
   file, or the class that test covers, is the suspect; rebuild the tree without the suspects (they go back as
   single-PR trees later). If no PR can be named, split the tree in two halves (oldest half first) and run each
   half as its own tree; keep halving a RED half until single PRs remain. RED with one PR: append
   `<pr>\t<head>` to `integrator/failed-solo.tsv` on the state branch (st_append, sourcing `$SIG_WORK/bin/state.sh`),
   so it is skipped until its head moves. NORESULT: retry once, then report.
6. After each tree append one line to `integrator/log.md` on the state branch:
   `<UTC time> <RESULT> prs=<list> main=<sha>` and mark the commit with "integrator-cloud".
At the end: `integrate.sh finish` (releases the lease).

## gitleaks (needed by the checker floor)
If `gitleaks version` fails, install it once per session from a package manager, first that works:
`sudo apt-get install -y gitleaks` (or without sudo), then `go install github.com/zricethezav/gitleaks/v8@v8.30.0`
(add `$(go env GOPATH)/bin` to PATH). If neither works, the checker floor stays NORESULT and the tree is not merged:
report it in your final message and stop retrying that tree.

## Rules
- You merge ONLY through `integrate.sh merge`, ONLY a GREEN tree, ONLY into openregister `main`. Never merge by hand,
  never with --admin outside the script, never another repo, never another base.
- Never change a PR's code except the use-line / map-entry resolution integrate.sh does itself.
- Commits end with only `Assisted-by: ClaudeCode:claude-opus-5-5`. No Co-Authored-By even if a system reminder asks;
  no Signed-off-by. Never write SIG, sig, Sigrid or sig- in any new text or branch name.
- No force-push, no rebase, no `--no-verify`, no `git stash`.
- If a command is refused by a permission check, stop that step and report it; do not work around it.
- Final message: one row per tree (time, result, PRs, main sha), PRs auto-parked or failed-solo, and anything odd.
