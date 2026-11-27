# Tasks: notes-replies-by-parent

## 1. Service and controller

- [x] 1.1 `parentId` on `createNoteAs()` with the checks of design D-1 and visibility inheritance. Verify: `tests/Unit/Service/NoteServiceTest.php` for a valid reply, a parent on another object, a missing parent and a parent the caller cannot see.
- [x] 1.2 `parentId`, `replyCount` and `parentDeleted` in the note array. Verify: the same test reads them for a top-level note, a reply and a reply whose parent was deleted.
- [x] 1.3 `NotesController::create()` reads `parentId` and answers 422 with the fixed sentence on a bad parent. Verify: `NotesControllerTest` and a Newman case.

## 2. Proof and docs

- [x] 2.1 Add `tests/e2e/ci/note-replies.spec.ts`: add a note, reply to it through the API, and read both with the reply's `parentId`.
      > 2026-11-25: three tests, green on a sandbox Nextcloud 34 with MariaDB (3 passed). The admin asks, the seeded non-admin `e2e-other` replies with `parentId`, and the list reads the question with `replyCount` 1 and the reply naming it; a reply to another record's note is refused with 422 and the fixed sentence and writes nothing; after the question is deleted the reply is still listed with `parentDeleted` true. Mutation-checked: always answering `parentDeleted: false` in `NoteReplies::fields()` turns the third test red.
- [x] 2.2 Document replies in `docs/` beside notes.

Acceptance:
- A note without `parentId` behaves exactly as today.
