# object-read-state

## ADDED Requirements

### Requirement: An object carries a read state per user (REQ-ORS-001)

The system SHALL keep, per user and per object, the moment the user last
saw it, and SHALL derive an unread flag by comparing that moment with the
object's last substantive change. A user SHALL be able to mark an object
read or back to unread by hand. The read state SHALL be private to that
user. A schema MAY declare which properties and which sub-resources count
as a substantive change; where it declares none, a change to any
non-computed property SHALL count.

#### Scenario: a change makes an object unread for the other reader

- **GIVEN** two users who have both opened the same object
- **WHEN** one of them changes a declared property
- **THEN** the object reads unread for the other user and read for the one who changed it

#### Scenario: a recalculated computed field changes nothing

- **GIVEN** an object whose computed field is re-evaluated on a schedule
- **WHEN** the recalculation writes a new computed value
- **THEN** the object stays read for every user who had seen it
- @e2e exclude {derivation, covered by unit tests}

#### Scenario: marking back to unread works

- **GIVEN** an object a user has read
- **WHEN** the user marks it unread
- **THEN** it reads unread for that user and stays read for everyone else

### Requirement: Unread is a filter and a badge, resolved in the query (REQ-ORS-002)

The object query and the search SHALL accept an unread filter for the
current user and SHALL return the unread flag with each row. The filter
SHALL be resolved inside the query, so paging and counts are correct, and
SHALL NOT be applied after the page is fetched. A saved view MAY carry the
filter. An object read SHALL return unread counts per sub-resource as one
map, so a page renders its tab badges without a call per tab.

#### Scenario: an unread-only list pages correctly

- **GIVEN** 120 objects of which 30 are unread for this user
- **WHEN** the list is requested with the unread filter and a page size of 25
- **THEN** the total is 30 and the second page holds the next 5 unread objects
- **AND** no page contains a read object

#### Scenario: the tabs badge from one read

- **GIVEN** an object with two unread files and one unread message for this user
- **WHEN** the object is read
- **THEN** the response carries counts of 2 for files and 1 for messages
- @e2e exclude {counts, covered by unit tests}
