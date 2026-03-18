---
status: reviewed
reviewed_date: 2026-02-28
---

# Object Interactions Specification

## Purpose

OpenRegister objects need tasks and notes — but these should use Nextcloud's native systems (CalDAV for tasks, Comments for notes) rather than custom schemas. This spec defines a convenience API layer in OpenRegister that wraps Nextcloud CalDAV VTODO items and the Comments system, linking them to OpenRegister objects via standardized properties.

Any app that uses OpenRegister (Procest, Pipelinq, OpenCatalogi, etc.) can use these endpoints to manage tasks and notes on their objects without knowing CalDAV or Comments internals.

**Standards**: RFC 5545 (iCalendar/VTODO), RFC 9253 (iCalendar LINK property), Nextcloud Comments API
**Feature tier**: MVP (tasks + notes CRUD with object linking)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────┐
│  App Frontend (Procest, Pipelinq, etc.)         │
│  - Simple JSON REST calls                       │
└──────────────┬──────────────────────────────────┘
               │ /api/objects/{register}/{schema}/{id}/tasks
               │ /api/objects/{register}/{schema}/{id}/notes
┌──────────────▼──────────────────────────────────┐
│  OpenRegister Convenience API                    │
│  - TasksController  → TaskService               │
│  - NotesController  → NoteService               │
└──────────┬───────────────────┬──────────────────┘
           │                   │
┌──────────▼──────┐  ┌────────▼─────────────────┐
│  Nextcloud      │  │  Nextcloud               │
│  CalDAV (sabre) │  │  Comments (ICommentsManager)│
│  CalDavBackend  │  │  objectType: openregister │
│  VTODO items    │  │  objectId: {uuid}        │
│  + X-OPENREG-*  │  │                           │
│  + LINK (9253)  │  │                           │
└─────────────────┘  └───────────────────────────┘

Cleanup:
┌──────────────────────────────────────────────────┐
│  ObjectCleanupListener (listens: ObjectDeletedEvent)│
│  - Deletes notes via NoteService::deleteNotesForObject() │
│  - Deletes tasks via TaskService::getTasksForObject()    │
│    + TaskService::deleteTask() per task                   │
└──────────────────────────────────────────────────┘

Comments Registration:
┌──────────────────────────────────────────────────┐
│  CommentsEntityListener (listens: CommentsEntityEvent)  │
│  - Registers objectType "openregister"                   │
│  - Validates UUIDs via ObjectEntityMapper::find()        │
└──────────────────────────────────────────────────┘
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

---

## Requirements

### REQ-OI-001: Task Service [MVP]

The system MUST provide a `TaskService` that creates, reads, updates, and deletes CalDAV VTODO items linked to OpenRegister objects.

#### Scenario: Create a task linked to an object

- GIVEN an OpenRegister object with UUID "abc-123" in register 5, schema 12
- WHEN the service creates a task with summary "Review documents" and due date "2026-03-01"
- THEN a VTODO MUST be created in the user's default calendar
- AND the VTODO MUST include:
  ```ics
  X-OPENREGISTER-REGISTER:5
  X-OPENREGISTER-SCHEMA:12
  X-OPENREGISTER-OBJECT:abc-123
  LINK;LINKREL="related";VALUE=URI:/apps/openregister/api/objects/5/12/abc-123
  ```
- AND the VTODO MUST include standard properties: SUMMARY, STATUS (NEEDS-ACTION), PRIORITY, DUE

#### Scenario: List tasks for an object

- GIVEN 3 VTODOs exist with `X-OPENREGISTER-OBJECT:abc-123`
- WHEN the service queries tasks for object "abc-123"
- THEN it MUST return all 3 tasks
- AND each task MUST be returned as a JSON object with: `id` (URI), `uid`, `calendarId`, `summary`, `description`, `status`, `priority`, `due`, `completed`, `created`, `objectUuid`, `registerId`, `schemaId`

#### Scenario: Update task status

- GIVEN a VTODO linked to an OpenRegister object
- WHEN the service updates its status to COMPLETED
- THEN the VTODO STATUS MUST be set to "COMPLETED"
- AND COMPLETED timestamp MUST be set
- AND the X-OPENREGISTER-* properties MUST remain unchanged

#### Scenario: Delete a task

- GIVEN a VTODO linked to an OpenRegister object
- WHEN the service deletes the task
- THEN the VTODO MUST be removed from the calendar

#### Scenario: Task query uses in-memory filtering

