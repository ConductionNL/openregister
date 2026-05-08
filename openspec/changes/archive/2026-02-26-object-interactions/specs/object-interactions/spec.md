# Delta Spec: object-interactions

This delta spec covers the full MVP implementation — all requirements are new.

## Scope

**In scope (MVP)**: REQ-OI-001, REQ-OI-002, REQ-OI-003, REQ-OI-004, REQ-OI-005, REQ-OI-006

---

## NEW Requirements

All 6 requirements from the main spec are new — no existing code addresses any of them. OpenRegister currently has no CalDAV integration and no Comments entity registration.

### Key Implementation Notes

**CalDAV access in PHP**:
- Nextcloud's CalDAV backend is accessible via `OCA\DAV\CalDAV\CalDavBackend` (inject from container)
- Calendar objects are stored as raw iCalendar text in `calendarobjects.calendardata`
- To query by X-property, use `CalDavBackend::calendarQuery()` with prop-filter, or load all VTODOs from the user's calendar and filter in PHP
- To create a VTODO, build iCalendar string and call `CalDavBackend::createCalendarObject()`

**Comments access in PHP**:
- Inject `OCP\Comments\ICommentsManager`
- Register entity type via `OCP\Comments\CommentsEntityEvent` listener
- `create('users', $userId, 'openregister', $objectUuid)` → returns IComment
- `getForObject('openregister', $objectUuid, $limit, $offset)` → returns IComment[]

**Route pattern follows existing files sub-resource**:
- Files: `/api/objects/{register}/{schema}/{id}/files`
- Tasks: `/api/objects/{register}/{schema}/{id}/tasks`
- Notes: `/api/objects/{register}/{schema}/{id}/notes`
