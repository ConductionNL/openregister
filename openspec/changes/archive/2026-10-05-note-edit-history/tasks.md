# Tasks: note-edit-history

## 1. Storage and service

- [x] 1.1 Migration: `openregister_note_versions` (comment id, message, editor, edited at).
      `Version1Date20260916141000`, plus `NoteVersion` and `NoteVersionMapper`.
      The row also carries the actor TYPE, because a note can be written by an
      access link rather than by an account.
- [x] 1.2 `NoteService::update()` with the author-or-manage guard, the lock refusal, the version write and the audit entry; `versions()`; cleanup on delete.
      The `manage` verdict is passed in by the controller, which is where the
      object is in hand; `TimelineVisibilityService::mayManageObject()` resolves
      it. Deleting an object clears the history of every note on it, not just
      the note deleted by hand.

## 2. API and leaf

- [x] 2.1 Routes `PATCH .../notes/{noteId}` and `GET .../notes/{noteId}/versions`; `editedAt`, `editedBy`, `versionCount` on note reads.
      PUT stays where it was so no existing client breaks. The summaries are
      read for a whole page in one query, not one query per note.
- [x] 2.2 "Edited" marker and a versions drawer on the notes leaf.
      Built in `nextcloud-vue`, where the notes leaf lives: `CnNotesTab` gains
      the marker and the action, `CnNoteHistoryDialog` is the drawer.
      ConductionNL/nextcloud-vue#1189. It reaches users once that release is
      consumed here.

## 3. Tests

- [x] 3.1 `tests/e2e/ci/note-edit-history.spec.ts`: write, edit, open the drawer, read the prior text.
      Over the HTTP API rather than the drawer: the drawer ships from
      nextcloud-vue and is not in this instance's bundle yet. The leaf half is
      asserted in `tests/components/CnNotesTabHistory.spec.js` there.
- [x] 3.2 Unit tests for the guards, the lock, the versions and the audit entry.
      `tests/Unit/Service/NoteVersionServiceTest.php` is new;
      `NoteServiceTest` and `NotesControllerTest` gained the guard, lock,
      summary, cleanup and refusal cases.