- GIVEN the service needs to find tasks for an object
- THEN `TaskService::getTasksForObject()` loads all calendar objects from the user's VTODO-supporting calendar via `CalDavBackend::getCalendarObjects()`
- AND performs a quick `strpos()` check for the object UUID in each calendar object's data
- AND parses matching VTODO objects with `Sabre\VObject\Reader` to extract X-OPENREGISTER-OBJECT for exact UUID matching
- NOTE: This is a PHP-based post-filter approach, not a CalDAV REPORT query. Performance is adequate for typical task counts but may degrade with very large calendars.

### REQ-OI-002: Tasks Controller and API [MVP]

The system MUST expose task operations as REST endpoints under the existing objects URL pattern.

#### Scenario: API endpoint pattern

- GIVEN the existing objects URL pattern `/api/objects/{register}/{schema}/{id}`
- THEN task endpoints MUST follow the sub-resource pattern:
  - `GET .../objects/{register}/{schema}/{id}/tasks` — List tasks
  - `POST .../objects/{register}/{schema}/{id}/tasks` — Create task
  - `PUT .../objects/{register}/{schema}/{id}/tasks/{taskId}` — Update task
  - `DELETE .../objects/{register}/{schema}/{id}/tasks/{taskId}` — Delete task

#### Scenario: Create task via API

- GIVEN a POST request to `.../objects/5/12/abc-123/tasks` with body:
  ```json
  {
    "summary": "Review documents",
    "description": "Check all uploaded files for completeness",
    "due": "2026-03-01T17:00:00Z",
    "priority": 1
  }
  ```
- THEN the API MUST create a VTODO with the correct X-OPENREGISTER-* properties
- AND the response MUST return the created task as JSON with HTTP 201
- AND the response MUST include the task `id` (CalDAV resource name) and `uid`

#### Scenario: List tasks returns JSON

- GIVEN a GET request to `.../objects/5/12/abc-123/tasks`
- THEN the API MUST return a JSON object with `results` (array of task objects) and `total` (count)
- AND each task object MUST include: `id` (URI), `uid`, `calendarId`, `summary`, `description`, `status`, `priority`, `due`, `completed`, `created`, `objectUuid`, `registerId`, `schemaId`
- NOTE: `assignee` is NOT currently implemented in the task response

#### Scenario: Task status mapping

- GIVEN CalDAV uses VTODO STATUS values: NEEDS-ACTION, IN-PROCESS, COMPLETED, CANCELLED
- THEN the API MUST map these to/from JSON:
  | CalDAV STATUS | JSON status |
  |---------------|-------------|
  | NEEDS-ACTION | `"needs-action"` |
  | IN-PROCESS | `"in-process"` |
  | COMPLETED | `"completed"` |
  | CANCELLED | `"cancelled"` |

#### Scenario: Verify object exists before creating task

- GIVEN a POST request to create a task on a non-existent object
- THEN the API MUST return HTTP 404
- AND no VTODO MUST be created

#### Scenario: CORS headers

- GIVEN a cross-origin request to the tasks API
- THEN the response MUST include appropriate CORS headers (following existing OpenRegister CORS patterns)

### REQ-OI-003: Note Service [MVP]

The system MUST provide a `NoteService` that wraps Nextcloud's `ICommentsManager` for creating, reading, and deleting notes (comments) on OpenRegister objects. The service also depends on `IUserSession` (for current user context) and `IUserManager` (for resolving display names).

#### Scenario: Register OpenRegister as a comments entity type

- GIVEN the OpenRegister app is loaded
- THEN it MUST register a `CommentsEntityListener` for `CommentsEntityEvent` (registered in `Application::registerEventListeners()`)
- AND the listener calls `$event->addEntityCollection('openregister', ...)` with a validation closure
- AND the closure uses `ObjectEntityMapper::find($objectUuid)` to validate whether the given object UUID exists in the database

#### Scenario: Create a note on an object

- GIVEN an OpenRegister object with UUID "abc-123"
- WHEN the service creates a note with message "Applicant called, will send documents tomorrow"
- THEN a comment MUST be created via ICommentsManager with:
  - `actorType`: `"users"`
  - `actorId`: current user ID
  - `objectType`: `"openregister"`
  - `objectId`: `"abc-123"`

#### Scenario: List notes for an object

- GIVEN 5 comments exist on object "abc-123"
- WHEN the service queries notes for "abc-123" via `NoteService::getNotesForObject(objectUuid, limit, offset)`
- THEN it MUST return notes up to the limit (default 50)
- AND each note MUST include: `id`, `message`, `actorType`, `actorId`, `actorDisplayName`, `createdAt`, `isCurrentUser`
- AND `actorDisplayName` is resolved from `IUserManager` (falls back to `actorId` if user not found)

#### Scenario: Delete a note

