# thematiq pixel pass 2026-11-27 run d (done-thematiq cloud, 11:38-14:20Z)

Tooling: design-system scripts/screens/compdiff runall.sh + summarize.mjs, rows tests/compdiff/admin-boards.tsv (28), boards re-flattened (build.py --only).
Stack: NC 34.0.6 tarball, PostgreSQL 16, nginx + php-fpm 8.3 (opcache), APCu + Redis; OpenRegister main c2f01e11; thematiq development 445b6ee + #2148 CSS; set zuiddrecht; admin en; seed: tests/compdiff/seed.sh (TqLettertypen measured alone with `seed.sh font`).
NOT installed this run: buildiq (run c had it). Most boards draw a buildiq app button in the page header, so pageHeader 'buttons n>n-1' adds +1 flag on ~12 boards against run c: environment, not app.
Merged this run: #2133 (nc-vue 2.235.0, perf spec on the floor rule), #2137 (own-set delete dialog as TqEigenSets draws, rows TqEigenSets/TqLettertypen open their dialog), #2148 (delete buttons 18 px as drawn, seed.sh font apart).
Result: 4/28 boards flag-free, 109 flags total (run c: 4/28, 99, with buildiq). TqEigenSets and TqLettertypen went from 0 paired to paired (0 and 1 data flag).

