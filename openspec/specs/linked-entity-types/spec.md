---
status: implemented
---

# Linked Entity Types

## Purpose

Unified system for linking Nextcloud entities (mail, contacts, calendar events, notes, todos, Talk conversations, Deck cards) to OpenRegister objects and entities. Provides schema-level `configuration.linkedTypes` declarations, Nc\* property types for typed field-level references, lean `_` metadata columns on both magic and entity tables, a generic API for ad-hoc linking and reverse lookups, read-time enrichment via `_extend`, and sidebar injection based on linkedTypes.

**Standards**: JSON Schema (custom type extensions), Nextcloud Mail/CardDAV/CalDAV/Talk/Deck APIs
**Cross-references**: [object-interactions](../object-interactions/spec.md), [schema-hooks](../schema-hooks/spec.md) (workflow hooks — separate concern)

## Requirements

### Requirement: Schema linkedTypes Configuration
Schemas MUST support a `linkedTypes` property in the `configuration` JSON field. The value MUST be an array of strings representing Nextcloud entity types that objects of this schema can link to. Valid values: `"files"`, `"mail"`, `"contacts"`, `"notes"`, `"todos"`, `"calendar"`, `"talk"`, `"deck"`. The `Schema::validateConfigurationArray()` method MUST validate `linkedTypes` as an array of strings matching the allowed values.

#### Scenario: Schema stores linkedTypes configuration
- **GIVEN** a Schema entity
- **WHEN** the `configuration` is set to `{"linkedTypes": ["mail", "contacts", "files"]}`
- **THEN** the configuration MUST be accepted and persisted
- **AND** `getConfiguration()['linkedTypes']` MUST return `["mail", "contacts", "files"]`

#### Scenario: Invalid linkedType value rejected
- **GIVEN** a Schema entity
- **WHEN** the `configuration` is set to `{"linkedTypes": ["mail", "invalid-type"]}`
- **THEN** validation MUST reject the configuration with an error identifying `"invalid-type"` as not a valid linked type

#### Scenario: linkedTypes defaults to empty array
- **GIVEN** a Schema entity with no `linkedTypes` in configuration
- **WHEN** `getConfiguration()` is called
- **THEN** `linkedTypes` MUST default to an empty array `[]`

#### Scenario: linkedTypes returned in API response
- **GIVEN** a Schema with `linkedTypes: ["mail", "contacts"]`
- **WHEN** a GET request is made to `/api/schemas/{id}`
- **THEN** the response MUST include `configuration.linkedTypes` as `["mail", "contacts"]`

### Requirement: Nc\* Property Types
The system MUST support the following custom property types in JSON Schema definitions: `NcFile`, `NcMail`, `NcContact`, `NcNote`, `NcTodo`, `NcCalendarEvent`, `NcTalk`, `NcDeck`. These MUST be added to `PropertyValidatorHandler::$validTypes`. Each type stores a reference envelope in the object's data.

#### Scenario: Schema property with NcMail type
- **GIVEN** a schema with property `relatedEmail` of type `NcMail`
- **WHEN** an object is created with `relatedEmail: { "type": "NcMail", "id": "1/6", "label": "RE: Aanvraag" }`
- **THEN** the value MUST be stored in the object's `_data` as the full reference envelope
- **AND** the `id` value `"1/6"` MUST be extracted and added to the object's `_mail` metadata column

#### Scenario: Array of Nc\* type
- **GIVEN** a schema with property `contacts` of type `array` with `items.type: "NcContact"`
- **WHEN** an object is created with `contacts: [{"type": "NcContact", "id": "abc-123", "label": "Jan"}, {"type": "NcContact", "id": "def-456", "label": "Piet"}]`
- **THEN** both values MUST be stored in `_data`
- **AND** both IDs `["abc-123", "def-456"]` MUST be extracted and added to the `_contacts` metadata column

#### Scenario: Invalid Nc\* reference envelope rejected
- **GIVEN** a schema with property `contact` of type `NcContact`
- **WHEN** an object is created with `contact: "just-a-string"` (not a valid envelope)
- **THEN** validation MUST reject the value with an error indicating the expected envelope format

#### Scenario: Nc\* reference envelope with optional fields
- **GIVEN** a schema with property `email` of type `NcMail`
- **WHEN** an object is created with `email: { "type": "NcMail", "id": "1/6" }` (no label)
- **THEN** the value MUST be accepted — `label` is optional

