## Context

OpenRegister objects live in per-schema "magic tables" with metadata columns (`_files`, `_relations`, `_locked`, etc.). The `_files` column stores file IDs as a JSON array, and `RenderObject::renderFiles()` enriches them at read time into full file objects with titles, URLs, and sizes. The `_relations` column stores related object UUIDs, with `RelationHandler` providing cross-table reverse lookups.

Currently, a mail sidebar was built as a standalone integration with its own `oc_openregister_email_links` table, `EmailsController`, `EmailService`, and `EmailLinkMapper`. This pattern doesn't scale — each new entity type (contacts, calendar, notes, etc.) would require its own table, controller, service, and mapper.

OpenRegister also has fixed entity tables (`oc_openregister_registers`, `oc_openregister_schemas`, `oc_openregister_organisations`) that should also support linking to Nextcloud entities.

### Existing branches
Several PRs already add sidebar integrations (mail, contacts, calendar) with entity-specific link tables. These haven't reached `dev` yet. We will merge them into the new branch and refactor to use the generic system, removing the per-entity migrations and controllers.

## Goals / Non-Goals

**Goals:**
- Unified metadata columns (`_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck`) on both magic tables and fixed entity tables
- Lean storage: columns hold string arrays of IDs only — no type or label duplication
- Read-time enrichment following the `_files` / `RenderObject::renderFiles()` pattern
- `_extend[_mail]`, `_extend[_contacts]`, etc. for opt-in hydration
- Generic metadata API for ad-hoc linking (sidebar use) and reverse lookups
- Nc\* property types in JSON Schema for structured field-level entity references
- SaveObject pipeline extraction of Nc\* property values into `_` columns
- `configuration.linkedTypes` on schemas to control sidebar injection
- Replace `oc_openregister_email_links` table and all entity-specific link code

