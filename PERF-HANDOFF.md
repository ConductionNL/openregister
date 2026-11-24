# PERF-HANDOFF.md: speed work handed from the local lanes to the cloud (24 Nov 2026)

Budget (ADR-124): API <= 100 ms, page usable <= 300 ms warm. Every page is still over it. Nextcloud core alone costs ~480 ms of main thread, so take the items that remove whole round trips or megabytes first. One small PR per item, base development (nextcloud-vue: main). Builders of the named app own its items; the nextcloud-vue items belong to the launchpad builder (it has the most to gain), one per run.

## launchpad
- [ ] Fill the dashboard store from the initial state PageController already computes (visible + active dashboards); the store fetches both again (~250 ms).

## dossiq
- [ ] Cases list: type and status labels load in a second wave after the list (5 request levels). Load them with the list or from initial state.
- [ ] ~1.6 s of script runs before the first API call on Cases: find what runs at module load and defer it.
- [ ] #6830's body promises before/after numbers "in a comment below"; edit the body to say the A/B numbers instead: Cases usable 7992 -> 6380 ms, Inbox 6371 -> 3958 ms (with vs without the Files scripts).

## nextcloud-vue (main)
- [ ] All 37 l10n catalogues are bundled statically into every app; load only the reader's language (dynamic import).
- [ ] The whole ~2 MB stylesheet sits inside every app bundle (dossiq Cases spends ~1.3 s recalculating styles). Ship it once, not per app.

## openregister (via the OR fix lanes and integrator)
- [ ] The Mail sidebar app chunk is 7.1 MB and costs ~243 ms on the inbox with the panel idle: lazy-load the panel body.
- [ ] The global script adds 552 KB to every page because it carries all 37 nextcloud-vue locale files.

## all apps
- [ ] English catalogues map 99% of strings to themselves (dossiq 1.25 MB, integriq 489 KB): drop identity entries at build time.
