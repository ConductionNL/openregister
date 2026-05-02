# nextcloud-entity-relations Specification

## Purpose
TBD - created by archiving change nextcloud-entity-relations. Update Purpose after archive.
## Requirements
### Requirement: Email Relations via Nextcloud Mail

The system SHALL provide an `EmailService` that links Nextcloud Mail messages to OpenRegister objects. Email relations are READ-ONLY references — emails are immutable and managed by the Mail app. The relation is stored as an `openregister_email_links` database table mapping object UUIDs to Mail message IDs.

#### Rationale

Emails are a primary communication channel in case management. A case handler receives an application by email, exchanges correspondence with citizens and colleagues, and needs all related emails visible on the case object. Unlike tasks (CalDAV) and notes (Comments), Nextcloud Mail does not have a generic entity-linking API, so we store the relation in our own table.

#### Storage Model

```
openregister_email_links
├── id (int, PK, autoincrement)
├── object_uuid (string, indexed) — the OpenRegister object UUID
├── mail_account_id (int) — Nextcloud Mail account ID
├── mail_message_id (int) — Nextcloud Mail internal message ID
├── mail_message_uid (string) — IMAP message UID for reference
├── subject (string) — cached subject line for display without Mail API call
├── sender (string) — cached sender address
├── date (datetime) — cached send date
├── linked_by (string) — user who created the link
├── linked_at (datetime) — when the link was created
└── register_id (int, indexed) — for scoping/cleanup
```

#### Scenario: Link an existing email to an object
- **GIVEN** an authenticated user `behandelaar-1` and an object with UUID `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/emails` with body `{"mailAccountId": 1, "mailMessageId": 42}`
- **THEN** the system MUST verify the email exists by querying Nextcloud Mail's message table
- **AND** create a record in `openregister_email_links` with the object UUID and mail message reference
- **AND** cache the subject, sender, and date from the mail message
- **AND** return HTTP 201 with the email link as JSON including `id`, `objectUuid`, `mailAccountId`, `mailMessageId`, `subject`, `sender`, `date`, `linkedBy`, `linkedAt`

#### Scenario: List email relations for an object
- **GIVEN** object `abc-123` has 4 linked emails
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/emails?limit=10&offset=0`
- **THEN** the response MUST return `{"results": [...], "total": 4}` with all 4 email links
- **AND** each link MUST include: `id`, `mailAccountId`, `mailMessageId`, `subject`, `sender`, `date`, `linkedBy`, `linkedAt`
- **AND** results MUST be ordered by `date` descending (newest first)

#### Scenario: Remove an email relation
- **GIVEN** email link with ID 7 exists on object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/abc-123/emails/7`
- **THEN** the record MUST be removed from `openregister_email_links`
- **AND** the actual email in Nextcloud Mail MUST NOT be deleted
- **AND** the response MUST return HTTP 200 with `{"success": true}`

#### Scenario: Link email that does not exist
- **GIVEN** a POST request with `mailMessageId: 99999` that does not exist in Nextcloud Mail
- **WHEN** the system verifies the email
- **THEN** the API MUST return HTTP 404 with `{"error": "Mail message not found"}`

#### Scenario: Prevent duplicate email links
- **GIVEN** email message 42 is already linked to object `abc-123`
- **WHEN** a POST request tries to link the same email again
- **THEN** the API MUST return HTTP 409 with `{"error": "Email already linked to this object"}`

#### Scenario: Search objects by linked email
- **GIVEN** multiple objects have email links
- **WHEN** a GET request is sent to `/api/emails/search?sender=burger@test.local`
- **THEN** the response MUST return all objects that have a linked email from that sender
- **AND** this enables cross-object email thread tracking

---

### Requirement: Calendar Event Relations via CalDAV VEVENT

