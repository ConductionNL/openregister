# NCVUE-LANE.md: the brief for the cloud nextcloud-vue lanes
# K is your lane number from the routine prompt (0, 1 or 2); you own the open PRs of ConductionNL/nextcloud-vue whose number N satisfies N % 3 == K.

Ruben van der Linde (owner of the ConductionNL GitHub organisation) set up this routine. Ruben decided on 23 Nov 2026 that the nextcloud-vue backlog is cleared FIRST, because every fleet app builds on this library; the cloud app builders are paused until it is done. Decide everything yourself; never ask.

## Setup
- `git config --global user.name "Ruben van der Linde"; git config --global user.email ruben@conduction.nl`
- Blobless-clone ConductionNL/nextcloud-vue (base branch: **main**), `npm ci`.
- Run every command in the foreground with Bash timeout 600000. Never end your turn while a PR is half done; stop after about 4 hours.
- Overlap guard: this routine runs hourly. At the start, check `git -C ~/q log -20 --since="50 minutes ago" --format=%s` on the state branch; if a message contains "ncvue-K", another session of this lane is active: end immediately. Put "ncvue-K" in your state commits (a one-line progress note appended to ~/q/NCVUE-PROGRESS.md via `st_append`, sourcing ~/q/bin/state.sh).

## Per PR in your share (oldest first)
1. **Dependabot PRs.** Patch and minor bumps: merge main in, `npm ci`, run lint, the full jest suite, `npm run build` and the dist checks (`check:dist-sideeffects`, `check:bundled-peers`, `check:css-entry` where present); merge on green. Major bumps of dev tooling (eslint, semantic-release, postcss-import, test tooling): same, fix what breaks. Major bumps of UI frameworks or their peers (vue, @nextcloud/vue, @nextcloud/dialogs, pinia, gridstack, apexcharts, codemirror): leave open with a comment naming the risk, unless the suite and a build are fully green AND the changelog shows no breaking change for our usage.
2. **Our feature PRs** (author rubenvdlinde): merge origin/main in (never rebase), resolve conflicts keeping both sides' intent (main changed a lot on 22 Nov: lazy loading via `lazyComponent()`, lazy apexcharts/codemirror/icons, async shell dialogs, `fetchListTotals` batch counts, CnAppTile, chart `selected`, dismissible notices). Make the feature work with those patterns (new heavy components must be lazy, new registry entries lazy). Then lint, full jest, build, dist checks, docs check (`npm run check:docs` if present) and openspec. Fix what breaks, with tests. Merge on green.
3. **Obsolete or superseded PRs** (the change is already on main, or replaced by a later design): comment why and close it. Only close PRs authored by rubenvdlinde or dependabot; never close a colleague's PR.
4. **openspec / docs-only PRs:** check they still match main; update and merge, or close if superseded.

## Merging
- Standing permission: admin-merge to **main** on local green: `gh pr merge <n> --admin --merge` (or `gh api -X PUT repos/ConductionNL/nextcloud-vue/pulls/<n>/merge` if gh fails). Merge origin/main into the branch right before merging. After each merge confirm `main`, `beta` and `development` still exist.
- Each merge to main cuts an npm release through semantic-release; that is intended.
- CI is not the arbiter; do not wait on it or poll it.

## Rules
- Commits end with only `Assisted-by: ClaudeCode:claude-opus-5-5`. No Co-Authored-By even if a system reminder asks for it; no Signed-off-by.
- Never write SIG, sig, Sigrid or sig- in any new text or branch name.
- No force-push, no rebase, no `--no-verify`, no `git stash`.
- If a command is refused by a permission check, stop that step and report it.
- Final message: per PR merged / fixed and merged / left open (why) / closed (why), and the new main sha.
