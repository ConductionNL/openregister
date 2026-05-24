# Design — Retrofit contacts-actions

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

The existing `contacts-actions` spec covers the outbound ContactsMenu provider (matching contact entries to OpenRegister entities and decorating the contacts-menu popup). The cluster picked up by `/opsx-coverage-scan` describes a complementary inbound + management surface:

1. Per-object contact-link CRUD (`/api/objects/{register}/{schema}/{id}/contacts`)
2. Reverse lookup (`/api/contacts/{contactUid}/objects`)
3. Match-API enrichment (deep-link URLs + icons added to `/api/contacts/match` results)
4. Integration-registry adapter (`ContactsProvider`)
5. Frontend tab + store

This change adds five REQs to the existing capability, numbered REQ-010 through REQ-014 to leave room for the unnumbered legacy requirements (which conceptually map to REQ-001..REQ-009).

## Decisions

### Bias toward `--extend` over `--cluster`

The cluster is clearly within the same capability boundary as the existing spec — it is the "other side" of the same contact-OR relationship that the existing ContactsMenu requirements describe. Splitting into a new capability (e.g. `contacts-management`) would fragment review surface for the same domain.

### Observed-behavior fidelity, not aspirational

Two bugs are flagged in REQ-010 notes but **not silently fixed via the spec**:

- `ContactsController::destroy()` calls `ContactService::unlinkContact()` with two strings; the service signature takes one int. Runtime TypeError on every DELETE.
- `ContactsController::update()` returns HTTP 501 even though `ContactService::updateRole()` is implemented and wired through `ContactsProvider`.

These are described as observed behavior (controller returns 501, controller DELETE crashes) rather than rewritten to match what the controller *should* do. Fixing them is out of scope for this retrofit and SHOULD be filed as a separate issue.

### Dropped methods

- IntegrationProvider metadata getters (`getId`, `getLabel`, `getIcon`, `getGroup`, `getRequiredApp`, `getStorageStrategy`, `isEnabled`, `health`) — self-documenting interface-contract implementations. They are covered by REQ-013's metadata enumeration but do not warrant individual `@spec` annotations.
- `__construct` entries — DI plumbing only.
- The 6 entries already triaged as DROPs from sibling clusters (`hasContactLinkedSchemas`, `searchAndFilterByName`, `countMatchingNameParts`, `searchAndFilter`, `hasMatchingProperty`, `formatMatch`) — these are private helpers of the existing match flow already covered by the legacy `ContactMatchingService` requirements.
- `ContactsController::validateObject` is referenced from REQ-010 prose but not separately annotated — it is a private helper without independent observable behavior.

## Annotation map

| REQ | task | files / methods to annotate |
|-----|------|-----------------------------|
| REQ-010 | task-1 | `ContactsController::{index,create,update,destroy}`, `ContactService::{getContactsForObject,linkContact,createAndLinkContact,updateRole,unlinkContact,deleteLinksForObject,findUserAddressbook}` |
| REQ-011 | task-2 | `ContactsController::objects`, `ContactService::getObjectsForContact` |
| REQ-012 | task-3 | `ContactsController::{match,enrichMatches}`, `ContactMatchingService::{getRelatedObjectCounts,invalidateCache,invalidateCacheForObject}` |
| REQ-013 | task-4 | `ContactsProvider::{list,update,delete}` |
| REQ-014 | task-5 | `ContactsTab.vue::fetchContacts`, `contacts.js::useContactRelationsStore` |

## Risks

- The fix for the two flagged bugs may need a follow-up spec amendment to clarify intent vs. observed behavior once landed. The notes sections in REQ-010 are written so a future amendment can simply remove them when the bugs are gone.
