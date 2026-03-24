## Why

Nextcloud user profiles are disconnected from OpenRegister data. When viewing a colleague's profile, there is no way to see their linked cases, leads, documents, or other register objects. Profile link actions provide quick access to user-related information across all consuming apps, reducing context switching and making the profile page a useful hub for cross-app navigation.

## What Changes

- **Profile link action**: New `ViewInRegisterAction` implementing `OCP\Profile\ILinkAction`, registered via `IRegistrationContext::registerProfileLinkAction()` in `Application.php`, showing a "View in Register" link on user profiles that opens their matched person entity in OpenRegister
- **User-entity matching**: New `UserEntityMappingService` that matches Nextcloud users to OpenRegister person entities by email address, user ID metadata, and display name (fuzzy match), with APCu caching (TTL 300s)
- **Dynamic profile actions from action registry**: On boot, read actions with `context: "profile"` from the ActionRegistry and create a separate `ILinkAction` for each (e.g., "View Cases" from Procest, "View Leads" from Pipelinq, "View Documents" from DocuDesk)
- **Visibility rules**: Profile actions only appear when the target user has a matching OpenRegister entity, the viewing user has access to the relevant register/schema, and the consuming app is enabled
- **URL template resolution**: Action hrefs support `{userId}` and `{entityId}` placeholders, resolved at render time via the user-entity mapping

## Capabilities

### New Capabilities
- `profile-link-actions`: Registration and rendering of profile link actions, including the base "View in Register" action and dynamic actions from the action registry with profile context
- `user-entity-mapping`: Backend service for matching Nextcloud users to OpenRegister person entities by email, user ID, and display name, with APCu caching and shared matching logic

### Modified Capabilities
- `deep-link-registry`: Profile action hrefs use DeepLink URL templates for navigation to consuming apps, requiring template variable support (`{userId}`, `{entityId}`)

## Impact

- **New files**: `lib/Profile/ViewInRegisterAction.php`, `lib/Profile/DynamicProfileAction.php`, `lib/Service/UserEntityMappingService.php`
- **Modified files**: `lib/AppInfo/Application.php` (register profile link actions)
- **Dependencies**: Requires `action-registry` change for dynamic profile actions from consuming apps; soft dependency on `mail-sidebar` and `contacts-actions` for shared entity matching logic in `UserEntityMappingService`
- **Existing code**: Leverages `EntityMapper` for user-entity lookups, `ActionRegistryService` for profile-context actions, `DeepLinkRegistry` for URL resolution
- **Performance**: APCu caching ensures entity lookups complete in < 100ms; action list loaded from cached ActionRegistry
- **Consuming apps**: Procest, Pipelinq, DocuDesk register profile-context actions (e.g., "View Cases", "View Leads", "View Documents") that appear on matched user profiles
