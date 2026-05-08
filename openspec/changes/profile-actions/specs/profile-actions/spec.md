---
status: draft
---

# Profile Actions

## Purpose

Extend the OpenRegister user profile system with self-service account management actions. The current profile API (`/api/user/me`) supports reading and updating basic profile fields, but lacks password change, avatar management, personal data export (GDPR Article 20 data portability), notification preferences, personal activity history, API token management, and account deactivation requests. This spec defines REST endpoints and frontend UI for each action, all respecting Nextcloud backend capabilities and organisation-level policies. Every action is scoped to the authenticated user (no admin elevation required) and integrates with existing `UserService`, `SecurityService`, and `OrganisationService`.

**Standards**: GDPR Articles 17 and 20 (erasure and data portability), Nextcloud OCS User API conventions, NL Design System (NLDS) theming tokens, WCAG 2.1 AA accessibility.
**Cross-references**: [object-interactions](../../specs/object-interactions/spec.md) (audit trail), [rbac-scopes](../../specs/rbac-scopes/spec.md) (permissions), [auth-system](../auth-system/) (authentication).

## Requirements

### Requirement: Password change MUST be available as a self-service action

The system SHALL provide an endpoint at `PUT /api/user/me/password` that allows the authenticated user to change their own password. The endpoint MUST validate the current password before accepting the new one, MUST enforce Nextcloud's password policy, and MUST check `IUser::canChangePassword()` before allowing the action. Rate limiting via `SecurityService` MUST be applied to prevent brute-force attacks on the current password field.

#### Scenario: Successful password change
- **GIVEN** an authenticated user `jan.pietersen` whose backend supports password changes (`canChangePassword()` returns `true`)
- **WHEN** the user sends `PUT /api/user/me/password` with body `{"currentPassword": "OldPass1234!", "newPassword": "NewSecure2026!"}`
- **THEN** `SecurityService::validateLoginCredentials()` SHALL verify the current password
- **AND** `IUser::setPassword("NewSecure2026!")` SHALL be called
- **AND** the response SHALL be HTTP 200 with `{"success": true, "message": "Password updated successfully"}`
- **AND** the response SHALL include security headers via `SecurityService::addSecurityHeaders()`

#### Scenario: Current password is incorrect
- **GIVEN** an authenticated user
- **WHEN** the user sends `PUT /api/user/me/password` with an incorrect `currentPassword`
- **THEN** `IUserManager::checkPassword()` SHALL return `false`
- **AND** `SecurityService::recordFailedLoginAttempt()` SHALL be called
- **AND** the response SHALL be HTTP 403 with `{"error": "Current password is incorrect"}`

#### Scenario: Backend does not support password changes
- **GIVEN** an authenticated user whose backend returns `canChangePassword() === false` (e.g., LDAP)
- **WHEN** the user sends `PUT /api/user/me/password`
- **THEN** the response SHALL be HTTP 409 with `{"error": "Password changes are not supported by your authentication backend"}`

#### Scenario: New password does not meet policy
- **GIVEN** an authenticated user with correct current password
- **WHEN** the user sends a new password that is too short (e.g., `"abc"`)
- **THEN** `IUser::setPassword()` SHALL throw an exception or return `false`
- **AND** the response SHALL be HTTP 400 with `{"error": "New password does not meet the password policy requirements"}`

#### Scenario: Rate limiting on repeated password change attempts
- **GIVEN** an authenticated user who has made 5 failed password change attempts in 15 minutes
- **WHEN** the user sends another `PUT /api/user/me/password`
- **THEN** `SecurityService::checkLoginRateLimit()` SHALL return `allowed: false`
- **AND** the response SHALL be HTTP 429 with a `retry_after` value

### Requirement: Avatar management MUST support upload and deletion

The system SHALL provide endpoints for uploading (`POST /api/user/me/avatar`) and deleting (`DELETE /api/user/me/avatar`) the user's avatar image. Upload MUST validate file type (JPEG, PNG, GIF, WebP), file size (max 5 MB), and `IUser::canChangeAvatar()` capability. The avatar MUST be set via Nextcloud's `IAvatarManager`. Deletion SHALL reset to the default Nextcloud-generated avatar.

#### Scenario: Upload a JPEG avatar
- **GIVEN** an authenticated user whose backend supports avatar changes
- **WHEN** the user sends `POST /api/user/me/avatar` with a multipart form containing a 200 KB JPEG image
- **THEN** `IAvatarManager::getAvatar($userId)` SHALL be called
- **AND** `IAvatar::set($imageData)` SHALL be called with the uploaded image data
- **AND** the response SHALL be HTTP 200 with `{"success": true, "avatarUrl": "/avatar/{uid}/128"}`

