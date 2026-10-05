---
kind: code
depends_on: [notes-leaf-rich-text-lock-export]
---

# Proposal: note-edit-history

## Summary

When a note is edited, keep what it said before and who changed it. A note
is a Nextcloud comment, and comments have no history: an edit overwrites.
This change stores every prior text as a version under the editor's name,
lists the versions to anyone who may read the note, and marks an edited note
as edited. A locked note (from `notes-leaf-rich-text-lock-export`) refuses
the edit, which is the iTop-shaped answer a municipality may prefer.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q6.17 | When a timeline entry is edited, does the product keep what it said before, and who changed it | partial | S |

## Why

The register's note: "Rated from the matrix. Row 2.16 records a
`version-history` tab on the case and row 6.1 a `JournalNoteDialog.vue`.
Whether editing a journal note keeps the previous text under the editor's
name is in neither cell. Znuny and iTop score `no` by forbidding the edit,
which a municipality may well prefer." The best competitor, verbatim from
the `best` column: "OTOBO 11.0: article_version with version_create_by
(`_round4/compare/proposed-rows-batch5.md`)".

The register's `why`: "keeping the previous text of an edited note is the
notes leaf; a locked note is the iTop-shaped answer". The lock exists in the
open notes change; the history does not exist anywhere.

## What changes

- `PATCH /api/objects/{register}/{schema}/{id}/notes/{noteId}` edits a
  note's message (and its visibility, see `timeline-entry-visibility`),
  allowed for the author or a user with `manage` on the object, refused
  with 423 when the note is locked.
- Every edit stores the previous message, the previous editor and the time
  in `openregister_note_versions` before overwriting the comment.
- A note read carries `editedAt`, `editedBy` and `versionCount`; `GET
  .../notes/{noteId}/versions` lists the versions newest first.
- Deleting a note deletes its versions; the object's audit trail gets one
  entry per edit naming the note.
- The notes leaf shows an "edited" marker and opens the versions in a
  drawer.

## Consumers

- dossiq: lock a journal note once it records a contact moment or a
  decision; otherwise an edit keeps its history. Specified in dossiq by the
  dossiq lane (register row Q6.17).
- humaniq (personnel file notes), keepiq, zaakafhandelapp.

## ADRs

- ADR-022: one notes primitive.
- openregister ADR-003 (immutable audit trail): the object's trail records
  that a note changed; the versions hold the text.

## Impact

- Extends: `object-interactions` requirement "Notes on Objects via
  ICommentsManager" and the delta "A note can be locked and a locked note is
  immutable" of `notes-leaf-rich-text-lock-export`.
- Affected code: `NoteService` (update, versions), one table and migration,
  `ObjectsController` routes, the notes leaf Vue surface.
- Size: S.
