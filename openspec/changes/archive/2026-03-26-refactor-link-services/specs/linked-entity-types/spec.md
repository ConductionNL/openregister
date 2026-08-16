## ADDED Requirements

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