#### Scenario: Upload file exceeds size limit
- **GIVEN** an authenticated user
- **WHEN** the user uploads an image larger than 5 MB
- **THEN** the response SHALL be HTTP 400 with `{"error": "Avatar image must be smaller than 5 MB"}`

#### Scenario: Upload unsupported file type
- **GIVEN** an authenticated user
- **WHEN** the user uploads a `.bmp` file
- **THEN** the response SHALL be HTTP 400 with `{"error": "Unsupported image format. Allowed: JPEG, PNG, GIF, WebP"}`

#### Scenario: Delete avatar
- **GIVEN** an authenticated user with a custom avatar
- **WHEN** the user sends `DELETE /api/user/me/avatar`
- **THEN** `IAvatar::remove()` SHALL be called
- **AND** the response SHALL be HTTP 200 with `{"success": true, "message": "Avatar removed"}`

#### Scenario: Backend does not support avatar changes
- **GIVEN** an authenticated user whose backend returns `canChangeAvatar() === false`
- **WHEN** the user sends `POST /api/user/me/avatar`
- **THEN** the response SHALL be HTTP 409 with `{"error": "Avatar changes are not supported by your authentication backend"}`

### Requirement: Personal data export MUST comply with GDPR Article 20

The system SHALL provide an endpoint at `GET /api/user/me/export` that generates a JSON export of all personal data associated with the authenticated user. The export MUST include Nextcloud profile data, organisation memberships, OpenRegister objects owned by the user (via the `owner` system field), audit trail entries where the user is the actor, and any files attached to owned objects. The export MUST be returned as a downloadable JSON file with `Content-Disposition: attachment` header.

#### Scenario: Export all personal data
- **GIVEN** an authenticated user `jan.pietersen` who owns 15 objects across 3 registers and is member of 2 organisations
- **WHEN** the user sends `GET /api/user/me/export`
- **THEN** the response SHALL be HTTP 200 with `Content-Type: application/json` and `Content-Disposition: attachment; filename="openregister-export-jan.pietersen-2026-03-24.json"`
- **AND** the JSON SHALL contain sections: `profile` (from `buildUserDataArray()`), `organisations` (membership list), `objects` (all owned objects grouped by register/schema), `auditTrail` (all audit entries where actor is the user)
- **AND** each object SHALL include its full JSON data including file references

#### Scenario: Export with no owned data
- **GIVEN** an authenticated user who owns no objects and has no audit trail entries
- **WHEN** the user sends `GET /api/user/me/export`
- **THEN** the response SHALL be HTTP 200 with a valid JSON containing `profile` data and empty `objects`, `auditTrail` arrays
- **AND** the `organisations` section SHALL still list current memberships

#### Scenario: Export rate limiting
- **GIVEN** an authenticated user who has already exported data in the last hour
- **WHEN** the user sends another `GET /api/user/me/export`
- **THEN** the response SHALL be HTTP 429 with `{"error": "Data export is limited to once per hour", "retry_after": <seconds_remaining>}`

#### Scenario: Export includes cross-organisation data
- **GIVEN** a user who is a member of organisations A and B, owning objects in both
- **WHEN** the user exports their data
- **THEN** the export SHALL include objects from ALL organisations the user owns, regardless of the currently active organisation
- **AND** each object SHALL include its `organisation` field for context

### Requirement: Notification preferences MUST be configurable per user

The system SHALL provide endpoints at `GET /api/user/me/notifications` and `PUT /api/user/me/notifications` for reading and updating per-user notification preferences. Preferences SHALL control which OpenRegister events trigger notifications for the user, stored via `IConfig::setUserValue()`. Categories SHALL include: object changes in owned objects, assignment notifications, organisation membership changes, and system announcements.

#### Scenario: Get default notification preferences
- **GIVEN** an authenticated user who has never set notification preferences
- **WHEN** the user sends `GET /api/user/me/notifications`
- **THEN** the response SHALL be HTTP 200 with default preferences: `{"objectChanges": true, "assignments": true, "organisationChanges": true, "systemAnnouncements": true, "emailDigest": "daily"}`

#### Scenario: Update notification preferences
- **GIVEN** an authenticated user
- **WHEN** the user sends `PUT /api/user/me/notifications` with `{"objectChanges": false, "emailDigest": "weekly"}`
- **THEN** `IConfig::setUserValue('openregister', 'notification_objectChanges', 'false')` SHALL be called
- **AND** `IConfig::setUserValue('openregister', 'notification_emailDigest', 'weekly')` SHALL be called
- **AND** the response SHALL be HTTP 200 with the complete updated preferences

