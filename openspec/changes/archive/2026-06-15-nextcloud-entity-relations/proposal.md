# Nextcloud Entity Relations

## Problem

OpenRegister objects currently support linking to Nextcloud files (IRootFolder), notes (ICommentsManager), and tasks (CalDAV VTODO). However, other core Nextcloud entities — emails, calendar events, contacts, and Deck cards — cannot be related to objects. This limits the ability of consuming apps (Procest, Pipelinq, ZaakAfhandelApp) to present a complete picture of all activities and stakeholders associated with a case/object.

The existing object-interactions spec established the pattern: wrap a Nextcloud subsystem, expose sub-resource endpoints under `/api/objects/{register}/{schema}/{id}/`, and handle cleanup on deletion. This change extends that pattern to four new entity types.

## Context

- **Existing integrations**: Files (IRootFolder), Notes (ICommentsManager), Tasks (CalDAV VTODO)
- **Established pattern**: Service wraps NC API, Controller exposes REST endpoints, ObjectCleanupListener cascades on delete
- **Consuming apps**: Procest (case management workflows), Pipelinq (pipeline/kanban workflows), ZaakAfhandelApp (ZGW case handling)
- **Key principle**: We do NOT sync/import NC entities into OpenRegister objects. We CREATE RELATIONS between OR objects and existing NC entities. The NC entity remains the source of truth; OR stores only the reference.

## Proposed Solution

Add four new integration services following the existing pattern:

1. **EmailService** — Link Nextcloud Mail messages to objects. Read-only references (emails are immutable). Uses the Nextcloud Mail app's internal API or database to resolve message metadata.
2. **CalendarEventService** — Link CalDAV VEVENT entries to objects, similar to how TaskService links VTODO. Uses X-OPENREGISTER-* custom properties and RFC 9253 LINK property.
3. **ContactService** — Link CardDAV vCard contacts to objects. Uses X-OPENREGISTER-* custom properties to tag contacts with object references.
4. **DeckCardService** — Link Nextcloud Deck cards to objects. Uses Deck's OCS API to create/manage board cards and store object references.

Each integration follows the same sub-resource endpoint pattern:
```
GET    /api/objects/{register}/{schema}/{id}/{entity}
POST   /api/objects/{register}/{schema}/{id}/{entity}
DELETE /api/objects/{register}/{schema}/{id}/{entity}/{entityId}
```

## Scope

### In scope
- Email relation service and API (link existing emails to objects)
- Calendar event relation service and API (link/create VEVENT on objects)
- Contact relation service and API (link/create vCard contacts on objects)
- Deck card relation service and API (link/create Deck cards on objects)
- Cleanup on object deletion for all four entity types
- Audit trail entries for relation mutations
- Event dispatching for relation changes
- Frontend components for viewing/managing relations on object detail pages

### Out of scope
- Sending emails from OpenRegister (that's n8n's job)
- Syncing/importing entities as OR objects (we only store references)
- Full CRUD on the NC entity itself (managed via native NC apps)
- Nextcloud Talk/Spreed integration (separate future change)
