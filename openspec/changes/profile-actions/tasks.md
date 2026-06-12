# Tasks: Profile Actions

## Backend Tasks

- [x] Implement: Password change endpoint -- Add `changePassword()` to UserController and UserService with current password validation, backend capability check (`canChangePassword()`), Nextcloud password policy enforcement, rate limiting via SecurityService, and security headers. Route: `PUT /api/user/me/password`.
- [x] Implement: Avatar upload endpoint -- Add `uploadAvatar()` to UserController and UserService with file type validation (JPEG/PNG/GIF/WebP), 5 MB size limit, backend capability check (`canChangeAvatar()`), and IAvatarManager integration. Route: `POST /api/user/me/avatar`.
- [x] Implement: Avatar delete endpoint -- Add `deleteAvatar()` to UserController and UserService with backend capability check and IAvatar::remove() call. Route: `DELETE /api/user/me/avatar`.
- [x] Implement: Personal data export endpoint -- Add `exportData()` to UserController and UserService that assembles profile data, organisation memberships, owned objects (via MagicMapper query by owner), and audit trail entries into a downloadable JSON file. Rate limit to once per hour. Route: `GET /api/user/me/export`.
- [x] Implement: Get notification preferences endpoint -- Add `getNotificationPreferences()` to UserController and UserService that reads from IConfig user values with defaults for unset preferences. Route: `GET /api/user/me/notifications`.
- [x] Implement: Update notification preferences endpoint -- Add `updateNotificationPreferences()` to UserController and UserService that validates preference keys/values and stores via IConfig::setUserValue(). Route: `PUT /api/user/me/notifications`.
- [x] Implement: Personal activity history endpoint -- Add `getActivity()` to UserController and UserService that queries AuditTrailMapper by actor user ID with pagination (_limit, _offset) and filtering (type, _from, _to date range). Route: `GET /api/user/me/activity`.
- [x] Implement: AuditTrailMapper findByActor method -- Add `findByActor(string $userId, int $limit, int $offset, ?string $type, ?string $from, ?string $to): array` to AuditTrailMapper for querying audit entries by actor.
- [x] Implement: List API tokens endpoint -- Add `listTokens()` to UserController and UserService that retrieves the user's API tokens with masked values (last 4 chars only). Route: `GET /api/user/me/tokens`.
- [x] Implement: Create API token endpoint -- Add `createToken()` to UserController and UserService using ISecureRandom for token generation with name, optional expiration, and maximum token limit (10). Route: `POST /api/user/me/tokens`.
- [x] Implement: Revoke API token endpoint -- Add `revokeToken()` to UserController and UserService that permanently deletes a token by ID. Route: `DELETE /api/user/me/tokens/{id}`.
- [x] Implement: Request account deactivation endpoint -- Add `requestDeactivation()` to UserController and UserService that creates a pending deactivation request stored in IConfig, prevents duplicates. Route: `POST /api/user/me/deactivate`.
- [x] Implement: Get deactivation status endpoint -- Add `getDeactivationStatus()` to UserController and UserService that returns the current deactivation request status. Route: `GET /api/user/me/deactivation-status`.
- [x] Implement: Cancel deactivation request endpoint -- Add `cancelDeactivation()` to UserController and UserService that removes a pending deactivation request. Route: `DELETE /api/user/me/deactivate`.
- [x] Implement: Register all new routes in routes.php -- Add all 14 new route definitions under `/api/user/me/*` with proper verb and requirements, following the existing route ordering conventions.
- [x] Implement: Consistent error handling across all profile action endpoints -- Ensure all new methods use `SecurityService::addSecurityHeaders()`, log errors via LoggerInterface, sanitize input via SecurityService::sanitizeInput(), and return standard `{"error": "..."}` format with appropriate HTTP status codes.

## Frontend Tasks

- [x] Implement: MyAccount.vue main page component -- Create the "Mijn Account" page with collapsible sections for each action category, proper heading hierarchy (h2 for sections), i18n labels via `t('openregister', ...)`, and NL Design System theming support.
- [x] Implement: PasswordSection.vue component -- Password change form with current password and new password fields, validation feedback, backend capability detection (disable if unsupported), and error display.
- [x] Implement: AvatarSection.vue component -- Avatar display using NcAvatar, upload button with file picker (image types only), delete button with confirmation, and backend capability detection.
- [x] Implement: NotificationsSection.vue component -- Toggle switches for each notification category, email digest frequency selector, save button with optimistic UI update.
- [x] Implement: ActivitySection.vue component -- Timeline-style activity list with type icons, relative timestamps, object links, pagination ("Load more" button), and type/date filtering.
- [x] Implement: TokensSection.vue component -- Token list with masked values, create button that opens NcModal with name/expiry inputs, copy-to-clipboard for new token value, delete button per token with confirmation.
- [x] Implement: AccountSection.vue component -- Deactivation request button with double-confirmation (type username), pending status display, cancel button for pending requests.
- [x] Implement: ExportSection.vue component -- Export trigger button, progress indicator during download, rate limit feedback display.
- [x] Implement: Vue router registration -- Add `/mijn-account` route pointing to MyAccount.vue, add navigation entry in user menu.

## Test Tasks

- [x] Test: Unit tests for UserController profile action methods -- Test all 13 new controller methods covering success paths, error paths, rate limiting, backend capability checks, and input validation.
- [x] Test: Unit tests for UserService profile action methods -- Test all service methods with mocked dependencies (IUserManager, IAvatarManager, IConfig, AuditTrailMapper, ISecureRandom).
- [ ] Test: Integration test for password change flow -- End-to-end test: authenticate, change password, verify new password works, verify old password fails. (Deferred: requires running Nextcloud instance)
- [ ] Test: Integration test for data export -- End-to-end test: create objects, export data, verify export contains all owned objects and profile data. (Deferred: requires running Nextcloud instance)
