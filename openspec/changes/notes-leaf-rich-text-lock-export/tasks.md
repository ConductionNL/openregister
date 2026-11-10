# Tasks: notes-leaf-rich-text-lock-export

## 1. Rich text

- [ ] 1.1 Markdown subset stored and sanitised on render.
- [ ] 1.2 Text editor when the Text app is present, textarea otherwise.

## 2. Lock

- [ ] 2.1 `POST .../notes/{id}/lock` sets verb `note-locked`, records actor
      and time, audits on the object.
- [ ] 2.2 Edit and delete refused with 423 on a locked note.

## 3. Export

- [ ] 3.1 Journal sheet PDF template and Markdown export over an object's
      notes.

## 4. Tests

- [ ] 4.1 Unit tests for sanitising, the lock guard and the export order.
- [ ] 4.2 `tests/e2e/ci/notes-leaf-lock.spec.ts`: write a note with a
      heading, lock it, see the edit control gone and the lock badge.
