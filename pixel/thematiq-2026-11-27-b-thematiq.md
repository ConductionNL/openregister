# thematiq compdiff, b-thematiq run, 2026-11-27T09:48:47Z (REPLACES earlier versions of this file)

Sandbox: NC 34 latest tarball, sqlite, php -S :8080, thematiq development 23edecc3 (after #2117), set zuiddrecht, admin language en (occ user:setting admin core lang en; without it live is measured in Dutch against the English board), background jobs cron, no seeded data, no buildiq. Rows: tests/compdiff/admin-boards.tsv with --base.

Total flagged over 28 rows: 193 (English, development before #2113) -> 121 now (b-thematiq #2113 #2116 #2117 + done-lane #2106 #2111).

One flag left, by design or sandbox: TqBasistokens + TqOverridesUploaden (dialog Cancel dark border = focus on the safe choice), TqTokenUitfaseren (focused field), TqBundel + TqContrastrapport (buildiq square, not installed in the sandbox). TqEigenSets/TqLettertypen/TqPersoonlijk/TqVoorbeeldSessie: 0 paired (the board measures as one grey component with --lang en: compdiff/board side; TqVoorbeeldSessie needs dossiq).

Left that needs data or decisions: tables (rowHeight/cell weight: the sandbox shows empty states against seeded board rows), TqLayout previews (instance name/logo), TqDonker/TqTokenSet status pills before the breadcrumb and the Dossiq preview cards, card gapBelowTitle (mixed directions).

# Per board

| board | paired | flagged | missing on live | extra on live | not measured | worst components (flagged deltas) |
|---|---|---|---|---|---|---|
| TqAiAssistent | 9 | 6 | 1 | 0 | 2 | **card: Assistant** (header.fontSize 15→14, header.titleInsetLeft 45→13, content.firstRowInsetLeft 28→24, content.minInsetLeft 28→24, content.gapBelowTitle 243.8→201.1)<br>**card: card@378,888** (rect.w 305.6→344.4, props.borderRadius 12→8)<br>**card: card@332,978** (rect.w 305.6→356, props.borderRadius 12→8) |
| TqBasistokens | 3 | 1 | 0 | 0 | 0 | **element button: button "Cancel"** (borderColor rgb(130, 138, 149)→rgb(27, 28, 29)) |
| TqBeweging | 5 | 8 | 21 | 8 | 4 | **card: Dialog slides in** (rect.w 753.8→390.8, props.background rgb(245, 246, 248)→rgb(255, 255, 255), props.boxShadow none→rgba(0, 0, 0, 0.12) 0px 4px 16px 0px, header.fontSize 16→14, header.fontWeight 700→600)<br>**card: card@315,897** (rect.w 753.8→674.8, props.background rgb(238, 240, 243)→color(srgb 1 0.962941 0.87098), content.firstRowInsetLeft 10→14, content.minInsetLeft 10→14, content.insetTop 6→14)<br>**pageHeader: Components and tokens ~ Motion tokens** (subtitle.fontSize 13→14, subtitle.fontWeight 600→400, subtitle.gapBelowTitle 13→17, buttons 4→2) |
| TqBundel | 5 | 1 | 5 | 0 | 0 | **pageHeader: Configuration bundle** (buttons 3→2) |
| TqComponenten | 4 | 10 | 42 | 26 | 0 | **card: Choose a component** (header (title row) present→absent, props.border.top 1→0, props.border.right 1→0, props.border.bottom 1→0, props.border.left 1→0)<br>**element button: button "Upload" in "record card"** (h 40→34, border 1→0, background rgb(255, 255, 255)→rgb(234, 240, 247), color rgb(27, 28, 29)→rgb(35, 74, 120), fontSize 14→15)<br>**element button: button "Export as token set" in "record card"** (h 40→34, border 1→0, background rgb(255, 255, 255)→rgb(234, 240, 247), color rgb(27, 28, 29)→rgb(35, 74, 120), fontSize 14→15) |
| TqContrastrapport | 9 | 1 | 4 | 5 | 0 | **pageHeader: Contrast report** (buttons 3→2) |
| TqDonker | 8 | 10 | 18 | 14 | 2 | **card: Dossiq** (header (title row) present→absent, props.border.top 0→1, props.border.right 0→1, props.border.left 0→1, props.borderRadius 0→10)<br>**card: Dossiq** (header (title row) present→absent, props.border.top 0→1, props.border.right 0→1, props.border.left 0→1, props.borderRadius 0→10)<br>**pageHeader: Dark variant** (subtitle.fontSize 13→14, subtitle.fontWeight 600→400, subtitle.gapBelowTitle 13→17, buttons 3→0) |
| TqEigenComponent | 3 | 5 | 19 | 11 | 3 | **card: Choose a component** (header (title row) present→absent, props.border.top 1→0, props.border.right 1→0, props.border.bottom 1→0, props.border.left 1→0)<br>**card: card@311,1287** (rect.w 761.8→800, props.borderColor rgb(130, 138, 149)→rgb(228, 230, 234), props.borderRadius 8→12, content.firstRowInsetLeft 15→23, content.minInsetLeft 15→23)<br>**element button: button "Save component"** (h 40→34, fontSize 14→15, fontWeight 600→700) |
| TqEigenCss | 6 | 4 | 2 | 0 | 1 | **element field: field "Custom CSS"** (h 232→264, borderRadius 0→8)<br>**pageHeader: Custom CSS** (buttons 1→0)<br>**card: In the log** (content.gapBelowTitle 43.5→14.4) |
| TqEigenSets | 0 | 1 | 4 | 15 | 0 | **page: page background** (background rgb(146, 148, 149)→rgb(245, 246, 248)) |
| TqEigenTokens | 8 | 3 | 6 | 0 | 0 | **table: table@317,297** (cell.fontSize 14→13, cell.fontWeight 600→400, cell.lineHeight 16.8→18.85, cell.color rgb(27, 28, 29)→rgb(94, 97, 104), rowHeight 68→39.8)<br>**table: table@317,749** (cell.fontSize 12→13, cell.lineHeight 14.4→18.85, cell.color rgb(61, 64, 71)→rgb(94, 97, 104), cell.fontFamily ui-monospace→Fira Sans, rowHeight 68→39.8)<br>**pageHeader: Own tokens** (buttons 3→2) |
| TqEmailDocumenten | 9 | 9 | 4 | 2 | 0 | **element pill: pill "Remove document logo"** (borderRadius 17→8, paddingLeft 12→16, paddingRight 12→16, border 0→1, background rgb(252, 237, 236)→rgb(255, 255, 255))<br>**card: card@1113,241** (props.background rgb(245, 246, 248)→rgb(255, 255, 255), content.minInsetLeft 15→1, content.insetTop 304→9)<br>**pageHeader: Email and documents** (buttons 1→0) |
| TqGalerij | 6 | 3 | 7 | 0 | 0 | **pageHeader: Theme gallery and updates** (buttons 1→0)<br>**element toggle: toggle "Show the theme gallery"** (pixels -→90.1)<br>**element toggle: toggle "Check daily for new tokens (via api.github.com)"** (pixels -→90.1) |
| TqGroepen | 6 | 2 | 15 | 1 | 0 | **table: table@317,297** (cell.fontWeight 700→400, cell.lineHeight 15.6→18.85, cell.color rgb(61, 64, 71)→rgb(94, 97, 104), cell.textInsetLeft 10.1→0, rowHeight 93→39.8)<br>**pageHeader: House style per group** (buttons 2→1) |
| TqLayout | 15 | 6 | 8 | 5 | 8 | **card: card@1059,918** (content.firstRowInsetLeft 13→71, content.minInsetLeft 13→71, content.insetTop 3.8→8.8)<br>**card: card@1055,545** (content.firstRowInsetLeft 13→71, content.minInsetLeft 13→71)<br>**pageHeader: Layout and options** (buttons 3→1) |
| TqLettertypen | 0 | 1 | 4 | 9 | 0 | **page: page background** (background rgb(146, 148, 149)→rgb(245, 246, 248)) |
| TqLogboek | 5 | 2 | 9 | 3 | 0 | **table: table@317,276** (cell.fontSize 14→13, cell.fontWeight 600→400, cell.lineHeight 16.8→18.85, cell.color rgb(27, 28, 29)→rgb(94, 97, 104), rowHeight 74→39.8)<br>**pageHeader: Log** (buttons 2→1) |
| TqMerkPerApp | 7 | 5 | 14 | 1 | 0 | **table: table@317,731** (cell.fontSize 14→13, cell.fontWeight 600→400, cell.lineHeight 16.8→18.85, cell.color rgb(27, 28, 29)→rgb(94, 97, 104), rowHeight 55→39.8)<br>**card: Mobile logo and home screen icon** (content.firstRowInsetLeft 105→42, content.gapBelowTitle 95.9→98, structure title / meta / divider / meta / meta pill / pill / divider / meta / meta pill / pill / meta→title / meta / divider / pill meta / meta / divider / pill meta / meta)<br>**pageHeader: Brand per app** (buttons 2→1) |
| TqOmgevingsmarkering | 13 | 6 | 3 | 0 | 6 | **card: Environment of this server** (content.firstRowInsetLeft 37→25, content.gapBelowTitle 47.8→42.3)<br>**pageHeader: Environment marker** (buttons 2→1)<br>**element badge: badge "Development environment"** (h 22→18, borderRadius 4→0) |
| TqOverridesUploaden | 2 | 1 | 0 | 0 | 0 | **element button: button "Cancel"** (borderColor rgb(130, 138, 149)→rgb(27, 28, 29)) |
| TqPersoonlijk | 0 | 1 | 28 | 6 | 4 | **page: page background** (background rgb(146, 148, 149)→rgb(245, 246, 248)) |
| TqTerugNaarNextcloud | 2 | 2 | 0 | 0 | 0 | **element button: button "Cancel"** (borderColor rgb(130, 138, 149)→rgb(27, 28, 29))<br>**element button: button "Reset to Nextcloud"** (background rgb(163, 0, 0)→rgb(138, 0, 0)) |
| TqTheming | 12 | 6 | 20 | 11 | 18 | **element button: button "#3669A5"** (h 44→26, borderRadius 8→13, paddingLeft 14→0, paddingRight 14→0, background rgb(54, 105, 165)→rgb(234, 240, 247))<br>**element field: field "File format of the download"** (h 40→79, borderColor rgb(130, 138, 149)→color(srgb 0.509804 0.541176 0.584314 / 0.35))<br>**pageHeader: Themes and appearance** (buttons 3→2) |
| TqTokenSet | 9 | 19 | 18 | 5 | 6 | **pageHeader: Municipality of Zuiddrecht ~ Gemeente Zuiddrecht** (subtitle.fontSize 13→14, subtitle.fontWeight 600→400, subtitle.gapBelowTitle 13→17, buttons 5→1)<br>**card: House styles of other organisations** (header.fontSize 17→18, header.lineHeight 20.4→23.4, header.titleInsetTop 21→45, content.gapBelowTitle 98→12.4)<br>**card: Dossiq** (header (title row) present→absent, props.background rgb(245, 246, 248)→rgb(255, 255, 255), header.separator 1→-, content.firstRowInsetLeft 13→17) |
| TqTokenToevoegen | 2 | 4 | 1 | 1 | 0 | **element field: field "Name"** (borderRadius 0→8, borderColor rgb(130, 138, 149)→rgb(54, 105, 165))<br>**element badge: badge "--nldesign-org-"** (background rgb(245, 246, 248)→rgb(240, 241, 243), fontSize 14→13)<br>**element field: field "Label"** (borderColor rgb(130, 138, 149)→rgb(204, 0, 0)) |
| TqTokenUitfaseren | 2 | 1 | 0 | 0 | 0 | **element field: field "Token"** (borderColor rgb(130, 138, 149)→rgb(54, 105, 165)) |
| TqVoorbeeldSessie | 0 | 1 | 24 | 2 | 1 | **page: page background** (background rgb(245, 246, 248)→rgb(0, 103, 158)) |
| TqWissels | 8 | 2 | 5 | 1 | 0 | **table: table@317,687** (cell.fontSize 14→13, cell.fontWeight 600→400, cell.lineHeight 16.8→18.85, cell.color rgb(27, 28, 29)→rgb(94, 97, 104), cell.textInsetLeft 24→583.2)<br>**pageHeader: Scheduled switches** (buttons 2→1) |

## Systematic (fleet-summary.md, top)
| component | property | direction | components | boards | median delta | most common board -> live | likely source |
|---|---|---|---|---|---|---|---|
| pageHeader | buttons | smaller on live | 18 | 18 | -1 | 2 -> 1 (5); 1 -> 0 (4) | dossiq (unclassed) (18) |
| widget/card (titled) | content.gapBelowTitle | smaller on live | 11 | 8 | -31.3 | 75 -> 42.3 (1); 50.5 -> 45.4 (1) | dossiq (thematiq-card.thematiq-panel__card) (9) |
| element pill | background | differs | 6 | 5 |  | rgb(27, 28, 29) -> rgb(255, 255, 255) (1); rgb(231, 243, 234) -> rgb(255, 244, 222) (1) | element (not attributed) (6) |
| widget/card (titled) | content.gapBelowTitle | larger on live | 6 | 5 | 6 | 12 -> 16 (1); 10 -> 16 (1) | dossiq (thematiq-card.thematiq-panel__card) (6) |
| table | cell.lineHeight | larger on live | 6 | 5 | 2.1 | 16.8 -> 18.85 (4); 14.4 -> 18.85 (1) | dossiq (unclassed) (6) |
| table | cell.color | differs | 6 | 5 |  | rgb(27, 28, 29) -> rgb(94, 97, 104) (4); rgb(61, 64, 71) -> rgb(94, 97, 104) (2) | dossiq (unclassed) (6) |
| table | rowHeight | smaller on live | 6 | 5 | -28.2 | 68 -> 39.8 (2); 55 -> 39.8 (2) | dossiq (unclassed) (6) |
| pageHeader | subtitle.fontSize | larger on live | 5 | 5 | 1 | 13 -> 14 (5) | dossiq (unclassed) (5) |
| pageHeader | subtitle.fontWeight | smaller on live | 5 | 5 | -200 | 600 -> 400 (5) | dossiq (unclassed) (5) |
| pageHeader | subtitle.gapBelowTitle | larger on live | 5 | 5 | 4 | 13 -> 17 (5) | dossiq (unclassed) (5) |
| element pill | color | differs | 5 | 5 |  | rgb(255, 255, 255) -> rgb(27, 28, 29) (1); rgb(30, 107, 42) -> rgb(122, 79, 0) (1) | element (not attributed) (5) |
| table | cell.fontWeight | smaller on live | 5 | 5 | -200 | 600 -> 400 (4); 700 -> 400 (1) | dossiq (unclassed) (5) |
| element button | background | differs | 7 | 4 |  | rgb(255, 255, 255) -> rgb(234, 240, 247) (2); rgb(234, 240, 247) -> rgb(54, 105, 165) (1) | element (not attributed) (7) |
| element button | h | smaller on live | 7 | 4 | -6 | 40 -> 34 (3); 21 -> 18 (3) | element (not attributed) (7) |
| element toggle | pixels | differs | 5 | 4 |  | - -> 90.1 (5) | element (not attributed) (5) |
| card (nested) | props.background | differs | 5 | 4 |  | rgb(245, 246, 248) -> rgb(255, 255, 255) (2); rgb(238, 240, 243) -> color(srgb 1 0.962941 0.87098) (1) | dossiq (thematiq-fonts-panel__notice.thematiq-motion-panel__notice) (1) |
| card (nested) | content.firstRowInsetLeft | larger on live | 5 | 4 | 8 | 13 -> 71 (2); 10 -> 14 (1) | dossiq (div) (2) |
| card (nested) | content.minInsetLeft | larger on live | 5 | 4 | 8 | 13 -> 71 (2); 10 -> 14 (1) | dossiq (div) (2) |
| element button | color | differs | 5 | 4 |  | rgb(27, 28, 29) -> rgb(35, 74, 120) (2); rgb(35, 74, 120) -> rgb(255, 255, 255) (1) | element (not attributed) (5) |
| page | background | differs | 4 | 4 |  | rgb(146, 148, 149) -> rgb(245, 246, 248) (3); rgb(245, 246, 248) -> rgb(0, 103, 158) (1) | dossiq (unclassed) (4) |
| table | cell.fontSize | smaller on live | 4 | 4 | -1 | 14 -> 13 (4) | dossiq (unclassed) (4) |
| element button | fontSize | larger on live | 6 | 3 | 1 | 14 -> 15 (6) | element (not attributed) (6) |
| card (nested) | rect.w | larger on live | 5 | 3 | 38.8 | 305.6 -> 344.4 (1); 305.6 -> 356 (1) | dossiq (div) (2) |
| element field | borderColor | differs | 5 | 3 |  | rgb(130, 138, 149) -> rgb(54, 105, 165) (2); rgb(130, 138, 149) -> rgb(204, 0, 0) (2) | element (not attributed) (5) |