The system SHALL provide a `CalendarEventService` that creates, reads, and deletes CalDAV VEVENT items linked to OpenRegister objects. This follows the exact same pattern as `TaskService` (VTODO), but for calendar events. Each VEVENT MUST include `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, and `X-OPENREGISTER-OBJECT` custom properties, plus an RFC 9253 LINK property.

#### Rationale

Cases have associated deadlines, hearings, meetings, and milestones that are best represented as calendar events. Unlike tasks (which track work items), calendar events represent time-bound occurrences that may involve multiple participants. Storing them in CalDAV ensures they appear in the user's Nextcloud Calendar app.

#### Scenario: Create a calendar event linked to an object
- **GIVEN** an object with UUID `abc-123` in register 5, schema 12
- **WHEN** a POST request is sent to `/api/objects/5/12/abc-123/events` with body:
  ```json
  {
    "summary": "Welstandscommissie - dakkapel Kerkstraat 42",
    "dtstart": "2026-03-25T13:00:00Z",
    "dtend": "2026-03-25T15:00:00Z",
    "location": "Raadzaal - Stadskantoor",
    "description": "Behandeling aanvraag ZK-2026-0142",
    "attendees": ["behandelaar@test.local"]
  }
  ```
- **THEN** a VEVENT MUST be created in the user's default calendar with:
  - `X-OPENREGISTER-REGISTER:5`
  - `X-OPENREGISTER-SCHEMA:12`
  - `X-OPENREGISTER-OBJECT:abc-123`
  - `LINK;LINKREL="related";VALUE=URI:/apps/openregister/api/objects/5/12/abc-123`
  - `SUMMARY`, `DTSTART`, `DTEND`, `LOCATION`, `DESCRIPTION`, `ATTENDEE` as provided
- **AND** the response MUST return HTTP 201 with the event as JSON including `id`, `uid`, `calendarId`, `summary`, `dtstart`, `dtend`, `location`, `description`, `attendees`, `objectUuid`, `registerId`, `schemaId`

#### Scenario: List calendar events for an object
- **GIVEN** 2 VEVENTs exist with `X-OPENREGISTER-OBJECT:abc-123`
- **WHEN** a GET request is sent to `/api/objects/5/12/abc-123/events`
- **THEN** the response MUST return `{"results": [...], "total": 2}` with all 2 events
- **AND** each event MUST include: `id` (URI), `uid`, `calendarId`, `summary`, `dtstart`, `dtend`, `location`, `description`, `attendees`, `status`, `objectUuid`, `registerId`, `schemaId`

#### Scenario: Link an existing calendar event to an object
- **GIVEN** a VEVENT already exists in the user's calendar (e.g., created via NC Calendar UI)
- **WHEN** a POST request is sent to `/api/objects/5/12/abc-123/events/link` with `{"calendarId": 1, "eventUri": "meeting-123.ics"}`
- **THEN** the system MUST update the VEVENT to add X-OPENREGISTER-* properties
- **AND** return HTTP 200 with the updated event JSON

#### Scenario: Delete a calendar event relation
- **GIVEN** a VEVENT linked to object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/5/12/abc-123/events/{eventId}`
- **THEN** the X-OPENREGISTER-* properties MUST be removed from the VEVENT
- **AND** the VEVENT itself MUST remain in the calendar (only the link is removed)
- **AND** the response MUST return `{"success": true}`

#### Scenario: Force-delete calendar event with object
- **GIVEN** a VEVENT linked to object `abc-123` and the object is being deleted
- **WHEN** `ObjectCleanupListener` handles `ObjectDeletedEvent`
- **THEN** the X-OPENREGISTER-* properties MUST be removed from all linked VEVENTs
- **AND** the VEVENTs MUST NOT be deleted (only unlinked)

#### Scenario: Calendar selection for events
- **GIVEN** the user has calendars `personal` (VEVENT+VTODO) and `birthdays` (VEVENT only)
- **WHEN** an event is created via the API
- **THEN** the service MUST use the user's default calendar or the first VEVENT-supporting calendar
- **AND** optionally accept a `calendarId` parameter to target a specific calendar

---

### Requirement: Contact Relations via CardDAV

The system SHALL provide a `ContactService` that links CardDAV vCard contacts to OpenRegister objects. Contacts represent stakeholders (citizens, applicants, suppliers, colleagues) associated with a case/object. The relation is stored via X-OPENREGISTER-* custom properties on the vCard AND in an `openregister_contact_links` table for efficient querying.

#### Rationale

Every case has stakeholders — the citizen who filed the application, the colleague who handles it, the external advisor who reviews it. These people exist as contacts in Nextcloud's address book. Linking them to objects allows consuming apps to show "who is involved" and find all cases a contact is involved in.

#### Storage Model (dual storage)

**vCard custom properties** (on the contact itself):
```
X-OPENREGISTER-OBJECT:abc-123
X-OPENREGISTER-ROLE:applicant
```

**Database table** (for efficient querying):
```
openregister_contact_links
├── id (int, PK, autoincrement)
├── object_uuid (string, indexed)
├── contact_uid (string) — vCard UID
├── addressbook_id (int) — CardDAV addressbook ID
├── contact_uri (string) — vCard URI in addressbook
├── display_name (string) — cached FN from vCard
├── email (string, nullable) — cached primary email
├── role (string, nullable) — e.g., "applicant", "handler", "advisor", "supplier"
├── linked_by (string) — user who created the link
├── linked_at (datetime)
└── register_id (int, indexed)
```