### Requirement: Metadata Columns on Magic Tables
For each linked type declared in a schema's `linkedTypes`, the corresponding magic table MUST have a `_` prefixed JSON column. Column names: `_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck`. Columns MUST be nullable JSON, storing arrays of string IDs (e.g., `["1/6", "1/12"]`). Columns MUST be indexed for reverse lookups.

#### Scenario: Magic table created with linked type columns
- **GIVEN** a schema with `linkedTypes: ["mail", "contacts"]`
- **WHEN** the magic table is created or updated via `MagicMapper::buildTableColumnsFromSchema()`
- **THEN** the table MUST include `_mail` and `_contacts` columns as nullable JSON
- **AND** the table MUST NOT include `_notes`, `_todos`, `_calendar`, `_talk`, or `_deck` columns

#### Scenario: Schema without linkedTypes has no extra columns
- **GIVEN** a schema with no `linkedTypes` in configuration
- **WHEN** the magic table is created
- **THEN** only the standard metadata columns (`_files`, `_relations`, etc.) MUST be present

#### Scenario: Adding a linkedType to existing schema adds column
- **GIVEN** a schema with `linkedTypes: ["mail"]` and an existing magic table with `_mail` column
- **WHEN** `linkedTypes` is updated to `["mail", "contacts"]`
- **THEN** `MagicMapper` MUST add the `_contacts` column to the existing table via ALTER TABLE

### Requirement: Metadata Columns on Entity Tables
Fixed entity tables (`oc_openregister_registers`, `oc_openregister_schemas`, `oc_openregister_organisations`) MUST have all linked type columns: `_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck`, `_files`. All columns MUST be nullable JSON storing arrays of string IDs. A database migration MUST add these columns.

#### Scenario: Entity table has linked type columns after migration
- **GIVEN** the migration has run
- **WHEN** the `oc_openregister_registers` table is inspected
- **THEN** it MUST have `_mail`, `_contacts`, `_notes`, `_todos`, `_calendar`, `_talk`, `_deck`, and `_files` columns
- **AND** all columns MUST be nullable JSON with DEFAULT NULL

#### Scenario: Existing entity data preserved after migration
- **GIVEN** existing registers in `oc_openregister_registers`
- **WHEN** the migration adds the new columns
- **THEN** all existing data MUST be preserved
- **AND** all new columns MUST contain NULL for existing rows

### Requirement: SaveObject Pipeline Extraction
The SaveObject pipeline MUST include a `LinkedEntityPropertyHandler` that runs after property validation. For each property with an Nc\* type, the handler MUST extract the `id` field from the reference envelope and append it to the corresponding `_` metadata column on the object. Duplicate IDs MUST NOT be added.

#### Scenario: Nc\* property extraction on object create
- **GIVEN** a schema with property `email` of type `NcMail` and `linkedTypes: ["mail"]`
- **WHEN** an object is created with `email: { "type": "NcMail", "id": "1/6", "label": "RE: Test" }`
- **THEN** the `_mail` column MUST contain `["1/6"]`

#### Scenario: Multiple Nc\* properties of same type merged
- **GIVEN** a schema with properties `primaryEmail` (type `NcMail`) and `secondaryEmails` (type `array`, items `NcMail`)
- **WHEN** an object is created with `primaryEmail: { "type": "NcMail", "id": "1/6" }` and `secondaryEmails: [{ "type": "NcMail", "id": "1/12" }]`
- **THEN** the `_mail` column MUST contain `["1/6", "1/12"]`

#### Scenario: Ad-hoc links preserved during property extraction
- **GIVEN** an object with `_mail: ["1/3"]` (ad-hoc linked via sidebar) and a property `email` of type `NcMail`
- **WHEN** the object is updated with `email: { "type": "NcMail", "id": "1/6" }`
- **THEN** the `_mail` column MUST contain `["1/3", "1/6"]` — the ad-hoc link MUST be preserved

#### Scenario: Duplicate IDs not added
- **GIVEN** an object with `_mail: ["1/6"]` and property `email` of type `NcMail`
- **WHEN** the object is updated with `email: { "type": "NcMail", "id": "1/6" }`
- **THEN** the `_mail` column MUST still contain `["1/6"]` — no duplicate

