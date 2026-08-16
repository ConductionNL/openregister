## 1. EmailService Refactoring

- [x] 1.1 Remove `EmailLinkMapper` dependency from `EmailService` constructor, add `MagicMapper` and `LinkedEntityService`
- [x] 1.2 Refactor `linkEmail()` — load object via MagicMapper, append `"accountId/messageId"` to `_mail` array, persist. Keep duplicate check (search array). Keep Mail DB metadata fetch for return value
- [x] 1.3 Refactor `unlinkEmail()` — change signature from `(int $linkId)` to `(string $objectUuid, string $mailRef)`. Load object, remove mailRef from `_mail` array, persist
- [x] 1.4 Refactor `getEmailsForObject()` — read `_mail` array from object, enrich each ID via `fetchMailMessage()` (Mail DB query stays), return enriched array with pagination
- [x] 1.5 Refactor `searchBySender()` — use `LinkedEntityService::reverseLookup('mail', ...)` or iterate objects, enrich mail IDs, filter by sender
- [x] 1.6 Refactor `deleteLinksForObject()` — load object, set `_mail` to null, persist

## 2. ContactService Refactoring

- [x] 2.1 Remove `ContactLinkMapper` dependency from `ContactService` constructor, add `MagicMapper` and `LinkedEntityService`
- [x] 2.2 Refactor `linkContact()` — append contact UID to `_contacts` array + keep vCard X-OPENREGISTER-\* sync via CardDAV
- [x] 2.3 Refactor `createAndLinkContact()` — create contact via CardDAV, append UID to `_contacts` array, persist
- [x] 2.4 Refactor `unlinkContact()` — change signature from `(int $linkId)` to `(string $objectUuid, string $contactUid)`. Remove from `_contacts` array + clean vCard properties
- [x] 2.5 Refactor `getContactsForObject()` — read `_contacts` array, enrich each UID from CardDAV
- [x] 2.6 Refactor `getObjectsForContact()` — delegate to `LinkedEntityService::reverseLookup('contacts', contactUid)`
- [x] 2.7 Refactor `deleteLinksForObject()` — iterate `_contacts` to clean vCard properties, set `_contacts` to null, persist

## 3. DeckCardService Refactoring

- [x] 3.1 Remove `DeckLinkMapper` dependency from `DeckCardService` constructor, add `MagicMapper` and `LinkedEntityService`
- [x] 3.2 Refactor `linkOrCreateCard()` — append `"boardId/cardId"` to `_deck` array. Keep Deck card creation logic
- [x] 3.3 Refactor `unlinkCard()` — change signature from `(int $linkId)` to `(string $objectUuid, string $deckRef)`. Remove from `_deck` array, persist
- [x] 3.4 Refactor `getCardsForObject()` — read `_deck` array, enrich each from Deck DB
- [x] 3.5 Refactor `getObjectsForBoard()` — delegate to `LinkedEntityService::reverseLookup('deck', ...)` and filter by boardId prefix
- [x] 3.6 Refactor `deleteLinksForObject()` — set `_deck` to null, persist

## 4. Controller and Route Updates

- [x] 4.1 Update `EmailsController` — removed old link-based routes (byMessage, quickLink, deleteLink)
- [x] 4.2 Update `ContactsController` — change `destroy()` and `update()` to accept contactUid instead of contactId (numeric). Update route params
- [x] 4.3 Update `DeckController` — change `destroy()` to accept deckRef instead of deckId (numeric). Update route params
- [x] 4.4 Update `appinfo/routes.php` — change DELETE route patterns from numeric to string entity references

## 5. Remove Link Entities and Mappers

- [x] 5.1 Remove `lib/Db/EmailLink.php` and `lib/Db/EmailLinkMapper.php`
- [x] 5.2 Remove `lib/Db/ContactLink.php` and `lib/Db/ContactLinkMapper.php`
- [x] 5.3 Remove `lib/Db/DeckLink.php` and `lib/Db/DeckLinkMapper.php`
- [x] 5.4 Verify no remaining references to removed classes — grep codebase for EmailLink, ContactLink, DeckLink class names

## 6. Integration Verification

- [x] 6.1 Verify `ObjectCleanupListener` still works — calls `deleteLinksForObject()` on each service (interface unchanged)
- [x] 6.2 Verify `RelationsController` still works — calls service getters (return format unchanged)
- [x] 6.3 Verify mail sidebar frontend uses correct API (already migrated to generic endpoints)
- [x] 6.4 PHP syntax check all modified files
