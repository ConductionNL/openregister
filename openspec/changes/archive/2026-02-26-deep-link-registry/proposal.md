## Why

OpenRegister's Nextcloud unified search provider (`ObjectsProvider`) currently links all search results to OpenRegister's own object detail view (`openregister.objects.show`). Consumer apps like Procest and Pipelinq store their domain data in OpenRegister but have their own detail views for those objects (e.g., a case detail page in Procest, a client detail page in Pipelinq). When a user searches for "Jan de Vries" and clicks the result, they should land in Pipelinq's client view — not OpenRegister's raw object view.

This creates a poor UX and breaks the "thin client" architecture where OpenRegister is invisible infrastructure and consuming apps own the user experience.

## What Changes

- Add a **deep link registry** that allows consuming apps to register URL patterns per register/schema combination
- Modify `ObjectsProvider` to look up registered deep links when building search result URLs, falling back to OpenRegister's own route when no app has claimed a schema
- Provide a PHP API for consuming apps to register their URL patterns during app boot (via `Application::register()`)
- Store registrations in memory (no database) — apps register on every request via their boot cycle

## Capabilities

### New Capabilities
- `deep-link-registry`: App-to-schema URL mapping registry that allows consuming Nextcloud apps to claim ownership of OpenRegister schemas for search result deep linking

### Modified Capabilities
(none — there are no existing specs to modify)

## Impact

- **Code**: `lib/Search/ObjectsProvider.php` (URL generation), new `lib/Service/DeepLinkRegistryService.php`, `lib/AppInfo/Application.php` (event registration)
- **APIs**: No REST API changes — this is an internal PHP API only
- **Dependencies**: Consumer apps (Procest, Pipelinq, OpenCatalogi, SoftwareCatalog) will need to add registration calls, but this is opt-in with backward-compatible fallback
- **Dependent apps**: No breaking changes — unregistered schemas continue to link to OpenRegister
