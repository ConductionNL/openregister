# thematiq pixel pass 2026-11-27 run e (done-thematiq cloud, 14:25-16:45Z)

Tooling: design-system compdiff runall.sh + summarize.mjs; rows tests/compdiff/admin-boards.tsv, now 40 (thematiq#2169 adds 12: the 8 apps menu tour boards, TqSetUploaden, TqTokensetToepassen, TqOverridesOpslaan, TqVersieHerstellen); boards re-flattened (build.py --only; thumbnails fail on PIL, flatten fresh).
Stack: NC 34.0.6 tarball, PostgreSQL 16, nginx + php-fpm 8.3 opcache, APCu + Redis; OpenRegister main 78a1b9c8; buildiq development; thematiq development bd876b3 -> 6201c92 + #2168; set zuiddrecht (seed.sh); admin en; tour boards with the admin's seen version cleared.
Result: 39 of 40 rows measured (TqLettertypen needs seed.sh font, measured alone in run d: 1 data flag). Flag-free: TqBasistokens, TqBundel, TqEigenSets, TqOverridesUploaden, TqTerugNaarNextcloud, TqVersieHerstellen, TqMenuRondleidingMeer, -Ordenen, -Vastzetten = 9/39. Merged: #2168 (tour card + Back/Next as drawn: tour flags 18 -> 6), #2169 (rows). With buildiq installed the pageHeader 'buttons n>n-1' flags of run d are gone.
Not measurable yet: TqNieuwThema (stock set + edit, dialog opened from script in e2e), TqThemingBijwerken (opens after a written set switch), TqMerkenKiezen (needs an answered upload), TqSetup* x8 (first-time-setup in build). 51 Tq boards in all.

| board | paired | flagged | missing | extra | worst |
|---|---|---|---|---|---|
| TqAiAssistent | 9 | 4 | 0 | 0 | **card: Assistant** (content.minInsetLeft 28→1, content.gapBelowTitle 243.8→264)<br>**card: card@378,888** (rect.w 305.6→280)<br>**card: card@332,978** (rect.w 305.6→280) |
| TqBasistokens | 3 | 0 | 0 | 0 |  |
| TqBeweging | 5 | 8 | 20 | 8 | **card: Dialog slides in** (rect.w 753.8→390.8, props.background rgb(245, 246, 248)→rgb(255, 255, 255), props.boxShadow none→rgba(0, 0, 0, 0.12) 0px 4px 16px 0px, header.fontSize 16→14, header.fontWeight 700→600)<br>**card: card@315,897** (rect.w 75|
| TqBundel | 5 | 0 | 4 | 0 |  |
| TqComponenten | 4 | 10 | 41 | 26 | **card: Choose a component** (header (title row) present→absent, props.border.top 1→0, props.border.right 1→0, props.border.bottom 1→0, props.border.left 1→0)<br>**element button: button "Upload" in "record card"** (h 40→34, border 1→0, backgrou|
| TqContrastrapport | 9 | 1 | 3 | 6 | **element badge: badge "Text Aa"** (background rgb(54, 105, 165)→rgb(138, 161, 185)) |
| TqDonker | 8 | 10 | 18 | 15 | **card: Dossiq** (header (title row) present→absent, props.border.top 0→1, props.border.right 0→1, props.border.left 0→1, props.borderRadius 0→10)<br>**card: Dossiq** (header (title row) present→absent, props.border.top 0→1, props.border.right 0|
| TqEigenComponent | 2 | 5 | 19 | 14 | **card: Choose a component** (header (title row) present→absent, props.border.top 1→0, props.border.right 1→0, props.border.bottom 1→0, props.border.left 1→0)<br>**element badge: badge "To the voting guide"** (h 36→21, borderRadius 8→0, paddingL|
| TqEigenCss | 6 | 3 | 1 | 0 | **element field: field "Custom CSS"** (h 232→264, borderRadius 0→8)<br>**card: In the log** (content.gapBelowTitle 43.5→14.4)<br>**element toggle: toggle "Enable custom CSS"** (pixels -→90.1) |
| TqEigenSets | 1 | 0 | 0 | 0 |  |
| TqEigenTokens | 8 | 2 | 5 | 0 | **table: table@317,297** (cell.fontSize 14→13, cell.fontWeight 600→400, cell.lineHeight 16.8→18.85, cell.color rgb(27, 28, 29)→rgb(94, 97, 104), rowHeight 68→39.8)<br>**table: table@317,749** (cell.fontSize 12→13, cell.lineHeight 14.4→18.85, cel|
| TqEmailDocumenten | 11 | 6 | 1 | 2 | **element pill: pill "Remove document logo"** (border 0→1, background rgb(252, 237, 236)→rgb(255, 255, 255), color rgb(163, 0, 0)→rgb(27, 28, 29), fontSize 13→14)<br>**card: Example: letter** (content.gapBelowTitle 326→229.4)<br>**card: If there is |
| TqGalerij | 6 | 2 | 6 | 0 | **element toggle: toggle "Show the theme gallery"** (pixels -→90.1)<br>**element toggle: toggle "Check daily for new tokens (via api.github.com)"** (pixels -→90.1) |
| TqGroepen | 6 | 1 | 14 | 1 | **table: table@317,297** (cell.fontWeight 700→400, cell.lineHeight 15.6→18.85, cell.color rgb(61, 64, 71)→rgb(94, 97, 104), cell.textInsetLeft 10.1→0, rowHeight 93→39.8) |
| TqLayout | 15 | 6 | 8 | 6 | **card: card@1059,918** (content.firstRowInsetLeft 13→71, content.minInsetLeft 13→71, content.insetTop 3.8→8.8)<br>**card: card@1055,545** (content.firstRowInsetLeft 13→71, content.minInsetLeft 13→71)<br>**pageHeader: Layout and options** (buttons 3|
| TqLogboek | 5 | 2 | 5 | 2 | **element button: button "Actions for 4 Oct 2026: restore configuration of 4"** (border 1→0, background rgb(255, 255, 255)→rgb(234, 240, 247), icon true→false)<br>**table: table@317,276** (rowHeight 74→61) |
| TqMerkPerApp | 7 | 4 | 13 | 1 | **table: table@317,731** (cell.fontSize 14→13, cell.fontWeight 600→400, cell.lineHeight 16.8→18.85, cell.color rgb(27, 28, 29)→rgb(94, 97, 104), rowHeight 55→39.8)<br>**card: Mobile logo and home screen icon** (content.firstRowInsetLeft 105→42, co|
| TqOmgevingsmarkering | 13 | 5 | 2 | 0 | **card: Environment of this server** (content.firstRowInsetLeft 37→25, content.gapBelowTitle 47.8→42.3)<br>**element badge: badge "Development environment"** (h 22→18, borderRadius 4→0)<br>**element badge: badge "Test environment"** (h 22→18, border|
| TqOverridesOpslaan | 2 | 2 | 0 | 0 | **element toggle: toggle "Do not ask again before saving"** (h 18→13)<br>**element button: button "Save overrides"** (icon true→false) |
| TqOverridesUploaden | 2 | 0 | 0 | 0 |  |
| TqPersoonlijk | 0 | 1 | 28 | 6 | **page: page background** (background rgb(146, 148, 149)→rgb(245, 246, 248)) |
| TqSetUploaden | 8 | 3 | 13 | 4 | **element pill: pill "WCAG AA" in "record card"** (h 21→17.4, background rgb(231, 243, 234)→color(srgb 0.88 0.919529 0.890353), color rgb(30, 107, 42)→rgb(0, 84, 22), fontSize 13→11.2, icon true→false)<br>**table: table@317,1773** (cell.fontWeight 6|
| TqTerugNaarNextcloud | 2 | 0 | 0 | 0 |  |
| TqTheming | 13 | 5 | 20 | 13 | **element button: button "#3669A5"** (h 44→26, borderRadius 8→13, paddingLeft 14→0, paddingRight 14→0, background rgb(54, 105, 165)→rgb(234, 240, 247))<br>**card: Keep the Nextcloud theme in line with the set ~ Nextcloud** (rect.w 750→572.4, heade|
| TqTokenSet | 9 | 17 | 18 | 6 | **card: House styles of other organisations** (header.fontSize 17→18, header.lineHeight 20.4→23.4, header.titleInsetTop 21→45, content.gapBelowTitle 98→12.4)<br>**card: Dossiq** (header (title row) present→absent, props.background rgb(245, 246, 248)|
| TqTokenToevoegen | 2 | 4 | 1 | 1 | **element field: field "Name"** (borderRadius 0→8, borderColor rgb(130, 138, 149)→rgb(54, 105, 165))<br>**element badge: badge "--nldesign-org-"** (background rgb(245, 246, 248)→rgb(240, 241, 243), fontSize 14→13)<br>**element field: field "Label"** (|
| TqTokenUitfaseren | 2 | 1 | 0 | 0 | **element field: field "Token"** (borderColor rgb(130, 138, 149)→rgb(54, 105, 165)) |
| TqTokensetToepassen | 2 | 3 | 5 | 3 | **dialog-card: dialog-card@384,276** (header (title row) absent→present)<br>**element toggle: toggle "Also update the Nextcloud theme"** (h 18→13)<br>**element button: button "Cancel"** (borderColor rgb(130, 138, 149)→rgb(27, 28, 29)) |
| TqVoorbeeldSessie | 0 | 1 | 24 | 2 | **page: page background** (background rgb(245, 246, 248)→rgb(255, 255, 255)) |
| TqWissels | 8 | 2 | 4 | 1 | **table: table@317,687** (cell.fontSize 14→13, cell.fontWeight 600→400, cell.lineHeight 16.8→18.85, cell.color rgb(27, 28, 29)→rgb(94, 97, 104), cell.textInsetLeft 24→583.2)<br>**card: Background jobs** (structure title / pill meta / meta→title / |
| TqMenuRondleiding | 1 | 2 | 11 | 2 | **dialog: Open the apps menu** (rect.w 340→320, header.actions 1→0)<br>**page: page background** (background rgb(255, 255, 255)→rgb(152, 153, 153)) |
| TqMenuRondleidingApps | 1 | 2 | 5 | 2 | **dialog: Your apps in a row** (header.actions 1→0)<br>**page: page background** (background rgb(152, 153, 153)→rgb(255, 255, 255)) |
| TqMenuRondleidingInstellingen | 1 | 3 | 8 | 3 | **page: page background** (background rgb(152, 153, 153)→rgb(255, 255, 255))<br>**element button: button "New document"** (pixels -→97.8)<br>**element button: button "More"** (pixels -→99.2) |
| TqMenuRondleidingMeer | 0 | 0 | 8 | 5 |  |
| TqMenuRondleidingNieuw | 1 | 1 | 6 | 5 | **dialog: Create ~ Menu settings** (header.actions 1→0) |
| TqMenuRondleidingOrdenen | 0 | 0 | 9 | 3 |  |
| TqMenuRondleidingVastzetten | 0 | 0 | 8 | 4 |  |
| TqMenuRondleidingOpnieuw | 2 | 10 | 8 | 2 | **element button: button "Reset to default"** (paddingLeft 10→4, paddingRight 12→4, fontSize 14→15, fontWeight 600→700)<br>**element row: row "Work packages OpenProject · openproject.zuiddrecht"** (h 53→45, paddingLeft 10→2, paddingRight 8→2)<b|
| TqVersieHerstellen | 1 | 0 | 3 | 0 |  |
