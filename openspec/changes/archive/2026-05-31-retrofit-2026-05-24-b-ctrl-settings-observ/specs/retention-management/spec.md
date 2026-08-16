## ADDED Requirements

### Requirement: Retention configuration and rebase administration API
The system SHALL expose an admin-gated API for reading and writing retention settings and
for recalculating deletion times across the corpus. `ConfigurationSettingsController`
provides `getRetentionSettings` (delegating to `SettingsService::getRetentionSettingsOnly()`)
and `updateRetentionSettings` (delegating to `updateRetentionSettingsOnly()`).
`SettingsController::rebase` recalculates deletion times for all objects and logs from the
current retention settings and assigns default owners/organisations to objects that lack
them.

#### Scenario: Read retention settings
- **WHEN** `getRetentionSettings` is called
- **THEN** it MUST return the retention settings from `SettingsService::getRetentionSettingsOnly()`

#### Scenario: Update retention settings
- **GIVEN** an admin posts updated retention values
- **WHEN** `updateRetentionSettings` runs
- **THEN** it MUST persist them via `SettingsService::updateRetentionSettingsOnly()` and return the result

#### Scenario: Rebase recalculates deletion times
- **GIVEN** retention settings have changed
- **WHEN** `SettingsController::rebase` runs
- **THEN** it MUST delegate to `SettingsService::rebase()` to recompute object/log deletion times
- **AND** a service failure MUST surface as HTTP 500 with an `error` field
