# thematiq same-shell-every-page, task 6.3, local compare (28 Nov 2026)

Instance: Ruben's local http://localhost:8076 (Nextcloud 34, thematiq 1.2.54 at development 54f528a: `css/workplace-apps/` holds only mail, calendar and deck, so `common.css` and `brand.css` are gone), Mail 5.13.3, Calendar 6.7.0, Deck 1.18.6, house style Zuiddrecht, light layout and workplace header on (`--thematiq-light-layout: 1`, `--thematiq-app-layer` set per app). Boards: design-system main 73d47dd (#1222), served from `ds-main/preview`, rendered at 1440 x 1080 with `?lang=en&ds=zuiddrecht`.

How: compdiff (`scripts/screens/compdiff`) ran on MlInbox but paired 0 components (board pageHeader against live list card/toolbar), so the boards were compared by eye from 1440 x 1080 screenshots, plus computed-style measurements with Playwright (row heights, selected-entry colours, titles, buttons).

Seed data added for this run, still on the instance: Deck board 4 "Northwind rollout Lyon" (4 lists, 7 cards); 7 events `compdiff-1..7` in admin's Personal calendar (week 48); Mail account 3 `compdiff-admin@zuiddrecht.test` on the shared greenmail (host.docker.internal:3143/3025) with 6 mails. Switched for the run and put back: `menu.showCreateButton` (false, on only for the '+' check) and Deck's `cardDetailsInModal` (true, false only for the TbCard sidebar check).

Boards 6.0 has not redrawn yet (MlNav, ClNav, TbNav selected entry; TbCard 20 px title) still draw the old entry, so the selected entry in accent on its tint (#A30000 on #FCEDEC) on live is intended (decision 1), not a difference.

## Per board

| Board | Result | What differs, and the likely rule or component |
|---|---|---|
| MlInbox | differs | (1) New message button is 48 px tall (board 34 px): the shared new-item rule targets `NcAppNavigationNew` (`.app-navigation-new .button-vue`), Mail 5.13's button is not inside it, and `css/workplace-apps/mail.css` "New message" mapping no longer reaches it. (2) Selected conversation row: `.list-item--active` computes `background: #fff` (board: primary light tint plus 3 px primary edge); shared list-row rule in `workplace-layout.css` "Every app's standard structures", or Mail's white-row rule winning on specificity. Matched: 34 px navigation rows, selected entry by the option, 410 px list column, hairline rows not tiles, 32 px initials in tint, unread dot, empty pane (64 px envelope circle, "Select a conversation" 20 px bold). Not shown: board's account folders, Views, labels and "Inbox 12 conversations" chips are Mail features this Mail does not draw (live shows Priority inbox and a search field). |
| MlThreadList | matched | Rows: one column, 1 px hairline, 0 radius, white, 63 px, initials avatar in tint, unread dot, 410 px column. Preview line not shown because message bodies do not load from greenmail (data, see MlThread). |
| MlThread | differs | Selected row tint missing (same as MlInbox). Matched: rail 64 px with labels under icons (rail trigger on `#mail-thread-header`), subject bar 20 px bold, conversation as white card. Could not show: the message body ("Not found" from Mail on the greenmail account) and the board's work-package panel (live shows OpenRegister's Connections panel, `#openregister-mail-sidebar`, by design). |
| ClWeek | differs | (1) Navigation head: Calendar 6.7 draws a week stepper ("Week 48 of 2026" with arrows), filter field, Today and view button in the navigation; the board has the mini month date picker in the navigation and the title row "October 2026 Week 42" with Week, Today and arrows in the content. `calendar.css` "date picker beside its arrows and the navigation head in the boards' order" does not match 6.7's markup. (2) Calendar 6.7 opens an "Unscheduled tasks" sidebar (389 px) by default that the board does not draw (title is the shared 20 px). Matched: 34 px navigation rows, New event 34 px filled primary, white grid with hairlines, darker weekend, entries in tint with 3 px edge, "Mon 23" labels, today badge. |
| ClMonth | differs | Same navigation head and Unscheduled tasks sidebar as ClWeek. Matched: plain day numbers, today badge, timed entries as chips, other months' days muted, darker weekend. |
| ClEventPopover | differs | Drag-created popover in Calendar 6.7 is 480 px with 34 px fields, From/To rows with date and time pickers, timezone, all day, repeat, location, description, attendees, "More details" outline plus "Save" with check icon; the board draws 340 px with Title, Start/End (44 px fields), All day, Calendar select, Save and "More options" link. `.event-popover` rules in `calendar.css` restyle but do not reshape 6.7's popover. Escape closed it, nothing saved. |
| TbBoard | differs | (1) Board title 18 px on live, board 20 px (`deck.css` "the 60 px title row"). (2) Top bar: see DqKop row (pinned "Gantt charts" under the search field). Matched: white canvas, 224 px grey columns with status dot, count pill and plus, card faces with short due date, 34 px navigation rows, selected entry by the option. Not shown: labels, numbers, people and mail lines on cards (no labels or assignees in the seed). |
| TbCard | differs | With Deck's default `cardDetailsInModal = true` the card opens as a 900 px centred modal whose title is 30 px: the shared panel title rule (`#app-sidebar-vue .app-sidebar-header__mainname`, 20 px) and the 480 px panel width do not reach the modal. With `cardDetailsInModal = false` it matches: 480 px panel, 20 px bold title, folder-tab strip, navigation folds to the rail. Fix: cover Deck's modal, or decide that the light layout opens Deck cards in the sidebar. |
| DqKop (top bar, every page) | differs | Brand start matched on every page (emblem, "Municipality of Zuiddrecht" over the page name; "Administration settings" and "Personal settings" on Settings). (1) The '+' Create button is off on this instance (`menu.showCreateButton` false); switched on it appears on every page (Files, Mail, Calendar, Deck, both Settings) as `.thematiq-quick-add__button` 44 x 44, but with a transparent background where the board draws a filled circle in the primary tint (#EAF0F7); `css/header-workplace.css` quick-add rule. (2) At 1440 px the pinned external app "Gantt charts" in the top bar runs under the centred search field (title clipped "Gantt chart" behind the field); at 1920 px it fits. Header layout in `css/header-workplace.css` with `menu.topBarTitles` on. |
| dossiq/DqZaken | could not show | dossiq is present in `custom_apps` but not enabled on this instance. |

Score: 1 of the 8 app boards matched (MlThreadList).

## Same-shell rules on pages without a board

| Page | Navigation rows | Selected entry | Sidebar title | Brand start | '+' (when on) |
|---|---|---|---|---|---|
| Files | 34 px | accent on tint, 600 | 20 px bold (details panel) | yes | yes |
| Admin settings | 34 px (`#app-navigation-vue`) | accent on tint ("Basic settings") | n/a | yes, "Administration settings" | yes |
| Personal settings | 34 px | accent on tint ("Personal info") | n/a | yes, "Personal settings" | yes |
| Mail, Calendar, Deck | 34 px (54 px in the folded rail) | accent on tint (Calendar has no selected entry) | Calendar 20 px; Deck 20 px in sidebar mode, 30 px in its default modal | yes | yes |
| Talk | not installed | | | | |
| Contacts | not installed (only contactsinteraction) | | | | |

Files keeps underlines on the README prose links; no external help link was found in Admin settings to check its underline.
