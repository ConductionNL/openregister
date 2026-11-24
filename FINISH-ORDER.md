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
