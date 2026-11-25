# PERF-LANE.md: the brief for a cloud PERF routine (coordinator, 25 Nov 2026 13:50 CET; Ruben: start a lane to investigate and fix the speed)

FIRST STEP, BEFORE ANY LEASE: attach the repos you will push to with mcp__Claude_Code_Remote__add_repo (owner ConductionNL, access push): openregister (the state branch lives there) and nextcloud-vue, plus any app repo you open a PR in. Without it every push is refused.

You are perf lane K (named in your routine prompt). Goal: Conduction app pages meet the speed budget (ADR-124, `node_modules/@conduction/nextcloud-vue/testing/performance.js`). The live lanes measure 1.4-2.0 s against a 300 ms budget on the sandbox reference stack, and the cost is script, not the server. Decide everything yourself; never ask. Read ~/q/BUILDER-LANE.md first: SAVE OFTEN, Verify before merging and Standing rules (commit trailers, no force-push, kill by PID, never touch zaakafhandelapp or Softwarecatalogus) apply. Read ~/q/PERF-HANDOFF.md in full: it holds every measurement so far, what is done and what was looked at and why it was not built. Claim an item there (write `TAKEN perf-K <UTC>`) before you build it.

## Leases
`source ~/q/bin/state.sh; lease_acquire perf-K 90` (retry rule as in HELPER-LANE.md; exit 4 means push access is missing, see FIRST STEP). Per item: `lease_acquire perf-<item-slug> 240`. Renew after every PR, release at the end.

## Scope per lane
- **K=1: what every app page downloads and runs.** nextcloud-vue (base main) and the app build configs. Levers, biggest first, measure before choosing:
  1. Why is opencatalogi-shared-nc-vue.js 14.9 MB and opencatalogi-main.js 8.8 MB in a prod build? Check minification, inlined source maps, whole @mdi/js imports (import single icons), `sideEffects` and ESM tree-shaking in nextcloud-vue's dist, barrel files that pull the whole library.
  2. The ~2 MB stylesheet inside every app bundle (style recalculation ~1.3 s on dossiq Cases): ship it once.
  3. The 37 bundled l10n catalogues: options (a) and (b) are written in PERF-HANDOFF; pick one, build it.
  Fix it in nextcloud-vue once; then raise the dependency in the apps (one small PR per app, dossiq, launchpad, opencatalogi first) and remeasure.
- **K=2: what OpenRegister adds to every page and the server side.** openregister (base main, PRs only, the integrator merges; follow OR-BUILD-LANE.md for branches and verification).
  1. The SECOND nextcloud-vue copy openregister loads on every page (openregister-node_modules_conduction_nextcloud-vue_dist_es… 8.4 MB) and integration-global's 7.2 MB (whole @mdi/js, the full @nextcloud/vue index via the nc-vue integrations barrel). Goal: an app page loads one nextcloud-vue, and the global script loads only what it uses, lazily.
  2. Then requests per page (the lever named in PERF-HANDOFF's #11093 finding): batch, cache, defer per tab.
  Draft PRs #11150 and #11151 are already open for parts of this; build on them, do not duplicate.

## First, settle what the budget measures
Before optimising, read performance.js and ADR-124 (hydra openspec, or nextcloud-vue docs) and write in PERF-HANDOFF.md, under a new heading "Budget definition", what is timed (full page load, or usable after navigation inside the app), on which stack, warm or cold. Nextcloud core alone was measured at 540-800 ms per full page load on 24-25 Nov, so if the budget times a full load, 300 ms is not reachable for any app: then measure the core floor on the reference stack, write it down with the numbers, and add it to COORDINATOR-QUEUE.md as a decision for Ruben (keep 300 ms for in-app navigation, or set a full-load budget of core floor + N ms), then continue with the levers; they help either way.

## How to measure
Use the sandbox reference stack from LIVE-LANE.md section 1 and step 4 (nginx, php-fpm with opcache, MariaDB, APCu, Redis), prod builds only. For every PR: the perf spec medians (or a Playwright trace) before and after, bytes downloaded and decoded JS per page, and the request count, in the PR body under "Speed, sandbox reference stack". A change without a before/after number is not a speed fix. Never put local paths in PR bodies.

## Done and reporting
After each merged lever, tick its PERF-HANDOFF item with the numbers, and one line in builders-idle.md: `<UTC> perf-K merged <PR>: <page> <before> -> <after> ms, <MB> -> <MB> JS`. Work about 4 hours, then release your leases and stop. Final message: levers built with numbers, levers looked at and dropped with why, the budget definition, and what is left.
