# object-interactions

## REMOVED Requirements

### Requirement: Opening an object records a per-user view

**Reason**: The view repeated the audit trail's `read` row in a table of its own, with its own throttle, cap and cleanup. Ruben ruled on 2026-10-09 that recently opened is one abstraction on top of logging.

**Migration**: Recently opened is read from the audit trail (see "Recently opened is read from the audit trail"). Migration `Version1Date20261009100100` drops `openregister_object_views`; its rows are not copied, because every one of them recorded a read the audit trail also holds while it was on.

## MODIFIED Requirements

### Requirement: Favourites and recent are lenses on the object query

The object query SHALL accept `_favourite=true`, returning only the current user's starred objects, and `_recent=true`, returning the objects the current user read most recently according to the audit trail, ordered by the latest read descending. Each lens SHALL compose with every other filter, and RBAC SHALL still decide which objects the caller may see.

#### Scenario: a favourites chip on an index page

- **GIVEN** a user who starred two of five cases
- **WHEN** the index page queries with `_favourite=true` and `status=open`
- **THEN** only the starred cases with status open are returned
- @e2e tests/e2e/ci/favourites-and-recent.spec.ts

#### Scenario: a recent chip composes with a filter

- **GIVEN** a user who opened three cases, one of them with status `open`
- **WHEN** the index page queries with `_recent=true` and `status=open`
- **THEN** only that open case is returned
- @e2e exclude {composition with a property filter is the generic `_ids` restriction; covered by SearchQueryHandlerPersonalLensesTest and the favourites e2e composition test}

## ADDED Requirements

### Requirement: Recently opened is read from the audit trail

The system SHALL answer `_recent=true` from the audit trail's `read` rows of the current user: distinct objects, ordered by the latest read, capped at 100 objects before RBAC. It SHALL NOT keep a separate table of views and SHALL NOT throttle audit rows: repeated reads collapse at query time. Every object on a `_recent=true` page SHALL carry `@self.viewedAt`, the ISO 8601 moment of the current user's latest read of that object. The key `viewedAt` is a contract with consuming apps (dossiq's recent tile) and SHALL NOT be renamed. Reads SHALL be registered through `ReadHistoryService`, the one read registration for the audit trail and the AVG processing log.

#### Scenario: a dashboard tile shows when each object was opened

- **GIVEN** audit trails are enabled and a user opened cases A, B and C, in that order
- **WHEN** the dashboard queries `_recent=true`
- **THEN** the page holds C, B and A in that order
- **AND** each object carries `@self.viewedAt` as an ISO 8601 moment
- **AND** the response carries `@self.lenses.recent.available` = `true`
- @e2e tests/e2e/ci/favourites-and-recent.spec.ts

#### Scenario: opening an object twice lists it once

- **GIVEN** a user who opened case A at 09:00 and again at 10:00
- **WHEN** the user queries `_recent=true`
- **THEN** case A is listed once with `@self.viewedAt` at 10:00
- **AND** the audit trail still holds both `read` rows
- @e2e exclude {the collapse is a GROUP BY in SQL; covered by AuditTrailMapperReadHistoryTest against the migrated table}

#### Scenario: one user's history is never another's

- **GIVEN** two users who opened different cases
- **WHEN** each queries `_recent=true`
- **THEN** each sees only the cases they opened themselves
- **AND** a request carrying `_recentViews`, `_recentFor` or `_recentLens` cannot change that
- @e2e exclude {per-user separation is asserted by the existing per-user e2e test; the stripping of forged keys by SearchQueryHandlerPersonalLensesTest}

### Requirement: The recent lens says why it is empty

Whenever `_recent=true` is asked, the list response SHALL carry `@self.lenses.recent` as `{"available": bool, "reason": string|null}`. When the lens cannot answer, `available` SHALL be `false`, the page SHALL be empty, and `reason` SHALL be one of `audit-trail-disabled` (the setting `retention.auditTrailsEnabled` is off), `anonymous` (no logged-in user) or `read-history-unavailable` (the history could not be read). With the audit trail off, the system SHALL NOT keep or read a shadow log.

#### Scenario: the audit trail is switched off

- **GIVEN** `retention.auditTrailsEnabled` is `false`
- **WHEN** a user queries `_recent=true`
- **THEN** the page is empty
- **AND** `@self.lenses.recent` is `{"available": false, "reason": "audit-trail-disabled"}`
- @e2e exclude {switching the instance audit setting would disturb every other e2e spec running against the shared instance; covered by ReadHistoryServiceTest and SearchQueryHandlerPersonalLensesTest}

#### Scenario: an anonymous caller

- **GIVEN** no logged-in user
- **WHEN** the caller queries `_recent=true`
- **THEN** the page is empty, never the whole register
- **AND** `@self.lenses.recent.reason` is `anonymous`
- @e2e exclude {an anonymous object list is usually refused before the lens runs; the lens answer is covered by ReadHistoryServiceTest and SearchQueryHandlerPersonalLensesTest}
