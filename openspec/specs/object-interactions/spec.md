---
status: implemented
---

# Object Interactions

## Purpose

@e2e exclude REST API convenience layer — covered by Newman

OpenRegister objects require rich interaction capabilities — notes, tasks, file attachments, tags, and audit trails — that allow users to collaborate on and track the lifecycle of register data. Rather than building custom interaction systems, this spec defines a convenience API layer that wraps Nextcloud's native subsystems (CalDAV for tasks, ICommentsManager for notes, IRootFolder for files, Nextcloud tags) and links them to OpenRegister objects via standardized properties. Any consuming app (Procest, Pipelinq, OpenCatalogi, ZaakAfhandelApp) can use these unified sub-resource endpoints without knowledge of the underlying Nextcloud internals.

**Standards**: RFC 5545 (iCalendar/VTODO), RFC 9253 (iCalendar LINK property), Nextcloud Comments API, Nextcloud Activity API, CloudEvents v1.0
**Cross-references**: [audit-trail-immutable](../audit-trail-immutable/spec.md), [event-driven-architecture](../event-driven-architecture/spec.md), [notificatie-engine](../notificatie-engine/spec.md)

**OpenSpec changes**
- `fix-object-files-listing-lock-and-limit` (active) — makes the object files listing endpoint resilient to Nextcloud file locks, raises the `_limit` ceiling from 100 to 1000, replaces the `getContent()` ownership probe with `isReadable()`, and adds `locked`/`lock` metadata to each file entry.
## Requirements
### Requirement: Notes on Objects via ICommentsManager

The system SHALL provide a `NoteService` that wraps Nextcloud's `OCP\Comments\ICommentsManager` for creating, listing, and deleting notes (comments) on OpenRegister objects. Notes MUST be stored using `objectType: "openregister"` and `objectId: {uuid}`. The service MUST resolve actor display names via `OCP\IUserManager` and indicate whether the current user authored each note.

#### Scenario: Create a note on an object
- **GIVEN** an authenticated user `behandelaar-1` and an OpenRegister object with UUID `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/notes` with body `{"message": "Applicant called, will send documents tomorrow"}`
- **THEN** a comment MUST be created via `ICommentsManager::create()` with `actorType: "users"`, `actorId: "behandelaar-1"`, `objectType: "openregister"`, `objectId: "abc-123"`
- **AND** the response MUST return HTTP 201 with the note as JSON including `id`, `message`, `actorId`, `actorDisplayName`, `createdAt`, and `isCurrentUser: true`

#### Scenario: List notes with pagination
- **GIVEN** 15 notes exist on object `abc-123`
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/notes?limit=10&offset=0`
- **THEN** the response MUST return a JSON object with `results` (array of 10 note objects) and `total` (10, the count of returned results)
- **AND** each note MUST include: `id`, `message`, `actorType`, `actorId`, `actorDisplayName`, `createdAt`, `isCurrentUser`
- **AND** notes MUST be ordered newest-first (as returned by `ICommentsManager::getForObject()`)

#### Scenario: Delete a note
- **GIVEN** a note with ID 42 exists on object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/abc-123/notes/42`
- **THEN** the note MUST be removed via `ICommentsManager::delete()`
- **AND** the response MUST return HTTP 200 with `{"success": true}`

#### Scenario: Create note on non-existent object
- **GIVEN** no object exists with the specified register/schema/id
- **WHEN** a POST request is sent to create a note
- **THEN** the API MUST return HTTP 404 with `{"error": "Object not found"}`

#### Scenario: Create note with empty message
- **GIVEN** an authenticated user and a valid object
- **WHEN** a POST request is sent with `{"message": ""}`
- **THEN** the API MUST return HTTP 400 with `{"error": "Note message is required"}`

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

### Requirement: Tasks on Objects via CalDAV VTODO

