# Design: Nextcloud Entity Relations

## Approach

Extend the established object-interactions pattern (NoteService wraps ICommentsManager, TaskService wraps CalDavBackend) to four new entity types. Each integration follows the same layered architecture:

```
Controller (REST API) → Service (NC API wrapper) → Nextcloud Subsystem
                      → Link Table (for email/contact/deck lookups)
                      → ObjectCleanupListener (cascade on delete)
                      → Event Dispatcher (CloudEvents)
```

## Architecture Decisions

### AD-1: Relation Tables vs. Custom Properties Only

**Decision**: Use dual storage for emails, contacts, and deck cards — a relation table AND (where applicable) custom properties on the NC entity.

**Why**: CalDAV/CardDAV custom properties (`X-OPENREGISTER-*`) enable discovery from the NC entity side, but querying "all emails for object X" across IMAP is not feasible. Relation tables provide O(1) lookups by object UUID. The existing TaskService uses only CalDAV properties because CalDavBackend supports searching by custom property; Mail and Deck do not.

**Trade-off**: Extra migration, extra cleanup logic. Worth it for query performance.

### AD-2: Emails Are Link-Only (No Send/Compose)

**Decision**: EmailService only links existing Mail messages to objects. Sending email is out of scope (handled by n8n workflows).

**Why**: The Mail app owns the SMTP pipeline. Duplicating send logic would create maintenance burden and divergent behavior. n8n workflows already handle automated notifications.

### AD-3: Calendar Events Unlink (Don't Delete) on Object Deletion

**Decision**: When an object is deleted, linked VEVENTs have their X-OPENREGISTER-* properties removed but are NOT deleted.

**Why**: Calendar events may involve external participants. Deleting a meeting because a case object was deleted would be surprising and potentially disruptive.

### AD-4: Contact Role as First-Class Field

**Decision**: Each contact-object link has a `role` field (e.g., "applicant", "handler", "advisor").

**Why**: The same contact may be linked to multiple objects in different capacities. Role enables filtering ("show me all cases where Jan is the applicant") and display ("Applicant: Jan de Vries").

### AD-5: Deck Integration via OCA\Deck\Service Classes

**Decision**: Use Deck's internal PHP service classes (`CardService`, `BoardService`, `StackService`) rather than the OCS REST API.

**Why**: Same-server PHP calls avoid HTTP overhead and authentication complexity. Deck services are injectable via DI when the app is installed.

## Files Affected

### New Files (Backend)

| File | Purpose |
|------|---------|
| `lib/Service/EmailService.php` | Wraps Mail message lookups, manages `openregister_email_links` |
| `lib/Service/CalendarEventService.php` | Wraps CalDAV VEVENT operations, mirrors TaskService pattern |
| `lib/Service/ContactService.php` | Wraps CardDAV vCard operations, manages `openregister_contact_links` |
| `lib/Service/DeckCardService.php` | Wraps Deck card operations, manages `openregister_deck_links` |
| `lib/Controller/EmailsController.php` | REST endpoints for email relations |
| `lib/Controller/CalendarEventsController.php` | REST endpoints for calendar event relations |
| `lib/Controller/ContactsController.php` | REST endpoints for contact relations |
| `lib/Controller/DeckController.php` | REST endpoints for deck card relations |
| `lib/Controller/RelationsController.php` | Unified relations endpoint |
| `lib/Db/EmailLink.php` | Entity for `openregister_email_links` |
| `lib/Db/EmailLinkMapper.php` | Mapper for email links |
| `lib/Db/ContactLink.php` | Entity for `openregister_contact_links` |
| `lib/Db/ContactLinkMapper.php` | Mapper for contact links |
| `lib/Db/DeckLink.php` | Entity for `openregister_deck_links` |
| `lib/Db/DeckLinkMapper.php` | Mapper for deck links |
| `lib/Migration/VersionXDateYYYY_entity_relations.php` | Database migration for 3 link tables |

### Modified Files (Backend)

| File | Change |
|------|--------|
| `appinfo/routes.php` | Add routes for emails, events, contacts, deck, relations |
| `lib/Listener/ObjectCleanupListener.php` | Extend with cleanup for 4 new entity types |
| `lib/AppInfo/Application.php` | Register new services and event listeners |

### New Files (Frontend)

| File | Purpose |
|------|---------|
| `src/entities/emailLink/` | Store, entity definition, API calls |
| `src/entities/calendarEvent/` | Store, entity definition, API calls |
| `src/entities/contactLink/` | Store, entity definition, API calls |
| `src/entities/deckLink/` | Store, entity definition, API calls |
| `src/views/objects/tabs/EmailsTab.vue` | Email relations tab on object detail |
| `src/views/objects/tabs/EventsTab.vue` | Calendar events tab |
| `src/views/objects/tabs/ContactsTab.vue` | Contacts tab |
| `src/views/objects/tabs/DeckTab.vue` | Deck cards tab |
| `src/views/objects/tabs/RelationsTab.vue` | Unified timeline view |

