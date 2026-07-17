## ADDED Requirements

### Requirement: The system MUST provide a public self-service login and logout surface with brute-force protection

`UserController` MUST expose `login` and `logout` as `@PublicPage` endpoints that complement the Consumer/API authentication methods with an interactive, session-based self-service flow. `login` MUST: validate and sanitize credentials via `SecurityService::validateLoginCredentials()`; enforce per-username and per-IP rate limiting via `SecurityService::checkLoginRateLimit()` (returning HTTP 429 with `retry_after`/`lockout_until` and applying any progressive delay); authenticate via `IUserManager::checkPassword()`; reject disabled accounts; record success/failure via `SecurityService`; on success call `IUserSession::setUser()` and return the sanitized user data with `session_created: true`. A pre-auth memory guard MUST return HTTP 503 when usage already exceeds 80% of the memory limit. Failed authentication MUST return a generic HTTP 401 ("Invalid username or password") that does not reveal whether the username exists. `logout` MUST end the session via `IUserSession::logout()`. Every response MUST pass through `SecurityService::addSecurityHeaders()`.

#### Scenario: Successful login creates a session
- **GIVEN** valid credentials for an enabled user that is not rate-limited
- **WHEN** `login` processes the request
- **THEN** the user MUST be set on the session via `IUserSession::setUser()`
- **AND** the response MUST contain `{message: "Login successful", user, session_created: true}` with security headers

#### Scenario: Rate limit returns 429
- **GIVEN** the username/IP combination is over the failed-attempt threshold
- **WHEN** `login` checks `SecurityService::checkLoginRateLimit()`
- **THEN** the response MUST be HTTP 429 with `retry_after` / `lockout_until`
- **AND** any specified progressive delay MUST be applied before responding

#### Scenario: Failure does not leak username existence
- **GIVEN** an invalid password (or unknown username)
- **WHEN** authentication fails
- **THEN** the response MUST be a generic HTTP 401 "Invalid username or password"
- **AND** the failed attempt MUST be recorded via `SecurityService::recordFailedLoginAttempt()`

#### Scenario: Disabled account is rejected
- **GIVEN** valid credentials for a disabled account
- **THEN** the response MUST be HTTP 401 "Account is disabled" and the failure MUST be recorded

### Requirement: The system MUST let an authenticated user manage their own profile, credentials, and avatar

`UserController` MUST provide self-service profile management gated by an authenticated session (HTTP 401 when `UserService::getCurrentUser()` is null). `me` MUST return the current user's profile (`UserService::buildUserDataArray()`). `updateMe` MUST sanitize the payload, strip leading-underscore internal keys and the immutable `id`/`uid`/`created` fields, and persist via `UserService::updateUserProperties()`. `changePassword` MUST require `currentPassword` and `newPassword`, apply login rate limiting, delegate to `UserService::changePassword()`, and on a 403 ("incorrect current password") record a failed attempt for brute-force protection. `uploadAvatar` MUST read the raw request body, reject an empty body with HTTP 400, and delegate to `UserService::uploadAvatar()`; `deleteAvatar` MUST delegate to `UserService::deleteAvatar()`. All responses MUST carry security headers.

#### Scenario: Unauthenticated access is rejected
- **GIVEN** no authenticated session
- **WHEN** any of `me`, `updateMe`, `changePassword`, `uploadAvatar`, `deleteAvatar` is called
- **THEN** the response MUST be HTTP 401 with `{error: "Not authenticated"}`

#### Scenario: Profile update drops immutable and internal fields
- **GIVEN** an `updateMe` payload containing `id`, `uid`, `created`, and `_internal`
- **WHEN** the controller prepares the data
- **THEN** those keys MUST be removed before `UserService::updateUserProperties()` is called
- **AND** the remaining values MUST be sanitized via `SecurityService::sanitizeInput()`

#### Scenario: Wrong current password is rate-limit tracked
- **GIVEN** `changePassword` with an incorrect `currentPassword` (service throws with code 403)
- **THEN** the response MUST surface the 403
- **AND** a failed attempt MUST be recorded via `SecurityService::recordFailedLoginAttempt()` with reason `password_change_incorrect`

#### Scenario: Avatar upload requires a body
- **GIVEN** an `uploadAvatar` request with an empty body
- **THEN** the response MUST be HTTP 400 with `{error: "No image data provided"}`

