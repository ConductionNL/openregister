---
kind: code
---

# Proposal: object-file-added-event

## Why

OpenRegister dispatches an event for a renamed, copied, moved, locked, unlocked or restored file of an object, but not for an added one. An app that must act on every document attached to its objects, outside its own code paths, has nothing to listen to. OpenCatalogi is the first: `publications-name-their-responsible-organisation` task 2.2d stamps the publisher's RSIN onto every document attached to a publication, and a document an editor uploads through the files API never passes OpenCatalogi's code.

## What changes

- `OCA\OpenRegister\Event\FileAddedEvent`, a sibling of the other `AbstractObjectFileEvent` classes: object uuid, file id, and data `{fileName, register, schema}`.
- `FilesController` dispatches it after the file is stored, from the three files-API adds: `create` with content, `create` with a `source` URL, and `createMultipart` (once per stored file).
- A listener that throws does not fail the add. The file is already stored, so the listener handles and logs its own failure.

## Not in scope

`FileService::addFile()` itself does not dispatch. An app that adds a file in its own code already knows it did, and dispatching there would make that app's listener run inside its own write. `files#save` (upsert), `files#attach` (an existing Nextcloud node) and `files#saveChat` do not dispatch yet; each can follow when a caller needs it.

## Impact

One new event class and one private dispatch helper in `FilesController`. The files-actions golden gains the dispatch line on the 31 create and multipart cases; nothing else in it changes.
