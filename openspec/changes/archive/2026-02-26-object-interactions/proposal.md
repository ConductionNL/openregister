# Proposal: object-interactions

## Why

OpenRegister objects currently have no way to attach tasks or notes. Apps like Procest need tasks linked to cases and Pipelinq needs tasks on leads, but each app shouldn't build its own task system. Nextcloud already has mature systems for this: CalDAV for tasks (VTODO) and the Comments system for notes. OpenRegister should provide a convenience API that wraps both, linking them to objects via standardized properties.

This lets any OpenRegister-based app get tasks and notes for free — with full compatibility with Nextcloud's Tasks app and Comments UI.

## What Changes

**New in OpenRegister:**

- **`TaskService`** — Creates/reads/updates/deletes CalDAV VTODO items with `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, `X-OPENREGISTER-OBJECT` properties and RFC 9253 LINK
- **`TasksController`** — REST endpoints at `/api/objects/{register}/{schema}/{id}/tasks`
- **`NoteService`** — Wraps `ICommentsManager` for notes on objects using objectType `"openregister"`
- **`NotesController`** — REST endpoints at `/api/objects/{register}/{schema}/{id}/notes`
- **`CommentsEntityEventListener`** — Registers `"openregister"` as a comments entity type
- **`ObjectDeletedEvent` handler** — Cleans up notes (and optionally tasks) when an object is deleted

## Capabilities

### New Capabilities

- **object-interactions** — Tasks and notes on any OpenRegister object, via Nextcloud-native systems

## Impact

- **API surface**: 7 new endpoints (4 task, 3 note) following the existing sub-resource pattern (like `/files`)
- **Dependencies**: Nextcloud CalDAV (sabre/dav, always available), Nextcloud Comments (core, always available)
- **Compatibility**: Tasks visible in Nextcloud Tasks app; notes visible via DAV comments API
- **Downstream apps**: Procest, Pipelinq, OpenCatalogi can immediately use these endpoints