#### Scenario: Link an existing contact to an object
- **GIVEN** an authenticated user and an object with UUID `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/contacts` with body `{"addressbookId": 1, "contactUri": "jan-de-vries.vcf", "role": "applicant"}`
- **THEN** the system MUST verify the contact exists via `CalDavBackend` (addressbook backend)
- **AND** add `X-OPENREGISTER-OBJECT:abc-123` and `X-OPENREGISTER-ROLE:applicant` properties to the vCard
- **AND** create a record in `openregister_contact_links` with cached display name and email
- **AND** return HTTP 201 with the contact link as JSON including `id`, `objectUuid`, `contactUid`, `displayName`, `email`, `role`, `linkedBy`, `linkedAt`

#### Scenario: Create a new contact and link to object
- **GIVEN** an authenticated user and an object with UUID `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/contacts` with body:
  ```json
  {
    "fullName": "Jan de Vries",
    "email": "jan@example.nl",
    "phone": "+31612345678",
    "role": "applicant"
  }
  ```
- **THEN** a new vCard MUST be created in the user's default address book with the provided properties and X-OPENREGISTER-* properties
- **AND** a record MUST be created in `openregister_contact_links`
- **AND** the response MUST return HTTP 201 with the contact link JSON

#### Scenario: List contacts for an object
- **GIVEN** object `abc-123` has 3 linked contacts (applicant, handler, advisor)
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/contacts`
- **THEN** the response MUST return `{"results": [...], "total": 3}`
- **AND** each contact MUST include: `id`, `contactUid`, `addressbookId`, `displayName`, `email`, `phone`, `role`, `linkedBy`, `linkedAt`

#### Scenario: Update contact role on an object
- **GIVEN** contact link with ID 5 exists with role `"applicant"`
- **WHEN** a PUT request is sent to `/api/objects/{register}/{schema}/abc-123/contacts/5` with `{"role": "co-applicant"}`
- **THEN** the role MUST be updated in both the `openregister_contact_links` table and the vCard's `X-OPENREGISTER-ROLE` property
- **AND** the response MUST return the updated contact link JSON

#### Scenario: Remove a contact relation
- **GIVEN** contact link with ID 5 exists on object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/abc-123/contacts/5`
- **THEN** the record MUST be removed from `openregister_contact_links`
- **AND** the `X-OPENREGISTER-OBJECT` and `X-OPENREGISTER-ROLE` properties MUST be removed from the vCard
- **AND** the vCard itself MUST NOT be deleted
- **AND** the response MUST return HTTP 200 with `{"success": true}`

#### Scenario: Find all objects linked to a contact
- **GIVEN** contact `jan-de-vries` is linked to objects `abc-123` and `def-456`
- **WHEN** a GET request is sent to `/api/contacts/{contactUid}/objects`
- **THEN** the response MUST return both objects with their respective roles
- **AND** this enables the "case history for this person" view in consuming apps

#### Scenario: Contact with multiple object links
- **GIVEN** contact `jan-de-vries` is already linked to object `abc-123` as applicant
- **WHEN** the same contact is linked to object `def-456` as co-applicant
- **THEN** the vCard MUST contain multiple `X-OPENREGISTER-OBJECT` properties
- **AND** both links MUST exist in the database table

---

### Requirement: Deck Card Relations via Nextcloud Deck API

The system SHALL provide a `DeckCardService` that links Nextcloud Deck cards to OpenRegister objects. Deck provides kanban-style boards, stacks (columns), and cards. Linking cards to objects enables workflow visualization where each card represents a case/object moving through process stages.

#### Rationale

Pipelinq and Procest use pipeline/kanban views. Deck is Nextcloud's native kanban tool. By linking Deck cards to objects, case managers get a visual workflow board where cards are backed by OpenRegister data. Moving a card between stacks can trigger status changes on the object.

#### Storage Model

```
openregister_deck_links
├── id (int, PK, autoincrement)
├── object_uuid (string, indexed)
├── board_id (int) — Deck board ID
├── stack_id (int) — Deck stack (column) ID
├── card_id (int) — Deck card ID
├── card_title (string) — cached card title
├── linked_by (string)
├── linked_at (datetime)
└── register_id (int, indexed)
```