- GIVEN a comment on an OpenRegister object
- WHEN the service deletes the note via `NoteService::deleteNote(int $noteId)`
- THEN the comment MUST be removed via `ICommentsManager::delete()`
- NOTE: The current implementation does NOT enforce author/admin authorization on delete. Any authenticated user with access to the object can delete any note. Authorization enforcement is a future improvement.

### REQ-OI-004: Notes Controller and API [MVP]

The system MUST expose note operations as REST endpoints under the existing objects URL pattern.

#### Scenario: API endpoint pattern

- THEN note endpoints MUST follow the sub-resource pattern:
  - `GET .../objects/{register}/{schema}/{id}/notes` — List notes
  - `POST .../objects/{register}/{schema}/{id}/notes` — Create note
  - `DELETE .../objects/{register}/{schema}/{id}/notes/{noteId}` — Delete note

#### Scenario: Create note via API

- GIVEN a POST request to `.../objects/5/12/abc-123/notes` with body:
  ```json
  {
    "message": "Applicant called, will send documents tomorrow"
  }
  ```
- THEN the API MUST create a comment via ICommentsManager
- AND the response MUST return the created note as JSON with HTTP 201

#### Scenario: List notes returns JSON with actor info

- GIVEN a GET request to `.../objects/5/12/abc-123/notes`
- THEN the API MUST return a JSON object with `results` (array of note objects) and `total` (count)
- AND each note MUST include: `id`, `message`, `actorType`, `actorId`, `actorDisplayName`, `createdAt`, `isCurrentUser`
- AND display names are resolved via `IUserManager`
- NOTE: Note ordering depends on `ICommentsManager::getForObject()` which returns newest-first by default. Pagination is supported via `limit` and `offset` query parameters.

### REQ-OI-005: Calendar Selection [MVP]

The system MUST determine which CalDAV calendar to use for task storage. The `TaskService::findUserCalendar()` method handles this.

#### Scenario: Use first VTODO-supporting calendar

- GIVEN the user has one or more CalDAV calendars
- WHEN creating or listing tasks
- THEN the service finds the first calendar that supports VTODO components (by checking `supported-calendar-component-set`)
- AND uses that calendar for all task operations
- NOTE: This is NOT necessarily the user's "default" calendar; it is the first VTODO-capable calendar found

#### Scenario: User has no VTODO-supporting calendars

- GIVEN the user has no CalDAV calendars that support VTODO
- WHEN creating a task
- THEN `TaskService` throws an Exception with message "No VTODO-supporting calendar found for user {uid}"
- AND the controller catches this as a general Exception, returning HTTP 500

### REQ-OI-006: Object Deletion Cleanup [MVP]

The system MUST clean up tasks and notes when an OpenRegister object is deleted.

#### Scenario: Object deleted — remove linked notes

- GIVEN an OpenRegister object with UUID "abc-123" that has 3 notes
- WHEN the object is deleted
- THEN all comments with objectType "openregister" and objectId "abc-123" MUST be deleted via `ICommentsManager::deleteCommentsAtObject()`

#### Scenario: Object deleted — remove linked tasks

- GIVEN an OpenRegister object with UUID "abc-123" that has 2 linked VTODOs
- WHEN the object is deleted
- THEN the `ObjectCleanupListener` queries all tasks for the object UUID via `TaskService::getTasksForObject()`
- AND deletes each task via `TaskService::deleteTask(calendarId, taskUri)`
- AND logs the number of deleted tasks
- NOTE: Tasks are always deleted (not marked CANCELLED). Failures on individual tasks are logged as warnings but do not block the deletion.

---

## Non-Functional Requirements

- **Performance**: Task listing MUST complete within 2 seconds for objects with up to 50 tasks. CalDAV REPORT queries are post-filtered (not SQL-indexed), so the service SHOULD limit queries to the relevant user's calendars.
- **Security**: Task/note operations MUST respect RBAC — only users with access to the OpenRegister object can create/view/delete tasks and notes on it.
- **Compatibility**: The X-OPENREGISTER-* properties MUST NOT break standard CalDAV clients (they ignore unknown X- properties). Tasks created through OpenRegister MUST be visible in Nextcloud's Tasks app.

