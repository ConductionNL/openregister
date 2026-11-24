# PERF-HANDOFF.md: speed work handed from the local lanes to the cloud (24 Nov 2026)

Budget (ADR-124): API <= 100 ms, page usable <= 300 ms warm. Every page is still over it. Nextcloud core alone costs ~480 ms of main thread, so take the items that remove whole round trips or megabytes first. One small PR per item, base development (nextcloud-vue: main). Builders of the named app own its items; the nextcloud-vue items belong to the launchpad builder (it has the most to gain), one per run.

## Draft PRs to finish first
- [ ] dossiq #6838 (case/status types read once per request) and #6839 (tenant memberships cached 60 s): merge development in, run `composer check:strict` once, fix the new phpcs findings on #6839's test lines, mark ready, merge.
- [ ] openregister #11032-#11035 (audit read row once per request, register lookup, organisation parent chain, SchemaMapper::findInIds cache): OR fix lanes run the diff check and check:strict, mark ready; the integrator lands them.

## launchpad
- [x] Fill the dashboard store from the initial state PageController already computes (visible + active dashboards); the store fetches both again (~250 ms). (launchpad #1739, merged 2026-11-24)

## dossiq
- [ ] Cases list: type and status labels load in a second wave after the list (5 request levels). Load them with the list or from initial state.
- [ ] ~1.6 s of script runs before the first API call on Cases: find what runs at module load and defer it.
- [x] #6830's body promises before/after numbers "in a comment below"; edit the body to say the A/B numbers instead: Cases usable 7992 -> 6380 ms, Inbox 6371 -> 3958 ms (with vs without the Files scripts).

## nextcloud-vue (main)
- [ ] All 37 l10n catalogues are bundled statically into every app; load only the reader's language (dynamic import).
- [ ] The whole ~2 MB stylesheet sits inside every app bundle (dossiq Cases spends ~1.3 s recalculating styles). Ship it once, not per app.

## openregister (via the OR fix lanes and integrator)
- [ ] The Mail sidebar app chunk is 7.1 MB and costs ~243 ms on the inbox with the panel idle: lazy-load the panel body.
- [ ] The global script adds 552 KB to every page because it carries all 37 nextcloud-vue locale files.

## openregister, later (security-sensitive or needs a decision: leave a note in the PR, do not merge without review)
- [ ] Search rebuilds access control per query part (33-90 ms/request): per-request memo of the schema baseline authorization.
- [ ] nextcloud-vue useLifecycleTransitions calls the deprecated /api/objects/{uuid}/available-actions (300 ms); switch to /{register}/{schema}/{id}/actions.

## all apps
- [ ] English catalogues map 99% of strings to themselves (dossiq 1.25 MB, integriq 489 KB): drop identity entries at build time.
