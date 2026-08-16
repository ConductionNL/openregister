# Seed Related Items

## Problem

OpenRegister's `ImportHandler::importSeedData()` currently creates flat register objects only. It does not create related Nextcloud items (notes, tasks, files, contacts) that would be linked to those objects via OpenRegister's relation system. This means that after app install, seed objects exist but lack the realistic context (attached documents, task lists, notes, contact links) that users and testers expect to see.

ADR-016 mandates that every app ships realistic seed data, and explicitly calls out related items as a gap: "OpenRegister's ImportHandler currently loads seed objects as standalone records. It does not support seeding related items (files, notes, tasks, contacts) that are linked to objects through OpenRegister's relation system."

## Context

- **Existing services**: TaskService (CalDAV VTODO), NoteService (ICommentsManager), FileService (IRootFolder). ContactService is planned in the `nextcloud-entity-relations` change but not yet implemented.
- **Current seed format**: `x-openregister.seedData.objects` in `_register.json` contains arrays of object data per schema slug.
- **Consuming apps**: Pipelinq, Procest, Docudesk, ZaakAfhandelApp all need realistic seed data for testing and demos.
- **ImportHandler location**: `lib/Service/Configuration/ImportHandler.php`, method `importSeedData()` (lines 2676-3085).

## Proposed Solution

Extend the `seedData` JSON format to support a `_relatedItems` key on each seed object. After creating the object, ImportHandler iterates over `_relatedItems` and calls the appropriate service (TaskService, NoteService, FileService) to create linked items. The `_relatedItems` key is stripped before saving the object data.

Example format:
```json
{
  "title": "Omgevingsvergunning Kerkstraat 12",
  "slug": "omgevingsvergunning-kerkstraat-12",
  "status": "in_behandeling",
  "_relatedItems": {
    "notes": [
      { "message": "Aanvraag ontvangen via e-formulier op 2025-01-15." }
    ],
    "tasks": [
      { "summary": "Beoordeel bouwtekening", "status": "needs-action", "priority": 5 }
    ],
    "files": [
      { "name": "bouwtekening-v1.pdf", "content": "base64:...", "tags": ["bouwtekening"] }
    ]
  }
}
```

## Scope

### In scope
- Extend seedData JSON schema with `_relatedItems` support for notes, tasks, and files
- Modify `ImportHandler::importSeedData()` to process `_relatedItems` after object creation
- Strip `_relatedItems` from object data before persisting
- Idempotency: skip related items if the seed object already existed
- Error handling: log and continue if a related item fails (non-fatal)

### Out of scope
- ContactService (not yet implemented; blocked on `nextcloud-entity-relations` change)
- CalendarEventService and DeckCardService (same blocker)
- Actual seed data content for consuming apps (each app adds its own seed data separately)
- Setup wizards or UI for managing seed data
