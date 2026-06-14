# Design: Mail Smart Picker

## Approach

Implement a Nextcloud Reference Provider for OpenRegister using the standard `OCP\Collaboration\Reference` API. The backend consists of a single PHP provider class that matches, resolves, and caches OpenRegister object references. The frontend consists of a Vue widget component registered via `@nextcloud/vue-richtext` for inline rendering of object preview cards.

The design leverages existing infrastructure:
- **Search**: Reuses the existing `ObjectsProvider` (IFilteringProvider) for Smart Picker search -- no new search logic needed.
- **Data access**: Uses `ObjectService::getObject()` for fetching object data.
- **URL resolution**: Uses `DeepLinkRegistryService::resolveUrl()` and `resolveIcon()` for consuming-app aware links and icons.
- **Metadata**: Uses `SchemaMapper` and `RegisterMapper` for schema/register names in the preview card.

## Architecture

```
Smart Picker Modal (Nextcloud core)
    |
    v
ObjectReferenceProvider (PHP)
    |-- matchReference()     --> URL pattern matching (regex)
    |-- resolveReference()   --> ObjectService + DeepLinkRegistryService
    |-- getSupportedSearchProviderIds() --> ['openregister_objects']
    |-- getCachePrefix/Key() --> caching support
    |
    v
ObjectReferenceWidget.vue (Frontend)
    |-- Renders rich object card inline
    |-- Uses NcAvatar, NcChip for schema/register tags
    |-- Links to deep-linked URL or OpenRegister URL
```

## Files Affected

### New Files
- `lib/Reference/ObjectReferenceProvider.php` -- Main reference provider class. Extends `ADiscoverableReferenceProvider`, implements `ISearchableReferenceProvider`. Constructor-injected with `IURLGenerator`, `IL10N`, `ObjectService`, `DeepLinkRegistryService`, `SchemaMapper`, `RegisterMapper`, `?string $userId`. Contains `matchReference()` (regex for UI hash routes, API routes, and index.php variants), `resolveReference()` (fetches object, builds rich data, resolves deep link), `getCachePrefix()`, `getCacheKey()`.
- `src/reference/ObjectReferenceWidget.vue` -- Vue widget for inline rendering. Receives the rich object data via props from `@nextcloud/vue-richtext`. Renders a card with app icon, title, schema/register subtitle, up to 4 property key-value pairs, updated timestamp, and a clickable link. Uses CSS variables for theming (NL Design System compatible). Lazy-loaded.
- `src/reference/init.ts` -- Widget registration entry point. Calls `registerWidget('openregister-object', () => import('./ObjectReferenceWidget.vue'))` on app init. Loaded as a separate webpack entry point to avoid bloating the main bundle.

### Modified Files
- `lib/AppInfo/Application.php` -- Add `$context->registerReferenceProvider(ObjectReferenceProvider::class)` in the `register()` method, alongside the existing `registerSearchProvider` call. Add import for the new class.
- `lib/Service/ObjectService.php` -- Add `IReferenceManager::invalidateCache()` call in `saveObject()` after successful save, passing the object's canonical URL to bust stale reference caches.
- `appinfo/info.xml` -- No changes needed (reference providers are auto-discovered from registration).
- `webpack.config.js` -- Add `'reference'` entry point pointing to `src/reference/init.ts` for the widget bundle.
- `l10n/en.json` / `l10n/nl.json` -- Add translation strings for provider title ("Register Objects" / "Register Objecten"), widget labels ("Schema", "Register", "Updated", "View object" / "Object bekijken", etc.).

## URL Pattern Matching

The provider matches three URL patterns:

1. **Hash-routed UI URL** (primary):
   ```
   /apps/openregister/#/registers/{registerId}/schemas/{schemaId}/objects/{uuid}
   /index.php/apps/openregister/#/registers/{registerId}/schemas/{schemaId}/objects/{uuid}
   ```

2. **API object URL**:
   ```
   /apps/openregister/api/objects/{registerId}/{schemaId}/{uuid}
   /index.php/apps/openregister/api/objects/{registerId}/{schemaId}/{uuid}
   ```

3. **Direct object show route**:
   ```
   /apps/openregister/objects/{registerId}/{schemaId}/{uuid}
   /index.php/apps/openregister/objects/{registerId}/{schemaId}/{uuid}
   ```

All patterns are anchored to the Nextcloud instance base URL via `IURLGenerator::getAbsoluteURL()`.

## Rich Object Data Contract

The `resolveReference()` method builds a `$richData` array passed to `Reference::setRichObject('openregister-object', $richData)`:

```php
[
    'id'          => string,  // Object UUID
    'title'       => string,  // Display name (@self.name or first string property)
    'description' => string,  // Truncated summary/description (max 200 chars)
    'schema'      => ['id' => int, 'title' => string],
    'register'    => ['id' => int, 'title' => string],
    'url'         => string,  // Deep-linked URL or OpenRegister fallback
    'icon_url'    => string,  // App icon from deep link registry or OR default
    'updated'     => string,  // ISO 8601 timestamp
    'properties'  => [        // Up to 4 preview properties
        ['label' => string, 'value' => string],
        ...
    ],
]
```

## Cache Strategy

- `getCachePrefix()`: Returns `{registerId}/{schemaId}/{uuid}` parsed from the URL.
- `getCacheKey()`: Returns `$this->userId ?? ''` because RBAC may differ per user.
- Cache invalidation: On `ObjectService::saveObject()`, call `IReferenceManager::invalidateCache($objectUrl)` using the canonical URL pattern. This ensures that when an object is updated, all cached reference previews are refreshed.

## Widget Component Design

The Vue widget renders a horizontal card:

```
+-------+------------------------------------------+
| [icon]| Title                                     |
|       | Schema: Producten | Register: Gemeente    |
|       | Eigenaar: Jan de Vries                     |
|       | Status: Actief                             |
|       | Updated: 2026-03-24 10:30                  |
+-------+------------------------------------------+
```

- The entire card is clickable (navigates to `url`).
- Uses `NcAvatar` for the app icon.
- Key properties are selected: first 4 top-level string/number properties from the object, excluding internal fields (`@self`, `_translationMeta`, etc.).
- Styling uses CSS custom properties for NL Design System compatibility.
- The component is responsive: on narrow widths, properties stack vertically.

## Error Handling

- `resolveReference()` catches all exceptions from `ObjectService::getObject()` and returns `null` (no preview, URL rendered as plain link).
- Authorization exceptions (RBAC) are caught silently -- no metadata leaks.
- Missing schema/register metadata degrades gracefully (shows "Unknown Schema" / "Unknown Register").

## Performance Considerations

- The reference provider only loads object data when a URL is actually resolved (not on every page load).
- Widget is lazy-loaded as a separate webpack chunk.
- Nextcloud's built-in reference caching prevents redundant DB queries for repeated views of the same reference.
- The `ObjectsProvider` search already handles pagination efficiently via `searchObjectsPaginated()`.