## API Routes (to add to routes.php)

```php
// Email relations
['name' => 'emails#index',   'url' => '/api/objects/{register}/{schema}/{id}/emails',            'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
['name' => 'emails#create',  'url' => '/api/objects/{register}/{schema}/{id}/emails',            'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
['name' => 'emails#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/emails/{emailId}',  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'emailId' => '\d+']],
['name' => 'emails#search',  'url' => '/api/emails/search',                                      'verb' => 'GET'],

// Calendar event relations
['name' => 'calendarEvents#index',   'url' => '/api/objects/{register}/{schema}/{id}/events',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
['name' => 'calendarEvents#create',  'url' => '/api/objects/{register}/{schema}/{id}/events',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
['name' => 'calendarEvents#link',    'url' => '/api/objects/{register}/{schema}/{id}/events/link',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
['name' => 'calendarEvents#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/events/{eventId}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'eventId' => '[^/]+']],

// Contact relations
['name' => 'contacts#index',   'url' => '/api/objects/{register}/{schema}/{id}/contacts',                'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
['name' => 'contacts#create',  'url' => '/api/objects/{register}/{schema}/{id}/contacts',                'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
['name' => 'contacts#update',  'url' => '/api/objects/{register}/{schema}/{id}/contacts/{contactId}',    'verb' => 'PUT',    'requirements' => ['id' => '[^/]+', 'contactId' => '\d+']],
['name' => 'contacts#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/contacts/{contactId}',    'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'contactId' => '\d+']],
['name' => 'contacts#objects', 'url' => '/api/contacts/{contactUid}/objects',                             'verb' => 'GET',    'requirements' => ['contactUid' => '[^/]+']],

// Deck card relations
['name' => 'deck#index',   'url' => '/api/objects/{register}/{schema}/{id}/deck',            'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
['name' => 'deck#create',  'url' => '/api/objects/{register}/{schema}/{id}/deck',            'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
['name' => 'deck#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/deck/{deckId}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'deckId' => '\d+']],
['name' => 'deck#objects', 'url' => '/api/deck/boards/{boardId}/objects',                     'verb' => 'GET',    'requirements' => ['boardId' => '\d+']],

// Unified relations
['name' => 'relations#index', 'url' => '/api/objects/{register}/{schema}/{id}/relations', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
```

## Database Migration

Three new tables:

```sql
-- Email links (Mail message → Object)
CREATE TABLE openregister_email_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    object_uuid VARCHAR(36) NOT NULL,
    register_id INT NOT NULL,
    mail_account_id INT NOT NULL,
    mail_message_id INT NOT NULL,
    mail_message_uid VARCHAR(255),
    subject VARCHAR(512),
    sender VARCHAR(255),
    date DATETIME,
    linked_by VARCHAR(64) NOT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_email_object (object_uuid, mail_message_id),
    INDEX idx_email_object_uuid (object_uuid),
    INDEX idx_email_sender (sender)
);

-- Contact links (vCard → Object)
CREATE TABLE openregister_contact_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    object_uuid VARCHAR(36) NOT NULL,
    register_id INT NOT NULL,
    contact_uid VARCHAR(255) NOT NULL,
    addressbook_id INT NOT NULL,
    contact_uri VARCHAR(512) NOT NULL,
    display_name VARCHAR(255),
    email VARCHAR(255),
    role VARCHAR(64),
    linked_by VARCHAR(64) NOT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contact_object (object_uuid),
    INDEX idx_contact_uid (contact_uid),
    INDEX idx_contact_role (role)
);

-- Deck links (Deck card → Object)
CREATE TABLE openregister_deck_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    object_uuid VARCHAR(36) NOT NULL,
    register_id INT NOT NULL,
    board_id INT NOT NULL,
    stack_id INT NOT NULL,
    card_id INT NOT NULL,
    card_title VARCHAR(255),
    linked_by VARCHAR(64) NOT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_deck_object_card (object_uuid, card_id),
    INDEX idx_deck_object (object_uuid),
    INDEX idx_deck_board (board_id)
);
```

Note: Calendar events use CalDAV properties only (same as tasks) — no separate table needed.

## Service Dependency Map

```
EmailService
├── Mail\Db\MessageMapper (read mail messages)
├── EmailLinkMapper (manage link table)
├── IUserSession
└── LoggerInterface

CalendarEventService
├── CalDavBackend (same as TaskService)
├── IUserSession
└── LoggerInterface

ContactService
├── CalDavBackend (CardDAV shares the DAV backend)
├── ContactLinkMapper (manage link table)
├── IUserSession
└── LoggerInterface

DeckCardService
├── OCA\Deck\Service\CardService (when Deck installed)
├── OCA\Deck\Service\StackService
├── DeckLinkMapper (manage link table)
├── IAppManager (check if Deck is installed)
├── IUserSession
└── LoggerInterface
```