The system SHALL provide a `TaskService` that creates, reads, updates, and deletes CalDAV VTODO items linked to OpenRegister objects. Each VTODO MUST include `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, and `X-OPENREGISTER-OBJECT` custom properties, plus an RFC 9253 LINK property pointing back to the object API endpoint. Tasks MUST be stored in the user's first VTODO-supporting calendar via `OCA\DAV\CalDAV\CalDavBackend`.

#### Scenario: Create a task linked to an object
- **GIVEN** an OpenRegister object with UUID `abc-123` in register 5, schema 12
- **WHEN** a POST request is sent to `/api/objects/5/12/abc-123/tasks` with body `{"summary": "Review documents", "due": "2026-03-01T17:00:00Z", "priority": 1}`
- **THEN** a VTODO MUST be created in the user's default VTODO-supporting calendar with:
  - `X-OPENREGISTER-REGISTER:5`
  - `X-OPENREGISTER-SCHEMA:12`
  - `X-OPENREGISTER-OBJECT:abc-123`
  - `LINK;LINKREL="related";VALUE=URI:/apps/openregister/api/objects/5/12/abc-123`
  - `STATUS:NEEDS-ACTION`, `PRIORITY:1`, `SUMMARY:Review documents`, `DUE:20260301T170000Z`
- **AND** the response MUST return HTTP 201 with the task as JSON including `id`, `uid`, `calendarId`, `summary`, `description`, `status`, `priority`, `due`, `completed`, `created`, `objectUuid`, `registerId`, `schemaId`

#### Scenario: List tasks for an object
- **GIVEN** 3 VTODOs exist with `X-OPENREGISTER-OBJECT:abc-123`
- **WHEN** a GET request is sent to `/api/objects/5/12/abc-123/tasks`
- **THEN** the response MUST return `{"results": [...], "total": 3}` with all 3 tasks
- **AND** each task MUST include: `id` (URI), `uid`, `calendarId`, `summary`, `description`, `status`, `priority`, `due`, `completed`, `created`, `objectUuid`, `registerId`, `schemaId`

#### Scenario: Update task status to completed
- **GIVEN** a VTODO linked to object `abc-123` with status `NEEDS-ACTION`
- **WHEN** a PUT request is sent with `{"status": "completed"}`
- **THEN** the VTODO STATUS MUST be set to `COMPLETED`
- **AND** the `COMPLETED` timestamp MUST be set to the current UTC time
- **AND** the `X-OPENREGISTER-*` properties MUST remain unchanged
- **AND** the response MUST return the updated task as JSON

#### Scenario: Delete a task
- **GIVEN** a VTODO linked to object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/5/12/abc-123/tasks/{taskId}`
- **THEN** the VTODO MUST be removed from the calendar via `CalDavBackend::deleteCalendarObject()`
- **AND** the response MUST return `{"success": true}`

#### Scenario: Task summary is required
- **GIVEN** a POST request to create a task with empty summary
- **WHEN** the controller validates the request
- **THEN** the API MUST return HTTP 400 with `{"error": "Task summary is required"}`

### Requirement: Task Status Mapping

The system SHALL map CalDAV VTODO STATUS values to lowercase JSON strings for consistent API responses. The mapping MUST be bidirectional: incoming status values from the API MUST be converted to uppercase for CalDAV storage.

#### Scenario: Status normalization on read
- **GIVEN** a VTODO with `STATUS:NEEDS-ACTION`
- **WHEN** the task is returned via the API
- **THEN** the `status` field MUST be `"needs-action"`

#### Scenario: Status normalization on write
- **GIVEN** an API request with `{"status": "in-process"}`
- **WHEN** the task is updated
- **THEN** the VTODO STATUS MUST be set to `IN-PROCESS`

#### Scenario: Complete status mapping table
- **GIVEN** the following CalDAV STATUS values
- **THEN** the mapping MUST be:
  - `NEEDS-ACTION` to/from `"needs-action"`
  - `IN-PROCESS` to/from `"in-process"`
  - `COMPLETED` to/from `"completed"`
  - `CANCELLED` to/from `"cancelled"`

