# Design: seed-related-items

## Context

OpenRegister's `ImportHandler::importSeedData()` (lines 2676-3085 of `lib/Service/Configuration/ImportHandler.php`) creates register objects from the `x-openregister.seedData.objects` section of app configuration JSON files. It supports schema resolution, external configuration references, idempotency checks, and magic mapper table pre-creation. However, after creating an object, the method does nothing with related Nextcloud items.

Three services already exist for managing related items on objects:
- **TaskService** -- Creates CalDAV VTODOs linked via X-OPENREGISTER-* properties.
- **NoteService** -- Creates Nextcloud Comments linked via objectType "openregister".
- **FileService** -- Creates files in the object's folder via IRootFolder.

This change extends the seed data import to call these services after object creation.

## Goals / Non-Goals

**Goals:**
- Support `_relatedItems` in seed data JSON for notes, tasks, and files
- Strip `_relatedItems` before persisting objects (import-only metadata)
- Graceful degradation: related item failures don't block import
- Idempotency: skip related items for objects that already exist

**Non-Goals:**
- Contact seeding (ContactService not yet implemented)
- Calendar event or Deck card seeding (services not yet implemented)
- Validating related item content against external schemas
- Providing a UI for editing seed data

## Decisions

### DD-01: _relatedItems Key Placement

**Decision**: Place `_relatedItems` at the top level of each seed object (sibling to `title`, `slug`, etc.), not in a separate section of the seed data config.

**Rationale**: Co-locating related items with the object they belong to keeps the JSON readable and makes it clear which items belong to which object. A separate top-level section would require cross-referencing by slug/uuid, adding complexity.

**Format**:
```json
{
  "title": "Vergunningsaanvraag Hoofdstraat 42",
  "slug": "vergunning-hoofdstraat-42",
  "status": "in_behandeling",
  "aanvrager": "Bouwbedrijf De Vries B.V.",
  "_relatedItems": {
    "notes": [
      { "message": "Aanvraag ontvangen en geregistreerd. Wachten op aanvullende documenten." },
      { "message": "Bouwtekening ontvangen, doorgestuurd naar afdeling Ruimtelijke Ordening." }
    ],
    "tasks": [
      {
        "summary": "Beoordeel constructietekening",
        "description": "Controleer de constructietekening op conformiteit met Bouwbesluit 2012.",
        "status": "needs-action",
        "priority": 3,
        "due": "2025-02-15"
      },
      {
        "summary": "Welstandsadvies opvragen",
        "status": "in-process",
        "priority": 5
      }
    ],
    "files": [
      {
        "name": "aanvraagformulier.txt",
        "content": "Aanvraag omgevingsvergunning\nLocatie: Hoofdstraat 42\nAanvrager: Bouwbedrijf De Vries B.V.\nDatum: 2025-01-10",
        "tags": ["aanvraag"]
      },
      {
        "name": "situatieschets.txt",
        "content": "base64:U2l0dWF0aWVzY2hldHMgdm9vciBIb29mZHN0cmFhdCA0Mg==",
        "share": true,
        "tags": ["tekening", "situatie"]
      }
    ]
  }
}
```

### DD-02: Service Injection into ImportHandler

**Decision**: Inject `TaskService`, `NoteService`, and `FileService` into ImportHandler via constructor. Use nullable types so ImportHandler remains functional even if a service cannot be resolved (e.g., CalDAV backend missing).

**Rationale**: ImportHandler already has ~15 constructor dependencies. Adding three more follows the existing pattern. Nullable injection ensures backward compatibility -- if any service is unavailable, its related items are simply skipped.

**Constructor additions**:
```php
private ?TaskService $taskService = null,
private ?NoteService $noteService = null,
private ?FileService $fileService = null,
```

### DD-03: Processing Order

**Decision**: Process related items in the order: files, notes, tasks. Process all items for one object before moving to the next.