**Non-Goals:**
- Building the actual sidebar UI components (already exist on feature branches)
- Real-time sync with external entity changes (enrichment is point-in-time at read)
- Replacing the existing `file` property type — `NcFile` is a lightweight reference; `file` keeps its upload/transform/auto-tag behavior
- Workflow hooks integration (that's the separate `schema-hooks` spec)

## Decisions

### Decision 1: Lean metadata columns over link tables
**Choice**: Add `_mail`, `_contacts`, etc. as JSON columns storing `["id1", "id2"]` on object rows.
**Why not link tables**: A generic `linked_entities` table would require joins on every object read. Per-type tables (like `email_links`) cause table proliferation. Metadata columns keep everything in one row — zero joins for the common case (read an object with its links). The `_relations` column already proves this pattern works at scale with GIN indexing.
**Trade-off**: Adding a new entity type requires a migration. But new types also need enricher code, renderers, and API logic — the migration is the smallest part.

### Decision 2: Entity tables get the same columns
**Choice**: Add `_mail`, `_contacts`, etc. to fixed entity tables (registers, schemas, organisations) via migration.
**Why**: A Register or Schema may need to link to mails or contacts just like objects do. Same API, same enrichment, same reverse lookups.
**Alternative considered**: A single `_linked` JSON column with nested structure `{ "mail": [...], "contacts": [...] }`. Rejected because JSON-path queries for reverse lookups are slower than querying a dedicated indexed column.

### Decision 3: `_extend` for opt-in enrichment
**Choice**: Enrichment only happens when the caller requests it via `_extend[_mail]`, `_extend[_contacts]`, etc.
**Why**: Enriching mail or contact data requires calling into Nextcloud's Mail/CardDAV/CalDAV APIs. This is expensive and shouldn't happen on every object read. The existing `_extend` mechanism already handles this for relations — we extend it to linked entity types.
**Implementation**: New enricher methods in `RenderObject` (e.g., `renderMail()`, `renderContacts()`) following the `renderFiles()` pattern. Each enricher calls the relevant Nextcloud OCP interface to resolve IDs into display data.

### Decision 4: Nc\* property types as valid JSON Schema types
**Choice**: Add `NcMail`, `NcContact`, `NcNote`, `NcTodo`, `NcCalendarEvent`, `NcTalk`, `NcDeck` to `PropertyValidatorHandler::$validTypes`. `NcFile` is added alongside the existing `file` type.
**Why**: The `Nc` prefix avoids conflict with standard JSON Schema types. Each type stores a reference envelope in `_data`: `{ "type": "NcMail", "id": "1/6", "label": "RE: Subject" }`.
**SaveObject extraction**: A new `LinkedEntityPropertyHandler` in the SaveObject pipeline scans all properties for Nc\* types, extracts the `id` values, and appends them to the corresponding `_` column. This ensures the metadata column is always in sync with property data.

### Decision 5: Standardized reference envelope for Nc\* property values
**Choice**: `{ "type": "NcType", "id": "string", "label": "optional cached text" }`
- `type`: The Nc\* type string — drives UI renderer selection
- `id`: Compact string identifier (format varies by type, always a string)
- `label`: Optional cached display text for rendering without fetching the source

**ID formats**:
| Type | Format | Example |
|------|--------|---------|
| NcFile | `{fileId}` | `"42"` |
| NcMail | `{accountId}/{messageId}` | `"1/6"` |
| NcContact | `{uid}` | `"f47ac10b-58cc"` |
| NcNote | `{noteId}` | `"17"` |
| NcTodo | `{calendarId}/{uid}` | `"5/abc-123"` |
| NcCalendarEvent | `{calendarId}/{uid}` | `"3/def-456"` |
| NcTalk | `{token}` | `"abc123xyz"` |
| NcDeck | `{boardId}/{cardId}` | `"1/5"` |

### Decision 6: Generic metadata API replaces per-entity controllers
**Choice**: Single `LinkedEntityController` with routes:
- `POST /api/objects/{uuid}/_mail` — add mail ID to `_mail` column
- `DELETE /api/objects/{uuid}/_mail/{id}` — remove mail ID from `_mail` column
- `GET /api/linked/_mail/{id}` — reverse lookup across all tables
- Same pattern for `/_contacts`, `/_notes`, `/_todos`, `/_calendar`, `/_talk`, `/_deck`

**Why**: One controller, one service, parameterized by entity type. Validates that the entity type is in the schema's `linkedTypes`. The reverse lookup scans all magic tables + entity tables that have the corresponding `_` column, same as `RelationHandler::findByRelationUsingRelationsColumn()`.

### Decision 7: `configuration.linkedTypes` as simple string array
**Choice**: `"linkedTypes": ["mail", "contacts", "files"]` — simple string array on the schema configuration.
**Why**: Purely declarative for now. Future evolution to objects (e.g., `{ "type": "mail", "label": "Gerelateerde e-mails" }`) is a backward-compatible migration (string auto-upgrades to object).
**Validation**: Added to `Schema::validateConfigurationArray()`. Valid values: `"files"`, `"mail"`, `"contacts"`, `"notes"`, `"todos"`, `"calendar"`, `"talk"`, `"deck"`.

### Decision 8: Magic table column creation driven by linkedTypes
**Choice**: When a schema declares `linkedTypes: ["mail", "contacts"]`, `MagicMapper::buildTableColumnsFromSchema()` includes `_mail` and `_contacts` columns. Schemas without those linkedTypes don't get those columns.
**Why**: No wasted columns. Tables only have the `_` columns their schema actually uses.
**Implementation**: `getMetadataColumns()` returns the base set; `buildTableColumnsFromSchema()` adds linked type columns based on `schema.configuration.linkedTypes`.

## Risks / Trade-offs

**[Risk] Cross-table reverse lookup performance** — Scanning all magic tables for `WHERE _mail @> '["1/6"]'` could be slow with many schemas.
→ Mitigation: GIN indexes on JSON columns (PostgreSQL) or generated columns with indexes (MySQL). Same approach already works for `_relations`. Add circuit breaker (max schemas to scan).

**[Risk] Enrichment latency** — Hydrating mail/contact/calendar data from Nextcloud APIs adds latency to object reads.
→ Mitigation: Enrichment is opt-in via `_extend`. Sidebar and list views use lean data (IDs only). Detail views request enrichment. Consider caching enriched data with TTL.

**[Risk] Source app unavailable** — Mail or Contacts app might be disabled or data deleted.
→ Mitigation: Enricher returns graceful fallback (ID with "not found" label). Metadata columns retain IDs regardless of source app state.

**[Risk] Entity table migrations on existing installs** — Adding columns to fixed entity tables that may have data.
→ Mitigation: All new columns are nullable JSON with `DEFAULT NULL`. No data loss, no locking issues on small tables.

## Migration Plan

1. Create new branch from `main`
2. Merge existing sidebar feature branches into new branch
3. Add migration: new `_` columns on magic tables (via `getMetadataColumns()` change) and fixed entity tables
4. Add migration: drop `oc_openregister_email_links` table (after migrating any existing data to `_mail` columns)
5. Implement `LinkedEntityPropertyHandler`, `LinkedEntityEnricher`, `LinkedEntityController`
6. Refactor sidebar components to use generic API
7. Update `PropertyValidatorHandler` and schema config validation
8. Update frontend schema editor for Nc\* types and linkedTypes configuration

## Seed Data

For testing and development, schemas should include `linkedTypes` configuration. Example seed data for a "Customer" schema:

```json
{
  "title": "Customer",
  "configuration": {
    "linkedTypes": ["mail", "contacts", "files"],
    "objectNameField": "name"
  },
  "properties": {
    "name": { "type": "string" },
    "primaryContact": { "type": "NcContact" },
    "relatedEmails": { "type": "array", "items": { "type": "NcMail" } }
  }
}
```

Example object with linked entities:
```json
{
  "name": "Gemeente Utrecht",
  "primaryContact": { "type": "NcContact", "id": "f47ac10b-58cc", "label": "Jan de Vries" },
  "relatedEmails": [
    { "type": "NcMail", "id": "1/6", "label": "RE: Aanvraag vergunning" }
  ],
  "_mail": ["1/6"],
  "_contacts": ["f47ac10b-58cc"],
  "_files": ["42", "87"]
}
```

## Open Questions

1. **Should `_files` on entity tables follow the same lean ID-only pattern?** Currently `_files` on objects stores richer data. For consistency, entity tables could use lean IDs. But this may be a separate migration concern.
2. **Should the reverse lookup API return entity results (registers, schemas) alongside object results?** Or should there be separate endpoints?
3. **Cache strategy for enrichment** — Should enriched data be cached in APCu/Redis with TTL, or always fetched fresh?
