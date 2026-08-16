## 1. Core Registry Service

- [x] 1.1 Create `lib/Dto/DeepLinkRegistration.php` value object with properties: appId, registerSlug, schemaSlug, urlTemplate, icon
- [x] 1.2 Create `lib/Service/DeepLinkRegistryService.php` with static storage array, `register()` method, and `resolve()` / `resolveUrl()` methods
- [x] 1.3 Add slug→ID lazy resolution in `DeepLinkRegistryService` using `RegisterMapper` and `SchemaMapper` for lookup at search time

## 2. Event System

- [x] 2.1 Create `lib/Events/DeepLinkRegistrationEvent.php` event class that carries a reference to `DeepLinkRegistryService`
- [x] 2.2 Dispatch `DeepLinkRegistrationEvent` in `Application::boot()` so consumer apps can listen and register their deep links

## 3. Search Provider Integration

- [x] 3.1 Inject `DeepLinkRegistryService` into `ObjectsProvider` constructor
- [x] 3.2 Replace hardcoded URL generation in `ObjectsProvider::search()` with `resolveUrl()` call and fallback to `openregister.objects.show`
- [x] 3.3 Replace hardcoded `icon-openregister` with the registered app's icon (falling back to `icon-openregister`)

## 4. Verification

- [x] 4.1 Verify search still works with no consuming apps registered (backward compatibility)
- [x] 4.2 Add a test registration from Procest for "cases" schema and verify search results link to `/apps/procest/#/cases/{uuid}`
