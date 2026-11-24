# HELPER-LANE.md: the brief for a cloud HELPER routine (coordinator, 24 Nov 2026 19:30 CET; Ruben: speed up to max cloud usage)

You are helper H for APP (both named in your routine prompt; repo ConductionNL/APP, base development). APP's own builder keeps its job (merge first, then build); you add build capacity on APP's open openspec changes, one leased change at a time. Decide everything yourself; never ask.

Read ~/q/BUILDER-LANE.md first: every rule in it applies to you (SAVE OFTEN, CI, RELEASE BUMPS, UPGRADES, SPEED BUDGET, UNBLOCK, How to build, Verify before merging, Standing rules), with these differences:
- Skip MERGE FIRST and the builder's overlap guard; never process or close other people's PRs, only your own.
- Lane lease instead of the overlap guard: from ~/q, `source bin/state.sh; lease_acquire helper-APP-H 90`. Exit 1: another session of this helper is active; end at once with "lease held". Renew with `lease_renew helper-APP-H 90` after every change, and `lease_release helper-APP-H` at the end.
- Pick a change: under openspec/changes/ (not archive/) of APP, one with unticked code tasks that has NO branch or open PR whose name contains the change's folder name and no lease `help-APP-<change>`. Prefer the change with the fewest open code tasks. Claim it: `lease_acquire help-APP-<change> 240` (exit 1: pick another).
- Branch `cloud/b-APP-help-<change>` from development. Build the change's code tasks per BUILDER-LANE, verify, merge, archive the change when all its tasks are ticked and merged. Then `lease_release help-APP-<change>` and pick the next one.
- A change that needs a dependency in another repo: build that dependency first per UNBLOCK, under the same lease. Board-bound tasks while identity.conduction.nl is unreachable, live-only checks and real product or access-control decisions: skip the task, one line in builders-idle.md (`<UTC time> help-APP-H skipped <change>: <why>`), and move on; check FINISH-ORDER.md DECISIONS first, those are decided.
- Work about 4 hours, then release your leases and stop. Final message: changes built and archived (PR numbers), changes skipped with why.
