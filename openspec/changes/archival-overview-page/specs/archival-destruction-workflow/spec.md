# archival-destruction-workflow

## ADDED Requirements

### Requirement: The archiving work has one overview read (REQ-AOV-001)

`GET /api/archival/overview` SHALL answer, for an archivist or an admin, `counts` (`destructionLists`, `awaitingApproval`, `legalHolds`, `transfers`, `selectionListVersions`), `destructionLists` (each with `uuid`, `title`, `summary`, `createdBy`, `createdByName`, `status`, `statusLabel`, `entryCount`, `excludedCount`), `legalHolds` (each record with an active hold: `uuid`, `title`, `reason`, `placedBy`, `placedDate`) with `legalHoldsTruncated`, `heldOffLists` (the records left off the open lists), `transfers`, `edepot` (the last completed transfer, its record count and package format, and the last failed attempt) and `selectionList` (the version in force and its category count). It SHALL refuse anybody else with 403 and an anonymous caller with 401. When no destruction list register is configured it SHALL say so with `configured: false` rather than answer an empty list.

#### Scenario: an archivist reads the overview
<!-- @e2e exclude Needs an archivist account and seeded lists; covered by PHPUnit ArchivalOverviewServiceTest and ArchivalOverviewControllerTest. -->

- **GIVEN** two destruction lists, one in review and one completed, and one record with an active legal hold
- **WHEN** an archivist calls `GET /api/archival/overview`
- **THEN** `counts.destructionLists` is 2, `counts.awaitingApproval` is 1, `counts.legalHolds` is 1 and each list carries a title and a translated status

#### Scenario: somebody who is not an archivist is refused
<!-- @e2e exclude Role check; covered by PHPUnit ArchivalOverviewControllerTest::testANonArchivistIsRefused. -->

- **GIVEN** a signed-in user outside the `archivaris` group who is not an admin
- **WHEN** they call `GET /api/archival/overview`
- **THEN** the answer is 403 and nothing is read

### Requirement: The archiving work has a page (REQ-AOV-002)

OpenRegister SHALL declare a page `Archival` at `/archival`, in the Administration menu group, as board `OrArchivering` draws it: a header with the primary action, tabs for destruction lists, legal holds, e-Depot transfers and the selection list, a notice for records held off a list, and fact cards for the e-Depot and the selection list. Every widget SHALL read `GET /api/archival/overview`; approve and reject on a destruction list SHALL call the existing approve and reject endpoints.

#### Scenario: the page is in the menu and reads the overview
<!-- @e2e exclude Manifest declaration; covered by vitest src/tests/archival-overview-page.spec.js. -->

- **GIVEN** the merged manifest
- **WHEN** the navigation is built
- **THEN** an `Archival` entry sits in the Administration group and its page's widgets read `/apps/openregister/api/archival/overview`

### Requirement: A destruction list can be drawn up from the page (REQ-AOV-003)

`POST /api/archival/destruction-lists/draw-up` SHALL draw up a destruction list now from every record the daily destruction sweep would put on one (eligible today and not on an open list), through the same creator as `POST /api/archival/destruction-lists`. It SHALL answer 201 with the list's `uuid`, `status` and `entryCount`, 422 when nothing may be destroyed now, 409 when no destruction list register is configured, and refuse anybody but an archivist or an admin. The page's primary action "Create destruction list" SHALL call it.

#### Scenario: an archivist draws up a list
<!-- @e2e exclude Needs records past their disposal date; covered by PHPUnit ArchivalOverviewServiceTest::testDrawingUpAListNamesEveryEligibleRecord and ArchivalOverviewControllerTest. -->

- **GIVEN** two records past their disposal date, one of them already on an open list
- **WHEN** an archivist presses "Create destruction list"
- **THEN** a list is drawn up holding the other record, and the page reads again

#### Scenario: nothing is eligible
<!-- @e2e exclude Covered by PHPUnit ArchivalOverviewControllerTest::testNothingEligibleIsA422. -->

- **GIVEN** no record past its disposal date
- **WHEN** an archivist presses "Create destruction list"
- **THEN** the answer is 422 and no list is written
