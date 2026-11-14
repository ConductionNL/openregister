# Tasks: notes-leaf-rich-text-lock-export

## 1. Rich text

- [ ] 1.1 Markdown subset stored and sanitised on render.
- [ ] 1.2 Text editor when the Text app is present, textarea otherwise.

## 2. Lock

- [x] 2.1 `POST .../notes/{id}/lock` sets verb `note-locked`, records actor
      and time, audits on the object.
- [x] 2.2 Edit and delete refused with 423 on a locked note.

## 3. Export

- [ ] 3.1 Journal sheet PDF template and Markdown export over an object's
      notes.
  - Markdown half done: `GET /api/objects/{register}/{schema}/{id}/notes-journal` downloads `NoteJournalExporter`'s sheet of every note the caller may list (the note list's visibility filter), oldest first, with author, time and who locked it when. Open: the PDF journal sheet template.

## 4. Tests

- [ ] 4.1 Unit tests for sanitising, the lock guard and the export order.
  - Export order: `NoteJournalExporterTest`, `NotesControllerJournalTest`. The lock guard was covered with 2.x. Sanitising waits on 1.1.
- [ ] 4.2 `tests/e2e/ci/notes-leaf-lock.spec.ts`: write a note with a
      heading, lock it, see the edit control gone and the lock badge.
