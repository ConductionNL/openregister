# Tasks: notes-replies-by-parent

## 1. Service and controller

- [ ] 1.1 `parentId` on `createNoteAs()` with the checks of design D-1 and visibility inheritance. Verify: `tests/Unit/Service/NoteServiceTest.php` for a valid reply, a parent on another object, a missing parent and a parent the caller cannot see.
- [ ] 1.2 `parentId`, `replyCount` and `parentDeleted` in the note array. Verify: the same test reads them for a top-level note, a reply and a reply whose parent was deleted.
- [ ] 1.3 `NotesController::create()` reads `parentId` and answers 422 with the fixed sentence on a bad parent. Verify: `NotesControllerTest` and a Newman case.

## 2. Proof and docs

- [ ] 2.1 Add `tests/e2e/ci/note-replies.spec.ts`: add a note, reply to it through the API, and read both with the reply's `parentId`.
- [ ] 2.2 Document replies in `docs/` beside notes.

Acceptance:
- A note without `parentId` behaves exactly as today.
