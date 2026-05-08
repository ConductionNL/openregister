# Tasks: Mail Smart Picker

## Backend

- [ ] Create `lib/Reference/ObjectReferenceProvider.php` extending `ADiscoverableReferenceProvider` and implementing `ISearchableReferenceProvider` with constructor injection of `IURLGenerator`, `IL10N`, `ObjectService`, `DeepLinkRegistryService`, `SchemaMapper`, `RegisterMapper`, `?string $userId`
- [ ] Implement `getId()` returning `'openregister-ref-objects'`, `getTitle()` returning `$this->l10n->t('Register Objects')`, `getOrder()` returning `10`, `getIconUrl()` using `IURLGenerator::imagePath('openregister', 'app-dark.svg')`
- [ ] Implement `getSupportedSearchProviderIds()` returning `['openregister_objects']` to wire up the existing `ObjectsProvider` search
- [ ] Implement `matchReference()` with regex patterns matching hash-routed UI URLs, API object URLs, and direct object show routes (both with and without `/index.php/` prefix)
- [ ] Implement `resolveReference()` to parse register ID, schema ID, and UUID from matched URLs, fetch the object via `ObjectService::getObject()`, fetch schema and register names via mappers, resolve deep link URL and icon via `DeepLinkRegistryService`, build rich object data array, and return `Reference` with `setRichObject('openregister-object', $richData)` plus `setTitle()` and `setDescription()` for fallback rendering
- [ ] Implement `getCachePrefix()` returning `{registerId}/{schemaId}/{uuid}` and `getCacheKey()` returning `$this->userId`
- [ ] Handle errors in `resolveReference()`: catch all exceptions and return `null`; catch authorization exceptions silently to prevent metadata leakage
- [ ] Extract up to 4 top-level string/number properties (excluding `@self`, `_translationMeta`, and internal fields) for the preview card's `properties` array

## Registration and Cache Invalidation

- [ ] Register the provider in `Application::register()` via `$context->registerReferenceProvider(ObjectReferenceProvider::class)` alongside the existing search provider registration
- [ ] Add `IReferenceManager` injection to `ObjectService` and call `invalidateCache()` with the object's canonical URL in `saveObject()` after successful persistence

## Frontend Widget

- [ ] Create `src/reference/ObjectReferenceWidget.vue` rendering a card-style preview with icon, title, schema/register subtitle, up to 4 property key-value pairs, updated timestamp, and clickable link to the object URL
- [ ] Create `src/reference/init.ts` registering the widget via `registerWidget('openregister-object', ...)` from `@nextcloud/vue-richtext`
- [ ] Add `'reference'` entry point to `webpack.config.js` pointing to `src/reference/init.ts`
- [ ] Style the widget with CSS custom properties for NL Design System compatibility; ensure responsive layout and WCAG AA contrast compliance
- [ ] Lazy-load the widget component to minimize initial bundle size

## Translations

- [ ] Add English translation strings to `l10n/en.json`: "Register Objects", "Schema", "Register", "Updated", "View object", "Unknown Schema", "Unknown Register"
- [ ] Add Dutch translation strings to `l10n/nl.json`: "Register Objecten", "Schema", "Register", "Bijgewerkt", "Object bekijken", "Onbekend schema", "Onbekend register"

## Testing

- [ ] Write unit tests for `ObjectReferenceProvider::matchReference()` covering all URL patterns (hash-routed, API, direct, with/without index.php, non-matching URLs)
- [ ] Write unit tests for `ObjectReferenceProvider::resolveReference()` covering successful resolution, object not found (returns null), and authorization error (returns null)
- [ ] Write unit tests for `getCachePrefix()` and `getCacheKey()` verifying correct key generation
- [ ] Manual test: verify provider appears in Smart Picker modal in Mail compose, Text editor, and Talk
- [ ] Manual test: verify pasting an OpenRegister object URL in Text produces a rich preview card
- [ ] Manual test: verify the preview card links to the correct deep-linked URL when a deep link is registered
- [ ] Manual test: verify updating an object invalidates the cached reference preview
