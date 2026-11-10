# Design: Profile Actions

## Approach

Extend the existing `UserController` and `UserService` with new action methods, following the established patterns in the codebase. Each action gets a dedicated method in the controller with proper Nextcloud annotations (`@NoAdminRequired`, `@NoCSRFRequired`) and delegates to the service layer for business logic. The frontend adds a new Vue view component (`MyAccount.vue`) registered in the router, composed of section components for each action category.

## Architecture Decisions

### Backend: Extend vs. New Controller

**Decision**: Extend `UserController` with new action methods rather than creating a separate `ProfileActionsController`.

**Rationale**: All profile actions share the same authentication context (current user), the same security patterns (`SecurityService` integration), and the same error handling. The existing `UserController` already handles `me()`, `updateMe()`, `login()`, and `logout()`. Adding password, avatar, export, notifications, activity, tokens, and deactivation methods keeps the API surface unified under `/api/user/me/*`. The controller will grow but each method is self-contained and delegates to `UserService`.

### Token Storage: Nextcloud App Passwords vs. Custom Table

**Decision**: Use Nextcloud's built-in app password system (`OC\Authentication\Token\IProvider`) for API tokens.

**Rationale**: Nextcloud already has a mature token system used by app passwords. Using it means tokens are automatically validated by Nextcloud's authentication middleware, revocation is immediate, and there's no need for a custom database migration. The `IProvider::generateToken()` method creates tokens that work with Basic Auth and can be scoped to the app.

### Notification Preferences: IConfig vs. Custom Entity

**Decision**: Store notification preferences in `IConfig` user values (key-value pairs per user).

**Rationale**: Notification preferences are simple boolean/enum values (5-6 keys per user). A full database entity with mapper would be overengineered. `IConfig::setUserValue('openregister', 'notification_<key>', '<value>')` is the standard Nextcloud pattern for user-level settings. This is consistent with how `active_organisation` is already stored.

### Personal Data Export: Streaming vs. In-Memory

**Decision**: Build the export in-memory and return as a single JSON response with `Content-Disposition: attachment`.

**Rationale**: For typical users (hundreds of objects, not millions), in-memory assembly is simpler and sufficient. The export is rate-limited to once per hour, preventing abuse. If a user owns an extremely large number of objects, the response will be paginated or chunked in a future iteration. For now, the memory monitoring pattern already in `UserController::login()` can be reused to guard against OOM.

### Activity Source: AuditTrail Table

**Decision**: Source activity data from the existing `AuditTrail` entity/mapper.

**Rationale**: The `AuditTrail` table already records all CRUD operations with actor ID, timestamp, object references, and action type. No new data storage is needed. The `AuditTrailMapper` needs a method to query by actor (user ID) with pagination and filtering, which is a straightforward QBMapper query.

### Frontend: Single Page with Sections vs. Tabbed Interface

**Decision**: Single scrollable page with collapsible sections, using `NcSettingsSection`-style layout.

**Rationale**: This matches the Nextcloud personal settings page pattern that users are already familiar with. Each section is independently loadable (API calls only fire when the section is expanded), keeping initial page load fast. The page is registered as a new route `/mijn-account` in the Vue router.

## Files Affected

### Backend (PHP)

- `lib/Controller/UserController.php` -- Add methods: `changePassword()`, `uploadAvatar()`, `deleteAvatar()`, `exportData()`, `getNotificationPreferences()`, `updateNotificationPreferences()`, `getActivity()`, `listTokens()`, `createToken()`, `revokeToken()`, `requestDeactivation()`, `getDeactivationStatus()`, `cancelDeactivation()`
- `lib/Service/UserService.php` -- Add methods: `changePassword()`, `uploadAvatar()`, `deleteAvatar()`, `exportPersonalData()`, `getNotificationPreferences()`, `setNotificationPreferences()`, `getUserActivity()`, `createApiToken()`, `listApiTokens()`, `revokeApiToken()`, `requestDeactivation()`, `getDeactivationStatus()`, `cancelDeactivation()`
- `appinfo/routes.php` -- Add routes for all new endpoints under `/api/user/me/*`
- `lib/Db/AuditTrailMapper.php` -- Add `findByActor(string $userId, int $limit, int $offset, ?string $type, ?string $from, ?string $to): array` method

### Frontend (Vue/JS)

- `src/views/account/MyAccount.vue` -- New main page component
- `src/views/account/sections/PasswordSection.vue` -- Password change form
- `src/views/account/sections/AvatarSection.vue` -- Avatar upload/delete
- `src/views/account/sections/NotificationsSection.vue` -- Notification preferences
- `src/views/account/sections/ActivitySection.vue` -- Activity timeline
- `src/views/account/sections/TokensSection.vue` -- API token management
- `src/views/account/sections/AccountSection.vue` -- Deactivation request
- `src/views/account/sections/ExportSection.vue` -- Data export trigger
- `src/router.js` (or equivalent router config) -- Add `/mijn-account` route

### Tests

- `tests/Unit/Controller/UserControllerTest.php` -- Unit tests for all new controller methods
- `tests/Unit/Service/UserServiceTest.php` -- Unit tests for all new service methods
- `tests/Integration/ProfileActionsTest.php` -- Integration tests for end-to-end profile action flows

## Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Memory exhaustion during large data exports | Rate limit to 1 export/hour; reuse existing memory monitoring from `login()`; set reasonable object count cap |
| Brute force on password change endpoint | Reuse existing `SecurityService` rate limiting infrastructure |
| Token leakage | Token value shown only once at creation; stored hashed; masked in list view |
| LDAP/external backend incompatibility | Check `canChange*()` methods before every action; return 409 with clear message |
| XSS in notification preference values | All input goes through `SecurityService::sanitizeInput()` |

## Dependencies

- `OCP\IAvatarManager` -- For avatar upload/delete operations
- `OC\Authentication\Token\IProvider` -- For API token generation and management
- `OCA\OpenRegister\Db\AuditTrailMapper` -- For activity history queries
- `OCP\IConfig` -- For notification preference storage
- `OCA\OpenRegister\Service\SecurityService` -- For rate limiting, sanitization, security headers
- `@nextcloud/vue` -- NcButton, NcTextField, NcModal, NcAvatar, NcActionButton components
