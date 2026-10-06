---
kind: code
---

## Why

OpenRegister's relation endpoints return related records (leaf items like notes/tasks/emails/events/contacts/deck, plus related objects via `/uses`, `/used`, `/contracts`) but none of them carry a canonical URL to open the item in the UI. The nc-vue `CnRelatedObjectsWidget` therefore has to guess where each related item lives, duplicating open-URL logic per consuming app. The server already knows the authoritative location: object URLs are resolved by the shared `DeepLinkRegistryService` (the same resolver unified search uses), and each leaf's owning Nextcloud app exposes a deep-link to the specific item. Stamping a `url` on every related record removes the frontend guessing and keeps a single source of truth.

## What Changes

- Every related record returned by the relation endpoints SHALL carry an optional `url` field — the canonical deep-link to open that item in the UI:
  - `GET /api/objects/{register}/{schema}/{id}/relations` — leaf groups: `notes`, `tasks`, `emails`, `events`, `contacts`, `deck`.
  - `GET /api/objects/{register}/{schema}/{id}/uses`, `/used`, `/contracts` — related objects.
- **Related OBJECTS** (`/uses`, `/used`, `/contracts`): `url` is built by REUSING the existing `OCA\OpenRegister\Service\DeepLinkRegistryService::resolveUrl(registerId, schemaId, objectData)` — the same per-(register,schema) resolver `lib/Search/ObjectsProvider.php` uses. When no registration matches, fall back to OpenRegister's own object route (`openregister.objects.show`), mirroring `ObjectsProvider`. No new object-URL logic is added.
- **LEAF records** (`contacts`, `events`, `tasks`, `deck`): `url` is built server-side inside each leaf service as the owning Nextcloud app's deep-link to the specific item, resolving the URI-based identifiers (addressbook URI, calendar URI, task URI) the records do not expose today.
- **Files**: reuse the existing `accessUrl`/permalink — do not rebuild.
- **Notes** (NC Comments): no standalone app page → leave `url` unset; the widget renders the item non-navigating.
- Consumer contract (informational, NO change in this spec): nc-vue `CnRelatedObjectsWidget.resolveItemHref` already prefers `raw.url || raw.link || raw.accessUrl`, so once OR stamps `url` the widget deep-links with zero frontend change and its per-app URL guessing becomes a fallback.

## Capabilities

### New Capabilities
- `relation-resource-urls`: Cross-cutting contract that every related record returned by OpenRegister's relation endpoints (`/relations` leaf groups + `/uses`, `/used`, `/contracts` objects) carries a `url` deep-link, built by reusing `DeepLinkRegistryService` for objects and the owning app's deep-link inside each leaf service.

### Modified Capabilities
<!-- None: the relation response shape is gaining a new optional field; no existing capability's requirements change. The behavior is owned by the new relation-resource-urls capability. -->

## Impact

- **Code (PHP only, `kind: code`)**:
  - `lib/Service/Object/RelationHandler.php` (`getUses()`, `getUsedBy()`, `getContracts()`) and/or `lib/Controller/RelationsController.php` (`gatherRelations()`) — stamp `url` on related objects via `DeepLinkRegistryService` (+ fallback route).
  - `lib/Service/ContactService.php` (`getContactsForObject`), `lib/Service/CalendarEventService.php`, `lib/Service/TaskService.php`, `lib/Service/DeckCardService.php` — build the leaf-specific `url`.
- **APIs**: relation endpoint responses gain an optional `url` field per record (additive, non-breaking).
- **Dependencies**: reuses `DeepLinkRegistryService` and the CardDAV/CalDAV backends already injected into the leaf services; no new schema, register config, or seed data.
- **Consumers**: nc-vue `CnRelatedObjectsWidget` benefits automatically (no change required here).
