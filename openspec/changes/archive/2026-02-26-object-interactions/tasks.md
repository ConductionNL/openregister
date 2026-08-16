# Tasks: object-interactions

## 1. Service — TaskService

- [x] 1.1 Create `lib/Service/TaskService.php` — Service that wraps CalDAV VTODO operations. Constructor injects `CalDavBackend` (from container: `OCA\DAV\CalDAV\CalDavBackend`), `IUserSession`, and `LoggerInterface`. Provide methods: `getTasksForObject(string $objectUuid): array`, `createTask(int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data): array`, `updateTask(string $calendarId, string $taskUri, array $data): array`, `deleteTask(string $calendarId, string $taskUri): void`. The `createTask` method MUST build a VCALENDAR/VTODO string with: `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, `X-OPENREGISTER-OBJECT`, RFC 9253 `LINK` property, and standard VTODO fields (UID, SUMMARY, DESCRIPTION, STATUS, PRIORITY, DUE, DTSTAMP). The `getTasksForObject` method MUST: get user's calendars via CalDavBackend, load calendar objects, parse each with sabre/vobject, filter by X-OPENREGISTER-OBJECT matching objectUuid, return JSON-friendly array.

- [x] 1.2 Add helper method `_findUserCalendar(): array` to TaskService — Returns the user's first VTODO-supporting calendar (calendarId + calendarUri). Checks `CalDavBackend::getCalendarsForUser('principals/users/{userId}')`, filters for calendars that support VTODO component, returns first match. Throws exception if no calendar found.

- [x] 1.3 Add helper method `_vtodoToArray(string $calendarData, string $calendarId, string $uri): array` to TaskService — Parses raw iCalendar string via `Sabre\VObject\Reader::read()`, extracts VTODO component, returns array with: `id` (uri), `uid`, `calendarId`, `summary`, `description`, `status` (lowercase: needs-action, in-process, completed, cancelled), `priority`, `due` (ISO 8601), `completed` (ISO 8601 or null), `created` (ISO 8601), `objectUuid` (from X-OPENREGISTER-OBJECT), `registerId` (from X-OPENREGISTER-REGISTER), `schemaId` (from X-OPENREGISTER-SCHEMA).

## 2. Service — NoteService

- [x] 2.1 Create `lib/Service/NoteService.php` — Service that wraps ICommentsManager. Constructor injects `ICommentsManager`, `IUserSession`, `IUserManager`, `LoggerInterface`. Provide methods: `getNotesForObject(string $objectUuid, int $limit = 50, int $offset = 0): array`, `createNote(string $objectUuid, string $message): array`, `deleteNote(int $noteId): void`. The `createNote` method MUST use `ICommentsManager::create('users', $userId, 'openregister', $objectUuid)`, set message, and save. The `getNotesForObject` method MUST use `ICommentsManager::getForObject('openregister', $objectUuid, $limit, $offset)` and map each IComment to a JSON-friendly array.

- [x] 2.2 Add helper method `_commentToArray(IComment $comment): array` to NoteService — Maps an IComment to: `id`, `message`, `actorType`, `actorId`, `actorDisplayName` (resolve via IUserManager), `createdAt` (ISO 8601), `isCurrentUser` (bool).

## 3. Controller — TasksController

- [x] 3.1 Create `lib/Controller/TasksController.php` — REST controller following FilesController pattern. Constructor injects `IRequest`, `TaskService`, `ObjectService`. Methods: `index($register, $schema, $id)` → GET list tasks, `create($register, $schema, $id)` → POST create task, `update($register, $schema, $id, $taskId)` → PUT update task, `destroy($register, $schema, $id, $taskId)` → DELETE. Each method MUST first verify the object exists via ObjectService (return 404 if not). Return JSONResponse with appropriate HTTP status codes (200, 201, 404, 400, 500).

## 4. Controller — NotesController

- [x] 4.1 Create `lib/Controller/NotesController.php` — REST controller following FilesController pattern. Constructor injects `IRequest`, `NoteService`, `ObjectService`. Methods: `index($register, $schema, $id)` → GET list notes, `create($register, $schema, $id)` → POST create note, `destroy($register, $schema, $id, $noteId)` → DELETE. Each method MUST verify object exists first. Return JSONResponse.

## 5. Registration — Routes and Application

- [x] 5.1 Add routes to `appinfo/routes.php` — Add 7 routes after existing files routes:
  ```
  tasks#index    GET    /api/objects/{register}/{schema}/{id}/tasks
  tasks#create   POST   /api/objects/{register}/{schema}/{id}/tasks
  tasks#update   PUT    /api/objects/{register}/{schema}/{id}/tasks/{taskId}
  tasks#destroy  DELETE /api/objects/{register}/{schema}/{id}/tasks/{taskId}
  notes#index    GET    /api/objects/{register}/{schema}/{id}/notes
  notes#create   POST   /api/objects/{register}/{schema}/{id}/notes
  notes#destroy  DELETE /api/objects/{register}/{schema}/{id}/notes/{noteId}
  ```
  Use `[^/]+` requirements for `{id}` and `{taskId}`.

- [x] 5.2 Create `lib/Listener/CommentsEntityListener.php` — Implements IEventListener. Handles `CommentsEntityEvent`. Registers objectType `"openregister"` with a closure that validates the object UUID exists (via ObjectEntityMapper or ObjectService).

- [x] 5.3 Update `lib/AppInfo/Application.php` — Register `CommentsEntityListener` for `CommentsEntityEvent`. Register `TaskService` and `NoteService` in the service container.

## 6. Cleanup — Object Deletion

- [x] 6.1 Add cleanup logic to `lib/Listener/WebhookEventListener.php` (or create a new `ObjectCleanupListener.php`) — Listen for `ObjectDeletedEvent`. On delete: (a) call `ICommentsManager::deleteCommentsAtObject('openregister', $objectUuid)` to remove all notes, (b) call `TaskService::getTasksForObject($objectUuid)` and delete each found task. Wrap in try/catch — log warnings on failure but don't block the deletion.

## 7. Verification

- [ ] 7.1 Verify task creation: POST a task on an object, confirm VTODO exists in user's calendar with correct X-OPENREGISTER-* properties
- [ ] 7.2 Verify task listing: Create 3 tasks on one object and 2 on another, GET tasks for first object returns exactly 3
- [ ] 7.3 Verify task update: Update status to completed, confirm VTODO STATUS=COMPLETED
- [ ] 7.4 Verify note creation: POST a note on an object, confirm comment exists via ICommentsManager
- [ ] 7.5 Verify note listing: Create notes, GET returns them in reverse chronological order with display names
- [ ] 7.6 Verify object deletion cleanup: Delete an object, confirm notes are removed
- [ ] 7.7 Verify tasks visible in Nextcloud Tasks app with X-properties intact
