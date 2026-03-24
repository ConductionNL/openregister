## Why

Users frequently need to reference OpenRegister entities (persons, organizations, cases, leads) in emails, documents, and chat messages, but currently must manually copy names, IDs, or URLs. The Nextcloud Smart Picker provides a standardized way to search and insert rich entity references with preview cards across Mail compose, Text editor, Talk chat, and any other app supporting the reference widget system. Implementing an `IDiscoverableReferenceProvider` makes OpenRegister data discoverable and insertable from anywhere in Nextcloud.

## What Changes

- Add a new PHP class `OCA\OpenRegister\Reference\EntityReferenceProvider` implementing `IDiscoverableReferenceProvider` and `ISearchableReferenceProvider`
- Register the reference provider via `IRegistrationContext::registerReferenceProvider()` in `Application.php`
- Add a `RenderReferenceEvent` listener to load the JavaScript widget bundle when OpenRegister references are rendered
- Create a Vue component `src/components/Reference/EntityReferenceWidget.vue` for rich preview cards
- Register the widget type via `OCP.Collaboration.registerType()` in JavaScript
- Search across entities and objects using existing `ObjectService` and `EntityMapper` infrastructure
- Resolve OpenRegister URLs (e.g., `/apps/openregister/objects/{register}/{schema}/{id}`) to rich reference cards showing type icon, title, metadata, and deep links
- Support type-prefixed search filtering (e.g., "person: John", "case: 2024-001")
- Cache resolved references in APCu for performance

## Capabilities

### New Capabilities
- `smart-picker-reference`: Reference provider for the Nextcloud Smart Picker, enabling search and insertion of OpenRegister entities/objects as rich references in Mail, Text, Talk, and other apps. Covers the PHP provider implementation, URL matching/resolution, search across registers/schemas/entities, the Vue preview widget, and APCu caching.

### Modified Capabilities
- `deep-link-registry`: Reference cards should use the existing deep link registry to resolve object URLs to consuming app URLs (e.g., linking a Procest case to Procest rather than OpenRegister). No spec-level requirement change, only consumption of existing capability.

## Impact

- **New files**: `lib/Reference/EntityReferenceProvider.php`, `lib/Listener/RenderReferenceListener.php`, `src/components/Reference/EntityReferenceWidget.vue`, `src/reference.js` (entry point for widget bundle)
- **Modified files**: `lib/AppInfo/Application.php` (register provider + event listener), `webpack.config.js` (add reference entry point)
- **APIs consumed**: `OCP\Collaboration\Reference\IDiscoverableReferenceProvider`, `OCP\Collaboration\Reference\ISearchableReferenceProvider`, `OCP\Collaboration\Reference\RenderReferenceEvent`
- **Internal services used**: `ObjectService` (search objects), `EntityMapper` (search entities), deep link registry
- **No breaking changes**: This is a purely additive feature with no impact on existing APIs or data
- **No hard dependencies**: Works independently; enhanced by the action-registry change if present
- **Performance**: Search capped at 10 results per type, 25 total; APCu caching for resolved references; target <500ms response time
