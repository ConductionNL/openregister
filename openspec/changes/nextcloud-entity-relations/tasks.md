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
- [ ] Implement DeckCardService (create/link/unlink Deck cards)
- [ ] Implement DeckController with REST endpoints
- [ ] Add deck routes to routes.php
- [ ] Implement board-level object listing
- [ ] Handle Deck app not installed (HTTP 501 graceful degradation)

## Unified Relations API
- [ ] Implement RelationsController with unified endpoint
- [ ] Support type filtering (?types=emails,contacts)
- [ ] Support timeline view (?view=timeline)

## Cleanup & Events
- [ ] Extend ObjectCleanupListener for email links cleanup
- [ ] Extend ObjectCleanupListener for calendar event unlinking
- [ ] Extend ObjectCleanupListener for contact links cleanup
- [ ] Extend ObjectCleanupListener for deck links cleanup
- [ ] Add CloudEvents for email.linked/unlinked
- [ ] Add CloudEvents for event.linked/unlinked/created
- [ ] Add CloudEvents for contact.linked/unlinked/created
- [ ] Add CloudEvents for deck.linked/unlinked/created
- [ ] Add audit trail entries for all relation mutations

## Service Registration
- [ ] Register new services in Application.php
- [ ] Register event listeners for cleanup

## Frontend
- [ ] Create EmailsTab.vue component for object detail
- [ ] Create EventsTab.vue component for object detail
- [ ] Create ContactsTab.vue component for object detail
- [ ] Create DeckTab.vue component for object detail
- [ ] Create RelationsTab.vue unified timeline component
- [ ] Add entity stores for email/event/contact/deck links

## Testing
- [ ] Unit tests for EmailService
- [ ] Unit tests for CalendarEventService
- [ ] Unit tests for ContactService
- [ ] Unit tests for DeckCardService
- [ ] Unit tests for RelationsController
- [ ] Integration tests with Greenmail (email linking)
- [ ] Integration tests with CalDAV (event creation)
- [ ] Integration tests with CardDAV (contact linking)
