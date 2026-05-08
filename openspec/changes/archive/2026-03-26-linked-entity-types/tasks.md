## 1. Branch Setup and Merge

- [x] 1.1 Create new branch `feature/linked-entity-types` from `development`
- [x] 1.2 Identify and merge existing sidebar feature branches (feat/sidebar-backend-apis) into the new branch
- [x] 1.3 Verify merged code compiles and basic functionality works before refactoring

## 2. Schema Configuration: linkedTypes

- [x] 2.1 Add `linkedTypes` validation to `Schema::validateConfigurationArray()` — accept array of strings from allowed values (`files`, `mail`, `contacts`, `notes`, `todos`, `calendar`, `talk`, `deck`)
- [x] 2.2 Ensure `linkedTypes` defaults to empty array when not present in configuration
- [x] 2.3 Add `linkedTypes` to schema API response serialization (verify in `jsonSerialize()`)

## 3. Nc\* Property Types

- [x] 3.1 Add `NcFile`, `NcMail`, `NcContact`, `NcNote`, `NcTodo`, `NcCalendarEvent`, `NcTalk`, `NcDeck` to `PropertyValidatorHandler::$validTypes`
- [x] 3.2 Add validation for Nc\* reference envelope format — require `type` (string) and `id` (string), optional `label` (string)
- [x] 3.3 Ensure array-of-Nc\* types work (`type: "array"`, `items.type: "NcMail"`) with proper validation of each item

## 4. Metadata Columns on Magic Tables

- [x] 4.1 Update `MagicMapper::getMetadataColumns()` or `buildTableColumnsFromSchema()` to add `_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck` columns based on schema `linkedTypes`
- [x] 4.2 Ensure columns are nullable JSON with appropriate indexes
- [x] 4.3 Handle ALTER TABLE for existing magic tables when `linkedTypes` is updated on an existing schema
- [x] 4.4 Add getters/setters on `ObjectEntity` for new metadata columns (`getMail()`, `setMail()`, `getContacts()`, `setContacts()`, etc.)

## 5. Metadata Columns on Entity Tables

- [x] 5.1 Create database migration to add `_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck`, `_files` columns to `oc_openregister_registers`
- [x] 5.2 Same migration for `oc_openregister_schemas`
- [x] 5.3 Same migration for `oc_openregister_organisations`
- [x] 5.4 Add getters/setters on `Register`, `Schema`, `Organisation` entities for the new columns
- [x] 5.5 Include new columns in entity `jsonSerialize()` responses

## 6. SaveObject Pipeline: LinkedEntityPropertyHandler