**Rationale**: Files are the most commonly expected related item and the least likely to fail (no external service dependency beyond filesystem). Notes depend on ICommentsManager (always available). Tasks depend on CalDAV (may fail if user has no calendar). Processing in reliability order means the most items get created even if a later type fails.

### DD-04: Stripping _relatedItems

**Decision**: Extract and unset `_relatedItems` from `$objectData` before passing to `ObjectEntity::setObject()`, in the existing foreach loop at line 2820.

**Rationale**: The stripping must happen before `setObject()` to prevent `_relatedItems` from being stored in the database. Extracting it into a local variable preserves the data for processing after the object is saved.

**Code location**: Between line 2820 (`foreach ($objects as $objectData)`) and line 2949 (`$objectSlug = ...`).

### DD-05: ObjectEntity Retrieval for FileService

**Decision**: After creating the seed object, use the returned `$createdObject` (ObjectEntity) directly when calling `FileService::addFile()`. The first parameter of `addFile()` accepts `ObjectEntity | string`.

**Rationale**: The object is already in memory after insert. No additional database lookup needed.

### DD-06: User Context Check

**Decision**: Check `IUserSession::getUser()` once at the start of `importSeedData()`. If null, set a flag that disables task and note creation (both require a user) but allows file creation (FileService falls back to system user for public uploads).

**Rationale**: During `occ` CLI import or app install without web context, there may be no user session. Tasks require a user calendar; notes require a user actor. Files can use a system fallback. A single check avoids repeated null-user exceptions.

### DD-07: Idempotency Scope

**Decision**: Related items are NOT individually checked for idempotency. If the parent object already exists (existing idempotency check), ALL its related items are skipped. If the parent object is new, ALL its related items are created.

**Rationale**: Checking individual notes/tasks/files for duplicates would require querying each service (CalDAV search, comment listing, folder scan), adding significant complexity for a marginal benefit. Seed data import is idempotent at the object level -- if you want to re-seed related items, delete the parent object first.

## Seed Data

This change does not introduce new schemas. It extends the seed data JSON format itself. Example seed data that consuming apps would use:

### Notes Format
```json
{
  "notes": [
    { "message": "Aanvraag ingediend door aanvrager via het online portaal." },
    { "message": "Dossier compleet verklaard na ontvangst van alle bijlagen." }
  ]
}
```

### Tasks Format
```json
{
  "tasks": [
    {
      "summary": "Inhoudelijke beoordeling aanvraag",
      "description": "Toets de aanvraag aan het bestemmingsplan en bouwvoorschriften.",
      "status": "needs-action",
      "priority": 3,
      "due": "2025-03-01"
    },
    {
      "summary": "Besluit voorbereiden",
      "status": "needs-action",
      "priority": 5,
      "due": "2025-04-01"
    }
  ]
}
```

### Files Format
```json
{
  "files": [
    {
      "name": "aanvraagformulier.txt",
      "content": "Voorbeeld aanvraagformulier\nNaam: Test Organisatie\nDatum: 2025-01-15",
      "tags": ["aanvraag", "formulier"]
    },
    {
      "name": "bijlage-situatieschets.txt",
      "content": "Situatieschets met toelichting op de voorgenomen werkzaamheden.",
      "share": true,
      "tags": ["bijlage"]
    }
  ]
}
```

## Component Diagram

```
_register.json
  x-openregister.seedData.objects[schema][n]
    -> object data (persisted via ObjectEntityMapper / RoutingMapper)
    -> _relatedItems.files[]  -> FileService::addFile()
    -> _relatedItems.notes[]  -> NoteService::createNote()
    -> _relatedItems.tasks[]  -> TaskService::createTask()
```

## Affected Files

| File | Change |
|------|--------|
| `lib/Service/Configuration/ImportHandler.php` | Add service injection, extract `_relatedItems`, call services after object insert |
| `lib/AppInfo/Application.php` | Ensure TaskService, NoteService, FileService are registered (may already be) |
| No new files | This change modifies existing code only |