#### Scenario: Invalid email digest frequency
- **GIVEN** an authenticated user
- **WHEN** the user sends `PUT /api/user/me/notifications` with `{"emailDigest": "hourly"}`
- **THEN** the response SHALL be HTTP 400 with `{"error": "Invalid emailDigest value. Allowed: none, daily, weekly"}`

#### Scenario: Notification preferences persist across sessions
- **GIVEN** a user who set `objectChanges` to `false`
- **WHEN** the user logs in again and sends `GET /api/user/me/notifications`
- **THEN** `objectChanges` SHALL be `false` (read from `IConfig::getUserValue()`)

### Requirement: Personal activity history MUST be retrievable

The system SHALL provide an endpoint at `GET /api/user/me/activity` that returns a paginated list of the authenticated user's recent actions within OpenRegister. Activities SHALL be sourced from the `AuditTrail` table filtered by the current user's ID as actor. The endpoint MUST support pagination via `_limit` and `_offset` query parameters and filtering by `type` (create, read, update, delete) and date range (`_from`, `_to`).

#### Scenario: List recent activity with default pagination
- **GIVEN** an authenticated user `jan.pietersen` who has performed 50 actions
- **WHEN** the user sends `GET /api/user/me/activity`
- **THEN** the response SHALL be HTTP 200 with `{"results": [...], "total": 50}` where `results` contains the 25 most recent activities (default limit)
- **AND** each activity SHALL include: `id`, `type` (create/update/delete), `objectUuid`, `objectTitle`, `register`, `schema`, `timestamp`, `summary`

#### Scenario: Filter activity by type
- **GIVEN** an authenticated user with create, update, and delete activities
- **WHEN** the user sends `GET /api/user/me/activity?type=create`
- **THEN** only activities with type `create` SHALL be returned

#### Scenario: Filter activity by date range
- **GIVEN** an authenticated user with activities spanning January through March 2026
- **WHEN** the user sends `GET /api/user/me/activity?_from=2026-03-01&_to=2026-03-24`
- **THEN** only activities within that date range SHALL be returned

#### Scenario: Paginate activity results
- **GIVEN** an authenticated user with 50 activities
- **WHEN** the user sends `GET /api/user/me/activity?_limit=10&_offset=20`
- **THEN** activities 21 through 30 SHALL be returned
- **AND** the `total` field SHALL remain `50`

#### Scenario: Activity for objects across organisations
- **GIVEN** a user who has performed actions in multiple organisations
- **WHEN** the user retrieves their activity history
- **THEN** activities from ALL organisations SHALL be included (not filtered by active organisation)

### Requirement: API token management MUST support create, list, and revoke operations

The system SHALL provide endpoints for managing personal API tokens at `/api/user/me/tokens`. API tokens enable programmatic access to the OpenRegister API without session cookies. Tokens SHALL be stored as Nextcloud app passwords via `IAppManager` or as custom token records in `IConfig`. Each token SHALL have a name, creation date, last used date, and optional expiration date. Token values SHALL only be displayed once at creation time.

#### Scenario: Create a new API token
- **GIVEN** an authenticated user
- **WHEN** the user sends `POST /api/user/me/tokens` with `{"name": "CI Pipeline", "expiresIn": "90d"}`
- **THEN** a new token SHALL be generated using a cryptographically secure random generator
- **AND** the response SHALL be HTTP 201 with `{"id": <id>, "name": "CI Pipeline", "token": "<full-token-value>", "created": "2026-03-24T10:00:00Z", "expires": "2026-06-22T10:00:00Z"}`
- **AND** the full token value SHALL NOT be retrievable after this response

#### Scenario: List API tokens
- **GIVEN** an authenticated user with 3 API tokens
- **WHEN** the user sends `GET /api/user/me/tokens`
- **THEN** the response SHALL be HTTP 200 with an array of 3 token objects
- **AND** each token SHALL include `id`, `name`, `created`, `lastUsed`, `expires`, and a masked token preview (last 4 characters)
- **AND** the full token value SHALL NOT be included

#### Scenario: Revoke an API token
- **GIVEN** an authenticated user with a token named "CI Pipeline" with ID 42
- **WHEN** the user sends `DELETE /api/user/me/tokens/42`
- **THEN** the token SHALL be permanently deleted
- **AND** the response SHALL be HTTP 200 with `{"success": true, "message": "Token revoked"}`
- **AND** subsequent API calls using the revoked token SHALL return HTTP 401

