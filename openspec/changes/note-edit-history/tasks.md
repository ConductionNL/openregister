# Tasks: note-edit-history

## 1. Storage and service

- [ ] 1.1 Migration: `openregister_note_versions` (comment id, message, editor, edited at).
- [ ] 1.2 `NoteService::update()` with the author-or-manage guard, the lock refusal, the version write and the audit entry; `versions()`; cleanup on delete.

## 2. API and leaf

- [ ] 2.1 Routes `PATCH .../notes/{noteId}` and `GET .../notes/{noteId}/versions`; `editedAt`, `editedBy`, `versionCount` on note reads.
- [ ] 2.2 "Edited" marker and a versions drawer on the notes leaf.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/note-edit-history.spec.ts`: write, edit, open the drawer, read the prior text.
- [ ] 3.2 Unit tests for the guards, the lock, the versions and the audit entry.
