---
status: approved
---

# Archival & Destruction Workflow - Design

## Architecture Overview

### Entities

**SelectionList** (`lib/Db/SelectionList.php`)
- Fields: id, uuid, category (string), retentionYears (int), action (enum: vernietigen/bewaren), description (string), schemaOverrides (json), organisation (string), created (datetime), updated (datetime)
- Mapper: `SelectionListMapper` with findByCategory(), findAll()

**DestructionList** (`lib/Db/DestructionList.php`)
- Fields: id, uuid, name (string), status (enum: pending_review/approved/completed/cancelled), objects (json array of object UUIDs), approvedBy (string), approvedAt (datetime), notes (string), organisation (string), created (datetime), updated (datetime)
- Mapper: `DestructionListMapper` with findByStatus(), findAll()

### Service

**ArchivalService** (`lib/Service/ArchivalService.php`)
- `setRetentionMetadata(ObjectEntity $object, array $retention): ObjectEntity` - validates and sets retention data
- `calculateArchivalDate(ObjectEntity $object, SelectionList $selectionList, DateTime $closeDate): DateTime` - calculates archiefactiedatum
- `generateDestructionList(): DestructionList` - finds eligible objects and creates list
- `approveDestructionList(DestructionList $list, string $userId): array` - destroys objects, creates audit entries
- `rejectFromDestructionList(DestructionList $list, array $objectUuids): DestructionList` - removes objects and extends dates
- `findObjectsDueForDestruction(): array` - queries objects with retention.archiefactiedatum <= now

### Controller

**ArchivalController** (`lib/Controller/ArchivalController.php`)
- `GET /api/archival/selection-lists` - list selection lists
- `POST /api/archival/selection-lists` - create selection list
- `GET /api/archival/selection-lists/{id}` - get selection list
- `PUT /api/archival/selection-lists/{id}` - update selection list
- `DELETE /api/archival/selection-lists/{id}` - delete selection list
- `PUT /api/archival/objects/{id}/retention` - set retention metadata on object
- `GET /api/archival/objects/{id}/retention` - get retention metadata
- `POST /api/archival/destruction-lists/generate` - generate destruction list
- `GET /api/archival/destruction-lists` - list destruction lists
- `GET /api/archival/destruction-lists/{id}` - get destruction list
- `POST /api/archival/destruction-lists/{id}/approve` - approve and execute
- `POST /api/archival/destruction-lists/{id}/reject` - reject items

### Background Job

**DestructionCheckJob** (`lib/BackgroundJob/DestructionCheckJob.php`)
- Extends `TimedJob`, runs daily (86400 seconds)
- Queries objects where retention->archiefactiedatum <= now AND retention->archiefnominatie = 'vernietigen' AND retention->archiefstatus = 'nog_te_archiveren'
- Generates destruction list if eligible objects found
- Logs via LoggerInterface

### Database Migration

**Version1Date20260325120000** - Creates `oc_openregister_selection_lists` and `oc_openregister_destruction_lists` tables

### Retention Field Schema
The existing `ObjectEntity.retention` JSON field will store:
```json
{
  "archiefnominatie": "vernietigen|bewaren|nog_niet_bepaald",
  "archiefactiedatum": "2031-03-01T00:00:00+00:00",
  "archiefstatus": "nog_te_archiveren|gearchiveerd|vernietigd|overgebracht",
  "classificatie": "B1"
}
```

### Integration Points
- **AuditTrailMapper**: Log `archival.destroyed` and `archival.retention_set` actions
- **ObjectEntityMapper**: Query objects by retention JSON fields
- **ObjectService**: Delete objects during destruction approval
