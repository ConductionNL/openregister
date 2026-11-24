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
