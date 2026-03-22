---
status: partial
---
# Object Interactions

## Purpose

OpenRegister objects require rich interaction capabilities — notes, tasks, file attachments, tags, and audit trails — that allow users to collaborate on and track the lifecycle of register data. Rather than building custom interaction systems, this spec defines a convenience API layer that wraps Nextcloud's native subsystems (CalDAV for tasks, ICommentsManager for notes, IRootFolder for files, Nextcloud tags) and links them to OpenRegister objects via standardized properties. Any consuming app (Procest, Pipelinq, OpenCatalogi, ZaakAfhandelApp) can use these unified sub-resource endpoints without knowledge of the underlying Nextcloud internals.

**Standards**: RFC 5545 (iCalendar/VTODO), RFC 9253 (iCalendar LINK property), Nextcloud Comments API, Nextcloud Activity API, CloudEvents v1.0
**Cross-references**: [audit-trail-immutable](../audit-trail-immutable/spec.md), [event-driven-architecture](../event-driven-architecture/spec.md), [notificatie-engine](../notificatie-engine/spec.md)

---

## Requirements


### Requirement: Register OpenRegister as Comments Entity Type

The system SHALL register `"openregister"` as a valid entity type with Nextcloud's Comments system via a `CommentsEntityListener` that handles `OCP\Comments\CommentsEntityEvent`. The validation closure MUST verify that the given object UUID exists in the database using `MagicMapper::find()`.

#### Scenario: Entity type registration on app load
- **GIVEN** the OpenRegister app is loaded and Nextcloud dispatches `CommentsEntityEvent`
- **WHEN** the `CommentsEntityListener` handles the event
- **THEN** it MUST call `$event->addEntityCollection('openregister', $validationClosure)`
- **AND** the validation closure MUST return `true` for existing object UUIDs and `false` for non-existent ones

#### Scenario: Comment on non-existent object rejected by Nextcloud
- **GIVEN** a direct attempt to create a comment with `objectType: "openregister"` and `objectId: "nonexistent-uuid"`
- **WHEN** Nextcloud's comment system validates the entity
- **THEN** the validation closure MUST return `false`
- **AND** the comment creation MUST be rejected by Nextcloud

#### Scenario: Listener registered in Application.php
- **GIVEN** the OpenRegister `Application` class
- **THEN** `CommentsEntityListener` MUST be registered as a listener for `CommentsEntityEvent` in `registerEventListeners()`


### Requirement: RBAC for Interaction Operations

All interaction endpoints (notes, tasks, files, tags) SHALL enforce the same role-based access controls as the parent object. Users MUST have read access to the object to list its interactions, and write access to create or modify interactions. The system MUST use the existing `ObjectService` validation to verify access before performing any interaction operation.

#### Scenario: Unauthorized user cannot create notes
- **GIVEN** user `viewer-1` has read-only access to object `abc-123`
- **WHEN** a POST request is sent to create a note
- **THEN** the API MUST return HTTP 403 or deny the operation per the object's access controls

#### Scenario: Object access validation before interaction
- **GIVEN** any interaction endpoint (notes, tasks, files)
- **WHEN** a request is received
- **THEN** the controller MUST first validate the object exists and the user has access via `ObjectService::setRegister()`, `setSchema()`, `setObject()`, and `getObject()`

#### Scenario: Note deletion authorization gap (known limitation)
- **GIVEN** the current `NoteService::deleteNote()` implementation
- **WHEN** any authenticated user with object access calls DELETE on a note
- **THEN** the note is deleted regardless of whether the user authored it
- **AND** this is a documented known limitation — future versions SHOULD enforce author-or-admin authorization

#### Scenario: Admin can delete any interaction
- **GIVEN** an admin user
- **WHEN** the admin deletes a note, task, or file on any object
- **THEN** the operation MUST succeed regardless of who created the interaction


### Requirement: Sub-Resource API Endpoint Pattern

All interaction endpoints SHALL follow a consistent sub-resource pattern under the objects URL. This pattern MUST align with the existing files sub-resource endpoints and enable consuming apps to discover all available interactions for an object.

#### Scenario: Consistent URL structure
- **GIVEN** the base object URL `/api/objects/{register}/{schema}/{id}`
- **THEN** interaction endpoints MUST follow this pattern:
  - Notes: `GET|POST .../notes`, `DELETE .../notes/{noteId}`
  - Tasks: `GET|POST .../tasks`, `PUT|DELETE .../tasks/{taskId}`
  - Files: `GET|POST .../files`, `GET|PUT|DELETE .../files/{fileId}`

#### Scenario: CORS headers on all interaction endpoints
- **GIVEN** a cross-origin request to any interaction endpoint
- **WHEN** the request is processed
- **THEN** the response MUST include appropriate CORS headers following existing OpenRegister CORS patterns

#### Scenario: Content-Type for all responses
- **GIVEN** any interaction endpoint
- **WHEN** a response is returned
- **THEN** the Content-Type MUST be `application/json`
- **AND** list responses MUST use the format `{"results": [...], "total": N}`

---

## Non-Functional Requirements

