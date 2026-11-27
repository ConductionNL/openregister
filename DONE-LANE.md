# DONE-LANE.md: the brief for a cloud DONE routine (coordinator, 27 Nov 2026 06:10 CET; Ruben: first get launchpad and thematiq to DONE)

FIRST STEP, BEFORE ANY LEASE: attach the repos you will push to with mcp__Claude_Code_Remote__add_repo (owner ConductionNL, access push): openregister (the state branch lives there), APP, and design-system or nextcloud-vue if you change them. Without it every push is refused.

You are the DONE lane for APP (named in your routine prompt; repo ConductionNL/APP, base development). Your job is to make APP meet every condition in FINISH-ORDER.md "An app is DONE only when all five hold", prove each with evidence, and then mark APP DONE there. Decide everything yourself; never ask. Read ~/q/BUILDER-LANE.md (SAVE OFTEN, Verify before merging, Standing rules), ~/q/LIVE-LANE.md (how to bring up Nextcloud: sections 1-3) and FINISH-ORDER.md in full first, including every DECISIONS line: they are answered, apply them.

## Lease
`source ~/q/bin/state.sh; lease_acquire done-APP 120` (retry rule as in HELPER-LANE.md; exit 4 = push access missing). Renew after every step; release at the end. Per fix: `lease_acquire done-APP-<topic> 240`, branch `cloud/done-APP-<topic>`.

## The five checks, in this order
1. Open changes: list openspec/changes (not archive/). For each, every task must be ticked with code, tests and live evidence. Build what is missing (follow BUILDER-LANE How to build), or, when a builder or live lane holds its lease, help it on a different task. Archive each change when complete (spec synced).
2. Live e2e and Newman: on your own sandbox (LIVE-LANE section 1, PostgreSQL or MariaDB, never SQLite: OpenRegister supports MariaDB/PostgreSQL only), run APP's FULL Playwright suite and its Newman collection. Fix every red: the spec to the board, or the app to the spec, never the board to the test (except where a DECISIONS line says otherwise, e.g. launchpad's Image form keeps the Fit select and preview). A red caused by OpenRegister: fix it in OpenRegister as a PR for the integrator (OR-BUILD-LANE.md rules) and name it in COORDINATOR-QUEUE.md. Record the final counts.
3. Pixel pass: every screen of APP against its drawn board on identity.conduction.nl/screens (design-system repo, ids `<app>/<Board>`). Use the existing comparison tooling in ConductionNL/design-system (look for the pixel/compdiff scripts and build-status.json); if none runs, screenshot each page at 1920x1080 and compare with the board's rendered image yourself. Fix the app to the board (boards are canon). Record per screen: matched / fixed / remaining difference with reason.
4. Speed: SPEED RULE (Ruben, 27 Nov 2026 09:10 CET, replaces the 300 ms-total line of 06:30): (1) an app page may add at most 150 ms to the Nextcloud floor for a full page load: app page total <= floor + 150 ms, where the floor is the bare Nextcloud Dashboard (no Conduction app enabled) measured in the same run on the same sandbox reference stack, warm; (2) the first useful content (the page's main content visible) appears within 300 ms; (3) every API request <= 100 ms. Report per page: floor, total, total minus floor, first useful content. Measure on the sandbox reference stack (LIVE-LANE step 4) with nextcloud-vue testing/performance.js, the floor in the same run. Pages over the rule: fix the app's part (PERF-LANE.md levers) or hand a measured item to PERF-HANDOFF.md. Gate 124 must be green.
5. Archived: openspec/changes holds only archive/.

## Marking DONE
Only when all five hold: add a line under "DONE:" in FINISH-ORDER.md: `<app> DONE <UTC date>: changes 0 open; e2e <passed>/<total> + Newman <n>/<n> on <stack>; pixel <matched>/<screens>; speed <worst page> floor <ms> / total <ms> (+<ms>, rule +150) / first content <ms> (rule 300); evidence: <PR numbers>`. Never mark DONE with an exception; if one condition cannot be met, write the blocker in COORDINATOR-QUEUE.md and keep working the others.

## Rules
- Merge your own PRs on local green where your session may; otherwise leave them open with one line in COORDINATOR-QUEUE.md (`<repo>#<n> merge: ...`), the coordinator merges every 30 minutes.
- Every Playwright run gets its own --output folder. Kill by PID only. No local paths in PR bodies.
- Work about 4 hours, then release your leases and stop. Final message: per check, where it stands, with numbers and PRs.
