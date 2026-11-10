# object-interactions

## ADDED Requirements

### Requirement: Notes accept a bounded Markdown subset rendered sanitised

A note SHALL accept a Markdown subset (headings, emphasis, lists, links),
SHALL be stored as Markdown, and SHALL be rendered through a sanitiser so
that raw HTML is escaped. When the Nextcloud Text app is present the leaf
SHALL offer its editor; otherwise a Markdown textarea.

#### Scenario: a heading renders and a script tag does not

- **GIVEN** a note whose body is `# Contact\n<script>x()</script>`
- **WHEN** the note is rendered on the object
- **THEN** the heading "Contact" renders as a heading and the script tag appears as escaped text
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/ci/notes-leaf-lock.spec.ts when the leaf ships}

### Requirement: A note can be locked and a locked note is immutable

The author of a note, or a user with `manage` on the object, SHALL be able
to lock it. A locked note SHALL refuse edit and delete for every user with
status 423, SHALL display who locked it and when, and SHALL NOT be
unlockable. Locking SHALL write an audit entry on the object.

#### Scenario: an edit on a locked note is refused

- **GIVEN** a note locked by its author
- **WHEN** an admin tries to edit its body
- **THEN** the request is refused with 423 and the body is unchanged
- @e2e exclude {the guard is covered by unit tests on NoteService}

#### Scenario: a reader cannot lock

- **GIVEN** a user with read scope on the object who did not write the note
- **WHEN** they try to lock it
- **THEN** the request is refused with 403
- @e2e exclude {authorization is covered by unit tests on NoteService}

### Requirement: An object's notes export as a journal sheet

The system SHALL export every note of one object, in chronological order,
with author, time and lock state, as a PDF through the existing PDF export
format and as Markdown.

#### Scenario: the sheet lists notes in order with their lock state

- **GIVEN** an object with three notes of which the second is locked
- **WHEN** the journal sheet is exported as Markdown
- **THEN** the document lists the three notes in chronological order and marks the second as locked with the locker's name
- @e2e exclude {export contents are covered by unit tests on the exporter}