### Requirement: Read-Time Enrichment via \_extend
The RenderObject pipeline MUST support enrichment of linked entity IDs via `_extend` parameters: `_extend[_mail]`, `_extend[_contacts]`, `_extend[_notes]`, `_extend[_todos]`, `_extend[_calendar]`, `_extend[_talk]`, `_extend[_deck]`. When requested, the enricher MUST resolve IDs into display objects from the source Nextcloud app.

#### Scenario: Extend mail IDs to full mail objects
- **GIVEN** an object with `_mail: ["1/6", "1/12"]`
- **WHEN** a GET request is made with `_extend[_mail]=1`
- **THEN** the response MUST include `_mail` as an array of enriched objects with at minimum `id`, `subject`, `sender`, `date` fields
- **AND** the enriched data MUST be fetched from the Nextcloud Mail app

#### Scenario: Extend contacts IDs to full contact objects
- **GIVEN** an object with `_contacts: ["f47ac10b-58cc"]`
- **WHEN** a GET request is made with `_extend[_contacts]=1`
- **THEN** the response MUST include `_contacts` as an array of enriched objects with at minimum `id`, `name`, `email` fields

#### Scenario: Extend without \_extend returns raw IDs
- **GIVEN** an object with `_mail: ["1/6"]`
- **WHEN** a GET request is made without `_extend[_mail]`
- **THEN** the response MUST include `_mail` as `["1/6"]` — raw ID array, not enriched

#### Scenario: Enrichment gracefully handles missing source entities
- **GIVEN** an object with `_mail: ["1/6", "1/999"]` where message 1/999 no longer exists
- **WHEN** a GET request is made with `_extend[_mail]=1`
- **THEN** the response MUST include the enriched object for `1/6` and a fallback object for `1/999` with `id: "1/999"` and `label: "Not found"`

### Requirement: Generic Metadata API for Ad-Hoc Linking
The system MUST provide a `LinkedEntityController` with generic endpoints for adding, removing, and reverse-looking-up linked entities on objects. The controller MUST validate that the entity type is in the schema's `linkedTypes` before allowing writes.

#### Scenario: Add ad-hoc mail link to object
- **GIVEN** an object with UUID `abc-123` in a schema with `linkedTypes: ["mail"]`
- **WHEN** a POST request is sent to `/api/objects/abc-123/_linked/mail` with body `{"id": "1/6"}`
- **THEN** `"1/6"` MUST be appended to the object's `_mail` column
- **AND** the response MUST return HTTP 200 with the updated `_mail` array

#### Scenario: Remove ad-hoc mail link from object
- **GIVEN** an object with `_mail: ["1/6", "1/12"]`
- **WHEN** a DELETE request is sent to `/api/objects/abc-123/_linked/mail/1%2F6`
- **THEN** `"1/6"` MUST be removed from the `_mail` column
- **AND** the response MUST return HTTP 200 with the updated `_mail` array `["1/12"]`

#### Scenario: Add link to non-allowed type rejected
- **GIVEN** an object in a schema with `linkedTypes: ["mail"]` (no contacts)
- **WHEN** a POST request is sent to `/api/objects/abc-123/_linked/contacts` with body `{"id": "f47ac10b"}`
- **THEN** the response MUST return HTTP 400 with an error indicating `contacts` is not in the schema's `linkedTypes`

#### Scenario: Add duplicate link is idempotent
- **GIVEN** an object with `_mail: ["1/6"]`
- **WHEN** a POST request is sent to `/api/objects/abc-123/_linked/mail` with body `{"id": "1/6"}`
- **THEN** the `_mail` column MUST remain `["1/6"]`
- **AND** the response MUST return HTTP 200 (not an error)

#### Scenario: Add link to entity (register/schema)
- **GIVEN** a register with UUID `reg-123`
- **WHEN** a POST request is sent to `/api/registers/reg-123/_linked/mail` with body `{"id": "1/6"}`
- **THEN** `"1/6"` MUST be appended to the register's `_mail` column
- **AND** the response MUST return HTTP 200

### Requirement: Reverse Lookup Across Tables
The system MUST provide a reverse lookup endpoint `GET /api/linked/{type}/{id}` that finds all objects and entities linked to a given Nextcloud entity. The lookup MUST scan all magic tables that have the corresponding `_` column plus all entity tables.

#### Scenario: Reverse lookup finds objects across schemas
- **GIVEN** two schemas each with `linkedTypes: ["mail"]`, and one object in each schema with `_mail` containing `"1/6"`
- **WHEN** a GET request is made to `/api/linked/mail/1%2F6`
- **THEN** the response MUST return both objects with their UUID, schema, register, and `_name` metadata