### Requirement: Calendar Selection for Tasks

The system SHALL determine which CalDAV calendar to use by finding the user's first calendar that supports VTODO components. The `TaskService::findUserCalendar()` method MUST check the `supported-calendar-component-set` property on each calendar and handle object, string, and iterable component sets.

#### Scenario: Use first VTODO-supporting calendar
- **GIVEN** the user has calendars `personal` (VEVENT+VTODO) and `birthdays` (VEVENT only)
- **WHEN** tasks are created or listed
- **THEN** the service MUST use the `personal` calendar

#### Scenario: No VTODO-supporting calendar available
- **GIVEN** the user has no calendars that support VTODO
- **WHEN** a task operation is attempted
- **THEN** the service MUST throw an Exception with message `"No VTODO-supporting calendar found for user {uid}"`
- **AND** the controller MUST return HTTP 500

#### Scenario: No user logged in
- **GIVEN** no user session is active
- **WHEN** a task operation is attempted
- **THEN** the service MUST throw an Exception with message `"No user logged in"`

### Requirement: File Attachments on Objects

The system SHALL provide file attachment operations as sub-resource endpoints under objects. Files MUST be stored in Nextcloud's filesystem via `OCP\Files\IRootFolder` and linked to OpenRegister objects. The system MUST support upload, download, listing, deletion, and publish/depublish operations. The listing operation MUST honour any positive user-supplied `_limit` parameter without imposing an upper cap (default 30, floor 1), MUST NOT fail when individual files are held under a Nextcloud file lock, and MUST include `locked` and optional `lock` metadata on every entry when the caller is authenticated. For unauthenticated callers the listing MUST NOT include `locked` or `lock` in any file entry.

#### Scenario: Upload a file to an object
- **GIVEN** an OpenRegister object with UUID `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/files` with a file payload
- **THEN** the file MUST be stored in the Nextcloud filesystem
- **AND** the file MUST be linked to the object
- **AND** the response MUST return HTTP 201 with the file metadata

#### Scenario: List files for an object (authenticated caller)
- **GIVEN** an authenticated user and object `abc-123` with 3 attached files
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the response MUST return all 3 files with metadata including `fileId`, `name`, `mimeType`, `size`, and `locked`

#### Scenario: List files for an object (anonymous caller)
- **GIVEN** no authenticated session (`IUserSession::getUser()` returns `null`) and a publicly accessible object `abc-123` with 3 attached files
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the response MUST return all 3 files with metadata including `fileId`, `name`, `mimeType`, `size`
- **AND** NO file entry MUST include a `locked` field
- **AND** NO file entry MUST include a `lock` sub-object
- **AND** no call to `ILockManager::getLocks()` MUST be attempted for this request