#### Scenario: Create a Deck card linked to an object
- **GIVEN** an authenticated user, an object with UUID `abc-123`, and a Deck board with ID 1
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/deck` with body:
  ```json
  {
    "boardId": 1,
    "stackId": 2,
    "title": "ZK-2026-0142 - Omgevingsvergunning dakkapel",
    "description": "Behandeling aanvraag omgevingsvergunning"
  }
  ```
- **THEN** a card MUST be created via the Deck API (`OCA\Deck\Service\CardService`)
- **AND** the card description MUST include a link back to the object: `[Object: abc-123](/apps/openregister/api/objects/{register}/{schema}/abc-123)`
- **AND** a record MUST be created in `openregister_deck_links`
- **AND** the response MUST return HTTP 201 with the deck link as JSON including `id`, `objectUuid`, `boardId`, `stackId`, `cardId`, `cardTitle`, `linkedBy`, `linkedAt`

#### Scenario: Link an existing Deck card to an object
- **GIVEN** a Deck card with ID 15 already exists
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/deck` with body `{"cardId": 15}`
- **THEN** the system MUST verify the card exists via Deck API
- **AND** update the card description to include the object link
- **AND** create a record in `openregister_deck_links`
- **AND** return HTTP 201 with the deck link JSON

#### Scenario: List Deck cards for an object
- **GIVEN** object `abc-123` is linked to 2 Deck cards (one in "Nieuw", one in "In behandeling")
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/deck`
- **THEN** the response MUST return `{"results": [...], "total": 2}`
- **AND** each link MUST include: `id`, `boardId`, `stackId`, `cardId`, `cardTitle`, `stackTitle`, `linkedBy`, `linkedAt`
- **AND** the `stackTitle` MUST be resolved from the Deck API (e.g., "Nieuw", "In behandeling")

#### Scenario: Remove a Deck card relation
- **GIVEN** deck link with ID 3 exists on object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/abc-123/deck/3`
- **THEN** the record MUST be removed from `openregister_deck_links`
- **AND** the Deck card itself MUST NOT be deleted (only the link is removed)
- **AND** the object link MUST be removed from the card description
- **AND** the response MUST return HTTP 200 with `{"success": true}`

#### Scenario: Find objects by Deck board
- **GIVEN** a Deck board "Vergunningen Pipeline" with cards linked to multiple objects
- **WHEN** a GET request is sent to `/api/deck/boards/{boardId}/objects`
- **THEN** the response MUST return all objects linked to cards on that board
- **AND** include the stack (column) each object's card is in

---

### Requirement: Unified Relations API

The system SHALL provide a unified endpoint to retrieve ALL relations (files, notes, tasks, emails, events, contacts, deck cards) for an object in a single request. This enables consuming apps to build a complete "object dossier" view without multiple API calls.

#### Scenario: Get all relations for an object
- **GIVEN** object `abc-123` has 2 files, 3 notes, 1 task, 4 emails, 2 events, 3 contacts, and 1 deck card
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/relations`
- **THEN** the response MUST return:
  ```json
  {
    "files": {"results": [...], "total": 2},
    "notes": {"results": [...], "total": 3},
    "tasks": {"results": [...], "total": 1},
    "emails": {"results": [...], "total": 4},
    "events": {"results": [...], "total": 2},
    "contacts": {"results": [...], "total": 3},
    "deck": {"results": [...], "total": 1}
  }
  ```

#### Scenario: Filter relations by type
- **GIVEN** the unified relations endpoint
- **WHEN** a GET request includes `?types=emails,contacts`
- **THEN** only email and contact relations MUST be returned

#### Scenario: Relations timeline view
- **GIVEN** all relations have a date field (creation date, send date, event date)
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/relations?view=timeline`
- **THEN** all relations MUST be returned in a flat array sorted by date
- **AND** each item MUST include a `type` field ("file", "note", "task", "email", "event", "contact", "deck")

---

### Requirement: Object Deletion Cleanup for New Entity Types

The `ObjectCleanupListener` SHALL be extended to handle cleanup of email links, calendar event links, contact links, and deck card links when an object is deleted. This follows the existing cleanup pattern for notes and tasks.

#### Scenario: Delete object with email links
- **GIVEN** object `abc-123` has 4 email links
- **WHEN** the object is deleted (triggering `ObjectDeletedEvent`)
- **THEN** all 4 records in `openregister_email_links` with `object_uuid: "abc-123"` MUST be deleted
- **AND** the actual emails in Nextcloud Mail MUST NOT be affected