| board | paired | flagged | missing | extra | worst |
|---|---|---|---|---|---|
| TqAiAssistent | 9 | 5 | 1 | 0 | card:Assistant[content.minInsetLeft 28>1; content.gapBelowTitle 243.8>264] // pageHeader:AI assistant[buttons 1>0] // card:card@378,888[rect.w 305.6>280] |
| TqBasistokens | 3 | 0 | 0 | 0 |  |
| TqBeweging | 5 | 8 | 21 | 8 | card:Dialog slides in[rect.w 753.8>390.8; props.background rgb(245, 246, 248)>rgb(255, 255, 255); props.boxShadow none>rgba(0, 0, 0, 0.12) 0px 4px 16px 0px; header.fontSize 16>14] // card:card@315,897[rect.w 753.8>674.8; |
| TqBundel | 5 | 1 | 5 | 0 | pageHeader:Configuration bundle[buttons 3>2] |
| TqComponenten | 4 | 10 | 42 | 26 | card:Choose a component[header (title row) present>absent; props.border.top 1>0; props.border.right 1>0; props.border.bottom 1>0] // element button:button "Upload" in "record card"[h 40>34; border 1>0; background rgb(255 |
| TqContrastrapport | 9 | 2 | 4 | 6 | pageHeader:Contrast report[buttons 3>2] // element badge:badge "Text Aa"[background rgb(54, 105, 165)>rgb(138, 161, 185)] |
| TqDonker | 8 | 10 | 18 | 14 | card:Dossiq[header (title row) present>absent; props.border.top 0>1; props.border.right 0>1; props.border.left 0>1] // card:Dossiq[header (title row) present>absent; props.border.top 0>1; props.border.right 0>1; props.bo |
| TqEigenComponent | 2 | 4 | 19 | 13 | card:Choose a component[header (title row) present>absent; props.border.top 1>0; props.border.right 1>0; props.border.bottom 1>0] // element badge:badge "To the voting guide"[h 36>21; borderRadius 8>0; paddingLeft 14>5;  |
| TqEigenCss | 6 | 4 | 2 | 0 | element field:field "Custom CSS"[h 232>264; borderRadius 0>8] // pageHeader:Custom CSS[buttons 1>0] // card:In the log[content.gapBelowTitle 43.5>14.4] |
| TqEigenSets | 1 | 0 | 0 | 0 |  |
| TqEigenTokens | 8 | 3 | 6 | 0 | table:table@317,297[cell.fontSize 14>13; cell.fontWeight 600>400; cell.lineHeight 16.8>18.85; cell.color rgb(27, 28, 29)>rgb(94, 97, 104)] // table:table@317,749[cell.fontSize 12>13; cell.lineHeight 14.4>18.85; cell.colo |
| TqEmailDocumenten | 11 | 7 | 2 | 2 | element pill:pill "Remove document logo"[border 0>1; background rgb(252, 237, 236)>rgb(255, 255, 255); color rgb(163, 0, 0)>rgb(27, 28, 29); fontSize 13>14] // pageHeader:Email and documents[buttons 1>0] // card:Example: |
| TqGalerij | 6 | 3 | 7 | 0 | pageHeader:Theme gallery and updates[buttons 1>0] // element toggle:toggle "Show the theme gallery"[pixels ->90.1] // element toggle:toggle "Check daily for new tokens (via api.github.com)"[pixels ->90.1] |
| TqGroepen | 6 | 2 | 15 | 1 | table:table@317,297[cell.fontWeight 700>400; cell.lineHeight 15.6>18.85; cell.color rgb(61, 64, 71)>rgb(94, 97, 104); cell.textInsetLeft 10.1>0] // pageHeader:House style per group[buttons 2>1] |
| TqLayout | 15 | 6 | 8 | 5 | card:card@1059,918[content.firstRowInsetLeft 13>71; content.minInsetLeft 13>71; content.insetTop 3.8>8.8] // card:card@1055,545[content.firstRowInsetLeft 13>71; content.minInsetLeft 13>71] // pageHeader:Layout and option |
| TqLettertypen | 1 | 1 | 0 | 0 | dialog: seeded font face/metrics only (data) |
| TqLogboek | 5 | 3 | 3 | 2 | element button:button "Actions for 4 Oct 2026: restore configuration of 4"[border 1>0; background rgb(255, 255, 255)>rgb(234, 240, 247); icon true>false] // pageHeader:Log[buttons 2>1] // table:table@317,276[rowHeight 74 |
| TqMerkPerApp | 7 | 5 | 14 | 1 | table:table@317,731[cell.fontSize 14>13; cell.fontWeight 600>400; cell.lineHeight 16.8>18.85; cell.color rgb(27, 28, 29)>rgb(94, 97, 104)] // card:Mobile logo and home screen icon[content.firstRowInsetLeft 105>42; conten |
| TqOmgevingsmarkering | 13 | 6 | 3 | 0 | card:Environment of this server[content.firstRowInsetLeft 37>25; content.gapBelowTitle 47.8>42.3] // pageHeader:Environment marker[buttons 2>1] // element badge:badge "Development environment"[h 22>18; borderRadius 4>0] |
| TqOverridesUploaden | 2 | 0 | 0 | 0 |  |
| TqPersoonlijk | 0 | 1 | 28 | 6 | page:page background[background rgb(146, 148, 149)>rgb(245, 246, 248)] |
| TqTerugNaarNextcloud | 2 | 0 | 0 | 0 |  |
| TqTheming | 12 | 5 | 20 | 14 | element button:button "#3669A5"[h 44>26; borderRadius 8>13; paddingLeft 14>0; paddingRight 14>0] // element field:field "File format of the download"[h 40>79; borderColor rgb(130, 138, 149)>color(srgb 0.509804 0.541176 0 |
| TqTokenSet | 9 | 15 | 18 | 5 | card:House styles of other organisations[header.fontSize 17>18; header.lineHeight 20.4>23.4; header.titleInsetTop 21>45; content.gapBelowTitle 98>12.4] // card:Dossiq[header (title row) present>absent; props.background r |
| TqTokenToevoegen | 2 | 4 | 1 | 1 | element field:field "Name"[borderRadius 0>8; borderColor rgb(130, 138, 149)>rgb(54, 105, 165)] // element badge:badge "--nldesign-org-"[background rgb(245, 246, 248)>rgb(240, 241, 243); fontSize 14>13] // element field:f |
| TqTokenUitfaseren | 2 | 1 | 0 | 0 | element field:field "Token"[borderColor rgb(130, 138, 149)>rgb(54, 105, 165)] |
| TqVoorbeeldSessie | 0 | 1 | 24 | 2 | page:page background[background rgb(245, 246, 248)>rgb(255, 255, 255)] |
| TqWissels | 8 | 2 | 5 | 1 | table:table@317,687[cell.fontSize 14>13; cell.fontWeight 600>400; cell.lineHeight 16.8>18.85; cell.color rgb(27, 28, 29)>rgb(94, 97, 104)] // pageHeader:Scheduled switches[buttons 2>1] |
