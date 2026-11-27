# launchpad pixel pass 2026-11-27, help-launchpad-3 (cloud): LpDashboardInstellingen, Details tab

Stack: NC 34.0.6 tarball, PostgreSQL 16, php -S (pcov off); OR main; launchpad development + launchpad#2253; demo seed `occ launchpad:demo:load --retire-showcases`, pieter (nl), acknowledgement confirmed first. thematiq NOT installed: font-family flags (Fira Sans vs system-ui) are sandbox, not app.
Tooling: design-system cf1799b compdiff, board fresh.

Route that unblocks own-look-fields task 6 (compdiff refused edit mode): no edit mode needed.
  --url /apps/launchpad/demo-havenpark-planning --skip-first-run launchpad --steps
  [{"click":"[data-testid=\"launchpad-more-menu\"]"},{"wait":800},{"click":"[data-testid=\"launchpad-more-menu-item-share\"]"},{"wait":1500},{"click":"[role=tab]:has-text(\"Details\")"},{"wait":1500}]
  Note: the direct URL lands on Vandaag (start-page rule), and switching dashboards inside a read-only run does not stick (the switch writes); the dialog is measured on the Vandaag dashboard. Fine for layout.

| component | before | after (#2253) |
|---|---|---|
| dialog-card Eigen uitstraling | missing on live (not a card) | paired, 1 flag (font only) |
| dialog Dashboardinstellingen | 3 (font, titleInsetTop +3.5, gapBelowTitle +2.5) | same (NcDialog chrome) |
| tabs | 2 (font) | 2 (font) |

Left on the Details tab (not in own-look-fields): two-column field grid as drawn (Titel|Bovenliggend, Thema|Taal, Opent in|Doorklikniveaus) vs one column live; compact icon row + 'Eigen afbeelding' vs the full icon picker with tabs (extra 'tabs' on live); NcDialog title inset +3.5 px; board draws the background image as a picker over uploaded files, REQ-THM-006 (Ruben item 17) keeps the styled upload: board follows the spec.

## 2026-11-27T17:40:52Z help-launchpad-3: LpMeer, LpMeerAlle (more-menu-favourites task 16)
Stack: sandbox reference stack (nginx, php-fpm 8.3 opcache, MariaDB, APCu, Redis), NC 34 tarball, launchpad development a3befac4; no OR, no thematiq (font flags = sandbox). Demo seed --retire-showcases, pieter (nl), his acknowledgement confirmed first, 4 favourites starred as drawn, allowUserDashboards on (board draws New dashboard). design-system 73d47dd compdiff, boards fresh.
Steps LpMeer: click [data-testid=launchpad-more-menu-trigger], click [data-testid=launchpad-more-menu-settings]; LpMeerAlle: trigger, then [data-testid=launchpad-more-menu-item-all-dashboards].
| board | before | after (launchpad#2327) |
|---|---|---|
| LpMeer | 1 card paired, rows 42/40 px apart (NC button margin 3px + 15px text), grid icon on More, no rule above More, tinted pill, uppercase 11px Powered by | paired, insets <=1 px, rows 34 px as drawn; flags left: popover border+shadow (NcPopover wrapper chrome) |
| LpMeerAlle | same + New dashboard plain | same as LpMeer; New dashboard brand colour 600 with the rule |
Left: nl 'Mogelijk gemaakt door' vs board 'Powered by'; Settings admin entries (pieter not admin); tree/filter rows follow data. Speed: menu open 28 ms, expand 50, star API 33; demo-vandaag first content 248 (ok), total 632 vs floor 413 (+219, RED, page-level: perf lanes / first-paint-skeleton).
