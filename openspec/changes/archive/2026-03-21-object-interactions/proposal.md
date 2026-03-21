# Object Interactions

## Problem
OpenRegister objects require rich interaction capabilities — notes, tasks, file attachments, tags, and audit trails — that allow users to collaborate on and track the lifecycle of register data. Rather than building custom interaction systems, this spec defines a convenience API layer that wraps Nextcloud's native subsystems (CalDAV for tasks, ICommentsManager for notes, IRootFolder for files, Nextcloud tags) and links them to OpenRegister objects via standardized properties. Any consuming app (Procest, Pipelinq, OpenCatalogi, ZaakAfhandelApp) can use these unified sub-resource endpoints without knowledge of the underlying Nextcloud internals.
**Standards**: RFC 5545 (iCalendar/VTODO), RFC 9253 (iCalendar LINK property), Nextcloud Comments API, Nextcloud Activity API, CloudEvents v1.0
**Cross-references**: [audit-trail-immutable](../audit-trail-immutable/spec.md), [event-driven-architecture](../event-driven-architecture/spec.md), [notificatie-engine](../notificatie-engine/spec.md)

## Proposed Solution
Implement Object Interactions following the detailed specification. Key requirements include:
- Requirement: Notes on Objects via ICommentsManager
- Requirement: Register OpenRegister as Comments Entity Type
- Requirement: Tasks on Objects via CalDAV VTODO
- Requirement: Task Status Mapping
- Requirement: Calendar Selection for Tasks

## Scope
This change covers all requirements defined in the object-interactions specification.

## Success Criteria
- Create a note on an object
- List notes with pagination
- Delete a note
- Create note on non-existent object
- Create note with empty message
