# Object Interactions

## Overview

OpenRegister objects support rich human collaboration through a unified interaction API that wraps Nextcloud's native subsystems: ICommentsManager for notes, CalDAV for tasks, IRootFolder for file attachments, and Nextcloud Tags. Any consuming app (Procest, Pipelinq, OpenCatalogi, ZaakAfhandelApp) can use these unified sub-resource endpoints without knowledge of the underlying Nextcloud internals.

## Notes

Notes are persistent comments attached to an object, stored via Nextcloud's `ICommentsManager`:

```
GET    /api/objects/{register}/{schema}/{id}/notes         List notes on an object
POST   /api/objects/{register}/{schema}/{id}/notes         Add a note
GET    /api/objects/{register}/{schema}/{id}/notes/{nid}   Get a specific note
PUT    /api/objects/{register}/{schema}/{id}/notes/{nid}   Edit a note (author only)
DELETE /api/objects/{register}/{schema}/{id}/notes/{nid}   Delete a note (author or admin)
```

Notes are stored with `objectType: "openregister"` and `objectId: {uuid}`. `NoteService` resolves actor display names via `IUserManager` and indicates whether the current user authored each note.

## Tasks

Tasks (TODOs) are linked to objects via Nextcloud's CalDAV infrastructure:

```
GET    /api/objects/{register}/{schema}/{id}/tasks         List tasks
POST   /api/objects/{register}/{schema}/{id}/tasks         Create a task
GET    /api/objects/{register}/{schema}/{id}/tasks/{tid}   Get a task
PUT    /api/objects/{register}/{schema}/{id}/tasks/{tid}   Update a task
DELETE /api/objects/{register}/{schema}/{id}/tasks/{tid}   Delete a task
```

Tasks conform to RFC 5545 (iCalendar VTODO) and RFC 9253 (iCalendar LINK property). The LINK property connects the VTODO to the OpenRegister object URI:

```
LINK;VALUE=URI:openregister://objects/{uuid}
```

Task fields include: `summary`, `description`, `due`, `dtstart`, `status` (NEEDS-ACTION, IN-PROCESS, COMPLETED, CANCELLED), `priority`, `assigned-to`.

## File Attachments

Files are attached to objects via Nextcloud's file system (`IRootFolder`):

```
GET    /api/objects/{register}/{schema}/{id}/files         List attached files
POST   /api/objects/{register}/{schema}/{id}/files         Attach a file (upload)
GET    /api/objects/{register}/{schema}/{id}/files/{fid}   Get file metadata
DELETE /api/objects/{register}/{schema}/{id}/files/{fid}   Remove attachment
GET    /api/objects/{register}/{schema}/{id}/files/{fid}/download  Download file
```

File metadata tracked per attachment:

| Field | Description |
|-------|-------------|
| `name` | Original filename |
| `size` | File size in bytes |
| `mimeType` | MIME type |
| `pronom` | PRONOM identifier (for archival) |
| `checksum` | SHA-256 hash |
| `uploadedBy` | Nextcloud user ID |
| `uploadedAt` | UTC timestamp |

File attachments are included in MDTO SIP packages when objects are transferred to e-Depot.

## Tags

Objects can be tagged using Nextcloud's tag system:

```
GET    /api/objects/{register}/{schema}/{id}/tags         List tags
POST   /api/objects/{register}/{schema}/{id}/tags         Add a tag
DELETE /api/objects/{register}/{schema}/{id}/tags/{tag}   Remove a tag
```

Tags are shared across all OpenRegister objects and are visible in Nextcloud's standard tag browser.

## Object Locking

Object locking prevents concurrent conflicting edits:

```
POST   /api/objects/{register}/{schema}/{id}/lock         Acquire lock
DELETE /api/objects/{register}/{schema}/{id}/lock         Release lock
GET    /api/objects/{register}/{schema}/{id}/lock         Check lock status
```

Lock metadata includes:

| Field | Description |
|-------|-------------|
| `lockedBy` | Nextcloud user ID |
| `lockedAt` | UTC timestamp |
| `expiresAt` | Auto-release timestamp (configurable timeout) |
| `sessionId` | Nextcloud session or browser tab identifier |

If a user tries to save an object that is locked by someone else, a `423 Locked` response is returned with details of who holds the lock.

## Activity Feed

All interactions (notes, tasks, attachments, locks, status changes) appear in the object's activity feed:

```
GET /api/objects/{register}/{schema}/{id}/activity        List all activity
```

The activity feed combines:
- Audit trail entries (create, update, delete)
- Notes (created, edited, deleted)
- Task status changes
- File attachments (added, removed)
- Lock events (acquired, released, expired)

## Standards

| Standard | Role |
|----------|------|
| RFC 5545 (iCalendar) | Task/VTODO format |
| RFC 9253 (iCalendar LINK) | Object-to-task linking |
| Nextcloud ICommentsManager | Notes storage |
| Nextcloud IRootFolder | File attachment storage |

## Related Features

- [Object Storage & Lifecycle](object-storage.md) — base object operations
- [Content Versioning & Audit Trail](versioning-and-audit.md) — audit trail records all interactions
- [Event-Driven Architecture](event-driven-architecture.md) — interaction events can trigger webhooks
- [Webhooks & Notifications](webhooks-and-notifications.md) — notify users of new notes or tasks
