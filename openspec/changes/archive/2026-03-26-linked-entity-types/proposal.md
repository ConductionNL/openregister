## Why

OpenRegister objects and entities need to link to Nextcloud entities (mail, contacts, calendar events, notes, todos, Talk conversations, Deck cards) in a uniform way. Currently, each integration builds its own link table and controller (e.g., `EmailLinks` table, `EmailsController`), leading to table proliferation, redundant code, and expensive joins. The existing `_files` and `_relations` metadata columns on magic tables already prove that storing references directly on the object row is fast and simple. We should extend this pattern to all Nextcloud entity types, provide a generic API, and let `_extend` hydrate linked entities at read time — just like it does for relations today.

## What Changes

- **Add `configuration.linkedTypes`** to schema config — a string array (e.g., `["mail", "contacts", "files"]`) declaring which Nextcloud entity types objects of this schema can link to. OpenRegister uses this to decide which sidebars to inject into other apps.
- **Add Nc\* property types** — `NcFile`, `NcMail`, `NcContact`, `NcNote`, `NcTodo`, `NcCalendarEvent`, `NcTalk`, `NcDeck` as valid JSON Schema types for schema properties. Values use a standardized reference envelope: `{ "type": "NcMail", "id": "1/6", "label": "RE: Aanvraag" }`.
- **Add `_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck` metadata columns** to both magic tables (objects) and fixed entity tables (registers, schemas, organisations, etc.). Columns store lean string arrays of IDs only (e.g., `["1/6", "1/12"]`). Indexed for fast reverse lookups.
- **SaveObject pipeline handler** extracts Nc\* property values and populates the corresponding `_` metadata columns (same pattern as files).
- **Read-time enrichment** via `_extend[_mail]`, `_extend[_contacts]`, etc. — hydrates IDs into full objects from the source app (Mail subject/sender, CardDAV contact name, CalDAV event details).
- **Generic metadata API endpoints** — `POST/DELETE /api/objects/{uuid}/_mail/{id}` for ad-hoc linking (sidebar), `GET /api/linked/_mail/{id}` for reverse lookups across all tables.
- **Generalize sidebar injection** — `MailAppScriptListener` and similar listeners check `linkedTypes` on schemas instead of hardcoded logic.
- **Remove entity-specific link tables** — `oc_openregister_email_links` and related `EmailsController`, `EmailService`, `EmailLinkMapper` code replaced by the generic system.
- **Merge and refactor existing sidebar branches** — existing PR branches for mail/contact/calendar sidebars get merged into the new branch; entity-specific migrations and controllers removed in favor of the generic approach.

## Capabilities

### New Capabilities
- `linked-entity-types`: Schema-level `configuration.linkedTypes` declaration, Nc\* property types, `_` metadata columns on magic and entity tables, SaveObject extraction pipeline, read-time enrichment via `_extend`, generic metadata API, reverse lookup service, and sidebar injection based on linkedTypes.

### Modified Capabilities
- `object-interactions`: The existing notes, tasks, and file interaction sub-resource endpoints now coexist with the `_` metadata columns. Interactions created via the object-interactions API should also populate the corresponding `_` column for reverse lookup consistency.
- `schema-hooks`: No requirement changes, but naming clarification — `schema-hooks` covers workflow lifecycle callbacks, `linked-entity-types` covers entity associations. The `hooks` field on schemas remains for workflow hooks; `linkedTypes` is a separate configuration key.

## Impact

- **Database**: New nullable JSON columns on all magic tables and fixed entity tables (`_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck`). Migration to add columns. Migration to drop `oc_openregister_email_links` table.
- **PHP Backend**: New `LinkedEntityHandler` in SaveObject pipeline, new `LinkedEntityEnricher` in RenderObject, new `LinkedEntityController` for generic API, changes to `MagicMapper` column definitions, changes to `PropertyValidatorHandler` for Nc\* types, changes to `Schema::validateConfigurationArray()` for `linkedTypes`.
- **Frontend**: New Nc\* type renderers for schema property editor and object detail view. Sidebar components generalized to use the generic API.
- **API**: New generic endpoints. Existing `/api/emails/*` endpoints deprecated and removed.
- **Dependent apps**: opencatalogi, softwarecatalog, and other apps using OpenRegister can declare `linkedTypes` on their schemas to enable entity linking without any code changes.
