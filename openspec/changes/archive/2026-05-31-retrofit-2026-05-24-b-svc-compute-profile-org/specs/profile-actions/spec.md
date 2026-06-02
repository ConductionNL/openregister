## ADDED Requirements

### Requirement: UserService MUST implement the self-service profile-action backend

`UserService` MUST provide the service-layer logic that backs the profile-action
controller endpoints. Every action MUST be scoped to a supplied `IUser` and MUST respect
the user's Nextcloud backend capabilities. The service MUST surface failures as typed
exceptions carrying the appropriate HTTP status code so the controller can map them to the
documented error responses. The required service operations are: profile-data assembly,
password change, avatar upload/delete, GDPR personal-data export, notification preferences,
activity history, API-token lifecycle, and account-deactivation lifecycle.

#### Scenario: Build comprehensive user data array
- **GIVEN** an authenticated `IUser`
- **WHEN** `buildUserDataArray($user)` is called
- **THEN** the result MUST include profile fields, group memberships, quota information, language/locale, backend capability flags (`displayName`, `email`, `password`, `avatar`), and an `organisations` block (`active`, `all`, `total`)
- **AND** organisation stats MUST be memoised per request so a second call within the same request does not re-query

#### Scenario: Password change enforces backend capability and current-password verification
- **WHEN** `changePassword($user, $current, $new)` is called
- **THEN** a backend returning `canChangePassword() === false` MUST raise an exception with code 409
- **AND** an incorrect current password MUST raise an exception with code 403
- **AND** a new password failing policy MUST raise an exception with code 400

#### Scenario: Avatar upload validates MIME type and size
- **WHEN** `uploadAvatar($user, $data, $mimeType, $size)` is called
- **THEN** a MIME type outside `[image/jpeg, image/png, image/gif, image/webp]` MUST raise an exception with code 400
- **AND** a size exceeding 5 MB MUST raise an exception with code 400
- **AND** a backend returning `canChangeAvatar() === false` MUST raise an exception with code 409
- **AND** `deleteAvatar($user)` MUST call `IAvatar::remove()` after the same capability gate

#### Scenario: Personal data export is rate limited to once per hour
- **WHEN** `exportPersonalData($user)` is called
- **THEN** the assembled structure MUST include `profile`, `organisations`, `objects`, and `auditTrail` (audit entries where the user is the actor)
- **AND** a second call within `EXPORT_RATE_LIMIT` (3600 s) MUST raise an exception with code 429 carrying `retry_after`

#### Scenario: Notification preferences default and validate
- **WHEN** `getNotificationPreferences($user)` is called for a user who never set them
- **THEN** the documented defaults MUST be returned with boolean values coerced from their stored string form
- **AND** `setNotificationPreferences($user, $prefs)` MUST reject an `emailDigest` outside `[none, daily, weekly]` with an `InvalidArgumentException`
- **AND** only keys present in the default preference set MUST be persisted

#### Scenario: Activity history projects audit trail by actor
- **WHEN** `getUserActivity($user, $limit, $offset, $type, $from, $to)` is called
- **THEN** entries MUST be sourced from the audit trail filtered by the user's UID as actor
- **AND** each entry MUST be projected to `{id, type, objectUuid, register, schema, timestamp, summary}` with the total count preserved across pagination

#### Scenario: API tokens are hashed at rest and shown in plaintext only once
- **WHEN** `createApiToken($user, $name, $expiresIn)` is called
- **THEN** the stored record MUST contain only the SHA-256 hash of the token plus a last-4 preview, never the plaintext
- **AND** the plaintext token MUST be returned exactly once in the create response
- **AND** exceeding `MAX_TOKENS` (10) MUST raise an exception with code 400
- **AND** `listApiTokens` MUST return masked previews; `revokeApiToken` MUST delete by id or raise 404

#### Scenario: Account deactivation request lifecycle
- **WHEN** `requestDeactivation($user, $reason)` is called
- **THEN** a pending request MUST be stored and returned with status `pending` and a `requestedAt` timestamp
- **AND** a second request while one is pending MUST raise an exception with code 409
- **AND** `getDeactivationStatus` MUST report `active` with `pendingRequest: null` when none exists
- **AND** `cancelDeactivation` MUST remove the request or raise 404 when none exists

## Notes

- **Security (observed, not changed):** API tokens are stored as `hash('sha256', $value)`
  with only the last 4 characters retained as a preview — plaintext is never persisted and
  is returned only once at creation. A prior bug let a malformed `expiresIn` (e.g. `"5x"`)
  fall through to a non-expiring token; the current code rejects unparseable `expiresIn`
  with an `InvalidArgumentException` so a typo can no longer mint a perpetual API key.
- The `profile-actions` spec marks these as "Not implemented" at the controller layer; the
  service-layer contract documented here is present and exercised. Controller-endpoint
  scenarios remain owned by the existing spec.
