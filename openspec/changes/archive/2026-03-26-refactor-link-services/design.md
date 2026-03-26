## Context

The `linked-entity-types` change added `_mail`, `_contacts`, `_deck` (and others) as JSON metadata columns on object and entity tables. It also created `LinkedEntityService` for generic ad-hoc linking/unlinking and `LinkedEntityEnricher` for read-time hydration. However, three specialized services still use dedicated link tables with per-type mappers — creating a dual storage system.

The link tables cache metadata (email subjects, contact display names, deck card titles) that already lives in the source Nextcloud apps. This cache goes stale and is redundant now that `LinkedEntityEnricher` can fetch fresh data at read time.

## Goals / Non-Goals

**Goals:**
- Remove all link table dependencies from EmailService, ContactService, DeckCardService
- Use `_mail`, `_contacts`, `_deck` metadata columns as the single storage for relationships
- Keep app integration logic (Mail DB queries, CardDAV vCard sync, Deck card creation)
- Use `LinkedEntityService.reverseLookup()` for cross-table reverse queries
- Use `LinkedEntityEnricher` for read-time metadata hydration
- Remove 6 entity/mapper files (EmailLink, ContactLink, DeckLink + their mappers)
- Update controllers and routes for new method signatures

**Non-Goals:**
- Changing the ID format (already defined by linked-entity-types spec)
- Adding new features to these services
- Refactoring CalendarEventService (it doesn't use a link table)
- Changing the RelationsController aggregation pattern

## Decisions

### Decision 1: Services load objects via MagicMapper
**Choice**: Each service receives `MagicMapper` (already injected in many places) to load objects, read/write `_` columns, and persist.
**Why**: `LinkedEntityService` also uses `MagicMapper` but encapsulates it behind add/remove methods. The specialized services need direct access because they do more than just link — they enrich, create external entities, and sync state.

### Decision 2: Enrichment at read time, not storage time
**Choice**: `getEmailsForObject()`, `getContactsForObject()`, `getCardsForObject()` read the `_` column (IDs only), then enrich each ID by querying the source app.
**Why**: Eliminates stale cache. The source app is always authoritative. Performance is acceptable because these are detail-view calls, not list calls.
**Trade-off**: Slightly slower reads (external queries per ID). Mitigated by the fact that ID arrays are typically small (< 20 items).

### Decision 3: Unlink by entity reference, not link ID
**Choice**: Change `unlinkEmail(int $linkId)` to `unlinkEmail(string $objectUuid, string $mailRef)` where mailRef is the ID format string (e.g., "1/6").
**Why**: Link table row IDs no longer exist. The entity reference is the natural identifier.
**Impact**: API breaking change. Controllers and routes must update.

### Decision 4: Reverse lookups delegate to LinkedEntityService
**Choice**: `searchBySender()` → call `LinkedEntityService.reverseLookup('mail', ...)` then filter. `getObjectsForContact()` → `reverseLookup('contacts', contactUid)`. `getObjectsForBoard()` → iterate deck IDs matching boardId prefix.
**Why**: One cross-table scan implementation, not three. Already handles circuit breakers and multi-tenancy.

### Decision 5: ContactService keeps vCard sync as secondary write
**Choice**: When linking/unlinking contacts, the service writes to both `_contacts` column AND vCard X-OPENREGISTER-\* properties.
**Why**: vCard properties allow the Contacts app to display the relationship from its side. The `_contacts` column is the primary store for OpenRegister; vCard properties are the secondary notification to the Contacts app.

## Risks / Trade-offs

**[Risk] Read-time enrichment latency** — Fetching email subjects or contact names from source app DBs adds latency.
→ Mitigation: Arrays are small. Can add in-memory caching within request scope if needed.

**[Risk] API breaking change** — External consumers using numeric linkId parameters will break.
→ Mitigation: These APIs are internal to OpenRegister sidebars. No known external consumers. Document the change.

**[Risk] searchBySender becomes expensive** — Without a dedicated sender column, we need to: reverseLookup all objects with _mail, then for each mail ID, query Mail DB for sender, then filter.
→ Mitigation: Accept performance hit for now. This is a sidebar suggestion feature, not a critical path. Can add sender caching later if needed.

## Seed Data

No new schemas introduced. Existing seed data with `linkedTypes` configuration from the linked-entity-types change is sufficient for testing.

## Open Questions

None — the design follows directly from the linked-entity-types architecture decisions.
