# Tasks: notes-leaf-rich-text-lock-export

## 1. Rich text

- [ ] 1.1 Markdown subset stored and sanitised on render.
  - Open: the note is rendered by the notes leaf in `@conduction/nextcloud-vue`, not in this repository; the sanitised render (and 1.2's editor) lands there.
- [ ] 1.2 Text editor when the Text app is present, textarea otherwise.

## 2. Lock

- [x] 2.1 `POST .../notes/{id}/lock` sets verb `note-locked`, records actor
      and time, audits on the object.
- [x] 2.2 Edit and delete refused with 423 on a locked note.

## 3. Export

- [x] 3.1 Journal sheet PDF template and Markdown export over an object's
      notes.
  - Markdown: `GET /api/objects/{register}/{schema}/{id}/notes-journal` downloads `NoteJournalExporter`'s sheet of every note the caller may list (the note list's visibility filter), oldest first, with author, time and who locked it when.
  - PDF: the same route with `?format=pdf` renders `NoteJournalExporter::table()` (time, author, lock, note; bodies whole, not cut) through the PDF export format's `ExportService::renderTableToPdf()`; 501 without an office server, 400 over the PDF row cap.

## 4. Tests

- [ ] 4.1 Unit tests for sanitising, the lock guard and the export order.
  - Export order: `NoteJournalExporterTest`, `NotesControllerJournalTest`, `NotesControllerJournalPdfTest`, `RowsPdfSectionTest`. The lock guard was covered with 2.x. Sanitising waits on 1.1.
- [ ] 4.2 `tests/e2e/ci/notes-leaf-lock.spec.ts`: write a note with a
      heading, lock it, see the edit control gone and the lock badge.
