---
kind: code
---

## Why

OpenRegister's Smart Picker and unified-search integration is a single, fleet-wide provider pair (`ObjectsProvider` / `ObjectReferenceProvider`) covering *all* objects under one generic "Register Objects" entry. Consuming apps (Pipelinq, Procest, etc.) cannot make an individual schema — e.g. Pipelinq's "Lead" — appear as its own entry in the Smart Picker's "Select provider" list, the way Files, Talk conversations, or Bookmarks do, because Nextcloud's registration API (`IRegistrationContext::registerReferenceProvider()`/`registerSearchProvider()`) is static: one registered class produces exactly one entry, and no dynamic/manifest-driven mechanism exists for it (confirmed against Nextcloud core and OpenRegister's own AppHost engine, which only populates a runtime URL/icon lookup table for the *existing* generic provider). Without a reusable base to subclass, every consuming app that wants a schema-specific picker entry would have to hand-roll the URL-parsing, RBAC-safe search, and rich-preview logic that already exists in `ObjectsProvider`/`ObjectReferenceProvider`.

## What Changes

- Add two reusable abstract base classes so a consuming app can expose one schema as its own Smart Picker entry with a single thin subclass:
  - `lib/AppHost/Reference/AbstractSchemaReferenceProvider.php` — extends `ADiscoverableReferenceProvider`, implements `ISearchableReferenceProvider`. A subclass configures it with only a register/schema slug pair (`getRegisterSlug()`/`getSchemaSlug()`); the provider id, supported search-provider id, title, and icon are computed deterministically by the base class itself (`getId()`/`getSupportedSearchProviderIds()` are `final`) rather than chosen by the app — title/icon are read live from the schema's own metadata via `SchemaMapper`. Matches/resolves references the same way `ObjectReferenceProvider` does, but only for that one schema.
  - `lib/AppHost/Search/AbstractSchemaSearchProvider.php` — implements `OCP\Search\IProvider`. Configured the same way, with a computed, `final` id and a display name read live from the schema's title; delegates to `ObjectService::searchObjectsPaginated()` with the schema forced into `@self.schema`, under the same RBAC (`_rbac: true`) / multitenancy (`_multitenancy: true`) contract `ObjectsProvider` already documents.
- Add a new `smartPickerEnabled` boolean schema flag (default `false`), mirroring the existing `searchable` flag pattern (`Schema` column + `SchemaMapper::findSmartPickerEnabledIds()`). Both new abstract base classes consult it at runtime and become functionally inert (no matches, no resolution, empty search results) when it is `false` for their configured schema. **Known limitation, documented not hidden:** because Nextcloud registers Smart Picker providers once at app boot from PHP class registration, this flag cannot remove a registered provider's entry from the "Select provider" list — it only gates functionality. Removing the entry requires the consuming app to stop registering the class in code.
- Refactor `lib/Search/ObjectsProvider.php` and `lib/Reference/ObjectReferenceProvider.php` to extract their icon-resolution, deep-link-URL-resolution, and excerpt/preview-formatting logic into small shared internal services, consumed by both the existing generic providers and the two new abstract base classes. Behavior-preserving — no change to the existing `openregister_objects` / `openregister-ref-objects` provider IDs or output.
- Implement two `mail-smart-picker` spec requirements that are already documented but not yet implemented in code:
  - Call `IReferenceManager::invalidateCache()` on object save so Smart Picker previews don't go stale after an edit, via a single shared URL-pattern registry so the recognizing (`parseReference()`) and building (invalidation) directions can never drift apart (see design.md D4).
  - Implement `IPublicReferenceProvider` on `ObjectReferenceProvider` so objects in publicly-readable schemas get rich previews for anonymous viewers, instead of failing closed.
- Correct stale "Current Implementation Status" sections found during investigation:
  - `openspec/specs/mail-smart-picker/spec.md` currently lists the provider, widget, and translations as "Not yet implemented" even though they exist and are registered.
  - `openspec/specs/deep-link-registry/spec.md` still describes the retired bespoke per-app `DeepLinkRegistrationListener` pattern with no mention of the AppHost `GenericDeepLinkRegistrationListener` / manifest-driven approach that Pipelinq and Procest have since migrated to.
- **Out of scope**: consuming-app concrete subclasses (e.g. Pipelinq's `LeadReferenceProvider` / `LeadSearchProvider`). That is a separate, follow-up change in Pipelinq's own repo, which will be the first real consumer validating this change's abstract base classes.

## Capabilities

### New Capabilities
- `schema-scoped-reference-providers`: abstract base classes (`AbstractSchemaReferenceProvider`, `AbstractSchemaSearchProvider`) that let a consuming app expose a single (register, schema) pair as its own discoverable, searchable Smart Picker entry, reusing OpenRegister's existing object engine, RBAC, and deep-link resolution rather than reimplementing them.

### Modified Capabilities
<!-- No existing requirement TEXT is changing. The cache-invalidation and public-reference work implements requirements `mail-smart-picker/spec.md` already states; it does not alter them. The status-section and deep-link-registry corrections are documentation-only fixes to the "Current Implementation Status" prose, not requirement deltas. -->

## Impact

- New: `lib/AppHost/Reference/AbstractSchemaReferenceProvider.php`, `lib/AppHost/Search/AbstractSchemaSearchProvider.php`, and the small shared formatting services they and the existing providers both call; a new `smartPickerEnabled` column migration on `openregister_schemas`.
- Modified: `lib/Search/ObjectsProvider.php`, `lib/Reference/ObjectReferenceProvider.php` (refactor to delegate to shared services; add `IPublicReferenceProvider`); the object-save path in `lib/Service/ObjectService.php` (add cache invalidation call); `lib/Db/Schema.php` and `lib/Db/SchemaMapper.php` (new `smartPickerEnabled` column, getter/setter, `findSmartPickerEnabledIds()`).
- Documentation: `openspec/specs/mail-smart-picker/spec.md`, `openspec/specs/deep-link-registry/spec.md` (status corrections only).
- Downstream: enables a follow-up Pipelinq change (`pipelinq::lead`) to prove the new abstract base classes end-to-end; no other consuming app is touched by this change.
- No breaking changes: existing `openregister_objects` / `openregister-ref-objects` providers keep their IDs, registration, and behavior.
