# thematiq pixel pass 2026-11-27 run c (done-thematiq cloud, 09:19-13:15Z)

Tooling: design-system scripts/screens/compdiff runall.sh + summarize.mjs (flag count = components + elements), rows tests/compdiff/admin-boards.tsv (28), boards re-flattened (build.py --only, all fresh).
Stack: NC 34.0.6 tarball, PostgreSQL 16, nginx + php-fpm 8.3 (opcache), APCu + Redis; OpenRegister main e422f05f; Buildiq development; thematiq development; set zuiddrecht; admin language en.
Start (dev 2eda28d + #2115 draft): 153 flags, 1 flag-free board. End (dev e9aa69f): 99 flags, 4 flag-free boards (TqBasistokens, TqBundel, TqOverridesUploaden, TqTerugNaarNextcloud). Merged this run: #2115 #2123 #2125 #2127 #2129 #2130 (other lanes merged #2113 #2116 #2117 in between).

| board | flagged start | flagged end | paired | missing | extra | status |
|---|---|---|---|---|---|---|
| TqAiAssistent | 5 | 4 | 9 | 0 | 0 | fixed #2127 (preview head/bubbles/ask/heading); rest: bubble widths follow copy length (nl board vs en live) |
| TqBasistokens | 1 | 0 | 3 | 0 | 0 | matched (Cancel field border #2115) |
| TqBeweging | 10 | 8 | 5 | 20 | 8 | rest: board draws the Components header + motion dialog (title Components and tokens vs Motion tokens), dialog card width |
| TqBundel | 2 | 0 | 5 | 4 | 0 | matched |
| TqComponenten | 12 | 10 | 4 | 41 | 26 | rest: instrument card chrome (Choose a component: border/title row), inline Upload/Export buttons where the board has them in the More menu (structural, builder); opening More in the spec row does not change the count |
| TqContrastrapport | 3 | 1 | 9 | 3 | 6 | rest: first sample colour (data: pairs differ in sandbox) |
| TqDonker | 10 | 10 | 8 | 18 | 15 | fixed #2115 (pills before crumbs); rest: header actions Clear overrides + Generate dark variant missing (needs a generate endpoint: occ-only today, builder), Dossiq preview frames chrome |
| TqEigenComponent | 7 | 5 | 2 | 19 | 14 | fixed #2115 (header pills); rest: Choose a component card chrome, own-component frame |
| TqEigenCss | 3 | 3 | 6 | 1 | 0 | rest: compdiff pairs the editor wrapper (live textarea 232px r0 = board), log card gap |
| TqEigenSets | 1 | 1 | 0 | 4 | 16 | spec row: board draws a dialog over the page (backdrop); row needs steps |
| TqEigenTokens | 6 | 2 | 8 | 5 | 0 | rest: table cells of empty-state rows (needs seeded own tokens) |
| TqEmailDocumenten | 10 | 6 | 11 | 1 | 2 | fixed #2123 (remove pill, example mail); rest: gapBelowTitle on aside explanatory text (copy wrap) |
| TqGalerij | 2 | 2 | 6 | 6 | 0 | rest: checkbox state (gallery off in sandbox) = data |
| TqGroepen | 3 | 1 | 6 | 14 | 1 | rest: table cells of empty-state rows (needs seeded groups) |
| TqLayout | 7 | 6 | 15 | 8 | 6 | rest: preview card insets, header actions 3 vs 2, session-changes card structure |
| TqLettertypen | 1 | 1 | 0 | 4 | 10 | spec row: board draws a dialog over the page (backdrop); row needs steps |
| TqLogboek | 7 | 2 | 5 | 3 | 4 | fixed #2125 (pill families); rest: row height from board data (longer From/To) |
| TqMerkPerApp | 7 | 4 | 7 | 13 | 1 | rest: table (no seeded brands), mobile-logo card structure |
| TqOmgevingsmarkering | 5 | 5 | 13 | 2 | 0 | rest: compdiff pairs the table badge with the stripe label (both match the board), environment card insets (no environment set in sandbox) |
| TqOverridesUploaden | 2 | 0 | 2 | 0 | 0 | matched (#2115) |
| TqPersoonlijk | 1 | 1 | 0 | 28 | 6 | spec row: board is the personal page with a dialog; row needs route/steps |
| TqTerugNaarNextcloud | 0 | 0 | 2 | 0 | 0 | matched (#2115: Cancel border, reset #A30000) |
| TqTheming | 19 | 4 | 12 | 19 | 14 | fixed #2129 (sample pill); rest: Nextcloud core theming colour picker + download-format select (core UI), card insets |
| TqTokenSet | 18 | 15 | 9 | 18 | 6 | fixed #2115 (pills before crumbs) #2130 (teal pill, muted aside titles, grey tabs); rest: header actions 5 vs 2, example-card chrome, tab strip width, contrast pills (data) |
| TqTokenToevoegen | 5 | 4 | 2 | 1 | 1 | rest: dialog focuses the Name field on open (focus ring = primary border; WCAG dialog focus), badge tint |
| TqTokenUitfaseren | 2 | 1 | 2 | 0 | 0 | rest: dialog focuses the Token field on open (same as TqTokenToevoegen) |
| TqVoorbeeldSessie | 1 | 1 | 0 | 24 | 2 | needs dossiq installed (preview session of a Dossiq page) |
| TqWissels | 3 | 2 | 8 | 4 | 1 | rest: table (no seeded switches), background-jobs card structure |
