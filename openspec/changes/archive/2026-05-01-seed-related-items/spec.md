# Spec: seed-related-items

## Overview

Extend OpenRegister's seed data import to support creating related Nextcloud items (notes, tasks, files) alongside seed objects. This enables apps to ship fully realistic seed data where objects come pre-loaded with attached documents, task checklists, and notes -- making the app immediately testable and demo-ready after install.

## Requirements

### REQ-01: _relatedItems JSON Schema

The `seedData.objects[schemaSlug][n]` object MAY contain a `_relatedItems` key. This key is an object with optional sub-keys:

- `notes` -- array of note definitions
- `tasks` -- array of task definitions
- `files` -- array of file definitions

Each sub-key is optional. If `_relatedItems` is absent or empty, the object is imported as before (no behavior change).

### REQ-02: Strip _relatedItems Before Persisting

The `_relatedItems` key MUST be removed from the object data before it is stored in the database. It is metadata for the import process only; it MUST NOT appear in the persisted object JSON.

### REQ-03: Process Related Items After Object Creation

Related items MUST be created after the seed object is successfully inserted. The created object's UUID, register ID, and schema ID are required to link the related items. If object creation fails, related items MUST be skipped for that object.

### REQ-04: Note Seeding

Each note definition MUST contain:
- `message` (string, required) -- The note text content.

The system MUST call `NoteService::createNote(objectUuid, message)` for each note. Notes are created as the system user (the user context active during import, typically `admin`).

**Scenario:**
```
GIVEN a seed object with _relatedItems.notes containing 2 entries
WHEN the seed object is created successfully
THEN 2 notes are created via NoteService linked to the object's UUID
AND each note has the specified message content
```

### REQ-05: Task Seeding

Each task definition MUST contain:
- `summary` (string, required) -- The task title.

Each task definition MAY contain:
- `description` (string) -- Task description text.
- `status` (string) -- One of: `needs-action`, `in-process`, `completed`, `cancelled`. Defaults to `needs-action`.
- `priority` (integer, 1-9) -- CalDAV priority. Defaults to 0 (undefined).
- `due` (string, ISO 8601 date) -- Due date.

The system MUST call `TaskService::createTask(registerId, schemaId, objectUuid, objectTitle, data)` for each task.

**Scenario:**
```
GIVEN a seed object with _relatedItems.tasks containing 1 entry with summary "Review document"
WHEN the seed object is created successfully
THEN 1 VTODO is created in the user's calendar
AND the VTODO has X-OPENREGISTER-OBJECT set to the object's UUID
AND the VTODO SUMMARY is "Review document"
```

### REQ-06: File Seeding

Each file definition MUST contain:
- `name` (string, required) -- The file name including extension.
- `content` (string, required) -- The file content. If prefixed with `base64:`, the remainder is base64-decoded. Otherwise treated as plain text.

Each file definition MAY contain:
- `tags` (array of strings) -- Tags to attach to the file.
- `share` (boolean) -- Whether to create a public share link. Defaults to `false`.

The system MUST call `FileService::addFile(objectEntity, fileName, content, share, tags)` for each file.

**Scenario:**
```
GIVEN a seed object with _relatedItems.files containing 1 entry named "rapport.pdf"
WHEN the seed object is created successfully
THEN a file named "rapport.pdf" is created in the object's folder
AND the file is linked to the object via OpenRegister's file system
```

### REQ-07: Idempotency for Related Items

If a seed object already exists (detected by the existing idempotency check on slug/uuid), related items MUST be skipped. The system MUST NOT create duplicate notes, tasks, or files on re-import.

**Scenario:**
```
GIVEN a seed object with slug "case-001" already exists in the register
AND the seed data defines _relatedItems with 2 notes
WHEN importSeedData runs again
THEN the object is skipped (existing behavior)
AND no new notes are created
```

### REQ-08: Error Isolation

If creating a related item fails (e.g., CalDAV unavailable, file quota exceeded), the error MUST be logged at WARNING level and the import MUST continue with the next related item. A failed related item MUST NOT prevent other related items or other seed objects from being imported.

**Scenario:**
```
GIVEN a seed object with _relatedItems containing 1 note and 1 task
AND TaskService throws an exception (no calendar available)
WHEN the seed object is imported
THEN the note is created successfully
AND a warning is logged for the failed task
AND import continues with the next seed object
```

### REQ-09: User Context for Related Items

Related items are created under the user context active during the import process. For app install, this is typically the admin user. The import MUST verify that a user session exists before attempting to create tasks (which require a user calendar). If no user session is available, task and note creation MUST be skipped with a warning log.

**Scenario:**
```
GIVEN importSeedData runs without an active user session
AND a seed object has _relatedItems with tasks
WHEN the object is created
THEN task creation is skipped
AND a warning is logged: "Skipping related items - no user session"
```

### REQ-10: Logging

The system MUST log:
- INFO: Start of related items processing for an object (count per type).
- DEBUG: Each successfully created related item (type + identifier).
- WARNING: Each failed related item (type + error message).
- INFO: Summary at end of seed data import (total related items created per type).
