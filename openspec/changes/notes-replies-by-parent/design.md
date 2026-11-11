# Design: notes-replies-by-parent

Read at openregister development 555af7212.

## Context

- Notes are Nextcloud comments with object type `openregister` and the object
  uuid as object id: `NoteService::createNoteAs()` calls
  `ICommentsManager::create()` and `save()` (`lib/Service/NoteService.php:340-350`).
- The note array (`:592-610`) has `id`, `message`, actor fields, `createdAt`,
  `visibility`, `locked` and edit summaries. No parent.
- `NotesController::create()` (`lib/Controller/NotesController.php:161-200`)
  reads `message` and `visibility` only.
- Nextcloud's `IComment` has `setParentId()` and `getParentId()`, and the
  comments manager maintains `topmostParentId` and the parent's child count.

## D-1: the parent is Nextcloud's own

`createNoteAs()` gains `?int $parentId`. When set, it loads the parent through
`ICommentsManager::get()`, checks that its object type and object id are this
note's, and that its visibility admits the caller (the same check a list read
applies), then calls `setParentId()` before `save()`. Any failed check throws
`InvalidNoteParentException`, which the controller answers with 422 and "The
note you reply to is not on this record."

## D-2: `parentId` on every note

The note array gains `parentId`: `(int)$comment->getParentId()`, or null when
Nextcloud reports `0`. Also `replyCount` from the comment's child count, so the
library can show "3 replies" without counting.

## D-3: deleting a parent

`deleteNote()` keeps using `ICommentsManager::delete()`. Nextcloud keeps the
children and their `parentId`; the list then contains replies whose parent is
missing. The note array marks such a reply `parentDeleted: true`, so the
library can show "reply to a deleted note" instead of hiding it.

## Risks

- A visibility-restricted parent with a public reply could reveal that the
  parent exists. D-1 refuses a reply the caller could not see, and a reply
  inherits the parent's visibility when it is stricter.
