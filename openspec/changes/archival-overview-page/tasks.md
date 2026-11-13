# Tasks: archival-overview-page

## 1. Overview read (REQ-AOV-001)

- [x] 1.1 `lib/Service/Archival/ArchivalOverviewService.php` assembles the overview from the destruction list repository, the retention scan, the transfer records and the selection list import. Verify: `tests/Unit/Service/Archival/ArchivalOverviewServiceTest.php` (fails on development: the class does not exist).
- [x] 1.2 `lib/Controller/ArchivalOverviewController.php` and route `archivalOverview#overview` (`GET /api/archival/overview`), archivist or admin only. Verify: `tests/Unit/Controller/ArchivalOverviewControllerTest.php`.

- [x] 1.3 `ArchivalOverviewService::drawUpList()` and route `archivalOverview#drawUp` (`POST /api/archival/destruction-lists/draw-up`) (REQ-AOV-003). Verify: `ArchivalOverviewServiceTest::testDrawingUpAListNamesEveryEligibleRecord`, `ArchivalOverviewControllerTest::testNothingEligibleIsA422`.

## 2. Page (REQ-AOV-002)

- [x] 2.1 Manifest fragment `src/manifest.d/archival-overview-page.json` (page `Archival`, menu entry), relocation into `AdministrationGroup` in `src/menu-layout.json`, icon `Gavel` in `src/icons.js`, strings in `l10n/nl.json` and `l10n/en.json`. Verify: `src/tests/archival-overview-page.spec.js` (fails on development: no such page).

## 3. Verify

- [x] 3.1 Diff check while building; once before the PR `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`.