### Requirement: The system MUST provide personal-data, activity, notification, token, and account-deactivation self-service

`UserController` MUST expose the remaining authenticated self-service operations, each rejecting an unauthenticated caller with HTTP 401 and carrying security headers:

- `exportData` (GDPR) MUST return the user's personal data as a downloadable JSON attachment (`DataDownloadResponse`, filename `openregister-export-{uid}-{date}.json`); a rate-limit (service code 429) MUST surface as HTTP 429.
- `getActivity` MUST return paginated personal activity history (`_limit`/`_offset`, optional `type`/`_from`/`_to` filters).
- `getNotificationPreferences` / `updateNotificationPreferences` MUST read and write the user's notification preferences (internal `_`-prefixed keys stripped on write; invalid input → HTTP 400).
- `listTokens`, `createToken`, `revokeToken` MUST manage the user's personal API tokens; `createToken` MUST require a non-empty `name` (HTTP 400 otherwise) and return HTTP 201.
- `requestDeactivation`, `getDeactivationStatus`, `cancelDeactivation` MUST drive the account-deactivation lifecycle; a conflicting request (service code 409) MUST surface as HTTP 409.

#### Scenario: Personal data export is a download
- **GIVEN** an authenticated user calls `exportData`
- **WHEN** the export is generated
- **THEN** the response MUST be a `DataDownloadResponse` with `application/json` and filename `openregister-export-{uid}-{date}.json`

#### Scenario: Token creation requires a name
- **GIVEN** a `createToken` request with an empty `name`
- **THEN** the response MUST be HTTP 400 with `{error: "Token name is required"}`
- **AND** a valid request MUST return HTTP 201 with the created token

#### Scenario: Deactivation conflict
- **GIVEN** `requestDeactivation` when a request already exists (service throws code 409)
- **THEN** the response MUST be HTTP 409 with the service's conflict payload

#### Scenario: Notification preference update rejects invalid input
- **GIVEN** `updateNotificationPreferences` with invalid input (service throws `InvalidArgumentException`)
- **THEN** the response MUST be HTTP 400 with the exception message

### Requirement: The system MUST let a user manage their own GitHub personal access token without exposing it

`UserSettingsController` MUST provide per-user GitHub PAT management, each endpoint requiring an authenticated session (HTTP 401 otherwise). `getGitHubTokenStatus` MUST report `{hasToken, isValid, message}` and MUST NOT return the token value itself; when a token exists it MUST be validated via `GitHubHandler::validateToken()`. `setGitHubToken` MUST require a non-empty `token`, validate it via `GitHubHandler` before considering it saved, and reject an invalid token with HTTP 400 ("Invalid GitHub token"). `removeGitHubToken` MUST clear the user's token. The token MUST be stored and validated per `userId` so one user's token is never resolvable by another.

#### Scenario: Status never echoes the token
- **GIVEN** the user has a stored GitHub token
- **WHEN** `getGitHubTokenStatus` is called
- **THEN** the response MUST contain only `{hasToken: true, isValid, message}` and MUST NOT contain the token string

#### Scenario: Invalid token is rejected on save
- **GIVEN** a `setGitHubToken` request whose token fails `GitHubHandler::validateToken()`
- **THEN** the response MUST be HTTP 400 with `{error: "Invalid GitHub token"}`
- **AND** an empty/missing token MUST return HTTP 400 with `{error: "Token is required"}`

#### Scenario: Token operations require authentication
- **GIVEN** no authenticated session
- **WHEN** any of `getGitHubTokenStatus`, `setGitHubToken`, `removeGitHubToken` is called
- **THEN** the response MUST be HTTP 401 with `{error: "User not authenticated"}`

## Notes

- `UserController` endpoints are `@NoCSRFRequired` (including the state-changing `updateMe`, `changePassword`, avatar, token, and deactivation operations). The session is established via cookie, so CSRF protection is being deliberately waived here — every response does add the strict security-header set via `SecurityService::addSecurityHeaders()`, but the absence of CSRF tokens on cookie-authenticated mutations is worth confirming against the auth ADR. `login`/`logout` being `@PublicPage @NoCSRFRequired` is expected (pre-session).
- `login`'s memory guard (HTTP 503 above 80% usage) and per-request memory logging are operational safeguards, not part of the auth contract — left unspecced.
