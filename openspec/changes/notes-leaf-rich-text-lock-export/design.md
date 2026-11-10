# Design: notes leaf rich text, lock and export

## D-1: Markdown on the wire, sanitised on render

Notes ride `ICommentsManager` (object-interactions, Notes on Objects via
ICommentsManager). The message stays a string; the leaf stores Markdown and
renders it through the same sanitiser the collectives leaf uses for its
preview. Raw HTML in a note is escaped, never rendered.

## D-2: the lock is a comment verb, not a flag on the object

`ICommentsManager` stores a `verb` per comment. A locked note has verb
`note-locked`, and its message is frozen by the service: an edit or delete on
a `note-locked` comment is refused with 423. The actor and time of the lock
are appended as the comment's last edit, so the Comments app shows them too.
Only the note's author or a user with `manage` on the object may lock.

## D-3: no unlock

A locked note stays locked. Correcting one is a new note that references the
old one. This mirrors the journal practice the competitors implement and
keeps the audit honest.

## D-4: the journal sheet is the PDF export format over notes

export-pdf-format already renders an object to PDF from a template. The
journal sheet is a second template over the object's notes, in order, with
author, time and lock state; Markdown export is the same data without the
template.

## D-5: kind

Code, in OpenRegister. Consuming apps get it by mounting the notes leaf.