#### Scenario: Token expiration enforcement
- **GIVEN** an API token that expired on 2026-03-20
- **WHEN** a client uses this token to authenticate on 2026-03-24
- **THEN** the authentication SHALL fail with HTTP 401
- **AND** the response SHALL include `{"error": "Token has expired"}`

#### Scenario: Maximum token limit
- **GIVEN** an authenticated user who already has 10 API tokens (the maximum)
- **WHEN** the user sends `POST /api/user/me/tokens`
- **THEN** the response SHALL be HTTP 400 with `{"error": "Maximum number of API tokens (10) reached. Revoke an existing token first."}`

### Requirement: Account deactivation request MUST be supported

The system SHALL provide an endpoint at `POST /api/user/me/deactivate` that allows a user to request deactivation of their own account. Deactivation SHALL NOT immediately disable the account; instead, it SHALL create a pending deactivation request that an administrator must approve. The request SHALL be stored and retrievable by the user via `GET /api/user/me/deactivation-status`. A user SHALL be able to cancel a pending deactivation request via `DELETE /api/user/me/deactivate`.

#### Scenario: Request account deactivation
- **GIVEN** an authenticated user `jan.pietersen`
- **WHEN** the user sends `POST /api/user/me/deactivate` with `{"reason": "Leaving the organization"}`
- **THEN** a deactivation request SHALL be stored via `IConfig::setUserValue('openregister', 'deactivation_request', <json>)`
- **AND** the response SHALL be HTTP 200 with `{"success": true, "message": "Deactivation request submitted", "status": "pending", "requestedAt": "2026-03-24T10:00:00Z"}`
- **AND** a notification SHALL be sent to all admin users

#### Scenario: Check deactivation status with no pending request
- **GIVEN** an authenticated user with no pending deactivation request
- **WHEN** the user sends `GET /api/user/me/deactivation-status`
- **THEN** the response SHALL be HTTP 200 with `{"status": "active", "pendingRequest": null}`

#### Scenario: Cancel deactivation request
- **GIVEN** an authenticated user with a pending deactivation request
- **WHEN** the user sends `DELETE /api/user/me/deactivate`
- **THEN** the deactivation request SHALL be removed
- **AND** the response SHALL be HTTP 200 with `{"success": true, "message": "Deactivation request cancelled", "status": "active"}`

#### Scenario: Prevent duplicate deactivation requests
- **GIVEN** an authenticated user with an existing pending deactivation request
- **WHEN** the user sends another `POST /api/user/me/deactivate`
- **THEN** the response SHALL be HTTP 409 with `{"error": "A deactivation request is already pending", "requestedAt": "2026-03-24T10:00:00Z"}`

### Requirement: Frontend MUST provide a "Mijn Account" page with action sections

The frontend SHALL include a "Mijn Account" (My Account) page accessible from the user menu that displays the current user's profile information and provides UI sections for each profile action. The page MUST use Nextcloud Vue components (`NcButton`, `NcTextField`, `NcModal`, `NcActionButton`, `NcAvatar`) and follow NL Design System theming via CSS custom properties. All labels MUST use `t('openregister', ...)` for i18n support in Dutch and English.

#### Scenario: Navigate to Mijn Account page
- **GIVEN** an authenticated user
- **WHEN** the user clicks their avatar in the header and selects "Mijn Account"
- **THEN** the router SHALL navigate to `/mijn-account`
- **AND** the page SHALL display sections: Profile Information, Password, Avatar, Notifications, Activity, API Tokens, Account

#### Scenario: Password section respects backend capabilities
- **GIVEN** a user whose backend does not support password changes
- **WHEN** the "Mijn Account" page renders
- **THEN** the Password section SHALL display a disabled state with text `t('openregister', 'Password changes are not supported by your authentication provider')`
- **AND** the password change form SHALL NOT be rendered

#### Scenario: Avatar section with upload and delete
- **GIVEN** a user with a custom avatar whose backend supports avatar changes
- **WHEN** the user views the Avatar section
- **THEN** the current avatar SHALL be displayed using `NcAvatar` component
- **AND** an "Upload new avatar" button and "Remove avatar" button SHALL be displayed
- **AND** clicking "Upload new avatar" SHALL open a file picker limited to image types

#### Scenario: Activity section with pagination
- **GIVEN** a user with 50 activity entries
- **WHEN** the user views the Activity section
- **THEN** the 25 most recent activities SHALL be displayed in a timeline format
- **AND** a "Load more" button SHALL be displayed
- **AND** each activity entry SHALL show: icon (based on type), description, timestamp (relative), and a link to the affected object