- **Performance**: Task listing MUST complete within 2 seconds for objects with up to 50 tasks. Note listing MUST complete within 1 second for objects with up to 200 notes. File listing MUST complete within 1 second.
- **Security**: All interaction operations MUST respect the parent object's RBAC. No interaction endpoint SHALL be accessible without valid authentication (enforced via `@NoAdminRequired` annotations on controllers).
- **Compatibility**: X-OPENREGISTER-* properties MUST NOT break standard CalDAV clients. Notes MUST be viewable through Nextcloud's native Comments UI where applicable. Tasks MUST be visible in Nextcloud's Tasks app.
- **Reliability**: Cleanup failures during object deletion MUST be logged but MUST NOT block the deletion. Individual task/note deletion failures MUST NOT prevent other cleanup operations from proceeding.
- **Scalability**: The in-memory task filtering approach is adequate for typical use (up to 1,000 calendar objects per user). For deployments with very large calendars, a CalDAV REPORT query or indexed storage SHOULD be considered as a future optimization.

---

## Architecture Overview

```
+--------------------------------------------------+
|  App Frontend (Procest, Pipelinq, etc.)          |
|  - Simple JSON REST calls                        |
+------------------+-------------------------------+
                   |
                   | /api/objects/{register}/{schema}/{id}/tasks
                   | /api/objects/{register}/{schema}/{id}/notes
                   | /api/objects/{register}/{schema}/{id}/files
                   |
+------------------v-------------------------------+
|  OpenRegister Convenience API                     |
|  - TasksController  -> TaskService                |
|  - NotesController  -> NoteService                |
|  - FilesController  -> FileService                |
|  - TagsController                                 |
+--------+------------------+-----------+----------+
         |                  |           |
+--------v--------+ +------v---------+ +v-----------------+
| Nextcloud       | | Nextcloud      | | Nextcloud        |
| CalDAV (sabre)  | | Comments       | | Files            |
| CalDavBackend   | | ICommentsManager| | IRootFolder      |
| VTODO items     | | objectType:    | | Object folders   |
| + X-OPENREG-*   | | openregister   | |                  |
| + LINK (9253)   | | objectId: uuid | |                  |
+-----------------+ +----------------+ +------------------+

Event Flow:
+-------------------------------------------------+
| ObjectDeletedEvent -> ObjectCleanupListener      |
|   - NoteService::deleteNotesForObject()          |
|   - TaskService::getTasksForObject() + delete    |
|   - File cleanup                                 |
+-------------------------------------------------+

Comments Registration:
+-------------------------------------------------+
| CommentsEntityEvent -> CommentsEntityListener     |
|   - Registers objectType "openregister"          |
|   - Validates UUIDs via MagicMapper::find()      |
+-------------------------------------------------+
```

---

## Linking Model

### CalDAV Task Linking (X-Properties)

Each VTODO created through OpenRegister MUST include:

| Property | Value | Purpose |
|----------|-------|---------|
| `X-OPENREGISTER-REGISTER` | Register ID (integer) | Identifies the register |
| `X-OPENREGISTER-SCHEMA` | Schema ID (integer) | Identifies the schema |
| `X-OPENREGISTER-OBJECT` | Object UUID (string) | Identifies the object |

Additionally, each VTODO SHOULD include an RFC 9253 LINK property:

```ics
LINK;LINKREL="related";LABEL="{object title}";VALUE=URI:
 /apps/openregister/api/objects/{register}/{schema}/{objectUuid}
```

### Comments Note Linking

Comments use Nextcloud's native system with:
- `objectType`: `"openregister"`
- `objectId`: The OpenRegister object UUID

### File Linking

Files are stored in Nextcloud's filesystem and linked to objects via the object's folder structure, managed by `FileService`.

---

## Implementation Status

- **Fully implemented**: TaskService, TasksController, NoteService, NotesController, CommentsEntityListener, ObjectCleanupListener, FilesController, TagsController
- **Known limitation**: Note deletion does not enforce author/admin authorization
- **Known limitation**: Task assignee field is not included in API responses
- **Known limitation**: No unified timeline endpoint (individual sub-resource endpoints only)
- **Future enhancement**: Fire typed interaction events (`nl.openregister.object.note.created`, etc.) via IEventDispatcher
- **Future enhancement**: Register interactions in the Nextcloud Activity stream via `OCP\Activity\IManager` / `IProvider`
- **Future enhancement**: Interaction count badges on object list views via EntityRelation tracking

---

## Nextcloud OCP Interfaces Used

| Interface | Used By | Purpose |
|-----------|---------|---------|
| `OCA\DAV\CalDAV\CalDavBackend` | TaskService | CalDAV VTODO CRUD operations |
| `OCP\Comments\ICommentsManager` | NoteService | Comment CRUD operations |
| `OCP\Comments\CommentsEntityEvent` | CommentsEntityListener | Entity type registration |
| `OCP\EventDispatcher\IEventDispatcher` | Application, listeners | Event dispatch and handling |
| `OCP\IUserSession` | TaskService, NoteService | Current user context |
| `OCP\IUserManager` | NoteService | Display name resolution |
| `OCP\Files\IRootFolder` | FileService, FilesController | File storage operations |
| `Sabre\VObject\Reader` | TaskService | iCalendar VTODO parsing |

---

## Standards and References

- RFC 5545 (iCalendar) for VTODO format
- RFC 9253 (iCalendar LINK property) for object linking in VTODOs
- CloudEvents v1.0 for interaction event format
- Nextcloud Comments API (`ICommentsManager`)
- Nextcloud CalDAV backend (`CalDavBackend`)
- Nextcloud Activity API (`IManager`, `IProvider`) for future activity stream integration
- Sabre VObject library for iCalendar parsing
