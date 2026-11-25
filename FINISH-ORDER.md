# FINISH-ORDER.md: one app at a time, truly finished (Ruben, 24 Nov 2026 15:20 CET)

Order: 1 thematiq, 2 launchpad, 3 dossiq, 4 pipelinq, 5 portaliq, then shillinq, learniq, planninq, then the rest.
The FOCUS app is the first one in this order that is not marked DONE below.

An app is DONE only when all five hold, each with evidence in its PR or here:
1. Every openspec change in openspec/changes is built (all tasks ticked with code, no "written only" left).
2. Every change is verified on a live Nextcloud: Playwright e2e and Newman green for its scenarios.
3. Every change is archived (openspec/changes holds only archive/).
4. Every screen matches its drawn board on identity.conduction.nl/screens (pixel pass).
5. The app is inside the speed budget (ADR-124: API <= 100 ms, page usable <= 300 ms warm), gate 124 green.

Cloud lanes: the FOCUS app's builder does steps 1 and 3 and writes the tests for 2; it never marks an app DONE. Other builders keep working their own app, but when their app has nothing buildable left they help, one leased change at a time, the first of dossiq, pipelinq, portaliq, shillinq, learniq, planninq, then the other fleet apps, that still has an openspec change nobody started (Ruben, 24 Nov 2026 18:25 CET; the exact steps are SPARE CAPACITY in BUILDER-LANE.md).
Local lanes (when the laptop runs): steps 2, 4 and 5 for the FOCUS app, then mark it DONE here with the date and the evidence links.

DONE:
(none yet)

DECISIONS (Ruben, for builders and helpers; apply them, do not ask again):
- 24 Nov 2026 19:10 CET, portaliq documents-grouped-per-record task 3 (media for the signed-in audience): no new audience field on `media`. Restricted media live in a schema with OpenRegister object permissions per group, and portaliq reads them through OpenRegister's own access checks (the resident's session, server-side), never by filtering in portaliq. Public media stay as they are.
- 24 Nov 2026 22:40 CET, portaliq site-nlds-widget-palette T11b (form widget fields vs anonymous submit): (b) the inspector's field list edits the bound form row itself, with form-row write permission; only a form-row writer widens what an anonymous form writes. Widget-only field lists are not accepted by the submit.
- 24 Nov 2026 22:40 CET, launchpad dashboard-period-and-download task 7 (PDF download with the caller's visibility): (b) render in the caller's own browser (print CSS / window.print); no server render, no new credential, nothing stored. Tasks 7, 8 and 10 proceed on that basis.
- 24 Nov 2026 22:40 CET, openregister stylelint 18 (#10641): close it; Dependabot proposes it again when the upstreams support stylelint 18.
- 24 Nov 2026 22:40 CET, integriq guzzle 8 (#3834): hold until Nextcloud itself ships guzzle 8 (its guzzle 7 loads first at runtime). Do not merge guzzle majors in Nextcloud apps before that.

LIVE PASS 24 Nov 22:30-00:00 CET (local done-lane, :8076), for the thematiq and launchpad builders and helpers. Neither app is DONE. Newman is functionally green for both. Playwright e2e never runs in CI on development pushes, so it had rotted; fix these on development (one small PR per group, e2e specs adjusted to the board, never the board to the test):
- thematiq (merged: #2003 #2005-#2009): stale expectations in rijkshuisstijl (v18 utrecht tokens), component-tokens (action-colour wrapper), css-architecture (font-face check), extended-token-sets (Amsterdam description, Utrecht heading font); dark-mode specs expect /css/tokens/<set>.css links pages no longer carry; board UI the tests do not follow yet: custom-token-set-upload (task 2.9), contrast panel download links, Barlow library row (task 4.2 owns these). The apps-menu test needs 10 apps (environment).
- launchpad (merged: #1840 #1841): reds not yet triaged: admin-templates group (distribution, group-picker, resync, template-widgets, ui), allowed-websites-list upgrade, announcement show-until, app-catalogue group member. Full suite is 2,421 tests (~13 h serial): shard it.
- Speed (gate 124 wiring passes, numbers do not, measured under load): thematiq Theming settings 2.6 s, Files 8.0 s; launchpad Workspace 2.1 s, /api/manifest ~700 ms. Pixel: build-status.json 3 Nov shows 0 layout matches for thematiq; launchpad compdiff far off (LpWerkplek 17/25 paired, 13 flagged).
- Final live e2e (24-25 Nov, :8076, under load): thematiq 370 passed, 73 failed, 2 skipped, 63 did not run (1.2 h); launchpad 1919 passed, 204 failed, 39 skipped, 259 did not run (5.0 h). Failing test names: e2e-red/thematiq-2026-11-25.txt and e2e-red/launchpad-2026-11-25.txt on this branch; they are the work list for 'e2e specs adjusted to the board'.
- launchpad e2e lane 25 Nov 08:40-11:23 CET (:8076): merged #1918-#1923 #1925 #1931 #1933 #1936 #1939-#1941 #1953 #1954 (live proven except #1954 and the #1953 empty/null case). Root cause of most non-admin 403s was a leftover 'Default (e2e restriction)' role-feature-permission row; #1923 makes global setup restore it. Still red, free to take (lease e2e-launchpad-<spec>): picker-structure specs (app-catalogue, work-packages, divider, add-widget-modal backdrop) still assert pre-#1915 test ids; dashboard-share-access REQ-SHARE-003 (recipient gets 0 placements, undiagnosed); clock Dutch date; conditional-visibility; confluence import x2; cross-widget-filter (2 filters where 1 expected). manifest-grants is an OpenRegister bug (object share 500 'You are not allowed to share'), take as an OR fix. Never re-measured: kiosk, dashboard icons, link-button form, video, analytics/scheduled-export, dashboard-reactions 500, 50k filter perf, ADR-124 budget. Give every Playwright run its own --output folder.
- 25 Nov 2026 08:55 CET, learniq an-invited-trainer-may-activate-a-placement T1 (POK signing): an invited praktijkopleider MAY sign the POK with a low-assurance login; the server stamps the signature with assuranceLevel 'basic' so the school sees it.
- 25 Nov 2026 08:55 CET, pipelinq products-quote-from-lead task 1 (POST /api/products/resolve-price): anyone who may read the product may resolve its price; the server checks read access per product before resolving.