### Current Implementation Status
- **Fully implemented — TaskService**: `TaskService` (`lib/Service/TaskService.php`) provides CRUD operations for CalDAV VTODO items linked to OpenRegister objects via `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, and `X-OPENREGISTER-OBJECT` properties. Uses `CalDavBackend` for calendar operations and `Sabre\VObject\Reader` for VTODO parsing.
- **Fully implemented — TasksController**: `TasksController` (`lib/Controller/TasksController.php`) exposes REST endpoints at `.../objects/{register}/{schema}/{id}/tasks` for list, create, update, and delete operations.
- **Fully implemented — NoteService**: `NoteService` (`lib/Service/NoteService.php`) wraps `ICommentsManager` for CRUD operations on comments linked to OpenRegister objects. Uses `objectType: "openregister"` and `objectId: {uuid}`.
- **Fully implemented — NotesController**: `NotesController` (`lib/Controller/NotesController.php`) exposes REST endpoints at `.../objects/{register}/{schema}/{id}/notes` for list, create, and delete operations.
- **Fully implemented — CommentsEntityListener**: `CommentsEntityListener` (`lib/Listener/CommentsEntityListener.php`) registers `"openregister"` as a comments entity type and validates object UUIDs via `ObjectEntityMapper::find()`.
- **Fully implemented — ObjectCleanupListener**: `ObjectCleanupListener` (`lib/Listener/ObjectCleanupListener.php`) listens for `ObjectDeletedEvent` and deletes linked notes (via `ICommentsManager::deleteCommentsAtObject()`) and tasks (via `TaskService::getTasksForObject()` + `deleteTask()` per task).
- **Fully implemented — calendar selection**: `TaskService::findUserCalendar()` finds the first VTODO-supporting calendar by checking `supported-calendar-component-set`.
- **Registered in Application**: `Application.php` (`lib/AppInfo/Application.php`) registers the `CommentsEntityListener` for `CommentsEntityEvent` and `ObjectCleanupListener`.
- **Known limitation**: Note deletion does not enforce author/admin authorization (any authenticated user can delete any note). Task assignee is not included in the response.

### Standards & References
- RFC 5545 (iCalendar) for VTODO format
- RFC 9253 (iCalendar LINK property) for object linking in VTODOs
- Nextcloud Comments API (`ICommentsManager`)
- Nextcloud CalDAV backend (`CalDavBackend`)
- Sabre VObject library for iCalendar parsing

### Specificity Assessment
- **Highly specific and fully implemented**: The spec is detailed, well-structured, and all requirements are implemented with matching code.
- **Architecture diagram included**: Clear visual representation of the system architecture.
- **Known limitations documented**: Authorization gaps and performance notes are explicitly called out.
- **No open questions**: The spec covers all MVP scenarios comprehensively.

## Nextcloud Integration Analysis

**Status**: Fully implemented. TaskService, NoteService, TasksController, NotesController, CommentsEntityListener, and ObjectCleanupListener are all in place and functional.

**Nextcloud Core Interfaces Used**:
- `CalDavBackend` (`OCA\DAV\CalDAV\CalDavBackend`): Used by `TaskService` for all CalDAV VTODO operations (create, read, update, delete). Tasks are stored in the user's default VTODO-supporting calendar with `X-OPENREGISTER-*` custom properties for object linking.
- `ICommentsManager` (`OCP\Comments\ICommentsManager`): Used by `NoteService` for comment CRUD operations. Notes are stored as Nextcloud comments with `objectType: "openregister"` and `objectId: {uuid}`.
- `IEventDispatcher` (`OCP\EventDispatcher\IEventDispatcher`): `CommentsEntityListener` listens for `CommentsEntityEvent` to register "openregister" as a valid comment entity type. `ObjectCleanupListener` listens for `ObjectDeletedEvent` to cascade-delete linked tasks and notes.
- `IUserSession` / `IUserManager`: Used by `NoteService` for current user context and display name resolution on note authors.

**Recommended Enhancements**:
- Fire typed events (`ObjectTaskCreatedEvent`, `ObjectNoteCreatedEvent`) via `IEventDispatcher` when tasks or notes are added to objects. This would enable consuming apps (Procest, Pipelinq) to react to interaction events — e.g., updating a case status when a task is completed, or sending notifications when a note is added.
- Register task and note activity in the Nextcloud Activity stream via `IActivityManager` / `IProvider`. This would surface object interactions (task created, task completed, note added) in the user's activity feed alongside other Nextcloud activity.
- Use `EntityRelation` tracking for interaction statistics — count tasks and notes per object for display in list views (e.g., badge counts on object cards).

**Dependencies on Existing OpenRegister Features**:
- `ObjectEntityMapper` — validates object existence before task/note creation and during comment entity registration.
- `ObjectDeletedEvent` — internal event fired by `ObjectService` when objects are deleted, triggering cleanup.
- `Application.php` — registers `CommentsEntityListener` and `ObjectCleanupListener` during app initialization.
- Routes registered in `routes.php` — task and note sub-resource endpoints under the objects URL pattern.
