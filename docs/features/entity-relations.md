# Nextcloud Entity Relations

## Standards

- **GEMMA Zaakrelatie** -- Dutch government standard for case-entity relationships
- **CalDAV (RFC 4791)** -- Calendar event creation and linking via VEVENT
- **CardDAV (RFC 6352)** -- Contact management via vCard
- **RFC 9253 (LINK)** -- Resource linking semantics

## Overview

Links register objects to native Nextcloud entities -- emails, calendar events, contacts, and Deck cards. Each entity type has a dedicated service layer and controller. A unified `RelationsController` aggregates all relation types for a single object into one response.

## Key Capabilities

- **EmailService** -- Links register objects to Nextcloud Mail messages. Supports lookup by message ID (`byMessage`), by sender address (`bySender`), quick-link creation (`quickLink`), and link deletion. Routes are registered and operational.
- **CalendarEventService** -- Creates and manages CalDAV VEVENT entries linked to register objects. Supports listing events for an object (`index`), creating new events (`create`), linking existing events (`link`), and unlinking (`destroy`). Events include `X-OPENREGISTER` custom properties for back-references.
- **ContactService** -- Manages CardDAV contacts linked to register objects. Full CRUD (index, create, update, destroy) plus reverse lookup (`objects` -- find all objects linked to a contact UID) and automatic matching (`match` -- find contacts matching object data). Uses both vCard storage and database link records.
- **DeckCardService** -- Links register objects to Nextcloud Deck cards. Supports listing linked cards (`index`), creating new cards (`create`), unlinking (`destroy`), and reverse lookup (`objects` -- find objects linked to a Deck board).
- **RelationsController** -- Unified endpoint that aggregates all relation types (emails, calendar events, contacts, deck cards, tasks, notes, files) for a given object into a single response.

## Route Registration Status

| Controller | Methods | Routes Registered |
|------------|---------|-------------------|
| `EmailsController` | `byMessage`, `bySender`, `quickLink`, `deleteLink` | Yes (lines 28-31) |
| `CalendarEventsController` | `index`, `create`, `link`, `destroy` | No |
| `ContactsController` | `index`, `create`, `update`, `destroy`, `objects`, `match` | No |
| `DeckController` | `index`, `create`, `destroy`, `objects` | No |
| `RelationsController` | `index` | No |

Only the email endpoints are currently routed. The calendar, contacts, deck, and unified relations controllers have full implementations but no route definitions in `appinfo/routes.php`. These controllers are not accessible via HTTP until routes are added.

## Expected URL Patterns (from controller signatures)

| Method | Expected URL | Controller |
|--------|-------------|------------|
| GET | `/api/emails/by-message/{accountId}/{messageId}` | `emails#byMessage` |
| GET | `/api/emails/by-sender` | `emails#bySender` |
| POST | `/api/emails/quick-link` | `emails#quickLink` |
| DELETE | `/api/emails/{linkId}` | `emails#deleteLink` |
| GET | `/api/objects/{register}/{schema}/{id}/calendar-events` | `calendarEvents#index` |
| POST | `/api/objects/{register}/{schema}/{id}/calendar-events` | `calendarEvents#create` |
| POST | `/api/objects/{register}/{schema}/{id}/calendar-events/link` | `calendarEvents#link` |
| DELETE | `/api/objects/{register}/{schema}/{id}/calendar-events/{eventId}` | `calendarEvents#destroy` |
| GET | `/api/objects/{register}/{schema}/{id}/contacts` | `contacts#index` |
| POST | `/api/objects/{register}/{schema}/{id}/contacts` | `contacts#create` |
| PUT | `/api/objects/{register}/{schema}/{id}/contacts/{contactId}` | `contacts#update` |
| DELETE | `/api/objects/{register}/{schema}/{id}/contacts/{contactId}` | `contacts#destroy` |
| GET | `/api/contacts/{contactUid}/objects` | `contacts#objects` |
| POST | `/api/contacts/match` | `contacts#match` |
| GET | `/api/objects/{register}/{schema}/{id}/deck` | `deck#index` |
| POST | `/api/objects/{register}/{schema}/{id}/deck` | `deck#create` |
| DELETE | `/api/objects/{register}/{schema}/{id}/deck/{deckId}` | `deck#destroy` |
| GET | `/api/deck/{boardId}/objects` | `deck#objects` |
| GET | `/api/objects/{register}/{schema}/{id}/relations` | `relations#index` |

## Related Files

- `/lib/Controller/EmailsController.php` -- Email link controller (routes active)
- `/lib/Controller/CalendarEventsController.php` -- Calendar event controller (no routes)
- `/lib/Controller/ContactsController.php` -- Contact controller (no routes)
- `/lib/Controller/DeckController.php` -- Deck card controller (no routes)
- `/lib/Controller/RelationsController.php` -- Unified relations controller (no routes)
- `/lib/Service/EmailService.php` -- Email linking service
- `/lib/Service/CalendarEventService.php` -- CalDAV VEVENT service
- `/lib/Service/ContactService.php` -- CardDAV vCard service
- `/lib/Service/ContactMatchingService.php` -- Automatic contact matching
- `/lib/Service/DeckCardService.php` -- Deck card linking service
- `/appinfo/routes.php` -- Route definitions (only email routes at lines 27-31)
