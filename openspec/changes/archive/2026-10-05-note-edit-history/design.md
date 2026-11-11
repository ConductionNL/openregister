# Design: note-edit-history

## D-1: versions beside the comment, not inside it

`ICommentsManager` has no history and its message column is the live text.
A small versions table keyed on the comment id keeps the prior texts without
touching the comments schema, and is deleted with the note.

## D-2: who may edit

The author, or `manage` on the object. `update` on the object is not
enough: a note is a signed statement by its author, and only the author or
someone who manages the object rewrites it. The lock beats both.

## D-3: audit the fact, version the text

The object's hash-chained trail gets `note.edited` with the note id and the
editor. The text lives in the versions table, so the trail stays small and
the versions stay readable.

## D-4: kind

Code, in OpenRegister. Consuming apps decide when to lock.
