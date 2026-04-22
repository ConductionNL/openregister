## Why

The `linked-entity-types` change introduced generic `_mail`, `_contacts`, `_deck` metadata columns on object and entity tables, with a `LinkedEntityService` for ad-hoc linking and reverse lookups. However, the three specialized services (EmailService, ContactService, DeckCardService) still use their own dedicated link tables (`email_links`, `contact_links`, `deck_links`) with per-type mappers and entities. This creates two parallel systems storing the same relationships — the link tables are redundant caches of data that lives in the source apps (Mail, Contacts, Deck) and can be fetched at read time via the `LinkedEntityEnricher`. The link tables must go, and the services must be refactored to use the `_` metadata columns as their storage layer.

## What Changes

- **EmailService**: Remove `EmailLinkMapper` dependency. Read/write `_mail` column on `ObjectEntity` via `MagicMapper`. Keep Mail app DB queries for enrichment. Change `unlinkEmail(linkId)` to `unlinkEmail(objectUuid, mailRef)`.
- **ContactService**: Remove `ContactLinkMapper` dependency. Read/write `_contacts` column. Keep CardDAV vCard sync (X-OPENREGISTER-\* properties). Change `unlinkContact(linkId)` to `unlinkContact(objectUuid, contactUid)`.
- **DeckCardService**: Remove `DeckLinkMapper` dependency. Read/write `_deck` column. Keep Deck card creation. Change `unlinkCard(linkId)` to `unlinkCard(objectUuid, deckRef)`.
- **Controllers**: Update `EmailsController`, `ContactsController`, `DeckController` to use new method signatures (objectUuid + entityRef instead of linkId).
- **Routes**: Update URL patterns for unlink/delete endpoints — `DELETE /api/objects/{register}/{schema}/{id}/contacts/{contactUid}` instead of `/{contactId}` (numeric link ID).
- **Reverse lookups**: `getObjectsForContact()`, `getObjectsForBoard()`, `searchBySender()` → delegate to `LinkedEntityService.reverseLookup()` with filtering.
- **ObjectCleanupListener**: No interface changes — services keep `deleteLinksForObject()` but implementation changes internally.
- **RelationsController**: No changes — calls service getters which keep same return format.
- **Remove**: `EmailLink.php`, `EmailLinkMapper.php`, `ContactLink.php`, `ContactLinkMapper.php`, `DeckLink.php`, `DeckLinkMapper.php`.
- **Migration**: `Version1Date20260326100001` already drops the three link tables — no new migration needed.

## Capabilities

### New Capabilities
_(none — this is a refactoring of existing capabilities)_

### Modified Capabilities
- `linked-entity-types`: The specialized services now use the `_` metadata columns as their storage layer instead of dedicated link tables. The generic `LinkedEntityService` is used for reverse lookups. Enrichment at read time replaces cached metadata in link tables.

## Impact

- **PHP Backend**: 3 services refactored (EmailService, ContactService, DeckCardService), 3 controllers updated, 6 entity/mapper files removed.
- **API**: **BREAKING** — unlink/delete endpoints change from numeric linkId to entity reference string. Affects: `DELETE /api/emails/{linkId}`, `DELETE .../contacts/{contactId}`, `DELETE .../deck/{deckId}`.
- **Frontend**: Mail sidebar already uses generic API. Other sidebar code (if any) needs updating for new delete signatures.
- **Database**: Link tables dropped (migration already exists).
- **Dependent apps**: Any external code calling the old unlink endpoints with numeric IDs will break.