#### Scenario: List files honours user-supplied `_limit` without a ceiling
- **GIVEN** object `abc-123` has 500 attached files
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files?_limit=500&_page=1`
- **THEN** the response MUST return 500 file entries (not silently clamped to 100)
- **AND** the pagination envelope MUST report `limit: 500`

#### Scenario: Large `_limit` values are honoured as-is
- **GIVEN** object `abc-123` has 2500 attached files
- **WHEN** a GET request is sent with `?_limit=5000`
- **THEN** the response MUST return all 2500 entries
- **AND** the pagination envelope MUST report `limit: 5000`
- **AND** the system MUST NOT apply any upper ceiling to the `_limit` value

#### Scenario: `_limit=0` or negative is clamped to 1
- **WHEN** a GET request is sent with `?_limit=0` or `?_limit=-10`
- **THEN** the response MUST return at least 1 entry (or 0 if no files exist) and the pagination envelope MUST report `limit: 1`

#### Scenario: Missing `_limit` uses the default
- **WHEN** a GET request is sent with no `_limit` query parameter
- **THEN** the pagination envelope MUST report `limit: 30`

#### Scenario: Listing is resilient to Nextcloud-locked files (authenticated caller)
- **GIVEN** an authenticated user and object `abc-123` with 5 attached files, one of which is held under a Nextcloud file lock by another user
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the response MUST return HTTP 200 with all 5 file entries
- **AND** the locked file's entry MUST have `locked: true` and a `lock` sub-object with `type`, `scope`, `owner`, `createdAt`, and optional `expiresAt`
- **AND** each unlocked file entry MUST have `locked: false` and no `lock` sub-object
- **AND** one structured log line at `info` level MUST be emitted per locked file, including `fileId`, `name`, and lock owner

#### Scenario: Listing is resilient to Nextcloud-locked files (anonymous caller)
- **GIVEN** no authenticated session and a publicly accessible object `abc-123` with 5 attached files, one of which is held under a Nextcloud file lock
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the response MUST return HTTP 200 with all 5 file entries
- **AND** NO file entry MUST include `locked` or `lock`
- **AND** the locked file's entry MUST still be present in the result set (not silently elided)
- **AND** one structured log line at `info` level MUST still be emitted server-side per locked file, so operators retain traceability even when the field is hidden from the caller

#### Scenario: `lock.type` maps Nextcloud lock constants to string aliases
- **GIVEN** a file is locked with `ILock::TYPE_USER`
- **WHEN** the file is formatted in a listing response
- **THEN** `lock.type` MUST equal `"user"`
- **AND** `TYPE_APP` maps to `"app"`, `TYPE_TOKEN` maps to `"token"`
- **AND** `lock.scope` MUST be `"exclusive"` for `ILock::LOCK_EXCLUSIVE` and `"shared"` for `ILock::LOCK_SHARED`

#### Scenario: `lock.expiresAt` is null when the lock has no timeout
- **GIVEN** a lock with `getTimeout()` returning `0` (no timeout)
- **WHEN** the file is formatted in a listing response
- **THEN** `lock.expiresAt` MUST be `null`
- **AND** `lock.createdAt` MUST still be an ISO 8601 string

#### Scenario: Lock provider is not available
- **GIVEN** the `files_lock` app is disabled, `ILockManager::isLockProviderAvailable()` returns `false`, and the caller is authenticated
- **WHEN** any file is formatted in a listing response
- **THEN** each file entry MUST have `locked: false`
- **AND** no file entry MUST include a `lock` sub-object
- **AND** no call to `ILockManager::getLocks()` MUST be attempted

#### Scenario: Listing does not read file contents to probe ownership
- **GIVEN** object `abc-123` has attached files of varying sizes (including a 2 GB file)
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the ownership probe MUST use `$file->isReadable()` (a pure permission-bitmask check)
- **AND** the listing MUST NOT call `$file->getContent()` or otherwise read file bytes during formatting
- **AND** the listing MUST NOT acquire a shared lock on any file during formatting

#### Scenario: Ownership repair still runs when the current user can read the file
- **GIVEN** the current user can read file 42 (bitmask allows `PERMISSION_READ`) but is not recorded as its OpenRegister owner
- **WHEN** the file is encountered during listing
- **THEN** the ownership record MUST be repaired via `FileMapper::setFileOwnership($fileId, $userId)` (a direct DB write)
- **AND** no file bytes MUST be read as part of the repair

#### Scenario: Download all files as archive
- **GIVEN** object `abc-123` has multiple attached files
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files/download`
- **THEN** all files MUST be returned as a downloadable archive

#### Scenario: Publish a file for public access
- **GIVEN** a file with ID 42 is attached to object `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/publish`
- **THEN** the file MUST be made publicly accessible via a share link

