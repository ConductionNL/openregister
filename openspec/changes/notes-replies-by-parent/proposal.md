---
kind: code
depends_on: []
---

# Proposal: notes-replies-by-parent

## Summary

A colleague answers a note on a record instead of adding a new one below it,
and the conversation stays together. OpenRegister stores the reply as a
Nextcloud comment with a parent, returns the parent on every note, and refuses
a reply to a note on another record.

## Halves this closes

This is the OpenRegister half of nextcloud-vue's merged change
`notes-replies-group-mentions-and-images` (nextcloud-vue `development`
e487bc8), which covers buildiq row `pg-record-comments` and planninq rows
`col-threaded-comments`, `col-group-mention` and `col-comment-images`. It has no
row in OpenRegister's matrix; the owner moves pass of 28 Sep 2026 handed it
here. Nextcloud-vue writes: "OpenRegister stores notes as Nextcloud comments
and returns a flat list; its original design says threading 'can add in V2
without API changes (just add `parentId` to responses)' (archived
`object-interactions` design). `NotesController::create()` does not read a
parent today (`lib/Controller/NotesController.php:172`). The OpenRegister half:
accept `parentId` on create and return `parentId` on every note. Listed for
the openregister lane. Until notes carry a `parentId` key, Reply is not
shown."

## What changes

- `POST /api/objects/{register}/{schema}/{id}/notes` accepts an optional
  `parentId`: the id of a note on the same object.
- Every note in the list, in `getNote()` and in the create answer carries
  `parentId` (null for a top-level note).
- A reply to a note on another object, to a missing note, or to a note the
  caller may not see is refused with 422 and a fixed sentence.
- Deleting a note that has replies keeps the replies; their parent shows as a
  deleted note, as Nextcloud Talk and Files comments do.

## Out of scope

- Group mentions and images in notes. Nextcloud-vue's change covers the
  editor; the comment message already carries mentions.
- Nesting deeper than one level in the list shape. The data allows any depth;
  the library draws one level.

## Impact

- `lib/Controller/NotesController.php` (`create()`, `:161-200`).
- `lib/Service/NoteService.php` (`createNoteAs()` at `:329-350`, the note
  array at `:592-610`).
- `openspec/specs/object-interactions/spec.md`.
