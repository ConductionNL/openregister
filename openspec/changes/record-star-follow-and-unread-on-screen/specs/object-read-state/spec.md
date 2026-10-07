# object-read-state

## ADDED Requirements

### Requirement: Opening a record's page marks it read and badges its tabs (REQ-ORS-005)

OpenRegister's record page SHALL send `PUT .../read-state` once the record's data has rendered, and not when the page fails to load. It SHALL show the counts from `@self.unreadCounts` as badges on the matching tabs, and SHALL offer "Mark as unread" in its action menu, which sends `DELETE .../read-state`.

#### Scenario: an opened record is no longer unread

- **GIVEN** a record changed by a colleague after the user last opened it
- **WHEN** the user opens the record's page and it renders
- **THEN** `PUT .../read-state` is sent and the record no longer shows as unread on the Tables page
- @e2e exclude {specified only; task 3.1 covers it}

#### Scenario: a page that fails to load marks nothing

- **GIVEN** an unread record whose detail read fails
- **WHEN** the user opens its page
- **THEN** no `PUT .../read-state` is sent and the record stays unread
- @e2e exclude {specified only; covered by a unit test on the page's load handler}

#### Scenario: the files tab shows what is new

- **GIVEN** a record with two files added since the user last opened it
- **WHEN** the user opens the record's page
- **THEN** the Files tab carries the badge 2
- @e2e exclude {specified only; task 3.1 covers it}

### Requirement: The Tables page shows unread records and filters on them (REQ-ORS-006)

The Tables page SHALL draw a row whose `@self.unread` is true in bold with an unread dot, and SHALL offer an Unread quick filter that adds `_unread=true` to the list query.

#### Scenario: only what changed

- **GIVEN** 40 records of which 6 changed since the user last opened them
- **WHEN** the user turns on Unread
- **THEN** the list shows the 6 records, each in bold, and the total says 6
- @e2e exclude {specified only; task 3.1 covers it}
