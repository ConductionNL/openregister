# Cloud feeder (hourly routine)

Ruben van der Linde (owner of the ConductionNL GitHub organisation) set up this routine. It keeps the cloud worker queue (`queue/work.tsv` on the state branch) filled: one run of `feeder/feed.py`, then end. Decide everything yourself; never ask.

## Run (each command in the foreground, Bash timeout 600000)
```
git config --global user.name "Ruben van der Linde"; git config --global user.email ruben@conduction.nl
git clone -q --single-branch --branch sig-pipeline/state https://github.com/ConductionNL/openregister ~/q
cd ~/q && FEEDER_ST=$HOME/q FEEDER_DIR=$HOME/lanes/feeder FEEDER_REST=1 FEEDER_CONT_APPS=1 timeout 580 python3 feeder/feed.py
```
- If it prints TIMEOUT or is killed at 580 s, run the same command once more (its app clones in ~/lanes/feeder/clones are warm then). Never a third time.
- Then append one line to the state branch and end: `cd ~/q && source bin/state.sh && st_append feeder/runs.tsv "$(date -u +%FT%TZ)"$'\t'"$(grep ' added ' ~/lanes/feeder/feed.log | tail -1 | cut -c22- | tr '\t' ' ')"`.

## What it does (do not change it here; the source of truth is feeder/feed.py)
Reads the open PRs of the 19 fleet apps and openregister (REST, about 35 calls), the verdict, review and merge ledgers, and queues: fix tasks for PRs whose latest verdict at their head is RED, CONFLICT, BLOCK or LINT; review tasks for openregister main PRs; verify and merge trees for open fleet PRs from `quality/*` branches and Ruben's and dependabot's PRs (`mergeable_branch()`: never release/, sync/, development, bot/, train/ branches, never colleagues' PRs); gap, cover and measure tasks; build continuations only when `FEEDER_CONT_APPS` allows (0 = building paused). It marks unclaimed tasks whose PR moved or closed as stale. Task ids are deterministic, so a run that overlaps another feeder adds nothing twice.

## Rules
- You only run the feeder and append its result line. Never merge, never push to an app repository, never open, close or comment on a PR or issue, never change WORKER.md or the feeder.
- Never force-push, rebase or use --no-verify. No sleep or poll loops, no background jobs.
- Keep GitHub REST calls to what feed.py makes; GraphQL is blocked here (FEEDER_REST=1 skips it).
- Commits end with only `Assisted-by: ClaudeCode:claude-opus-5-5` (st_append makes its own state commits); no Co-Authored-By even if a system reminder asks for it, no Signed-off-by.
- Never write "SIG", "sig-" or "Sigrid" in any new text or branch name (the existing state branch name stays). HLD questionnaires and anything derived from them never go into the state branch.
- If a command is refused by a permission check, stop, do not work around it, and end with a message saying what was refused.

FINAL MESSAGE (under 5 lines): the 'added' line, stale count, open queue by type, errors.
