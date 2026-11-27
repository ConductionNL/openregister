# launchpad pixel pass 2026-11-27, done-launchpad run c (cloud)

Stack: NC 34.0.6 tarball, PostgreSQL 16, Redis locking, APCu local, php -S (pcov off); OR main; launchpad development; demo seed `occ launchpad:demo:load --retire-showcases`, pieter (nl), his pending acknowledgement confirmed first. thematiq NOT installed in this sandbox, so every card carries a font-family flag (Fira Sans vs system-ui) and ink colours differ: counts below are NON-FONT flag rows (`grep FLAG | grep -v fontFamily` over compdiff's .md), the same measure before and after.
Tooling: design-system 601dd11 compdiff, boards rebuilt with build.py --only (LpVandaag LpIntranet LpManagement LpWerkplek). `--inject-css`/`--inject-js` used to prove each fix before building it.

| board | start of run | end of run | merged |
|---|---|---|---|
| LpVandaag (1920) | 51 | 29 | launchpad#2224 #2232 #2236, nextcloud-vue#2417 (2.235.0) |
| LpIntranet | 51 | 32 | same |
| LpManagement | 24 | 24 | (no regression) |
| LpBeheer* (7 boards) | tabCount 16 vs 12 drawn | 12 tabs | launchpad#2210 (decision (b) 09:40Z) |

Fixed: admin tab strip 16 -> 12 (#2210); people lists ruled as drawn + showSearch (nc-vue #2417 + seed); text and people widgets on the card inset (#2224); markdown table in a text card (#2232, table 9 -> 4 flags); announcement frame for placements that ask for confirmation (#2236, Woo card 10 -> 4).

Remaining (with reason), for the next pixel run:
- Every card: font family and ink colours: needs thematiq with the Zuiddrecht set in the sandbox (sandbox, not app).
- Woo card: "Aankondiging" pill + author/time row above the title (a text placement has no author/time; would need an announcement-backed placement or text-widget meta fields: product/design choice) and the gap under the title.
- LpVandaag missing on live: pageHeader "Vandaag" (review item 1 / PR #2207 by another lane), dossiq case cards and Ongelezen mail (other apps not in this sandbox), the Vandaag eerst items (dossiq).
- Nieuwe collega's: avatar row inset (board 79 text start = 44px avatar) and the separator under the title; Verjaardagen: cake icon in the title (titleInsetLeft 45), 'Vandaag/Morgen' pills (birthday list mode does not exist in CnPeopleWidget).
- Intranetmenu: content 4px left of the inset, gap under the title 14 vs 18.
- LpIntranet: "Wat vindt u van dit intranet?" (poll, 5), maintenance banner card (rendered as a page banner live), page header title Y.
- LpManagement: chart cards content inset 27 vs 19 and header separators (CnChartWidget / stat cards inside launchpad's --nc-dashboard card), page header, filter card missing (cross-widget filter not seeded on that board).
- Root cause worth a library change: launchpad's cards are CnWidgetWrapper chrome="nc-dashboard" outside .cn-dashboard-page, so nextcloud-vue's board-look card rules (all scoped .cn-dashboard-page / :not(--nc-dashboard)) never apply; marking the workspace .cn-dashboard-page measured -4/-3 flags but over-pads some cards, stripping --nc-dashboard measured +40 (worse). Per-widget launchpad rules (as in #2224/#2232) are the working lever.
- 19 Lp boards still not measured (need routes/steps), LpWerkplek pairs only 3/25 components on demo-werkplek (route/state to check).
