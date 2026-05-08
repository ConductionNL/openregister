# Design: object-interactions

## Context

OpenRegister provides generic object storage with CRUD, files, RBAC, and webhooks. It lacks task and note capabilities. Nextcloud has mature native systems for both: CalDAV VTODO for tasks and ICommentsManager for notes. Rather than building custom task/note storage, OpenRegister wraps these Nextcloud systems with a convenience API, linking interactions to objects via standardized properties.

## Goals / Non-Goals

**Goals:**
- Task CRUD via CalDAV VTODO with X-OPENREGISTER-* linking properties
- Note CRUD via Nextcloud Comments with objectType "openregister"
- REST API following the existing sub-resource pattern (like files)
- Cleanup on object deletion

**Non-Goals:**
- Task assignment workflows (apps handle this in their UI)
- Task-to-task dependencies (complex CalDAV features, V2)
- Threaded comment replies (Comments supports it, but API returns flat list for MVP)
- Real-time updates / WebSocket push (use polling or webhooks)

## Decisions

### DD-01: X-OPENREGISTER-* Properties on VTODO

**Decision**: Use three X-properties to link a VTODO to an OpenRegister object:
- `X-OPENREGISTER-REGISTER:{registerId}` (integer)
- `X-OPENREGISTER-SCHEMA:{schemaId}` (integer)
- `X-OPENREGISTER-OBJECT:{objectUuid}` (string)

Plus an RFC 9253 LINK property for standards compliance.

**Rationale**: Using IDs (not slugs) ensures stability — slugs can change, IDs don't. Three separate properties allow querying by object, by schema (all tasks on any case), or by register (all tasks in Procest). The LINK provides a clickable URI for CalDAV clients that support RFC 9253.

**VTODO template**:
```ics
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//OpenRegister//Tasks//EN
BEGIN:VTODO
UID:{generated-uuid}
DTSTAMP:{now}
SUMMARY:{summary}
DESCRIPTION:{description}
STATUS:NEEDS-ACTION
PRIORITY:{priority}
DUE:{dueDate}
X-OPENREGISTER-REGISTER:{registerId}
X-OPENREGISTER-SCHEMA:{schemaId}
X-OPENREGISTER-OBJECT:{objectUuid}
LINK;LINKREL="related";LABEL="{objectTitle}";VALUE=URI:/apps/openregister/api/objects/{registerId}/{schemaId}/{objectUuid}
END:VTODO
END:VCALENDAR
```

### DD-02: CalDAV Access via CalDavBackend

**Decision**: Access CalDAV directly via `OCA\DAV\CalDAV\CalDavBackend` rather than making HTTP requests to the DAV endpoint.

**Rationale**: Internal PHP access avoids HTTP overhead, authentication complexity, and request limits. CalDavBackend provides `createCalendarObject()`, `updateCalendarObject()`, `deleteCalendarObject()`, and `calendarQuery()` — everything needed. The backend stores raw iCalendar text, so X-properties are preserved transparently.

**Calendar selection**: Use the first calendar that supports VTODO. Nextcloud users always have a "personal" calendar by default.

### DD-03: Comments via ICommentsManager

**Decision**: Use `OCP\Comments\ICommentsManager` directly for notes, with objectType `"openregister"`.

**Rationale**: The Comments system is core Nextcloud — no app dependency. It provides CRUD, threading, mentions, reactions, and read markers out of the box. By registering "openregister" as an entity type via `CommentsEntityEvent`, notes become accessible through both our REST API and Nextcloud's native DAV comments endpoint.

### DD-04: Task Query Strategy

**Decision**: For listing tasks by object, load all VTODOs from the user's calendars and filter in PHP by `X-OPENREGISTER-OBJECT` value.

**Rationale**: CalDAV `calendar-query` REPORT supports prop-filter on X-properties, but Nextcloud's CalDavBackend doesn't expose this directly — it requires building XML queries. For MVP, loading all VTODOs from a calendar and filtering by X-property in PHP is simpler and fast enough (most users have <100 tasks). The CalDavBackend `getCalendarObjects()` returns metadata; we load full data only for matching objects.

**Performance bound**: A user with 200 tasks in their calendar → ~200 iCalendar parses per query. At ~1ms per parse, this is ~200ms. Acceptable for MVP.

**V2 optimization**: Add a mapping table `openregister_object_tasks` that caches object UUID → CalDAV resource path for O(1) lookup.

### DD-05: Cleanup on Object Deletion

**Decision**: Listen for `ObjectDeletedEvent` and clean up both notes and tasks.

**Rationale**: Notes are easy — `ICommentsManager::deleteCommentsAtObject('openregister', $uuid)` handles it in one call. Tasks require querying all user calendars for matching X-OPENREGISTER-OBJECT values, which is more complex. For MVP, notes cleanup is mandatory; task cleanup is best-effort (log a warning if some tasks couldn't be cleaned up because the deleting user doesn't own all linked calendars).

### DD-06: Controller Pattern — Follows FilesController

**Decision**: Create `TasksController` and `NotesController` following the same pattern as `FilesController` — injecting ObjectService to verify object existence, then delegating to TaskService/NoteService.

**Route registration**: Add to routes.php after the existing files routes.

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/TaskService.php` | CalDAV VTODO wrapper — create, list, update, delete tasks |
| `lib/Service/NoteService.php` | ICommentsManager wrapper — create, list, delete notes |
| `lib/Controller/TasksController.php` | REST API for tasks on objects |
| `lib/Controller/NotesController.php` | REST API for notes on objects |
| `lib/Listener/CommentsEntityListener.php` | Registers "openregister" objectType for Comments |

### Modified Files

| File | Changes |
|------|---------|
| `appinfo/routes.php` | Add 7 task/note routes |
| `lib/AppInfo/Application.php` | Register CommentsEntityListener, TaskService, NoteService |
| `lib/Listener/WebhookEventListener.php` | Add ObjectDeletedEvent → cleanup tasks/notes |

## Risks / Trade-offs

- **[Risk] CalDAV query performance** — Filtering by X-property is a post-filter scan. Mitigation: Bounded by calendar size (typically <200 items). V2 can add a mapping table.
- **[Risk] Multi-user task ownership** — Tasks are in individual user calendars. If user A creates a task on an object and user B views the object, user B won't see user A's task. Mitigation: For MVP, tasks are per-user (like Nextcloud's Tasks app). V2 could use a shared calendar per register.
- **[Trade-off] No real-time updates** — Notes/tasks won't auto-refresh. Mitigation: UI can poll or the existing webhook system can notify.
- **[Trade-off] Flat notes list** — Comments supports threading, but MVP returns flat list. Mitigation: Can add threading in V2 without API changes (just add `parentId` to responses).
