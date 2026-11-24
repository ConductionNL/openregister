# PERF-HANDOFF.md: speed work handed from the local lanes to the cloud (24 Nov 2026)

Budget (ADR-124): API <= 100 ms, page usable <= 300 ms warm. Every page is still over it. Nextcloud core alone costs ~480 ms of main thread, so take the items that remove whole round trips or megabytes first. One small PR per item, base development (nextcloud-vue: main). Builders of the named app own its items; the nextcloud-vue items belong to the launchpad builder (it has the most to gain), one per run.

## dossiq demo bugs (FIRST for the dossiq builder, before everything else)
- [ ] Edit case Save hang: ROOT CAUSE FOUND AND FIXED in nextcloud-vue #2304 (merged, release 2.204.1): CnFormDialog re-seeded formData on the post-lock re-read and dropped stored x-prop answers, so required questions read empty and Save stayed disabled. dossiq follow-up: raise @conduction/nextcloud-vue to >= 2.204.1 (check `npm view @conduction/nextcloud-vue@2.204.1`), and make tests/vitest/caseEditFormSaves.spec.js reproduce the real case (give one definition isRequired: true and let the case re-read after the lock land ~50 ms late; it must fail on 2.203.0 and pass on 2.204.1). One PR, merge on local green.
- [ ] Edit case dialog never saves: pick a handler (e.g. Thomas Brouwer), click Save; the footer says "Saved just now" but Save keeps spinning, the dialog never closes and no write request reaches the server, so the assignee stays unchanged. The open dialog also leaves an OpenRegister edit lock on the case. Find why the save is never sent (dossiq edit dialog and the nextcloud-vue form it uses), fix it, add a unit test that the save call is made and the dialog closes. A draft PR titled "fix: ..." from a local lane may already carry the diagnosis: finish that one instead of starting over. NOTE 24 Nov (cloud): not reproducible at nc-vue 2.203 in a mounted CnDetailPage with the CaseDetail config (PATCH sent, dialog closes, lock released); regression test in dossiq #6868 (merged 24 Nov). Suspect: CnFormDialog.executeConfirm sets loading before building the payload with no try/catch; check the demo browser console and the nc-vue build it serves.
- [x] (dossiq #6866, merged 24 Nov: the share is accepted for the caller before the link; live check follows) "Open in editor" (dossiq #6836, CaseFileEditorController) opens /apps/onlyoffice/<fileId> but ONLYOFFICE shows "File not found" for the case user. Case files live in the openregister system user's folder; the controller shares the one file with the user before returning the link, yet the recipient cannot resolve it (share not accepted/mounted, or the link uses the owner's file id/path). Fix it so the user's editor opens the shared file. Same rule: finish an existing "fix:" draft if there is one.

## Draft PRs to finish first
- [x] (both merged into development by 24 Nov) dossiq #6838 (case/status types read once per request) and #6839 (tenant memberships cached 60 s): merge development in, run `composer check:strict` once, fix the new phpcs findings on #6839's test lines, mark ready, merge.
- [ ] openregister #11032-#11035 (audit read row once per request, register lookup, organisation parent chain, SchemaMapper::findInIds cache): OR fix lanes run the diff check and check:strict, mark ready; the integrator lands them.

## launchpad
- [x] Fill the dashboard store from the initial state PageController already computes (visible + active dashboards); the store fetches both again (~250 ms). (launchpad #1739, merged 2026-11-24)

## dossiq
- [x] (dossiq #6867, merged 24 Nov: lookups start on entering Cases, beside the list; live numbers follow) Cases list: type and status labels load in a second wave after the list (5 request levels). Load them with the list or from initial state.
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
- [ ] English catalogues map 99% of strings to themselves (dossiq 1.25 MB, integriq 489 KB): drop identity entries at build time. NOTE 24 Nov (cloud): thematiq done in #1967 (merged): scripts/build-l10n-js.js drops identity entries for en only, en.json stays complete, gate 103 still green; en.js 183 KB -> 1.6 KB. Other apps with the same generator can copy browserEntries() and tests/vitest/l10n-browser-catalogue.spec.js.