- [x] 6.1 Create `LinkedEntityPropertyHandler` in `lib/Service/Object/SaveObject/`
- [x] 6.2 Implement Nc\* property scanning — iterate schema properties, find Nc\* types, extract `id` values
- [x] 6.3 Implement metadata column population — append extracted IDs to corresponding `_` columns, deduplicating
- [x] 6.4 Preserve ad-hoc links — merge property-extracted IDs with existing column values (don't overwrite sidebar-created links)
- [x] 6.5 Register handler in the SaveObject pipeline (after property validation, before persistence)

## 7. Read-Time Enrichment

- [x] 7.1 Add `_extend` support for `_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck` in the RenderObject pipeline
- [x] 7.2 Implement `renderMail()` enricher — resolve mail IDs to `{id, subject, sender, date}` via Nextcloud Mail app
- [x] 7.3 Implement `renderContacts()` enricher — resolve contact UIDs to `{id, name, email}` via CardDAV/Contacts
- [x] 7.4 Implement `renderNotes()` enricher — resolve note IDs to `{id, message, author, date}` via ICommentsManager
- [x] 7.5 Implement `renderTodos()` enricher — resolve todo UIDs to `{id, title, status, due}` via CalDAV VTODO
- [x] 7.6 Implement `renderCalendar()` enricher — resolve event UIDs to `{id, title, start, end, location}` via CalDAV VEVENT
- [x] 7.7 Implement `renderTalk()` enricher — resolve tokens to `{id, name, type}` via Talk API
- [x] 7.8 Implement `renderDeck()` enricher — resolve board/card IDs to `{id, title, board, stack}` via Deck API
- [x] 7.9 Implement graceful fallback for missing/deleted source entities (`{id, label: "Not found"}`)

## 8. Generic Metadata API

- [x] 8.1 Create `LinkedEntityController` with POST `/api/objects/{uuid}/_{type}` endpoint for adding links
- [x] 8.2 Add DELETE `/api/objects/{uuid}/_{type}/{id}` endpoint for removing links
- [x] 8.3 Add entity-level endpoints: POST/DELETE `/api/registers/{uuid}/_{type}`, `/api/schemas/{uuid}/_{type}`
- [x] 8.4 Validate that `{type}` is in the schema's `linkedTypes` before allowing writes on objects
- [x] 8.5 Implement idempotent add (no duplicates, no error on re-add)
- [x] 8.6 Register routes in `appinfo/routes.php`

## 9. Reverse Lookup API

- [x] 9.1 Create reverse lookup endpoint `GET /api/linked/_{type}/{id}`
- [x] 9.2 Implement cross-table scan for magic tables — find all schemas with the corresponding linkedType, query each table's `_` column
- [x] 9.3 Implement entity table scan — query `_` column on registers, schemas, organisations tables
- [x] 9.4 Return unified results with `entityType` (`"object"`, `"register"`, `"schema"`), UUID, name, schema/register info
- [x] 9.5 Add circuit breaker for performance (max tables to scan, timeout)

## 10. Sidebar Injection Generalization

- [x] 10.1 Refactor `MailAppScriptListener` to check if any schema has `"mail"` in `linkedTypes` before injecting
- [x] 10.2 Create equivalent listeners for Contacts, Calendar, Notes, Talk, Deck apps (or a generic listener factory)
- [x] 10.3 Update sidebar frontend components to use generic metadata API (`/api/objects/{uuid}/_linked/mail`, `/api/linked/mail/{id}`) instead of entity-specific endpoints

## 11. Remove Email-Specific Infrastructure

- [x] 11.1 Create migration to migrate `oc_openregister_email_links` data to `_mail` columns on corresponding objects
- [x] 11.2 Create migration to drop `oc_openregister_email_links`, `oc_openregister_contact_links`, `oc_openregister_deck_links` tables
- [x] 11.3 Remove email-specific routes (kept sender lookup as legacy during transition)
- [x] 11.4 Remove email-specific routes from `appinfo/routes.php`

## 12. Frontend: Schema Editor

- [x] 12.1 Add `linkedTypes` to schema entity type definition and constructor
- [x] 12.2 Add Nc\* types to the property type dropdown in `EditSchemaProperty.vue`
- [x] 12.3 Add Nc\* types to array items sub-type dropdown

## 13. Frontend: Object Detail Rendering

- [x] 13.1 Create Nc\* type renderer components (NcMailReference, NcContactReference, etc.) for inline display on object detail
- [x] 13.2 Register renderers in the property renderer registry keyed by Nc\* type name
- [x] 13.3 Support both single and array rendering

## 14. Seed Data

- [x] 14.1 Add seed schema with `linkedTypes: ["mail", "contacts", "files"]` and Nc\* typed properties for development/testing

## 15. Testing and Verification

- [x] 15.1 Test linkedTypes validation on schema create/update
- [x] 15.2 Test Nc\* property types — create objects with NcMail, NcContact, etc. properties and verify `_` column population
- [x] 15.3 Test generic metadata API — ad-hoc link add/remove, idempotency, linkedType enforcement
- [x] 15.4 Test reverse lookup — objects across multiple schemas, entity results, empty results
- [x] 15.5 Test `_extend` enrichment — verify enriched responses for each entity type
- [x] 15.6 Test sidebar injection — verify sidebar appears only when schemas declare the linkedType
- [x] 15.7 Regression test with opencatalogi and softwarecatalog apps to verify no breakage
