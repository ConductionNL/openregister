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
│  VTODO items    │  │  objectType: openregister │
│  + X-OPENREG-*  │  │  objectId: {uuid}        │
│  + LINK (9253)  │  │                           │
└─────────────────┘  └───────────────────────────┘
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
- AND each task MUST be returned as a JSON object with: `id`, `uid`, `summary`, `description`, `status`, `priority`, `due`, `completed`, `created`

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

#### Scenario: Task query uses CalDAV REPORT

- GIVEN the service needs to find tasks for an object
- THEN it MUST use CalDAV calendar-query REPORT with a prop-filter on `X-OPENREGISTER-OBJECT`
- AND the text-match MUST use the object UUID

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
- THEN the API MUST return a JSON array of task objects
- AND each task object MUST include: `id`, `uid`, `summary`, `description`, `status`, `priority`, `due`, `completed`, `created`, `assignee`
- AND the response MUST include `total` count

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

The system MUST provide a `NoteService` that wraps Nextcloud's ICommentsManager for creating, reading, and deleting notes (comments) on OpenRegister objects.

#### Scenario: Register OpenRegister as a comments entity type

- GIVEN the OpenRegister app is loaded
- THEN it MUST register a `CommentsEntityEvent` listener
- AND the listener MUST register objectType `"openregister"` with a validation closure that checks the object UUID exists

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
- WHEN the service queries notes for "abc-123"
- THEN it MUST return all 5 notes in reverse chronological order
- AND each note MUST include: `id`, `message`, `actorId`, `actorDisplayName`, `createdAt`

#### Scenario: Delete a note

- GIVEN a comment on an OpenRegister object
- WHEN the service deletes the note
- THEN the comment MUST be removed via ICommentsManager
- AND only the note author or an admin MUST be able to delete

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
- THEN the API MUST return a JSON array of note objects
- AND each note MUST include: `id`, `message`, `actorId`, `actorDisplayName`, `createdAt`
- AND notes MUST be ordered newest-first

### REQ-OI-005: Calendar Selection [MVP]

The system MUST determine which CalDAV calendar to use for task storage.

#### Scenario: Use user's default calendar

- GIVEN the user has a default calendar (personal)
- WHEN creating a task
- THEN the VTODO MUST be created in the user's default/personal calendar

#### Scenario: User has no calendars

- GIVEN the user has no CalDAV calendars
- WHEN creating a task
- THEN the API MUST return HTTP 400 with message "No calendar available"

### REQ-OI-006: Object Deletion Cleanup [MVP]

The system MUST clean up tasks and notes when an OpenRegister object is deleted.

#### Scenario: Object deleted — remove linked notes

- GIVEN an OpenRegister object with UUID "abc-123" that has 3 notes
- WHEN the object is deleted
- THEN all comments with objectType "openregister" and objectId "abc-123" MUST be deleted via `ICommentsManager::deleteCommentsAtObject()`

#### Scenario: Object deleted — optionally remove linked tasks

- GIVEN an OpenRegister object with UUID "abc-123" that has 2 linked VTODOs
- WHEN the object is deleted
- THEN the linked VTODOs SHOULD be deleted (or marked CANCELLED)
- AND the deletion SHOULD be logged

---

## Non-Functional Requirements

- **Performance**: Task listing MUST complete within 2 seconds for objects with up to 50 tasks. CalDAV REPORT queries are post-filtered (not SQL-indexed), so the service SHOULD limit queries to the relevant user's calendars.
- **Security**: Task/note operations MUST respect RBAC — only users with access to the OpenRegister object can create/view/delete tasks and notes on it.
- **Compatibility**: The X-OPENREGISTER-* properties MUST NOT break standard CalDAV clients (they ignore unknown X- properties). Tasks created through OpenRegister MUST be visible in Nextcloud's Tasks app.