#### Scenario: Delete object with calendar event links
- **GIVEN** object `abc-123` has 2 linked VEVENTs
- **WHEN** the object is deleted
- **THEN** X-OPENREGISTER-* properties MUST be removed from both VEVENTs
- **AND** the VEVENTs MUST remain in the calendar

#### Scenario: Delete object with contact links
- **GIVEN** object `abc-123` has 3 linked contacts
- **WHEN** the object is deleted
- **THEN** all 3 records in `openregister_contact_links` MUST be deleted
- **AND** X-OPENREGISTER-* properties referencing `abc-123` MUST be removed from the vCards
- **AND** the vCards MUST NOT be deleted

#### Scenario: Delete object with Deck card links
- **GIVEN** object `abc-123` has 1 linked Deck card
- **WHEN** the object is deleted
- **THEN** the record in `openregister_deck_links` MUST be deleted
- **AND** the object link MUST be removed from the Deck card description
- **AND** the Deck card MUST NOT be deleted

#### Scenario: Partial cleanup failure does not block deletion
- **GIVEN** an object with relations across all entity types
- **WHEN** the cleanup of one entity type fails (e.g., Deck API unavailable)
- **THEN** cleanup of other entity types MUST still proceed
- **AND** the failure MUST be logged as a warning
- **AND** the object deletion MUST NOT be blocked

---

### Requirement: Event Dispatching for Relation Changes

The system SHALL fire typed events when relations are created or removed. These events follow the CloudEvents format from [event-driven-architecture](../../../specs/event-driven-architecture/spec.md).

#### Scenario: Email link created fires event
- **GIVEN** an email is linked to object `abc-123`
- **THEN** an event `nl.openregister.object.email.linked` MUST be dispatched with the object UUID and mail message details

#### Scenario: Contact linked fires event
- **GIVEN** a contact is linked to object `abc-123` with role `applicant`
- **THEN** an event `nl.openregister.object.contact.linked` MUST be dispatched with the object UUID, contact UID, and role

#### Scenario: Calendar event linked fires event
- **GIVEN** a calendar event is linked to object `abc-123`
- **THEN** an event `nl.openregister.object.event.linked` MUST be dispatched with the object UUID and event summary/dates

#### Scenario: Deck card linked fires event
- **GIVEN** a Deck card is linked to object `abc-123`
- **THEN** an event `nl.openregister.object.deck.linked` MUST be dispatched with the object UUID, board ID, and card title

#### Scenario: Relation removed fires event
- **GIVEN** any relation is removed from an object
- **THEN** an `*.unlinked` event MUST be dispatched (e.g., `nl.openregister.object.email.unlinked`)

---

### Requirement: Audit Trail for Relation Mutations

All relation mutations SHALL generate audit trail entries per [audit-trail-immutable](../../../specs/audit-trail-immutable/spec.md).

#### Scenario: Audit entries for relation actions
- **GIVEN** the following relation actions occur
- **THEN** the corresponding audit entries MUST be created:
  - `email.linked` / `email.unlinked`
  - `event.linked` / `event.unlinked` / `event.created`
  - `contact.linked` / `contact.unlinked` / `contact.created`
  - `deck.linked` / `deck.unlinked` / `deck.created`

---

### Requirement: Graceful Degradation When NC Apps Are Disabled

The system SHALL gracefully handle cases where a required Nextcloud app (Mail, Deck) is not installed or disabled. CalDAV/CardDAV are core Nextcloud features and always available; Mail and Deck are optional apps.

#### Scenario: Mail app not installed
- **GIVEN** the Nextcloud Mail app is not installed
- **WHEN** a request is made to `/api/objects/{register}/{schema}/{id}/emails`
- **THEN** the API MUST return HTTP 501 with `{"error": "Nextcloud Mail app is not installed", "code": "APP_NOT_AVAILABLE"}`

#### Scenario: Deck app not installed
- **GIVEN** the Nextcloud Deck app is not installed
- **WHEN** a request is made to `/api/objects/{register}/{schema}/{id}/deck`
- **THEN** the API MUST return HTTP 501 with `{"error": "Nextcloud Deck app is not installed", "code": "APP_NOT_AVAILABLE"}`

#### Scenario: Relations API with missing apps
- **GIVEN** the unified relations endpoint and Mail app is not installed
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/{id}/relations`
- **THEN** the `emails` section MUST be omitted from the response (not an error)
- **AND** all other relation types MUST still be returned normally

#### Scenario: CalDAV/CardDAV always available
- **GIVEN** CalDAV and CardDAV are core Nextcloud services
- **WHEN** calendar event or contact relation endpoints are called
- **THEN** these MUST always work regardless of which apps are installed