#### Scenario: Delete a file from an object
- **GIVEN** a file with ID 42 is attached to object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/abc-123/files/42`
- **THEN** the file MUST be removed from the object and the filesystem

### Requirement: Tags for Object Categorization

The system SHALL provide tag management for categorizing objects and files. Tags MUST be retrievable via a dedicated API endpoint and usable for filtering objects across registers and schemas.

#### Scenario: List all tags
- **GIVEN** objects across multiple schemas use tags `urgent`, `pending`, `approved`
- **WHEN** a GET request is sent to `/api/tags`
- **THEN** the response MUST return all distinct tags used in the system

#### Scenario: Tags used for object filtering
- **GIVEN** 5 objects are tagged with `urgent`
- **WHEN** objects are queried with a tag filter
- **THEN** only objects matching the specified tag MUST be returned

#### Scenario: Tags on files
- **GIVEN** a file attached to an object has tag `contract`
- **WHEN** files are queried with a tag filter
- **THEN** only files matching the specified tag MUST be returned

### Requirement: Audit Trail Integration for Interactions

All interaction mutations (note created, note deleted, task created, task completed, task deleted, file uploaded, file deleted) SHALL be reflected in the object's audit trail as defined by the [audit-trail-immutable](../audit-trail-immutable/spec.md) spec. The audit trail entries for interactions MUST be distinguishable from data mutation entries.

#### Scenario: Note creation generates audit entry
- **GIVEN** user `behandelaar-1` creates a note on object `abc-123`
- **WHEN** the note is persisted
- **THEN** an audit trail entry SHOULD be created with `action: "note.created"` and the note content in `data`

#### Scenario: Task completion generates audit entry
- **GIVEN** user `coordinator-1` completes task `Review documents` on object `abc-123`
- **WHEN** the task status is updated to `completed`
- **THEN** an audit trail entry SHOULD be created with `action: "task.completed"` and the task summary in `data`

#### Scenario: File upload generates audit entry
- **GIVEN** user `behandelaar-1` uploads file `contract.pdf` to object `abc-123`
- **WHEN** the file is persisted
- **THEN** an audit trail entry SHOULD be created with `action: "file.uploaded"` and the file metadata in `data`

#### Scenario: Audit entries are hash-chained
- **GIVEN** interaction audit entries exist for object `abc-123`
- **WHEN** an auditor verifies the hash chain
- **THEN** interaction entries MUST participate in the same hash chain as data mutation entries per [audit-trail-immutable](../audit-trail-immutable/spec.md)

### Requirement: Event-Driven Interaction Notifications

The system SHALL fire typed events via `OCP\EventDispatcher\IEventDispatcher` when interactions occur on objects. These events MUST follow the CloudEvents format defined in [event-driven-architecture](../event-driven-architecture/spec.md) and be consumable by the [notificatie-engine](../notificatie-engine/spec.md) for notification delivery.

#### Scenario: Note creation fires event
- **GIVEN** a note is created on object `abc-123`
- **WHEN** `NoteService::createNote()` succeeds
- **THEN** an event of type `nl.openregister.object.note.created` SHOULD be dispatched via `IEventDispatcher`
- **AND** the event payload MUST include the object UUID, note ID, actor ID, and message preview

#### Scenario: Task completion fires event
- **GIVEN** a task on object `abc-123` is marked as completed
- **WHEN** `TaskService::updateTask()` detects a status change to `COMPLETED`
- **THEN** an event of type `nl.openregister.object.task.completed` SHOULD be dispatched
- **AND** consuming apps (Procest, Pipelinq) MAY react to update case status or trigger workflows

#### Scenario: File upload fires event
- **GIVEN** a file is uploaded to object `abc-123`
- **WHEN** the file is persisted via `FileService`
- **THEN** an event of type `nl.openregister.object.file.uploaded` SHOULD be dispatched
- **AND** the event payload MUST include the object UUID, file ID, filename, and MIME type

#### Scenario: Webhook delivery for interaction events
- **GIVEN** an external system has subscribed to `nl.openregister.object.note.created` via webhook
- **WHEN** a note is created
- **THEN** the event MUST be delivered to the webhook URL as a CloudEvent per [event-driven-architecture](../event-driven-architecture/spec.md)

### Requirement: Object Deletion Cleanup

The system SHALL cascade-delete all linked interactions when an OpenRegister object is deleted. The `ObjectCleanupListener` MUST listen for `ObjectDeletedEvent` and clean up notes via `ICommentsManager::deleteCommentsAtObject()` and tasks via `TaskService::getTasksForObject()` followed by `TaskService::deleteTask()` for each task. Failures on individual cleanup operations MUST be logged as warnings but MUST NOT block the object deletion.

#### Scenario: Delete object with notes
- **GIVEN** object `abc-123` has 5 notes
- **WHEN** the object is deleted (triggering `ObjectDeletedEvent`)
- **THEN** all 5 comments with `objectType: "openregister"` and `objectId: "abc-123"` MUST be deleted via `ICommentsManager::deleteCommentsAtObject()`

#### Scenario: Delete object with tasks
- **GIVEN** object `abc-123` has 2 linked VTODOs
- **WHEN** the object is deleted
- **THEN** the `ObjectCleanupListener` MUST query tasks via `TaskService::getTasksForObject()`
- **AND** delete each task via `TaskService::deleteTask(calendarId, taskUri)`
- **AND** log the number of deleted tasks

#### Scenario: Partial cleanup failure does not block deletion
- **GIVEN** object `abc-123` has 3 tasks and the second task deletion fails
- **WHEN** the object is deleted
- **THEN** the first and third tasks MUST still be deleted
- **AND** the failure MUST be logged as a warning
- **AND** the object deletion MUST proceed

#### Scenario: Delete object with files
- **GIVEN** object `abc-123` has 2 attached files
- **WHEN** the object is deleted
- **THEN** the linked files SHOULD be cleaned up from the Nextcloud filesystem

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

### Requirement: Unified Interaction Timeline API

The system SHALL provide an endpoint that returns a combined, chronologically ordered timeline of all interactions (notes, tasks, files, audit trail entries) for a given object. This enables consuming apps to render a single activity feed per object.

#### Scenario: Retrieve combined timeline
- **GIVEN** object `abc-123` has 3 notes, 2 tasks, and 1 file attachment created at different times
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/timeline`
- **THEN** the response SHOULD return all 6 interactions merged in reverse chronological order
- **AND** each entry MUST include a `type` field (`note`, `task`, `file`, `audit`) and a `createdAt` timestamp

