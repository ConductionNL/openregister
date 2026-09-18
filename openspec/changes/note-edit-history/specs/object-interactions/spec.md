# object-interactions

## ADDED Requirements

### Requirement: An edited note keeps what it said before and who changed it

The system SHALL let a note's author, or a user with `manage` on the object,
edit the note's message, SHALL store the previous message with the previous
editor and time as a version before overwriting, SHALL mark the note with
`editedAt`, `editedBy` and `versionCount`, and SHALL list a note's versions
newest first to anyone who may read the note. A locked note SHALL refuse the
edit with HTTP 423.

#### Scenario: the previous text survives an edit

- **GIVEN** a note reading "Applicant called" by user A
- **WHEN** user A edits it to "Applicant called, will send documents"
- **THEN** the versions list holds "Applicant called" by A, and the note carries `editedBy` A and `versionCount` 1
- @e2e tests/e2e/ci/note-edit-history.spec.ts

#### Scenario: a locked note cannot be edited

- **GIVEN** a locked note
- **WHEN** its author tries to edit it
- **THEN** the response is 423 and no version is written
- @e2e exclude {nothing sets the verb yet: the lock ships with notes-leaf-rich-text-lock-export. Asserted in tests/Unit/Service/NoteServiceTest.php::testALockedNoteRefusesTheEditAndWritesNoVersion}

#### Scenario: a colleague with update cannot rewrite another's note

- **GIVEN** a note by user A and user B with `update` but not `manage` on the object
- **WHEN** user B tries to edit it
- **THEN** the response is 403
- @e2e tests/e2e/ci/note-edit-history.spec.ts

### Requirement: A note edit is audited on the object and versions die with the note

Every note edit SHALL write an audit entry on the object naming the note and
the editor. Deleting a note SHALL delete its versions.

#### Scenario: the trail records the edit, not the text

- **GIVEN** an edited note
- **WHEN** the object's audit trail is read
- **THEN** it holds a `note.edited` entry with the note id and editor and no note text
- @e2e tests/e2e/ci/note-edit-history.spec.ts
