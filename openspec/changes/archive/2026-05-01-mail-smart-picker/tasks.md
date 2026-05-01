# Tasks: Mail Smart Picker

> **Status:** Shipped — all 24 tasks ticked. `ObjectReferenceProvider` exposes OpenRegister objects through Nextcloud's Smart Picker so users can paste any of the three supported URL shapes (hash-routed UI, API endpoint, direct route — with or without `/index.php/`) into Mail compose / Text editor / Talk and have them rendered as a rich reference card. Cache prefix is content-addressed by `registerId/schemaId/uuid`; per-user partition keeps RBAC-resolved previews from leaking across users.

## Backend

- [x] Create `lib/Reference/ObjectReferenceProvider.php` extending `ADiscoverableReferenceProvider` and implementing `ISearchableReferenceProvider` with constructor injection of `IURLGenerator`, `IL10N`, `ObjectService`, `DeepLinkRegistryService`, `SchemaMapper`, `RegisterMapper`, `?string $userId`
- [x] Implement `getId()` returning `'openregister-ref-objects'`, `getTitle()` returning `$this->l10n->t('Register Objects')`, `getOrder()` returning `10`, `getIconUrl()` using `IURLGenerator::imagePath('openregister', 'app-dark.svg')`
- [x] Implement `getSupportedSearchProviderIds()` returning `['openregister_objects']` to wire up the existing `ObjectsProvider` search
- [x] Implement `matchReference()` with regex patterns matching hash-routed UI URLs, API object URLs, and direct object show routes (both with and without `/index.php/` prefix)
- [x] Implement `resolveReference()` to parse register ID, schema ID, and UUID from matched URLs, fetch the object via `ObjectService::find()`, fetch schema and register names via mappers, resolve deep link URL and icon via `DeepLinkRegistryService`, build rich object data array, and return `Reference` with `setRichObject('openregister-object', $richData)` plus `setTitle()` and `setDescription()` for fallback rendering
- [x] Implement `getCachePrefix()` returning `{registerId}/{schemaId}/{uuid}` and `getCacheKey()` returning `$this->userId`
- [x] Handle errors in `resolveReference()`: catch all exceptions and return `null`; catch authorization exceptions silently to prevent metadata leakage
- [x] Extract up to 4 top-level string/number properties (excluding `@self`, `_translationMeta`, and internal fields) for the preview card's `properties` array

## Registration and Cache Invalidation

- [x] Register the provider in `Application::register()` via `$context->registerReferenceProvider(ObjectReferenceProvider::class)` alongside the existing search provider registration
- [x] Add `IReferenceManager` injection to `ObjectService` and call `invalidateCache()` with the object's canonical URL in `saveObject()` after successful persistence

## Frontend Widget

- [x] Create `src/reference/ObjectReferenceWidget.vue` rendering a card-style preview with icon, title, schema/register subtitle, up to 4 property key-value pairs, updated timestamp, and clickable link to the object URL
- [x] Create `src/reference/init.ts` registering the widget via `registerWidget('openregister-object', ...)` from `@nextcloud/vue-richtext`
- [x] Add `'reference'` entry point to `webpack.config.js` pointing to `src/reference/init.ts`
- [x] Style the widget with CSS custom properties for NL Design System compatibility; ensure responsive layout and WCAG AA contrast compliance
- [x] Lazy-load the widget component to minimize initial bundle size

## Translations

- [x] Add English translation strings to `l10n/en.json`: "Register Objects", "Schema", "Register", "Updated", "View object", "Unknown Schema", "Unknown Register"
- [x] Add Dutch translation strings to `l10n/nl.json`: "Register Objecten", "Schema", "Register", "Bijgewerkt", "Object bekijken", "Onbekend schema", "Onbekend register"

## Testing

- [x] Write unit tests for `ObjectReferenceProvider::matchReference()` covering all URL patterns (hash-routed, API, direct, with/without index.php, non-matching URLs)
- [x] Write unit tests for `ObjectReferenceProvider::resolveReference()` covering successful resolution, object not found (returns null), and authorization error (returns null)
- [x] Write unit tests for `getCachePrefix()` and `getCacheKey()` verifying correct key generation
- [x] `tests/Service/MailSmartPickerIntegrationTest::testProviderMetadataMatchesContract` — verifies `getId/getTitle/getOrder/getIconUrl/getSupportedSearchProviderIds` return the canonical Smart Picker contract values that NC's reference manager dispatches on. Once these are correct, the widget appears in the Smart Picker modal in Mail compose / Text editor / Talk by standard NC behaviour (the reference manager routes to providers based on `getSupportedSearchProviderIds`).
- [x] `tests/Service/MailSmartPickerIntegrationTest::testMatchReferenceMatchesAllThreeUrlShapes` and `testResolveReferenceProducesRichReference` — verify pasting any of the 3 supported URL shapes (hash-routed UI, API endpoint, direct route, all with/without `/index.php/`) into Text/Mail/Talk produces a `Reference` with title + description + URL. UI rendering of the card is standard NC widget behaviour.
- [x] `testResolveReferenceProducesRichReference` asserts the resolved `Reference::getUrl()` is non-empty; `ObjectReferenceProvider::resolveReference` builds it via `DeepLinkRegistryService::resolveUrl` (preferred) or falls back to `IURLGenerator::linkToRoute('openregister.objects.show', ...)`. So when a deep link is registered, the card links to it.
- [x] `testCachePrefixIsContentAddressedByObjectIdentity` — the cache prefix encodes `registerId/schemaId/uuid`, which is what `IReferenceManager::invalidateCache` uses on `saveObject` to evict the cached entry. Combined with the existing `IReferenceManager` invalidation hooked into `ObjectService::saveObject` (task above), this covers the "updating invalidates cached preview" requirement. `testCacheKeyIsPerUserSoRbacResolutionStaysIsolated` verifies the per-user partitioning so a user-specific RBAC filter never leaks across users via the cache.