#### Scenario: Reverse lookup finds entities
- **GIVEN** a register with `_mail: ["1/6"]`
- **WHEN** a GET request is made to `/api/linked/mail/1%2F6`
- **THEN** the response MUST include the register alongside any matching objects
- **AND** each result MUST indicate its entity type (`"object"`, `"register"`, `"schema"`, etc.)

#### Scenario: Reverse lookup returns empty for unlinked entity
- **GIVEN** no objects or entities have `_mail` containing `"1/999"`
- **WHEN** a GET request is made to `/api/linked/mail/1%2F999`
- **THEN** the response MUST return an empty results array

### Requirement: Sidebar Injection Based on linkedTypes
OpenRegister's app script listeners (e.g., `MailAppScriptListener`) MUST check whether any schema declares the corresponding entity type in `linkedTypes` before injecting sidebar scripts. If no schema has that entity type, the sidebar script MUST NOT be injected.

#### Scenario: Mail sidebar injected when schemas have mail linkedType
- **GIVEN** at least one schema with `linkedTypes` containing `"mail"`
- **WHEN** the Mail app template is rendered
- **THEN** OpenRegister MUST inject the mail sidebar script via `Util::addScript()`

#### Scenario: Mail sidebar not injected when no schemas have mail linkedType
- **GIVEN** no schema has `"mail"` in its `linkedTypes`
- **WHEN** the Mail app template is rendered
- **THEN** OpenRegister MUST NOT inject the mail sidebar script

#### Scenario: Sidebar uses reverse lookup API
- **GIVEN** the mail sidebar is displayed for mail message `1/6`
- **WHEN** the sidebar loads
- **THEN** it MUST call `GET /api/linked/mail/1%2F6` to find all linked objects
- **AND** display the results with object name, schema, and register information

### Requirement: Remove Email-Specific Link Infrastructure
The `oc_openregister_email_links` table, `EmailsController`, `EmailService`, `EmailLinkMapper`, and `EmailLink` entity MUST be removed. A migration MUST drop the `oc_openregister_email_links` table after migrating any existing data to the `_mail` metadata columns of the corresponding objects.

#### Scenario: Existing email links migrated to \_mail column
- **GIVEN** existing rows in `oc_openregister_email_links` linking mail `1/6` to object `abc-123`
- **WHEN** the migration runs
- **THEN** `"1/6"` MUST be added to the `_mail` column of object `abc-123`
- **AND** the `oc_openregister_email_links` table MUST be dropped

#### Scenario: Email API endpoints removed
- **GIVEN** the old endpoints `/api/emails/link`, `/api/emails/{accountId}/{messageId}`, `/api/emails/sender/{sender}`
- **WHEN** a request is made to any of these endpoints
- **THEN** the response MUST return HTTP 404 (routes no longer registered)

### Requirement: Specialized Services Use Metadata Columns
EmailService, ContactService, and DeckCardService MUST use the `_mail`, `_contacts`, and `_deck` metadata columns on ObjectEntity as their primary storage. They MUST NOT use dedicated link tables or link entity mappers. The services MUST load objects via `MagicMapper`, read/write the appropriate `_` column, and persist changes.

#### Scenario: EmailService links an email to an object
- **GIVEN** an object with UUID `abc-123` and an empty `_mail` column
- **WHEN** `EmailService::linkEmail("abc-123", 1, 6)` is called (registerId=1, accountId=1, messageId=6)
- **THEN** the object's `_mail` column MUST contain `["1/6"]`
- **AND** the object MUST be persisted via MagicMapper

#### Scenario: EmailService unlinks an email by reference
- **GIVEN** an object with `_mail: ["1/6", "1/12"]`
- **WHEN** `EmailService::unlinkEmail("abc-123", "1/6")` is called
- **THEN** the object's `_mail` column MUST contain `["1/12"]`

#### Scenario: EmailService returns enriched emails for an object
- **GIVEN** an object with `_mail: ["1/6"]`
- **WHEN** `EmailService::getEmailsForObject("abc-123")` is called
- **THEN** the response MUST include enriched email data (subject, sender, date) fetched from the Mail app database
- **AND** each result MUST include the `id` field as `"1/6"`