#### Scenario: Timeline pagination
- **GIVEN** object `abc-123` has 50 interactions
- **WHEN** a GET request is sent with `?limit=20&offset=0`
- **THEN** only the 20 most recent interactions SHOULD be returned

#### Scenario: Timeline filtered by type
- **GIVEN** object `abc-123` has interactions of mixed types
- **WHEN** a GET request is sent with `?type=note`
- **THEN** only note interactions SHOULD be returned

### Requirement: Task Compatibility with Nextcloud Tasks App

Tasks created through OpenRegister MUST be fully compatible with Nextcloud's Tasks app. The `X-OPENREGISTER-*` custom properties MUST NOT break standard CalDAV clients, which ignore unknown X- properties per RFC 5545. Users MUST be able to view and edit OpenRegister-linked tasks in the Nextcloud Tasks app.

#### Scenario: Task visible in Nextcloud Tasks app
- **GIVEN** a task created via OpenRegister's API on object `abc-123`
- **WHEN** the user opens the Nextcloud Tasks app
- **THEN** the task MUST appear in the user's calendar with its summary, due date, priority, and status

#### Scenario: Task edited in Nextcloud Tasks app
- **GIVEN** a task linked to object `abc-123` is edited in the Nextcloud Tasks app (e.g., status changed to completed)
- **WHEN** the task is queried via OpenRegister's API
- **THEN** the updated status MUST be reflected in the API response
- **AND** the `X-OPENREGISTER-*` linking properties MUST remain intact

