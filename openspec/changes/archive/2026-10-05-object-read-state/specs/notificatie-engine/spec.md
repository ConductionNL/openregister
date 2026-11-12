# notificatie-engine

## ADDED Requirements

### Requirement: A notification is cleared by opening what it was about (REQ-ORS-003)

When a user opens an object, the system SHALL mark read every
notification for that user whose subject is that object. When a user opens
a sub-resource, the system SHALL mark read every notification whose
subject is that sub-resource. A notification whose subject no longer
exists SHALL be archived rather than left unread.

#### Scenario: doing the work empties the bell

- **GIVEN** a user with three unread notifications about one object
- **WHEN** the user opens that object
- **THEN** those three notifications read as read
- **AND** the user's unread count drops by three

#### Scenario: a deleted subject does not leave a dead alert

- **GIVEN** an unread notification about an object that is then deleted
- **WHEN** the notification list is read
- **THEN** that notification is archived and absent from the unread count
- @e2e exclude {lifecycle, covered by unit tests}

### Requirement: A notification may be snoozed or archived, and the list has an axis (REQ-ORS-004)

A notification SHALL support `snoozedUntil` and `archivedAt` beside its
read state. A snoozed notification SHALL be absent from the unread list
until that moment and SHALL return unread afterwards. An archived
notification SHALL leave the list without being marked read. The
notification list SHALL filter by subject type and by object, and a thread
SHALL be markable read as a whole.

#### Scenario: a snoozed notification comes back

- **GIVEN** an unread notification snoozed until tomorrow
- **WHEN** the unread list is read today and again after that moment
- **THEN** it is absent today and present afterwards, still unread
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}

#### Scenario: archiving is not reading

- **GIVEN** an unread notification
- **WHEN** the user archives it
- **THEN** it leaves the list, its read state stays unread
- **AND** the archive is visible on the notification

#### Scenario: the bell is filtered by what a notice is about

- **GIVEN** 400 notifications across four subject types
- **WHEN** the list is requested for one subject type
- **THEN** only that type's notifications are returned, with their own count
