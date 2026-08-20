# Tasks: Nextcloud Entity Relations

## Database & Infrastructure
- [x] Create database migration for openregister_email_links, openregister_contact_links, openregister_deck_links tables
- [x] Create EmailLink entity and EmailLinkMapper
- [x] Create ContactLink entity and ContactLinkMapper
- [x] Create DeckLink entity and DeckLinkMapper

## Email Relations
- [x] Implement EmailService (link/unlink/list emails, verify mail message exists)
- [x] Implement EmailsController with REST endpoints
- [x] Add email routes to routes.php
- [x] Add email search endpoint (find objects by sender)
- [x] Handle Mail app not installed (HTTP 501 graceful degradation)

## Calendar Event Relations
- [x] Implement CalendarEventService (create/link/unlink VEVENT with X-OPENREGISTER-* properties)
- [x] Implement CalendarEventsController with REST endpoints
- [x] Add calendar event routes to routes.php
- [x] Implement calendar selection (find first VEVENT-supporting calendar)
- [x] Handle attendees in VEVENT creation

## Contact Relations
- [x] Implement ContactService (link/create/unlink vCard contacts with X-OPENREGISTER-* properties)
- [x] Implement ContactsController with REST endpoints
- [x] Add contact routes to routes.php
- [x] Implement role management on contact-object links
- [x] Implement reverse lookup (find objects for a contact)
- [x] Handle dual storage (vCard properties + database table)

## Deck Card Relations
- [x] Implement DeckCardService (create/link/unlink Deck cards)
- [x] Implement DeckController with REST endpoints
- [x] Add deck routes to routes.php
- [x] Implement board-level object listing
- [x] Handle Deck app not installed (HTTP 501 graceful degradation)

## Unified Relations API
- [x] Implement RelationsController with unified endpoint
- [x] Support type filtering (?types=emails,contacts)
- [x] Support timeline view (?view=timeline)

## Cleanup & Events
- [x] Extend ObjectCleanupListener for email links cleanup
- [x] Extend ObjectCleanupListener for calendar event unlinking
- [x] Extend ObjectCleanupListener for contact links cleanup
- [x] Extend ObjectCleanupListener for deck links cleanup
- [x] Add CloudEvents for email.linked/unlinked
- [x] Add CloudEvents for event.linked/unlinked/created
- [x] Add CloudEvents for contact.linked/unlinked/created
- [x] Add CloudEvents for deck.linked/unlinked/created
- [x] Add audit trail entries for all relation mutations

## Service Registration
- [x] Register new services in Application.php
- [x] Register event listeners for cleanup

## Frontend
- [x] Create EmailsTab.vue component for object detail (`src/components/object-relations/EmailsTab.vue`)
- [x] Create EventsTab.vue component for object detail (`src/components/object-relations/EventsTab.vue`)
- [x] Create ContactsTab.vue component for object detail (`src/components/object-relations/ContactsTab.vue`)
- [x] Create DeckTab.vue component for object detail (`src/components/object-relations/DeckTab.vue`)
- [x] Create RelationsTab.vue unified timeline component (`src/components/object-relations/RelationsTab.vue`)
- [x] Add entity stores for email/event/contact/deck links (`src/store/modules/object-relations/{emails,events,contacts,deck}.js`)

## Routing fix (gate-14 route-reachability)
- [x] Route the schema-level sticky default board endpoints `deckLinks#getDefault` (GET) and `deckLinks#setDefault` (PUT) at `/api/integrations/deck/default/{schema}`; both were implemented but unrouted (dead code). Added `@no-admin-idor-exempt` annotations (schema-level config, no per-object resource to scope).

## Testing
- [x] Unit tests for EmailService
- [x] Unit tests for CalendarEventService
- [x] Unit tests for ContactService
- [x] Unit tests for DeckCardService
- [x] Unit tests for RelationsController
- [x] Integration tests with Greenmail (email linking) — `tests/Service/GreenmailSmtpIntegrationTest.php`
- [x] Integration tests with CalDAV (event creation) — `tests/Service/CalDavIntegrationTest.php`
- [x] Integration tests with CardDAV (contact linking) — `tests/Service/CardDavIntegrationTest.php`
