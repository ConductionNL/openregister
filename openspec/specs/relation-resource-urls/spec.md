---
status: in-progress
---
# Relation Resource URLs

## Purpose

@e2e exclude backend relation URL stamping — covered by PHPUnit

Defines the cross-cutting contract that every related record returned by OpenRegister's relation endpoints carries a `url` deep-link to where the item actually lives in the UI. This covers both the leaf relation groups of `GET /api/objects/{register}/{schema}/{id}/relations` (notes, tasks, emails, events, contacts, deck) and the related objects of `GET /api/objects/{register}/{schema}/{id}/uses`, `/used`, and `/contracts`. Related-object URLs are resolved by reusing the existing `DeepLinkRegistryService` (the same resolver unified search uses), with a fallback to OpenRegister's own object route; leaf URLs are built server-side inside each owning leaf service as the owning Nextcloud app's deep-link to the specific item. The `url` field is the documented consumer contract for the nc-vue `CnRelatedObjectsWidget`.

**Status**: in-progress

**OpenSpec changes**
- `relation-resourceurl-deeplinks` (in-progress) — adds the `url` deep-link field to every related record: reuses `DeepLinkRegistryService::resolveUrl()` (+ `openregister.objects.show` fallback) for `/uses`/`/used`/`/contracts` objects, and builds the owning app's deep-link inside `ContactService`/`CalendarEventService`/`TaskService`/`DeckCardService` for leaves; files reuse `accessUrl`, notes stay unset; documents the `CnRelatedObjectsWidget` consumer contract.

## Requirements

### Requirement: Relation records carry a deep-link URL

Related records returned by OpenRegister's relation endpoints SHALL carry an optional `url` field — the canonical deep-link to open that item in the UI. For related objects (`/uses`, `/used`, `/contracts`) the `url` MUST be produced by reusing `DeepLinkRegistryService::resolveUrl()` with a fallback to the `openregister.objects.show` route, with no duplication of object-URL logic. For leaf records (`/relations` groups contacts, events, tasks, deck) the `url` MUST be the owning Nextcloud app's deep-link to the specific item, built inside the leaf service; files reuse their existing `accessUrl` and notes leave `url` unset. URL resolution SHALL be defensive and never alter which records are returned. The full normative behaviour is defined by the `relation-resourceurl-deeplinks` change delta.

#### Scenario: Related record exposes its UI deep-link

- **GIVEN** an object with related items returned by a relation endpoint
- **WHEN** the endpoint serializes each related record
- **THEN** each record carries a `url` deep-link where resolvable (registered app template or `openregister.objects.show` for objects; owning-app deep-link for leaves), and omits `url` where it cannot be resolved
- @e2e exclude backend serialization — asserted by PHPUnit
