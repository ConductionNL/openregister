# launchpad pixel pass 2026-11-28, live-launchpad-1 (cloud)

Stack: sandbox reference stack (nginx, php-fpm 8.3 opcache, MariaDB 10.11, APCu, Redis), NC 34.0.6 (GitHub release tarball), OR main, launchpad development; demo seed `occ launchpad:demo:load --retire-showcases`. No thematiq: font-family and brand-colour flags are sandbox. design-system 73d47dd compdiff, boards fresh.
Tip for edit-mode boards: entering edit mode posts the presence beat; compdiff refuses it and the app reads that as "may not edit". Let the per-user POSTs through:
  --read-post '/apps/launchpad/api/dashboards/*/presence|/apps/launchpad/api/dashboard/*/activate|/apps/launchpad/api/dashboards/active|/apps/launchpad/api/dashboards/*/view-event|/apps/launchpad/api/announcements/seen'

## LpBewerken (pieter, /apps/launchpad/demo-werkplek; steps: More trigger, launchpad-more-menu-item-edit, waitFor launchpad-edit-bar) -> launchpad#2398
| item | before | after |
|---|---|---|
| card title vs drag grip | grip on the first letter, titleInsetLeft 19 (board 47) | 47 |
| edit-mode card border/shadow | hairline, none | field border + 0 1px 2px shadow (colour delta = theme token) |
| edit bar | 2 rows, 122 px (docked More with label wrapped) | 1 row, 70 px (board 62) |
Left: fonts, brand colour; states a read-only run cannot reach (co-editor banner, drag, keyboard panel, open menu, leave prompt); demo content differs.

## LpVersies (pieter; steps: More trigger, launchpad-more-menu-item-share, tab Versies, click 2nd version-row) -> launchpad#2398
5 strings untranslated in all locales (intro, What changed, Current version, preview) -> translated (nl as drawn, "Deze versie terugzetten"); preview card + sketch as drawn (thumbnail 0 flags, card 672x196 as drawn); "Deze versie nu opslaan" no longer cut. Left: table line heights (font), sortable/filterable columns the board draws.

## LpZichtbaarheid (communicatie, /apps/launchpad/demo-intranet; steps: edit, Nieuws cn-widget-edit-cog button, menuitem "Wie ziet deze widget?") -> launchpad#2437
Dialog flags 8 -> 3: kicker "Nieuws · Intranet Zuiddrecht" above a 20px/700 title, question label above the choice cards, View as line, Person/Moment halves (Moment no longer cut). Left: width 600 vs 640 (NcModal size), font; seed has no rules on Nieuws (board draws 4); board footer Annuleren / Regels opslaan vs draft-at-once dialog; Preview button not drawn.