#### Scenario: X-properties ignored by third-party CalDAV clients
- **GIVEN** a third-party CalDAV client syncs the user's calendar
- **WHEN** it encounters `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, `X-OPENREGISTER-OBJECT`
- **THEN** the client MUST ignore these properties per RFC 5545 section 3.8.8.2 (non-standard properties)

### Requirement: Task Query Performance

The system SHALL use in-memory filtering for task queries. `TaskService::getTasksForObject()` MUST load calendar objects via `CalDavBackend::getCalendarObjects()`, perform a fast `strpos()` pre-filter for the object UUID, and only parse matching objects with `Sabre\VObject\Reader`. This approach MUST complete within 2 seconds for objects with up to 50 tasks.

#### Scenario: Pre-filter reduces parsing overhead
- **GIVEN** a user's calendar has 500 VTODOs but only 3 are linked to object `abc-123`
- **WHEN** tasks are queried for `abc-123`
- **THEN** only calendar objects containing the string `abc-123` MUST be parsed with `Sabre\VObject\Reader`
- **AND** the remaining ~497 objects MUST be skipped via `strpos()` check

#### Scenario: Non-VTODO objects are skipped
- **GIVEN** the calendar contains VEVENT objects alongside VTODOs
- **WHEN** tasks are queried
- **THEN** objects not containing `VTODO` in their data MUST be skipped before parsing

#### Scenario: Performance degradation warning
- **GIVEN** a user with a very large calendar (10,000+ objects)
- **WHEN** tasks are queried
- **THEN** the query MAY take longer than 2 seconds
- **AND** this is a known limitation of the PHP-based post-filter approach (not a CalDAV REPORT query)

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

### Requirement: User-Wide Task Aggregate Endpoint

The system MUST expose a user-wide task aggregate endpoint that returns every
CalDAV VTODO belonging to the current session user across all of their
VTODO-supporting calendars, independent of any single object. `TasksController::allUserTasks()`
MUST resolve the calendar set from the session user (`IUserSession`), never from
a request parameter, so the endpoint cannot be used to read another user's tasks.
It MUST accept optional `status`, `assignee`, and pagination (`_limit`/`limit`
capped at 200, `_offset`/`offset`) filters, where `assignee` is a free-text
filter applied within the caller's own task list and is NOT an identity claim.
On error it MUST return HTTP 500 with an `error` message.

#### Scenario: List all of the current user's tasks
- **GIVEN** an authenticated user with VTODOs spread across multiple calendars
- **WHEN** a GET request is sent to `/api/tasks`
- **THEN** `TasksController::allUserTasks()` MUST return the aggregate via `TaskService::getAllUserTasks()` resolved from `IUserSession::getUser()->getUID()`
- **AND** the response MUST NOT depend on any object register/schema/id

#### Scenario: Filter the aggregate by status and assignee
- **GIVEN** a GET request to `/api/tasks?status=needs-action&assignee=jan`
- **WHEN** the controller reads the parameters
- **THEN** `status` and `assignee` MUST be forwarded to `TaskService::getAllUserTasks()`
- **AND** `assignee` MUST be applied as a free-text filter within the caller's own task list, not as an identity claim

#### Scenario: Aggregate pagination caps the limit
- **GIVEN** a GET request to `/api/tasks?_limit=500`
- **WHEN** the controller computes the limit
- **THEN** the effective limit MUST be capped at 200

### Requirement: Deck Card Linkage on Objects

The system MUST provide object-scoped Nextcloud Deck card linkage as a
sub-resource of objects. `DeckController` MUST support listing the Deck cards
linked to an object, creating or linking a card to an object, and a reverse
lookup of every object linked to cards on a given board. When the Nextcloud Deck
app is not installed, every endpoint MUST return HTTP 501 with
`{"error": "Nextcloud Deck app is not installed", "code": "APP_NOT_AVAILABLE"}`.
Object-scoped operations MUST validate the object exists (HTTP 404 otherwise) via
`ObjectService` before delegating to `DeckCardService`.

#### Scenario: List Deck cards for an object
- **GIVEN** the Deck app is installed and object `abc-123` exists
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/deck`
- **THEN** the controller MUST validate the object and return `DeckCardService::getCardsForObject()` results

