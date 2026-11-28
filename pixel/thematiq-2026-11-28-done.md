# thematiq pixel pass 2026-11-28 (done-thematiq cloud, 16:55-17:40Z)

Tooling: design-system compdiff runall.sh + summarize.mjs at 73d47dd (boards fresh), rows tests/compdiff/admin-boards.tsv minus TqVoorbeeldSessie (dossiq not installed). Stack: NC 34.0.6, PostgreSQL 16, nginx + php-fpm 8.3 opcache, APCu, Redis; OR main, thematiq development 54f528a + #2254; Zuiddrecht via seed.sh; admin en.

NOTE: a first round read card borders #c4c7cb and control borders #74777c on 18 boards: stored thematiq custom overrides (a full set of Nextcloud default colours) left on the instance by an e2e spec override every page. Cleared (POST overrides {}), measured again. The tour boards need the admin's apps_menu_walkthrough_seen_version cleared or the tour never opens.

Result: 37 measured, flag-free 7/37: TqBasistokens TqEigenSets TqMenuRondleidingOrdenen TqOverridesOpslaan TqOverridesUploaden TqTerugNaarNextcloud TqVersieHerstellen. Not measured: TqLettertypen (needs seed.sh font alone), TqVoorbeeldSessie (dossiq).
Fixed this run: walkthrough card 340 px per board (thematiq#2258): rect.w flag gone on 6 tour boards; their remaining flag is header.actions 1>0, a compdiff artefact (close button drawn in place but outside CnWalkthrough's header element).

## Systematic (3+ boards)
| component | property | direction | components | boards | median delta | most common board -> live | likely source |
|---|---|---|---|---|---|---|---|
| pageHeader | buttons | smaller on live | 19 | 19 | -1 | 2 -> 1 (6); 1 -> 0 (4) | dossiq (unclassed) (19) |
| widget/card (titled) | content.gapBelowTitle | smaller on live | 12 | 9 | -31.3 | 63 -> 14.4 (2); 75 -> 42.3 (1) | dossiq (thematiq-card.thematiq-panel__card) (10) |
| table | rowHeight | smaller on live | 7 | 6 | -15.2 | 68 -> 39.8 (2); 55 -> 39.8 (2) | dossiq (unclassed) (7) |
| dialog | header.actions | smaller on live | 6 | 6 | -1 | 1 -> 0 (6) | library (nextcloud-vue cn-walkthrough) (6) |
| widget/card (titled) | content.gapBelowTitle | larger on live | 6 | 5 | 6 | 12 -> 16 (1); 10 -> 16 (1) | dossiq (thematiq-card.thematiq-panel__card) (6) |
| table | cell.color | differs | 6 | 5 |  | rgb(27, 28, 29) -> rgb(94, 97, 104) (3); rgb(61, 64, 71) -> rgb(94, 97, 104) (2) | dossiq (unclassed) (6) |
| element pill | background | differs | 5 | 5 |  | rgb(27, 28, 29) -> rgb(255, 255, 255) (1); rgb(231, 243, 234) -> rgb(255, 244, 222) (1) | element (not attributed) (5) |
| element pill | color | differs | 5 | 5 |  | rgb(255, 255, 255) -> rgb(27, 28, 29) (1); rgb(30, 107, 42) -> rgb(122, 79, 0) (1) | element (not attributed) (5) |
| dialog | rect.w | smaller on live | 5 | 5 | -20 | 340 -> 320 (5) | library (nextcloud-vue cn-walkthrough) (5) |
| element button | background | differs | 7 | 4 |  | rgb(255, 255, 255) -> rgb(234, 240, 247) (3); rgb(234, 240, 247) -> rgb(54, 105, 165) (1) | element (not attributed) (7) |
| element button | h | smaller on live | 7 | 4 | -6 | 40 -> 34 (3); 21 -> 18 (3) | element (not attributed) (7) |
| element button | fontSize | larger on live | 7 | 4 | 1 | 14 -> 15 (7) | element (not attributed) (7) |
| element button | fontWeight | larger on live | 7 | 4 | 100 | 600 -> 700 (7) | element (not attributed) (7) |
| element toggle | pixels | differs | 5 | 4 |  | - -> 90.1 (5) | element (not attributed) (5) |

## Per board

| board | paired | flagged | missing | extra | worst |
|---|---|---|---|---|---|
| TqAiAssistent | 9 | 5 | 1 | 0 | card:Assistant[content.minInsetLeft 28>1; content.gapBelowTitle 243.8>264] || pageHeader:AI assistant[buttons 1>0] || card:card@378,888[rect.w 305.6>280] |
| TqBasistokens | 3 | 0 | 0 | 0 |  |
| TqBeweging | 5 | 8 | 21 | 8 | card:Dialog slides in[rect.w 753.8>390.8; props.background rgb(245, 246, 248)>rgb(255, 255, 255); props.boxShadow none>rgba(0, 0, 0, 0.12) 0px 4px 16px 0px; header.fontSize 16>14] || card:card@315,897[rect.w 753.8>674.8; props.background rgb(238, 240, 243)>color(srgb 1 0.962941 0.87098); content.fir |
| TqBundel | 5 | 1 | 5 | 0 | pageHeader:Configuration bundle[buttons 3>2] |
| TqComponenten | 4 | 10 | 42 | 26 | card:Choose a component[header (title row) present>absent; props.border.top 1>0; props.border.right 1>0; props.border.bottom 1>0] || element button:button "Upload" in "record card"[h 40>34; border 1>0; background rgb(255, 255, 255)>rgb(234, 240, 247); color rgb(27, 28, 29)>rgb(35, 74, 120)] || eleme |
| TqContrastrapport | 9 | 1 | 4 | 5 | pageHeader:Contrast report[buttons 3>2] |
| TqDonker | 8 | 10 | 18 | 14 | card:Dossiq[header (title row) present>absent; props.border.top 0>1; props.border.right 0>1; props.border.left 0>1] || card:Dossiq[header (title row) present>absent; props.border.top 0>1; props.border.right 0>1; props.border.left 0>1] || element row:row "Primary on hover is not converted and stays # |
| TqEigenComponent | 2 | 4 | 19 | 13 | card:Choose a component[header (title row) present>absent; props.border.top 1>0; props.border.right 1>0; props.border.bottom 1>0] || element badge:badge "To the voting guide"[h 36>21; borderRadius 8>0; paddingLeft 14>5; paddingRight 14>3] || element button:button "Save component"[h 40>34; fontSize 1 |
| TqEigenCss | 6 | 4 | 2 | 0 | element field:field "Custom CSS"[h 232>250; borderRadius 0>8] || pageHeader:Custom CSS[buttons 1>0] || card:In the log[content.gapBelowTitle 43.5>14.4] |
| TqEigenSets | 1 | 0 | 0 | 0 |  |
| TqEigenTokens | 8 | 3 | 6 | 0 | table:table@317,297[cell.fontSize 14>13; cell.fontWeight 600>400; cell.lineHeight 16.8>18.85; cell.color rgb(27, 28, 29)>rgb(94, 97, 104)] || table:table@317,749[cell.fontSize 12>13; cell.lineHeight 14.4>18.85; cell.color rgb(61, 64, 71)>rgb(94, 97, 104); cell.fontFamily ui-monospace>Fira Sans] || p |
| TqEmailDocumenten | 11 | 7 | 2 | 2 | element pill:pill "Remove document logo"[border 0>1; background rgb(252, 237, 236)>rgb(255, 255, 255); color rgb(163, 0, 0)>rgb(27, 28, 29); fontSize 13>14] || pageHeader:Email and documents[buttons 1>0] || card:Example: letter[content.gapBelowTitle 326>229.4] |
| TqGalerij | 6 | 3 | 7 | 0 | pageHeader:Theme gallery and updates[buttons 1>0] || element toggle:toggle "Show the theme gallery"[pixels ->90.1] || element toggle:toggle "Check daily for new tokens (via api.github.com)"[pixels ->90.1] |
| TqGroepen | 6 | 2 | 15 | 1 | table:table@317,297[cell.fontWeight 700>400; cell.lineHeight 15.6>18.85; cell.color rgb(61, 64, 71)>rgb(94, 97, 104); cell.textInsetLeft 10.1>0] || pageHeader:House style per group[buttons 2>1] |
| TqLayout | 15 | 6 | 8 | 5 | card:card@1059,918[content.firstRowInsetLeft 13>71; content.minInsetLeft 13>71; content.insetTop 3.8>8.8] || card:card@1055,545[content.firstRowInsetLeft 13>71; content.minInsetLeft 13>71] || pageHeader:Layout and options[buttons 3>1] |
| TqLogboek | 5 | 3 | 6 | 2 | table:table@317,276[rect.w 1070>1137.8; rowHeight 74>61] || element button:button "Actions for 4 Oct 2026: restore configuration of 4"[border 1>0; background rgb(255, 255, 255)>rgb(234, 240, 247); icon true>false] || pageHeader:Log[buttons 2>1] |
| TqMenuRondleiding | 1 | 2 | 12 | 2 | dialog:Open the apps menu[rect.w 340>320; header.actions 1>0] || page:page background[background rgb(255, 255, 255)>rgb(152, 153, 153)] |
| TqMenuRondleidingApps | 1 | 2 | 5 | 2 | dialog:Your apps in a row[header.actions 1>0] || page:page background[background rgb(152, 153, 153)>rgb(255, 255, 255)] |
| TqMenuRondleidingInstellingen | 2 | 3 | 6 | 4 | dialog:Menu settings[rect.w 340>320; header.actions 1>0] || dialog-card:Want to see the walkthrough again? Menu settings, Replay walkthrough. Or from He[header (title row) present>absent; rect.w 308>288] || element button:button "Done"[icon false>true] |
| TqMenuRondleidingMeer | 1 | 1 | 7 | 2 | dialog:More and fewer[rect.w 340>320; header.actions 1>0] |
| TqMenuRondleidingNieuw | 1 | 1 | 5 | 3 | dialog:Create[rect.w 340>320; header.actions 1>0] |
| TqMenuRondleidingOpnieuw | 2 | 11 | 8 | 2 | element button:button "Reset to default"[paddingLeft 10>4; paddingRight 12>4; fontSize 14>15; fontWeight 600>700] || element toggle:toggle "Only for me"[color rgb(61, 64, 71)>rgb(94, 97, 104); fontSize 14>15; fontWeight 600>700] || element toggle:toggle "For everyone"[fontSize 14>15; fontWeight 600> |
| TqMenuRondleidingOrdenen | 0 | 0 | 9 | 5 |  |
| TqMenuRondleidingVastzetten | 1 | 1 | 7 | 2 | dialog:Pin an app to the top bar[rect.w 340>320; header.actions 1>0] |
| TqMerkPerApp | 7 | 5 | 14 | 1 | table:table@317,731[cell.fontSize 14>13; cell.fontWeight 600>400; cell.lineHeight 16.8>18.85; cell.color rgb(27, 28, 29)>rgb(94, 97, 104)] || card:Mobile logo and home screen icon[content.firstRowInsetLeft 105>42; content.gapBelowTitle 95.9>98; structure title / meta / divider / meta / meta pill / p |
| TqOmgevingsmarkering | 13 | 6 | 3 | 0 | card:Environment of this server[content.firstRowInsetLeft 37>25; content.gapBelowTitle 47.8>42.3] || pageHeader:Environment marker[buttons 2>1] || element badge:badge "Development environment"[h 22>18; borderRadius 4>0] |
| TqOverridesOpslaan | 2 | 0 | 0 | 0 |  |
| TqOverridesUploaden | 2 | 0 | 0 | 0 |  |
| TqSetUploaden | 8 | 4 | 14 | 4 | element pill:pill "WCAG AA" in "record card"[h 21>17.4; background rgb(231, 243, 234)>color(srgb 0.88 0.919529 0.890353); color rgb(30, 107, 42)>rgb(0, 84, 22); fontSize 13>11.2] || table:table@317,1773[cell.fontWeight 600>700; rowHeight 68>55] || pageHeader:Own token sets[buttons 2>1] |
| TqTerugNaarNextcloud | 2 | 0 | 0 | 0 |  |
| TqTheming | 13 | 8 | 21 | 14 | element button:button "#3669A5"[h 44>26; borderRadius 8>13; paddingLeft 14>0; paddingRight 14>0] || card:Keep the Nextcloud theme in line with the set ~ Nextcloud[rect.w 750>572.4; header.titleInsetLeft 15>49] || element field:field "File format of the download"[h 40>79; borderColor rgb(130, 138, 14 |
| TqTokenSet | 9 | 17 | 18 | 5 | card:House styles of other organisations[header.fontSize 17>18; header.lineHeight 20.4>23.4; header.titleInsetTop 21>45; content.gapBelowTitle 98>12.4] || card:Dossiq[header (title row) present>absent; props.background rgb(245, 246, 248)>rgb(255, 255, 255); header.separator 1>-; content.firstRowInse |
| TqTokenToevoegen | 2 | 4 | 1 | 1 | element field:field "Name"[borderRadius 0>8; borderColor rgb(130, 138, 149)>rgb(54, 105, 165)] || element badge:badge "--nldesign-org-"[background rgb(245, 246, 248)>rgb(240, 241, 243); fontSize 14>13] || element field:field "Label"[borderColor rgb(130, 138, 149)>rgb(204, 0, 0)] |
| TqTokenUitfaseren | 2 | 1 | 0 | 0 | element field:field "Token"[borderColor rgb(130, 138, 149)>rgb(54, 105, 165)] |
| TqTokensetToepassen | 2 | 2 | 5 | 3 | dialog-card:dialog-card@384,276[header (title row) absent>present] || element button:button "Cancel"[borderColor rgb(130, 138, 149)>rgb(27, 28, 29)] |
| TqVersieHerstellen | 1 | 0 | 3 | 0 |  |
| TqWissels | 8 | 3 | 5 | 1 | table:table@317,687[cell.fontSize 14>13; cell.fontWeight 600>400; cell.lineHeight 16.8>18.85; cell.color rgb(27, 28, 29)>rgb(94, 97, 104)] || pageHeader:Scheduled switches[buttons 2>1] || card:Background jobs[structure title / pill meta / meta>title / pill deadline / meta] |