#### Scenario: ContactService links a contact with vCard sync
- **GIVEN** an object with UUID `abc-123` and an empty `_contacts` column
- **WHEN** `ContactService::linkContact("abc-123", 1, 5, "ABC123.vcf")` is called
- **THEN** the object's `_contacts` column MUST contain the contact UID
- **AND** the contact's vCard MUST have `X-OPENREGISTER-OBJECT` property set to `abc-123`
- **AND** the object MUST be persisted via MagicMapper

#### Scenario: ContactService unlinks a contact by UID
- **GIVEN** an object with `_contacts: ["f47ac10b-58cc", "a3b2c1d0"]`
- **WHEN** `ContactService::unlinkContact("abc-123", "f47ac10b-58cc")` is called
- **THEN** the object's `_contacts` column MUST contain `["a3b2c1d0"]`
- **AND** the contact's vCard X-OPENREGISTER-\* properties MUST be removed

#### Scenario: ContactService creates and links a new contact
- **GIVEN** an object with UUID `abc-123`
- **WHEN** `ContactService::createAndLinkContact("abc-123", 1, {"fullName": "Jan de Vries"})` is called
- **THEN** a new contact MUST be created via CardDAV
- **AND** the new contact's UID MUST be appended to `_contacts`
- **AND** the new contact's vCard MUST have `X-OPENREGISTER-OBJECT` set to `abc-123`

#### Scenario: DeckCardService links a card to an object
- **GIVEN** an object with UUID `abc-123` and an empty `_deck` column
- **WHEN** `DeckCardService::linkOrCreateCard("abc-123", 1, {"cardId": 42, "boardId": 3})` is called
- **THEN** the object's `_deck` column MUST contain `["3/42"]`

#### Scenario: DeckCardService unlinks a card by reference
- **GIVEN** an object with `_deck: ["3/42", "3/43"]`
- **WHEN** `DeckCardService::unlinkCard("abc-123", "3/42")` is called
- **THEN** the object's `_deck` column MUST contain `["3/43"]`

### Requirement: Reverse Lookups Via LinkedEntityService
The specialized services MUST delegate reverse lookups to `LinkedEntityService::reverseLookup()` instead of querying their own link tables. This provides cross-table scanning with circuit breakers.

#### Scenario: Find objects linked to a contact
- **WHEN** `ContactService::getObjectsForContact("f47ac10b-58cc")` is called
- **THEN** it MUST delegate to `LinkedEntityService::reverseLookup("contacts", "f47ac10b-58cc")`
- **AND** return all matching objects across all schemas

#### Scenario: Find objects linked to a Deck board
- **WHEN** `DeckCardService::getObjectsForBoard(3)` is called
- **THEN** it MUST use `LinkedEntityService` to find all objects with `_deck` containing entries prefixed with `"3/"`

#### Scenario: Search objects by email sender
- **WHEN** `EmailService::searchBySender("jan@example.com")` is called
- **THEN** it MUST find all objects with `_mail` entries, enrich each to get the sender, and filter by matching sender

### Requirement: Remove Link Entities and Mappers
The following files MUST be removed: `EmailLink.php`, `EmailLinkMapper.php`, `ContactLink.php`, `ContactLinkMapper.php`, `DeckLink.php`, `DeckLinkMapper.php`. No code MUST reference these classes after refactoring.

#### Scenario: No references to link mappers remain
- **GIVEN** the refactoring is complete
- **WHEN** the codebase is searched for `EmailLinkMapper`, `ContactLinkMapper`, `DeckLinkMapper`
- **THEN** zero references MUST be found

### Requirement: Controller API Signature Changes
Controllers MUST accept entity reference strings instead of numeric link IDs for unlink/delete operations.

#### Scenario: Delete email link via controller
- **GIVEN** an object with `_mail: ["1/6"]`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/{id}/emails/1%2F6`
- **THEN** `"1/6"` MUST be removed from the object's `_mail` column

#### Scenario: Delete contact link via controller
- **GIVEN** an object with `_contacts: ["f47ac10b-58cc"]`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/{id}/contacts/f47ac10b-58cc`
- **THEN** `"f47ac10b-58cc"` MUST be removed from `_contacts`
- **AND** the contact's vCard X-OPENREGISTER-\* properties MUST be removed

#### Scenario: Delete deck card link via controller
- **GIVEN** an object with `_deck: ["3/42"]`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/{id}/deck/3%2F42`
- **THEN** `"3/42"` MUST be removed from `_deck`
