---
status: done
---

# account-self-service Specification

## Purpose
Provides the signed-in user's self-service account page, letting users change their own password, request account deactivation, and manage their personal API tokens and avatar. Password changes and deactivation requests surface inline section feedback and leave the current session active until an administrator acts on the request.
## Requirements
### Requirement: The account page MUST provide self-service password and deactivation flows @e2e exclude isolated Vue component contract (PasswordSection.changePassword / AccountSection.requestDeactivation API call shape, inline feedback, form clearing, soft-state mutation) — covered by Vitest component unit tests with mocked fetch; the underlying password/deactivate endpoints are covered by Newman

The `/account` page MUST expose a `PasswordSection` and an `AccountSection`. `PasswordSection` MUST accept the user's current password and a new password, MUST issue `PUT /apps/openregister/api/user/me/password` with `{currentPassword, newPassword}`, and MUST surface success or failure as inline section feedback (no toast). `AccountSection.requestDeactivation()` MUST issue `POST /apps/openregister/api/user/me/deactivate` with an optional `reason` field, and on success MUST set the local status to `pending`, stamp `requestedAt` to the current ISO timestamp, and close the confirmation modal. Neither action MUST sign the user out or invalidate the current session immediately — the deactivation is a request, not an effect.

#### Scenario: Successful password change clears the form and shows success feedback
- **GIVEN** a signed-in user enters their current password and a valid new password and submits PasswordSection
- **WHEN** `changePassword()` calls `PUT /apps/openregister/api/user/me/password` and the response is HTTP 200 with `{message}`
- **THEN** `currentPassword` and `newPassword` MUST be cleared
- **AND** the section's `message` MUST display the API's `data.message` (or fall back to "Password updated successfully")
- **AND** `isError` MUST be `false`

#### Scenario: Failed password change surfaces the API error inline
- **GIVEN** the user submits a wrong current password
- **WHEN** the API responds with HTTP 4xx and body `{error: "..."}`
- **THEN** `message` MUST equal the API's `error` string (or fall back to "Failed to change password")
- **AND** `isError` MUST be `true`
- **AND** the form fields MUST be preserved so the user can correct and retry

#### Scenario: Deactivation request is a soft state change
- **GIVEN** the user opens AccountSection and enters a reason "leaving the organisation"
- **WHEN** `requestDeactivation()` calls `POST /apps/openregister/api/user/me/deactivate` with `{reason}` and the response is HTTP 2xx
- **THEN** `status` MUST become `'pending'`
- **AND** `requestedAt` MUST be a fresh ISO timestamp
- **AND** `showConfirmModal` MUST be `false`
- **AND** the section MUST display "Deactivation request submitted"
- **AND** the user's session MUST remain active until an admin acts on the request (separate flow, out of this spec)

### Requirement: The account page MUST list and manage the signed-in user's personal API tokens and avatar @e2e exclude isolated Vue component contract (TokensSection.loadTokens silent population/error-swallow, AvatarSection.triggerUpload ref-click) — covered by Vitest component unit tests with mocked fetch/refs; the tokens endpoint is covered by Newman

The `TokensSection` MUST issue `GET /apps/openregister/api/user/me/tokens` on `loadTokens()` and bind the response array to `tokens`. The list MUST refresh after any create/delete action on the same section. `AvatarSection.triggerUpload()` MUST programmatically click the hidden file input (`this.$refs.fileInput.click()`) so that the file-picker dialog opens; the section's "Upload" button is the only entry point — there MUST NOT be a drag-target zone. Avatar upload itself (the eventual `POST .../avatar`) is initiated by the file-input's `change` handler, not by `triggerUpload()`. `loadTokens()` MUST swallow errors during initial load (tokens may legitimately not yet be set for a new user) and MUST NOT show a global error.

#### Scenario: TokensSection mounts and silently populates the list
- **GIVEN** a signed-in user navigates to `/account` and the TokensSection mounts
- **WHEN** `loadTokens()` issues `GET /apps/openregister/api/user/me/tokens` and receives `[{...}, {...}]`
- **THEN** `this.tokens` MUST be set to the response array
- **AND** `this.loading` MUST be `false` after the request settles

#### Scenario: TokensSection silently tolerates "no tokens yet" on initial load
- **GIVEN** a new user with no API tokens whose backend returns HTTP 4xx on the tokens endpoint
- **WHEN** `loadTokens()` runs during the section's first mount
- **THEN** the catch branch MUST suppress the error (no toast, no inline error message)
- **AND** `this.loading` MUST be reset to `false` via the `finally` block
- **AND** the user MUST be able to click "Create token" to recover

#### Scenario: AvatarSection's Upload button opens the file picker
- **GIVEN** the AvatarSection is mounted and `canChangeAvatar` is true
- **WHEN** the user clicks the "Upload" button bound to `@click="triggerUpload"`
- **THEN** `triggerUpload()` MUST invoke `this.$refs.fileInput.click()`
- **AND** the browser's native file picker MUST open
- **AND** no network request MUST be made until the user selects a file (which then triggers `uploadAvatar`)