#### Scenario: API Token creation with copy-to-clipboard
- **GIVEN** a user creating a new API token
- **WHEN** the token is created successfully
- **THEN** an `NcModal` SHALL display the full token value with a "Copy to clipboard" button
- **AND** a warning message SHALL state: `t('openregister', 'This token will only be shown once. Copy it now.')`
- **AND** after closing the modal, the token list SHALL refresh showing the new token with a masked value

#### Scenario: Account deactivation with confirmation dialog
- **GIVEN** a user clicking "Request account deactivation"
- **WHEN** the confirmation dialog appears
- **THEN** an `NcModal` SHALL display with a textarea for the reason and a warning about the consequences
- **AND** the user MUST type their username to confirm (double-confirmation pattern)
- **AND** the submit button SHALL be disabled until the username matches

#### Scenario: Page accessibility
- **GIVEN** the "Mijn Account" page is rendered
- **WHEN** a screen reader navigates the page
- **THEN** each section SHALL have a proper heading hierarchy (h2 for section titles)
- **AND** all interactive elements SHALL have `aria-label` or visible labels
- **AND** color contrast SHALL meet WCAG 2.1 AA (4.5:1 minimum for text)

### Requirement: All profile action endpoints MUST return consistent error responses

All profile action endpoints SHALL follow the existing OpenRegister error response format: `{"error": "<message>"}` with appropriate HTTP status codes. Authentication failures SHALL return 401, authorization failures 403, validation errors 400, rate limiting 429, and server errors 500. All error messages in controller responses SHALL use `$this->l10n->t()` for internationalization. All responses SHALL include security headers via `SecurityService::addSecurityHeaders()`.

#### Scenario: Unauthenticated request to any profile action
- **GIVEN** no authentication credentials
- **WHEN** a request is sent to any `/api/user/me/*` endpoint
- **THEN** the response SHALL be HTTP 401 with `{"error": "Not authenticated"}`

#### Scenario: Server error during profile action
- **GIVEN** an authenticated user
- **WHEN** an unexpected exception occurs during any profile action
- **THEN** the error SHALL be logged via `LoggerInterface::error()` with context including file, line, and error message
- **AND** the response SHALL be HTTP 500 with a generic error message (no stack trace or internal details)

#### Scenario: Input sanitization on all profile actions
- **GIVEN** an authenticated user sending data to any profile action endpoint
- **WHEN** the request body contains HTML or script tags
- **THEN** `SecurityService::sanitizeInput()` SHALL be called on all input fields before processing
- **AND** any XSS-bearing input SHALL be stripped or escaped

## Current Implementation Status

**Not implemented.** The existing codebase has the foundation:

- `UserController` provides `me()`, `updateMe()`, `login()`, `logout()` endpoints
- `UserService` provides `buildUserDataArray()`, `updateUserProperties()`, `updateStandardUserProperties()`, `updateProfileProperties()`
- `SecurityService` provides rate limiting, input sanitization, and security headers
- `UserProfileUpdatedEvent` dispatches on profile changes
- `DataAccessProfile` entity exists but is not yet integrated with user tokens
- Routes exist at `/api/user/me` (GET, PUT), `/api/user/login` (POST), `/api/user/logout` (POST)
- Frontend has no dedicated "Mijn Account" page; profile data is shown via the Nextcloud user menu

**Not yet implemented:**
- Password change endpoint (`PUT /api/user/me/password`)
- Avatar management endpoints (`POST/DELETE /api/user/me/avatar`)
- Personal data export endpoint (`GET /api/user/me/export`)
- Notification preferences endpoints (`GET/PUT /api/user/me/notifications`)
- Activity history endpoint (`GET /api/user/me/activity`)
- API token management endpoints (`POST/GET/DELETE /api/user/me/tokens`)
- Account deactivation endpoints (`POST/GET/DELETE /api/user/me/deactivate`)
- Frontend "Mijn Account" page with action sections
- Consistent error handling across all profile endpoints

## Standards & References

- GDPR Article 17 (Right to erasure) and Article 20 (Right to data portability)
- Nextcloud OCS User Provisioning API conventions
- NL Design System (Rijkshuisstijl) design tokens for UI theming
- WCAG 2.1 Level AA accessibility requirements
- RFC 6750 (Bearer Token Usage) for API token format
- Nextcloud `IAvatarManager` for avatar operations
- Nextcloud `IConfig` for user-level preference storage
- Nextcloud `IUser` backend capability checks (`canChangePassword()`, `canChangeAvatar()`, etc.)

## Cross-References

- `object-interactions` -- Audit trail provides activity data source
- `rbac-scopes` -- Permission framework for action authorization
- `auth-system` -- Authentication and session management
- `production-observability` -- Logging patterns for error tracking
