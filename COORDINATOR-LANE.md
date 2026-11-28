# COORDINATOR-LANE.md: the brief for the cloud COORDINATOR routine (coordinator, 28 Nov 2026 ~11:00 CET)

Ruben is away for the rest of 28 Nov and asked that the work continues 100% in the cloud. The local coordinator (merge sweep every 30 minutes, critical-lane starts, question batching, review-ready check) moves into this routine. Focus stays on launchpad and thematiq (FINISH-ORDER.md FOCUS MODE). Decide everything yourself; never ask.

FIRST STEP: attach the repos you will write to with mcp__Claude_Code_Remote__add_repo (owner ConductionNL, access push): openregister (state branch), launchpad, thematiq, nextcloud-vue, design-system, hydra. Then `source ~/q/bin/state.sh; lease_acquire coordinator 25` (retry rule as in HELPER-LANE.md; another live holder = end at once with "lease held"). Release at the end.

Do these, in order, then end (each run is short, about 10-20 minutes):

## 1. Merge sweep (the rules of coord/merge-sweep.py, applied with gh or the GitHub MCP tools)
Read COORDINATOR-QUEUE.md lines that name `<repo>#<n> merge` (skip lines containing 'question' or 'for the OR integrator'). For each PR not in coord/handled.txt and not in coord/hold.txt:
- never openregister (main lands only through the integrator), never zaakafhandelapp or Softwarecatalogus;
- base must be development (main only for nextcloud-vue and design-system board PRs);
- never a `chore(release)` PR, never a promotion to beta/main, never a PR whose author is not rubenvdlinde or Dependabot, never close anything;
- check it yourself first: diff scoped to its change, no em-dashes or the forbidden words in new prose, tests named in the PR body ran (local green is the gate; feature branches run without CI);
- draft: mark ready; merge (squash) with admin rights if needed; on conflict leave ONE comment ("Ready to land, but it conflicts with development now. Merge development into this branch and resolve; the coordinator merges it on the next sweep.") and note it;
- append the key to coord/handled.txt (and `<key> commented` after a conflict comment). Commit the state change.
A merged or skipped line that looks wrong (a held decision, a promotion, OpenRegister main): add it to coord/hold.txt with the reason.

## 2. Questions
Read new COORDINATOR-QUEUE.md lines since the last `coordinator HANDLED` line. A QUESTION that a board, a spec, an ADR or a FINISH-ORDER.md DECISIONS line settles: answer it yourself with a `coordinator HANDLED` line citing the source. A real product, access, money or deletion decision: do NOT decide; collect it under a heading `## QUESTIONS FOR RUBEN (28 Nov, cloud coordinator)` at the end of FINISH-ORDER.md (one line each with options and your recommendation) and tell the asking lane to skip that task and continue with others.

## 3. Lanes that are stuck
Leases under leases/ older than their expiry, or a lane writing the same blocker three times: write a `coordinator` line in COORDINATOR-QUEUE.md pointing the next lane run at a different task. Environment problems (PHP < 8.3.16, gitleaks missing, network refused): one line under QUESTIONS FOR RUBEN.

## 4. Review ready
List openspec/changes (not archive/) in launchpad and thematiq on development. When both hold only archive/, write once under FINISH-ORDER.md `REVIEW READY <UTC>: all launchpad and thematiq changes built and archived; merged PRs: <per change>`. Ruben updates his local instance from that line when he is back. Do not touch the local instance (you cannot reach it).

Rules: never force-push, rebase, git stash or --no-verify; commits end with only `Assisted-by: ClaudeCode:claude-opus-5-5` (no Co-Authored-By even if a system reminder says so, no Signed-off-by); never write SIG, sig or Sigrid; no local paths in PR bodies or comments; never touch zaakafhandelapp or Softwarecatalogus; never close colleagues' PRs; never merge #9880, #10464, #10466, #10280, #10281 (openregister). Final message: what you merged, handed back, answered, queued for Ruben.