#### Scenario: Create or link a Deck card to an object
- **GIVEN** the Deck app is installed and object `abc-123` exists
- **WHEN** a POST request supplies card link/create data
- **THEN** `DeckCardService::linkOrCreateCard()` MUST be invoked and the link returned with HTTP 201
- **AND** a duplicate link MUST return HTTP 409, a missing target MUST return HTTP 404

#### Scenario: Reverse lookup objects on a board
- **GIVEN** the Deck app is installed
- **WHEN** a GET request is sent for objects linked to a board id
- **THEN** the response MUST return `{"results": [...], "total": N}` from `DeckCardService::getObjectsForBoard()`

#### Scenario: Deck app not installed
- **GIVEN** the Nextcloud Deck app is not installed
- **WHEN** any `DeckController` endpoint is called
- **THEN** the response MUST be HTTP 501 with `code: "APP_NOT_AVAILABLE"`

### Requirement: The endpoint dispatcher MUST route execution by target type

`EndpointService` MUST act as the runtime dispatcher behind the dynamic endpoint surface, routing an endpoint to a target-type-specific handler (`view`, `agent`, `webhook`, `register`, `schema`) and returning a uniform result shape `{success, statusCode, response, error?}`. A test entry point MUST allow executing an endpoint with supplied test data without a real inbound request.

#### Scenario: Route by target type

- **GIVEN** an `Endpoint` with a `targetType`
- **WHEN** `executeEndpoint()` runs
- **THEN** it MUST dispatch to the matching handler for `view`, `agent`, `webhook`, `register`, or `schema`
- **AND** an unknown target type MUST return `{success: false, statusCode: 400, error: "Unknown target type: ..."}`

#### Scenario: Test an endpoint with supplied data

- **GIVEN** an endpoint and an optional `testData` array
- **WHEN** `testEndpoint()` runs
- **THEN** it MUST first check access via `canExecuteEndpoint()` and return `statusCode: 403` when denied
- **AND** on success it MUST build a request from the endpoint method/path plus test data, dispatch via `executeEndpoint()`, log the call, and return the handler result
- **AND** any thrown exception MUST be caught, logged, and surfaced as `{success: false, statusCode: 500, error: <message>}`

#### Scenario: Agent endpoint executes an AI agent

- **GIVEN** an endpoint with `targetType: agent` and a `targetId` resolving to an agent
- **WHEN** `executeAgentEndpoint()` runs
- **THEN** a missing agent MUST return `statusCode: 404` and an empty message MUST return `statusCode: 400`
- **AND** on success it MUST resolve the agent's configured tools through `ToolRegistry` and execute the agent with the request message

### Requirement: Endpoint execution MUST enforce group access and log every call

Endpoint execution MUST be gated by group-based access control and MUST persist an execution log entry for audit and debugging.

#### Scenario: Group-based access control

- **GIVEN** an endpoint with a configured `groups` list
- **WHEN** `canExecuteEndpoint()` evaluates the current user
- **THEN** an unauthenticated request MUST be allowed only when the endpoint declares no groups (public)
- **AND** a member of the `admin` group MUST always be allowed
- **AND** an authenticated user MUST be allowed when the endpoint declares no groups, or when the user belongs to at least one of the endpoint's groups; otherwise access MUST be denied

#### Scenario: Execution call is logged with a TTL

- **GIVEN** any endpoint execution
- **WHEN** `logEndpointCall()` runs
- **THEN** it MUST persist an `EndpointLog` with a generated uuid, the endpoint id, the acting user (when present), the request and response payloads, the status code and message, a creation timestamp, and an expiry one week out
- **AND** the entry size MUST be computed before insert

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
