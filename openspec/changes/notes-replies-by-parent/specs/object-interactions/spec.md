# object-interactions

## ADDED Requirements

### Requirement: A note can reply to another note on the same record

`POST /api/objects/{register}/{schema}/{id}/notes` SHALL accept an optional
`parentId` naming a note on the same object that the caller may see, and SHALL
store the reply as a Nextcloud comment with that parent. A parent on another
object, a missing parent, or a parent the caller may not see SHALL be refused
with 422. Every note returned SHALL carry `parentId`, null for a top-level
note, and `replyCount`.

#### Scenario: a colleague answers a question on a case

- **GIVEN** a case handler who can read case `Z-2026-014`, with a note "Is the permit attached?" from a colleague
- **WHEN** the handler calls `POST /api/objects/zaken/zaak/{id}/notes` with `message: "Yes, see the files tab"` and the note's id as `parentId`
- **THEN** the answer carries the new note with that `parentId`
- **AND** `GET .../notes` returns the question with `replyCount` 1 and the reply with the question's id as `parentId`
- @e2e exclude {specified only; task 2.1 adds tests/e2e/ci/note-replies.spec.ts}

#### Scenario: a reply cannot point at another record's note

- **GIVEN** a note on case `Z-2026-015`
- **WHEN** the handler posts a reply on case `Z-2026-014` with that note's id as `parentId`
- **THEN** the answer is 422 with "The note you reply to is not on this record."
- @e2e exclude {API contract; covered by NotesControllerTest in task 1.3}

#### Scenario: replies outlive a deleted question

- **GIVEN** the question and its reply from the first scenario
- **WHEN** the colleague deletes the question
- **THEN** the reply is still listed, with `parentDeleted` true
- @e2e exclude {specified only; covered by NoteServiceTest in task 1.2}
